<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

/**
 * Dynamic virtual channels (MS-RDPEDYC): Display Control (MS-RDPEDISP) and, optionally, the graphics pipeline (MS-RDPEGFX),
 * whose messages are forwarded to the browser untouched (it decompresses and decodes them), the microphone (see Audin) and
 * the camera (see Rdpecam).
 */
final class Drdynvc implements Channel
{
    private const DISP = 'Microsoft::Windows::RDS::DisplayControl';
    private const GFX  = 'Microsoft::Windows::RDS::Graphics';

    private ?int $dispId = null;
    private ?int $gfxId = null;
    private ?int $micId = null;
    private ?Audin $mic = null;
    private ?int $touchId = null;
    private ?Rdpei $touch = null;
    private ?int $camEnumId = null;
    /** @var array<int, true> the camera's channels open (Windows 11 opens two) */
    private array $camIds = [];
    private ?Rdpecam $cam = null;
    private array $buf = [];
    private array $total = [];

    /**
     * $avc: the browser decodes H.264. The graphics pipeline itself is $c->gfx, decided when connecting.
     * $mic: the browser's microphone is offered (the connection option "Microphone and camera", off by default).
     * $touch: multitouch is taken (the connection option, off by default; see Rdpei).
     * $camera: the browser's camera, by this name, offered (with the microphone, when the browser has one); null: none.
     */
    public function __construct(private Client $c, private bool $avc = false, bool $mic = false, bool $touch = false, ?string $camera = null)
    {
        if ($mic) {
            $this->mic = new Audin($c, fn(string $m) => $this->micId === null ? null : $this->sendData($this->micId, $m));
        }
        if ($camera !== null) {
            $this->cam = new Rdpecam($c, fn(string $m) => $this->camEnumId === null ? null : $this->sendData($this->camEnumId, $m),
                fn(int $ch, string $m) => isset($this->camIds[$ch]) ? $this->sendData($ch, $m) : null, $camera);
        }
        if ($touch) {
            $this->touch = new Rdpei($c, fn(string $m) => $this->touchId === null ? null : $this->sendData($this->touchId, $m));
        }
    }

    public function name(): string
    {
        return 'drdynvc';
    }

    private static function readVar(Reader $r, int $size): int
    {
        return match ($size) { 0 => $r->u8(), 1 => $r->u16(), default => $r->u32() };
    }

    private static function cb(int $id): int
    {
        return $id <= 0xff ? 0 : ($id <= 0xffff ? 1 : 2);
    }

    private static function var(int $id): string
    {
        return match (self::cb($id)) { 0 => chr($id), 1 => pack('v', $id), default => pack('V', $id) };
    }

    private function send(int $cmd, int $id, string $payload): void
    {
        $this->c->sendChannel('drdynvc', chr(($cmd << 4) | self::cb($id)) . self::var($id) . $payload);
    }

    /**
     * One message of any size: each PDU fits in one channel chunk (1600 bytes), so a longer message goes as a Data First
     * PDU, with its total length, and Data PDUs with the rest.
     */
    private function sendData(int $id, string $msg): void
    {
        $len  = strlen($msg);
        $room = 1600 - 1 - strlen(self::var($id));
        if ($len <= $room) {
            $this->send(3, $id, $msg);
            return;
        }
        $first = $room - strlen(self::var($len));
        $this->c->sendChannel('drdynvc', chr((2 << 4) | (self::cb($len) << 2) | self::cb($id)) . self::var($id) . self::var($len) . substr($msg, 0, $first));
        for ($o = $first; $o < $len; $o += $room) {
            $this->send(3, $id, substr($msg, $o, $room));
        }
    }

    public function onData(string $data): void
    {
        $r   = new Reader($data);
        $h   = $r->u8();
        $cmd = $h >> 4;
        $sp  = ($h >> 2) & 3;
        $cb  = $h & 3;
        if ($cmd === 5) { // capabilities
            $this->c->trace?->log('dvc capabilities', ['serverVersion' => strlen($data) >= 4 ? unpack('v', $data, 2)[1] : 0, 'answered' => 2]);
            $this->c->sendChannel('drdynvc', "\x50\x00" . pack('v', 2)); // version 2: no compressed data PDUs (version 3)
            return;
        }
        $id = self::readVar($r, $cb);
        switch ($cmd) {
            case 1: // create
                unset($this->buf[$id], $this->total[$id]);   // (a message of an earlier channel of that id: not joined to this one)
                $name = strstr($r->rest(), "\0", true);
                $ok   = $name === self::DISP || ($this->c->gfx && $name === self::GFX) || ($this->mic && $name === Audin::NAME)
                     || ($this->touch && $name === Rdpei::NAME)
                     || ($this->cam && ($name === Rdpecam::ENUMERATOR || ($name === Rdpecam::DEVICE && $this->camEnumId !== null))
                         && !in_array('pnp', $this->c->denied, true));   // (a gateway's policy without Plug and Play devices: no camera, as Windows' client)
                if ($ok && $name === Rdpecam::DEVICE) {
                    $this->camIds[$id] = true;          // (another one may be open: Windows 11 opens two, each answered apart)
                    $this->cam->opened($id);
                }
                if ($ok && $name === Rdpei::NAME) {
                    $this->touchId = $id;
                }
                if ($name === self::DISP) {
                    $this->dispId = $id;
                }
                if ($ok && $name === Audin::NAME) {
                    $this->mic->stop();                 // a program started recording there (again)
                    $this->micId = $id;
                }
                $this->c->trace?->log('dvc create', ['id' => $id, 'name' => $name, 'accepted' => $ok]);
                $this->send(1, $id, pack('V', $ok ? 0 : 0xC0000001));
                if ($ok && $name === Rdpecam::ENUMERATOR) {
                    $this->camEnumId = $id;
                    $this->cam->enumOpened();           // the client speaks first there: its version
                }
                if ($ok && $name === self::GFX) {
                    $this->gfxId = $id;
                    // CAPS_ADVERTISE: 8.1 (with AVC420 when the browser decodes H.264) and 8.0; with H.264 also 10.7 first:
                    // Windows only uses H.264 with 10.x (offered 8.1 it chose 8.0); AVC444 is shown from its 4:2:0 view.
                    // 10.7 flag 0x80 (SCALEDMAP_DISABLE): surfaces mapped 1:1, as with 8.x
                    $caps = ($this->avc ? pack('VVV', 0x000A0701, 4, 0x80) : '') . pack('VVV', 0x00080105, 4, $this->avc ? 0x10 : 0) . pack('VVV', 0x00080004, 4, 0);
                    $this->send(3, $id, pack('vvV', 0x0012, 0, 10 + strlen($caps)) . pack('v', strlen($caps) / 12) . $caps);
                    $this->c->trace?->log('gfx caps advertise', ['versions' => [...($this->avc ? ['10.7'] : []), '8.1 flags 0x' . dechex($this->avc ? 0x10 : 0), '8.0']]);
                }
                break;
            case 2: // data first
                $total = self::readVar($r, $sp);
                if ($total > 67108864 || $total > Client::memoryRoom() || (!isset($this->total[$id]) && count($this->total) >= 8)) {
                    throw new ProtocolException("Dynamic channel message of $total bytes", 'protocol');
                }
                $this->total[$id] = $total;
                $this->buf[$id]   = $r->rest();
                $this->complete($id);
                break;
            case 3: // data
                if (isset($this->total[$id])) {
                    $this->buf[$id] .= $r->rest();
                    $this->complete($id);
                } else {
                    $this->dispatch($id, $r->rest());
                }
                break;
            case 4: // close
                unset($this->buf[$id], $this->total[$id]);   // what it was joining goes with it (it counted towards the 8)
                $this->c->trace?->log('dvc close', ['id' => $id]);
                if ($id === $this->dispId) {
                    $this->dispId = null;
                }
                if ($id === $this->gfxId) {
                    $this->gfxId = null;
                }
                if ($id === $this->micId) {
                    $this->micId = null;
                    $this->mic->stop();                 // no program records there any more: the browser lets go of the microphone
                }
                if ($id === $this->touchId) {
                    $this->touchId = null;
                    $this->touch->closed();
                }
                if (isset($this->camIds[$id])) {
                    unset($this->camIds[$id]);
                    $this->cam->closed($id);            // its stream ends: the browser lets go of its camera if no other runs
                } elseif ($id === $this->camEnumId) {
                    $this->camEnumId = null;
                }
                $this->send(4, $id, '');
                break;
        }
    }

    private function complete(int $id): void
    {
        if (strlen($this->buf[$id]) >= $this->total[$id]) {
            $msg = $this->buf[$id];
            unset($this->buf[$id], $this->total[$id]);
            $this->dispatch($id, $msg);
        }
    }

    private function dispatch(int $id, string $msg): void
    {
        $this->c->trace?->count('dvc:' . ($id === $this->gfxId ? 'gfx' : ($id === $this->dispId ? 'displayControl' : ($id === $this->micId ? 'microphone'
            : ($id === $this->touchId ? 'touch' : (isset($this->camIds[$id]) || $id === $this->camEnumId ? 'camera' : $id))))), strlen($msg));
        if ($id === $this->gfxId) {
            $this->c->emit(Client::F_GFX, $msg);
            return;
        }
        if ($id === $this->camEnumId) {
            $this->cam->onEnum($msg);
            return;
        }
        if (isset($this->camIds[$id])) {
            $this->cam->onDevice($id, $msg);
            return;
        }
        if ($id === $this->micId) {
            $this->mic->onMessage($msg);
            return;
        }
        if ($id === $this->touchId) {
            $this->touch->onMessage($msg);
            return;
        }
        if ($id === $this->dispId && strlen($msg) >= 8 && unpack('V', $msg)[1] === 5) {
            $this->c->trace?->log('display control caps', ['data' => Trace::hex($msg)]);
            $this->c->emit(Client::F_DISP, ''); // DISPLAYCONTROL_CAPS: dynamic resize available
        }
    }

    /** FRAME_ACKNOWLEDGE from the browser: $ids = u32 frameId + u32 totalFramesDecoded. */
    public function gfxAck(string $ids): void
    {
        if ($this->gfxId !== null) {
            $this->send(3, $this->gfxId, pack('vvVV', 0x000D, 0, 20, 0) . $ids);
        }
    }

    /** A multitouch frame from the browser (see Rdpei::fromBrowser). */
    public function touchData(string $frame): void
    {
        if ($this->touchId !== null) {
            $this->touch->fromBrowser($frame);
        }
    }

    /** Microphone audio from the browser (16-bit PCM in the format it was told); what the remote computer was sent of it (see Audin::fromBrowser). */
    public function micData(string $pcm): string
    {
        return $this->micId !== null ? $this->mic->fromBrowser($pcm) : '';
    }

    /** From the browser's camera: u8 the start it belongs to (F_CAM), then a picture (JPEG), or nothing when it could not open its camera. */
    public function camData(string $m): void
    {
        if ($this->camIds && $m !== '') {
            strlen($m) === 1 ? $this->cam->browserFailed(ord($m[0])) : $this->cam->fromBrowser(ord($m[0]), substr($m, 1));
        }
    }

    /**
     * This connection given up (another one made in its place): the browser lets go of its microphone and its camera, which
     * the new one asks for again if a program there still uses them (else the camera stayed on, its pictures dropped).
     */
    public function stopCapture(): void
    {
        $this->mic?->stop();
        $this->cam?->stop();
    }

    /** Requests a new desktop size; returns false if the channel is not open. */
    public function resize(int $w, int $h): bool
    {
        return $this->resizeMonitors([[0, 0, $w, $h, true]]);
    }

    /**
     * Monitor layout (DISPLAYCONTROL_MONITOR_LAYOUT_PDU): each monitor [x, y, w, h, primary] in desktop coordinates, the
     * primary at 0,0; widths even and sizes 200..8192, as MS-RDPEDISP asks. Windows applies it without reconnecting.
     */
    public function resizeMonitors(array $mons): bool
    {
        if ($this->dispId === null || !$mons) {
            return false;
        }
        $body = '';
        $log  = [];
        foreach ($mons as [$x, $y, $w, $h, $primary]) {
            $w      = max(200, min(8192, $w & ~1));
            $h      = max(200, min(8192, $h));
            $body  .= pack('VllVVVVVVV', $primary ? 1 : 0, $x, $y, $w, $h, 0, 0, 0, 100, 100);
            $log[]  = "$x,$y {$w}x$h";
        }
        $this->c->trace?->log('display control layout', ['monitors' => $log]);
        $this->send(3, $this->dispId, pack('VVVV', 2, 16 + 40 * count($mons), 40, count($mons)) . $body);
        return true;
    }

    /**
     * Several monitors from the browser, [[x, y, w, h, primary (1)], ...] relative to the primary: at most 16, each 200..8192
     * px within +-32766, exactly one primary, at 0,0. Null for anything else (one monitor is no layout of several).
     */
    public static function monitors(array $list): ?array
    {
        $mons = [];
        foreach (array_slice($list, 0, 16) as $m) {
            // (a float past the int range, 1e20 in the JSON, gave a warning on PHP 8.5 when cast)
            [$x, $y, $w, $h, $pr] = array_map(static fn($v) => is_int($v) || is_bool($v) ? (int)$v
                : (is_float($v) || (is_string($v) && is_numeric($v)) ? (int)max(-1e9, min(1e9, (float)$v)) : 0), array_pad(array_values((array)$m), 5, 0));
            if ($w >= 200 && $w <= 8192 && $h >= 200 && $h <= 8192 && abs($x) <= 32766 && abs($y) <= 32766) {
                $mons[] = [$x, $y, $w, $h, $pr === 1];
            }
        }
        $primaries = count(array_filter($mons, static fn($m) => $m[4] && $m[0] === 0 && $m[1] === 0));
        return count($mons) > 1 && $primaries === 1 && count(array_filter($mons, static fn($m) => $m[4])) === 1 ? $mons : null;
    }

    /** Size of the desktop of several monitors: their bounding box. */
    public static function bounds(array $mons): array
    {
        return [max(array_map(static fn($m) => $m[0] + $m[2], $mons)) - min(array_column($mons, 0)),
            max(array_map(static fn($m) => $m[1] + $m[3], $mons)) - min(array_column($mons, 1))];
    }
}
