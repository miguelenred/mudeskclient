<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

/**
 * Audio output redirection (MS-RDPEA, static channel "rdpsnd").
 * Accepts the formats the browser can decode (IMA ADPCM, A-law, mu-law, PCM) and forwards the samples:
 * frame F_AUDIO 'F' + JSON format list | 'W' + u16 format index + data.
 */
final class Rdpsnd implements Channel
{
    // formats the browser decodes (IMA ADPCM, A-law, mu-law, PCM), answered in the server's order: Windows 11 sends no audio
    // when the client reorders them (it picks the format itself)
    private const TAGS = [0x0011, 0x0006, 0x0007, 0x0001];

    /** server format index => client list index */
    private array $map = [];
    private ?array $pending = null;

    public function __construct(private Client $c)
    {
    }

    public function name(): string
    {
        return 'rdpsnd';
    }

    private function send(int $type, string $body): void
    {
        $this->c->sendChannel('rdpsnd', pack('CCv', $type, 0, strlen($body)) . $body);
    }

    public function onData(string $data): void
    {
        if ($this->pending !== null) { // Wave PDU: its first 4 bytes are replaced by the ones sent in Wave Info
            [$ts, $fmt, $block, $first] = $this->pending;
            $this->pending = null;
            $this->play($ts, $fmt, $block, $first . substr($data, 4));
            return;
        }
        $r    = new Reader($data);
        $type = $r->u8();
        $r->skip(3);
        switch ($type) {
            case 0x07:
                $this->formats($r);
                break;
            case 0x06: // training
                $this->send(0x06, pack('vv', $r->u16(), $r->u16()));
                break;
            case 0x02: // wave info, data follows in the next message
                $ts  = $r->u16();
                $fmt = $r->u16();
                $blk = $r->u8();
                $r->skip(3);
                $this->pending = [$ts, $fmt, $blk, $r->bytes(4)];
                break;
            case 0x0D: // wave2
                $ts  = $r->u16();
                $fmt = $r->u16();
                $blk = $r->u8();
                $r->skip(7);
                $this->play($ts, $fmt, $blk, $r->rest());
                break;
        }
    }

    /**
     * Whether the browser can play a format the server offers: PCM of 8 or 16 bits, A-law, mu-law or IMA ADPCM, 1 or 2
     * channels, a rate the Web Audio API takes (8-192 kHz) and, for IMA ADPCM, blocks of a header per channel followed by
     * whole groups of 4 bytes per channel. Anything else (no channels, a rate of 0, a block of 0 or an odd one) made the
     * page throw on every chunk.
     */
    private static function playable(int $tag, int $ch, int $rate, int $bits, int $align): bool
    {
        return in_array($tag, self::TAGS, true) && $ch >= 1 && $ch <= 2 && $rate >= 8000 && $rate <= 192000
            && match ($tag) {
                0x0001  => $bits === 16 || $bits === 8,
                0x0011  => $align > 4 * $ch && ($align - 4 * $ch) % (4 * $ch) === 0,
                default => true,
            };
    }

    private function formats(Reader $r): void
    {
        $r->skip(14);
        $n = $r->u16();
        $r->skip(1);
        $version = $r->u16();
        $r->skip(1);
        $accepted = [];
        $offered  = [];
        for ($i = 0; $i < $n && $r->left() >= 18; $i++) {
            $start = $r->pos;
            $tag   = $r->u16();
            $ch    = $r->u16();
            $rate  = $r->u32();
            $r->skip(4);
            $align = $r->u16();
            $bits  = $r->u16();
            $r->skip($r->u16());
            $raw = substr($r->data, $start, $r->pos - $start);
            $offered[] = sprintf('0x%04x %dch %dHz %dbit', $tag, $ch, $rate, $bits);
            if (self::playable($tag, $ch, $rate, $bits, $align)) {
                $accepted[] = ['i' => $i, 'raw' => $raw, 'f' => ['tag' => $tag, 'ch' => $ch, 'rate' => $rate, 'bits' => $bits, 'align' => $align]];
            }
        }
        $this->c->trace?->log('audio formats', ['serverVersion' => $version, 'offered' => $offered, 'accepted' => array_column($accepted, 'i')]);
        $this->map = [];
        $list      = [];
        foreach ($accepted as $k => $a) {
            $this->map[$k] = $a['f'];
            $list[]        = $a['raw'];
        }
        $this->send(0x07, pack('VVVvvCvC', 0x1, 0xFFFFFFFF, 0, 0, count($list), 0, 8, 0) . implode('', $list));
        if ($version >= 6) {
            $this->send(0x0C, pack('vv', 0, 0)); // quality mode: dynamic
        }
        $this->c->emit(Client::F_AUDIO, 'F' . json_encode(array_values($this->map)));
    }

    private function play(int $ts, int $fmt, int $block, string $samples): void
    {
        if (isset($this->map[$fmt])) {
            $this->c->emit(Client::F_AUDIO, 'W' . pack('v', $fmt) . $samples);
        }
        $this->send(0x05, pack('vCC', ($ts + 50) & 0xffff, $block, 0));
    }
}
