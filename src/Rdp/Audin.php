<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

/**
 * Microphone (MS-RDPEAI, the AUDIO_INPUT dynamic channel): the browser's microphone recorded on the remote computer.
 * Windows opens the channel when a program there starts recording and closes it when none records any more. The gateway
 * answers the negotiation itself: Windows waits 5 s at most for each answer, and the browser may take longer to be allowed
 * the microphone. Only 16-bit PCM is accepted, which the browser sends as it is: mono first, then the highest rates.
 */
final class Audin
{
    public const NAME = 'AUDIO_INPUT';
    private const VERSION       = 0x01;
    private const FORMATS       = 0x02;
    private const OPEN          = 0x03;
    private const OPEN_REPLY    = 0x04;
    private const INCOMING      = 0x05;
    private const DATA          = 0x06;
    private const FORMAT_CHANGE = 0x07;
    /** Most microphone audio kept while the server is slow to take it, in seconds: older audio is dropped, not delayed */
    private const BACKLOG = 1;

    /** @var list<array{rate:int, ch:int}> formats agreed with the server (Open and Format Change give an index into them) */
    private array $formats = [];
    /** @var array{rate:int, ch:int}|null the format in use while open */
    private ?array $format = null;
    private int $asked = 0;            // frames in each Data PDU, as the Open PDU asked (FramesPerPacket)
    private int $frames = 0;           // ... as sent: see use()
    private string $pending = '';      // audio from the browser not sent yet: less than a packet

    /** @param \Closure(string):void $send sends one message on the channel */
    public function __construct(private Client $c, private \Closure $send)
    {
    }

    /** One message from the server. A malformed one is left out: it never ends the session. */
    public function onMessage(string $msg): void
    {
        try {
            $r  = new Reader($msg);
            $id = $r->u8();
            match ($id) {
                self::VERSION       => $this->version($r),
                self::FORMATS       => $this->formats($r),
                self::OPEN          => $this->openPdu($r),
                self::FORMAT_CHANGE => $this->formatChange($r),
                default             => $this->c->trace?->log('mic unknown message', ['id' => $id]),
            };
        } catch (ProtocolException $e) {
            $this->c->trace?->log('mic bad message', ['message' => $e->getMessage(), 'data' => Trace::hex(substr($msg, 0, 64))]);
        }
    }

    /** Version 1: version 2 only changes how AAC formats are switched, and AAC is not accepted. */
    private function version(Reader $r): void
    {
        $this->c->trace?->log('mic version', ['server' => $r->u32(), 'answered' => 1]);
        ($this->send)(pack('CV', self::VERSION, 1));
    }

    private function formats(Reader $r): void
    {
        $n = $r->u32();
        $r->skip(4);                                // cbSizeFormatsPacket: reserved from the server
        $offered = [];
        $taken   = [];
        $body    = '';
        for ($i = 0; $i < min($n, 1000); $i++) {    // (read whole first: one cut short changes nothing)
            $f = unpack('vtag/vch/Vrate/Vavg/valign/vbits/vextra', $r->bytes(18));
            $r->skip($f['extra']);
            $offered[] = sprintf('0x%04x %d Hz %d ch %d bits', $f['tag'], $f['rate'], $f['ch'], $f['bits']);
            if ($f['tag'] === 0x0001 && $f['bits'] === 16 && ($f['ch'] === 1 || $f['ch'] === 2) && $f['rate'] >= 8000 && $f['rate'] <= 48000) {
                $taken[] = ['rate' => $f['rate'], 'ch' => $f['ch']];
            }
        }
        $this->stop();
        // mono first (a microphone is), then the highest rate: the server takes one of them, at most 96 KB/s
        usort($taken, static fn($a, $b) => [$a['ch'], -$a['rate']] <=> [$b['ch'], -$b['rate']]);
        $this->formats = array_values(array_unique($taken, SORT_REGULAR));
        foreach ($this->formats as $f) {
            $body .= pack('vvVVvvv', 0x0001, $f['ch'], $f['rate'], $f['rate'] * $f['ch'] * 2, $f['ch'] * 2, 16, 0);
        }
        $this->c->trace?->log('mic formats', ['offered' => $offered, 'accepted' => array_map(static fn($f) => "{$f['rate']} Hz {$f['ch']} ch", $this->formats)]);
        ($this->send)(chr(self::INCOMING));          // as the client sends it before a Sound Formats or Data PDU
        ($this->send)(pack('CVV', self::FORMATS, count($this->formats), 9 + strlen($body)) . $body);
    }

    private function openPdu(Reader $r): void
    {
        $frames = $r->u32();
        $index  = $r->u32();
        if (!isset($this->formats[$index])) {
            $this->c->trace?->log('mic open', ['format' => $index, 'error' => 'no such format']);
            ($this->send)(pack('CV', self::OPEN_REPLY, 0x80070057));   // E_INVALIDARG
            return;
        }
        $this->asked = $frames;
        $this->use($index);
        $this->c->trace?->log('mic open', ['format' => "{$this->format['rate']} Hz {$this->format['ch']} ch", 'framesPerPacket' => $frames]);
        ($this->send)(pack('CV', self::FORMAT_CHANGE, $index));      // the initial format confirmed, then the result
        ($this->send)(pack('CV', self::OPEN_REPLY, 0));
    }

    private function formatChange(Reader $r): void
    {
        $index = $r->u32();
        if (!isset($this->formats[$index]) || $this->format === null) {   // (before an Open there was no packet size: an endless loop)
            $this->c->trace?->log('mic format change', ['format' => $index, 'error' => $this->format === null ? 'not open' : 'no such format']);
            return;
        }
        $this->use($index);
        $this->c->trace?->log('mic format change', ['format' => "{$this->format['rate']} Hz {$this->format['ch']} ch"]);
        ($this->send)(pack('CV', self::FORMAT_CHANGE, $index));
    }

    /** Records in this format from now on: the browser is told (F_MIC: 1, u32 rate, u8 channels). */
    private function use(int $index): void
    {
        $this->format  = $this->formats[$index];
        $this->pending = '';
        // as many frames as asked in each packet, from 10 ms to a second (20 ms for none): 1 frame a packet sent two messages
        // for every 2 bytes, 88,200 a second of audio, and took the gateway's time
        $rate          = $this->format['rate'];
        $this->frames  = $this->asked === 0 ? intdiv($rate, 50) : max(intdiv($rate, 100), min($rate, $this->asked));
        $this->c->emit(Client::F_MIC, pack('CVC', 1, $this->format['rate'], $this->format['ch']));
    }

    /** Audio from the browser, 16-bit PCM in the format in use, sent in packets of the size the server asked for. */
    public function fromBrowser(string $pcm): void
    {
        if ($this->format === null) {
            return;                                   // closed meanwhile: audio recorded before the browser knew
        }
        $frame = $this->format['ch'] * 2;
        $max   = $this->format['rate'] * $frame * self::BACKLOG;
        $this->pending .= $pcm;
        if (strlen($this->pending) > $max) {          // the oldest goes: the remote computer hears now, not a growing delay
            $this->pending = substr($this->pending, -intdiv($max, $frame) * $frame);
        }
        $size = $this->frames * $frame;
        $sent = 0;
        while (strlen($this->pending) - $sent >= $size) {
            ($this->send)(chr(self::INCOMING));
            ($this->send)(chr(self::DATA) . substr($this->pending, $sent, $size));
            $sent += $size;
        }
        $this->pending = (string)substr($this->pending, $sent);
        $this->c->trace?->count('mic:sent', $sent);
    }

    /** The channel closed (or the formats start over): the browser stops recording. */
    public function stop(): void
    {
        if ($this->format !== null) {
            $this->c->emit(Client::F_MIC, "\0");
        }
        $this->format  = null;
        $this->pending = '';
    }
}
