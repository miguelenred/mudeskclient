<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

/**
 * Multitouch (MS-RDPEI, the dynamic channel "Microsoft::Windows::RDS::Input"): the fingers on the browser's screen reach
 * Windows as touch, not as a mouse, so its own gestures work (pinch, pan with two fingers, press and hold). The server says
 * it is ready (SC_READY), the gateway answers (CS_READY) and tells the browser, which then sends a frame per change: every
 * finger on the screen with its state; each frame goes as one touch event PDU. Offered only with the connection option:
 * without it the page's own handling is used (the finger moves the mouse; pinching zooms the page's view).
 */
final class Rdpei
{
    public const NAME         = 'Microsoft::Windows::RDS::Input';
    public const MAX_CONTACTS = 10;
    private const V100        = 0x00010000;
    private const V101        = 0x00010001;
    /**
     * The browser's states to contactFlags, among the combinations MS-RDPEI 2.2.3.3.1.1 allows: down (DOWN, INRANGE,
     * INCONTACT), moved or still (UPDATE, INRANGE, INCONTACT), lifted (UP), taken by the system (UP, CANCELED).
     */
    private const FLAGS = [0 => 0x19, 1 => 0x1A, 2 => 0x04, 3 => 0x24];

    private bool $ready     = false;
    private bool $suspended = false;
    private int $last       = 0;          // hrtime of the previous frame (frameOffset); 0 before the first

    /** @param \Closure(string): void $send sends a message on the channel */
    public function __construct(private Client $c, private \Closure $send)
    {
    }

    /** A message of the server: ready (answered), or input suspended / resumed; anything else, or malformed, left out. */
    public function onMessage(string $m): void
    {
        if (strlen($m) < 6) {
            return;
        }
        ['e' => $event, 'n' => $len] = unpack('ve/Vn', $m);
        if ($len !== strlen($m)) {
            return;                             // a length that does not match what came: ignored, as the specification says
        }
        if ($event === 1 && $len >= 10) {       // SC_READY: answered with CS_READY (touch visuals shown, up to 10 fingers)
            $version = unpack('V', $m, 6)[1] >= self::V101 ? self::V101 : self::V100;
            ($this->send)(pack('vVVVv', 2, 16, 1, $version, self::MAX_CONTACTS));
            $this->ready     = true;
            $this->suspended = false;
            $this->last      = 0;
            $this->c->trace?->log('rdpei ready', ['server' => sprintf('0x%08x', unpack('V', $m, 6)[1]), 'answered' => sprintf('0x%08x', $version)]);
            $this->c->emit(Client::F_STATUS, '{"touch":true}');
        } elseif ($event === 4 || $event === 5) {   // SUSPEND_INPUT, RESUME_INPUT
            $this->suspended = $event === 4;
            $this->c->emit(Client::F_STATUS, $this->suspended ? '{"touch":false}' : '{"touch":true}');
        }
    }

    /** The channel closed: the browser goes back to its own handling of touch. */
    public function closed(): void
    {
        if ($this->ready) {
            $this->ready = false;
            $this->c->emit(Client::F_STATUS, '{"touch":false}');
        }
    }

    /**
     * A frame from the browser: u8 count, then per finger u8 id, u8 state (0 down, 1 moved or still, 2 lifted, 3 canceled),
     * i16 x, i16 y (little-endian, desktop coordinates). Sent as a touch event PDU with that one frame; anything malformed,
     * or sent while the server is not ready or has suspended input, is left out.
     */
    public function fromBrowser(string $d): void
    {
        $n = ord($d[0] ?? "\0");
        if (!$this->ready || $this->suspended || $n === 0 || $n > self::MAX_CONTACTS || strlen($d) !== 1 + 6 * $n) {
            return;
        }
        $contacts = '';
        for ($i = 0; $i < $n; $i++) {
            ['id' => $id, 's' => $state, 'x' => $x, 'y' => $y] = unpack('Cid/Cs/vx/vy', $d, 1 + 6 * $i);
            if (!isset(self::FLAGS[$state])) {
                return;
            }
            $contacts .= chr($id) . self::u16(0) . self::s32($x >= 0x8000 ? $x - 0x10000 : $x) . self::s32($y >= 0x8000 ? $y - 0x10000 : $y)
                       . self::u32(self::FLAGS[$state]);
        }
        $now        = hrtime(true);
        $offset     = $this->last === 0 ? 0 : intdiv($now - $this->last, 1000);   // microseconds since the previous frame
        $this->last = $now;
        $body = self::u32(0) . self::u16(1) . self::u16($n) . self::u64($offset) . $contacts;   // encodeTime 0, one frame
        ($this->send)(pack('vV', 3, 6 + strlen($body)) . $body);
    }

    // ---- the variable-length integers of MS-RDPEI 2.2.2: a byte count in the top bits of the first byte, a sign bit for
    // the signed ones (sign and magnitude), then the value with its most significant bits first; the shortest form

    public static function u16(int $v): string
    {
        return self::varint($v, 1, false, 2);
    }

    public static function s16(int $v): string
    {
        return self::varint($v, 1, true, 2);
    }

    public static function u32(int $v): string
    {
        return self::varint($v, 2, false, 4);
    }

    public static function s32(int $v): string
    {
        return self::varint($v, 2, true, 4);
    }

    public static function u64(int $v): string
    {
        return self::varint($v, 3, false, 8);
    }

    private static function varint(int $v, int $countBits, bool $signed, int $maxBytes): string
    {
        $m     = abs($v);
        $first = 8 - $countBits - ($signed ? 1 : 0);   // value bits in the first byte
        for ($n = 1; $n <= $maxBytes; $n++) {
            if ($m < 1 << ($first + 8 * ($n - 1))) {
                $out = chr((($n - 1) << (8 - $countBits)) | ($signed && $v < 0 ? 1 << $first : 0) | ($m >> (8 * ($n - 1))));
                for ($i = $n - 2; $i >= 0; $i--) {
                    $out .= chr(($m >> (8 * $i)) & 0xFF);
                }
                return $out;
            }
        }
        throw new \RangeException("$v does not fit");
    }
}
