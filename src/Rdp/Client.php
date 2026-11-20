<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

use MuDesk\Text;

/**
 * RDP client core (MS-RDPBCGR): connection sequence, capabilities, fast-path I/O and static virtual channels.
 * Graphics are not decoded here: bitmap and pointer updates are forwarded to the browser as-is.
 */
final class Client
{
    // frames sent to the browser
    public const F_UPDATE = 1; // u8 fast-path update code + data
    public const F_RESIZE = 2; // u16 width, u16 height, u8 bpp
    public const F_STATUS = 3; // JSON
    public const F_CLIP   = 4; // clipboard sub-protocol
    public const F_DISP   = 5; // dynamic resize available
    public const F_AUDIO  = 7; // audio sub-protocol (see Rdpsnd)
    public const F_PRINT  = 8; // JSON {job, size, type}: a document printed on MuDeskPDF
    public const F_GFX    = 9; // graphics pipeline message (RDP_SEGMENTED_DATA, decoded in the browser)
    public const F_ZUPDATE = 10; // bulk compression on: u8 code, u8 fragmentation, u8 compression flags + update as received
    public const F_ZSLOW   = 11; // bulk compression on: u8 flags, u8 pduType2 + compressed slow-path data PDU (updates and pointers are drawn from it)
    public const F_RAIL    = 12; // RemoteApp: JSON about the program's windows that does not come with the drawing orders (cloaked)
    public const F_MIC     = 13; // microphone: u8 1 + u32 rate + u8 channels (record 16-bit PCM like this), or u8 0 (stop)

    private const IO_SERVER = 0x03EA;
    /** Seconds from the Client Info to the Demand Active (licensing included) */
    private const ACTIVATION_TIMEOUT = 30;
    /** Largest fast-path update reassembled from fragments: the MaxRequestSize announced (multifragment capability) */
    private const FRAGMENT_MAX = 0x1000000;
    /**
     * Characters typed one by one when a text cannot be pasted (the rest is dropped): at 100 ms each, all the input behind
     * waits up to 200 s; a 1 MB text took hours and exhausted the memory.
     */
    private const TYPE_MAX = 2000;

    public Transport $t;
    public int $width;
    public int $height;
    public bool $active = false;
    /** Graphics pipeline in use (option 'gfx': true/'on', false/'off' or 'auto', decided after NLA) */
    public bool $gfx = false;
    /** Server's Windows version seen during NLA ([major, minor, build]), null without NLA or when not sent */
    public ?array $serverVersion = null;
    public int $errorInfo = 0;
    public string $certFingerprint = '';
    public ?Trace $trace = null;      // diagnostic mode

    private const CAPS = [1 => 'general', 2 => 'bitmap', 3 => 'order', 4 => 'bitmapCache', 5 => 'control', 7 => 'activation', 8 => 'pointer',
        9 => 'share', 10 => 'colorCache', 12 => 'sound', 13 => 'input', 14 => 'font', 15 => 'brush', 16 => 'glyphCache', 17 => 'offscreen',
        18 => 'bitmapCacheHostSupport', 19 => 'bitmapCacheRev2', 20 => 'virtualChannel', 21 => 'drawNineGrid', 22 => 'drawGdiPlus',
        23 => 'rail', 24 => 'window', 25 => 'desktopComposition', 26 => 'multifragmentUpdate', 27 => 'largePointer', 28 => 'surfaceCommands',
        29 => 'bitmapCodecs', 30 => 'frameAcknowledge'];
    private const SLOW = [0x02 => 'update', 0x14 => 'control', 0x1B => 'pointer', 0x1F => 'synchronize', 0x21 => 'refreshRect', 0x22 => 'playSound',
        0x23 => 'suppressOutput', 0x26 => 'saveSessionInfo', 0x27 => 'fontList', 0x28 => 'fontMap', 0x29 => 'setKeyboardIndicators',
        0x2C => 'bitmapCachePersistentList', 0x2D => 'bitmapCacheErrorPdu', 0x2E => 'setKeyboardImeStatus', 0x2F => 'setErrorInfo',
        0x30 => 'drawNineGridError', 0x31 => 'drawGdiPlusError', 0x32 => 'arcStatus', 0x36 => 'statusInfo', 0x37 => 'monitorLayout'];
    private const COMPR = ['mppc8k', 'mppc64k', 'ncrush', 'xcrush', 'type4', 'type5', 'type6', 'type7', 'type8', 'type9', 'type10', 'type11', 'type12', 'type13', 'type14', 'type15'];
    private const FAST = [0 => 'orders', 1 => 'bitmap', 2 => 'palette', 3 => 'synchronize', 4 => 'surfaceCommands', 5 => 'pointerHidden',
        6 => 'pointerDefault', 8 => 'pointerPosition', 9 => 'colorPointer', 10 => 'cachedPointer', 11 => 'newPointer', 12 => 'largePointer'];

    private int $userId = 0;          // MCS initiator (raw value, 1001 + n - 1001)
    private int $ioChannel = 0x03EB;
    private int $shareId = 0;
    private bool $licensed = false;
    private ?Bulk $bulk = null;          // bulk decompression, when negotiated
    /** @var list<array{string, int}> compressed fast-path updates not yet run through the history (see catchUp) */
    private array $backlog = [];
    private ?Licensing $lic = null;
    private string $fragment = '';
    /** @var array<string,int> */
    private array $channelIds = [];
    /** @var array<string,int> */
    private array $channelOpts = [];
    /** @var array<int,Channel> */
    private array $handlers = [];
    /** @var array<int,list<string>> chunks of the channel message being received */
    private array $chanBuf = [];
    private array $railCaps = [];              // RemoteApp: the server's capability sets 23 and 24 (Demand Active)
    private array $chanTotal = [];             // channel id -> size the first chunk of a message announced
    private const CHANNEL_MAX = 67108864;      // largest channel message (a 4K screenshot copied is 33 MB)
    /** @var array<int,int> their total size */
    private array $chanLen = [];
    /** @var list<array{0:string,1:int,2:int,3?:string}> input waiting behind typed text or a paste (see input(), pasteText()) */
    private array $inputQueue = [];
    private float $inputAt = 0.0;        // input waits until then: a typed character's pause, or INF while a paste is under way
    /** pastes text through the remote clipboard (Cliprdr::typeText), which then calls inputDone() */
    public ?\Closure $paster = null;
    private int $drawn = 0;              // screen updates received (fast-path or graphics pipeline)
    private int $drawnAtInput = 0;       // ... when input was last sent (see drawnSinceInput())
    private ?int $drawnBefore = null;    // after a paste, input waits until the screen changes (see inputDone())

    /** @param callable(int,string):void $sink */
    public function __construct(private array $o, private $sink)
    {
        $this->width  = $o['width'];
        $this->height = $o['height'];
    }

    public function emit(int $type, string $payload): void
    {
        ($this->sink)($type, $payload);
    }

    public function addChannel(Channel $ch): void
    {
        $this->handlers[count($this->handlers)] = $ch;
    }

    // ------------------------------------------------------------------ framing

    private static function tpkt(string $x224): string
    {
        return "\x03\x00" . pack('n', strlen($x224) + 4) . $x224;
    }

    /**
     * An NLA failure as the user sees it: most servers drop the connection on bad credentials (Windows sends the error);
     * one that goes silent is not answering, which is not a wrong password (a slow domain controller, the 8 s waits).
     */
    private static function nlaFailure(ProtocolException $e): ProtocolException
    {
        return $e->key === 'closed' ? new ProtocolException($e->getMessage(), 'auth_failed') : $e;
    }

    private static function perLen(int $n): string
    {
        return $n < 0x80 ? chr($n) : pack('n', 0x8000 | $n);
    }

    private function mcsSend(int $channel, string $data): void
    {
        $this->t->send(self::tpkt("\x02\xf0\x80\x64" . pack('nn', $this->userId, $channel) . "\x70" . self::perLen(strlen($data)) . $data));
    }

    private function shareData(int $type2, string $payload): void
    {
        $data = pack('VCCvCCv', $this->shareId, 0, 1, strlen($payload), $type2, 0, 0) . $payload;
        $this->mcsSend($this->ioChannel, pack('vvv', strlen($data) + 6, 0x17, $this->userId + 1001) . $data);
    }

    // ------------------------------------------------------------------ connection sequence

    /** @param callable(string):void $verifyCert receives the SHA-256 certificate fingerprint; throw to abort */
    public function connect(callable $verifyCert): void
    {
        $o       = $this->o;
        $this->t = new Transport($o['ip'], $o['port']);

        // X.224 Connection Request with RDP negotiation (TLS, or TLS+NLA)
        $want   = $o['security'] === 'tls' ? 1 : 3;
        $cookie = 'Cookie: mstshash=' . preg_replace('/[^A-Za-z0-9._-]/', '', substr($o['user'], 0, 9)) . "\r\n";
        $neg    = pack('CCvV', 1, 0, 8, $want);
        $x      = "\xe0\x00\x00\x00\x00\x00" . $cookie . $neg;
        $this->t->send(self::tpkt(chr(strlen($x)) . $x));
        $r = new Reader($this->t->recvPdu());
        $r->skip(5);
        if ($r->u8() !== 0xD0) {
            throw new ProtocolException('Unexpected X.224 response', 'protocol');
        }
        $r->skip(5);
        if ($r->left() < 8) {
            throw new ProtocolException('Server only supports legacy RDP security', 'rdp_security');
        }
        $type = $r->u8();
        $r->skip(3);
        $val = $r->u32();
        if ($type === 3) {
            throw new ProtocolException("Negotiation failure $val", [1 => 'tls_required', 2 => 'rdp_security', 5 => 'nla_required'][$val] ?? 'protocol');
        }
        if ($val === 0) {
            throw new ProtocolException('Server only supports legacy RDP security', 'rdp_security');
        }
        if ($val & ~$want) {                     // not asked for (RDSTLS, HYBRID_EX...): never taken on
            throw new ProtocolException("Server chose protocol $val, not offered", 'protocol');
        }
        $this->trace?->log('x224', ['requested' => $want, 'selected' => $val, 'meaning' => [1 => 'TLS', 2 => 'NLA (CredSSP)', 4 => 'RDSTLS', 8 => 'NLA extended (HYBRID_EX)'][$val] ?? '?']);
        if ($o['security'] === 'nla' && $val !== 2) {
            throw new ProtocolException('Server does not offer NLA', 'nla_unavailable');
        }

        $cert                  = $this->t->startTls();
        $this->certFingerprint = (string)openssl_x509_fingerprint($cert, 'sha256');
        if ($this->trace) {
            $x = openssl_x509_parse($cert) ?: [];
            $this->trace->log('tls', $this->t->tlsInfo() + ['subject' => $x['subject']['CN'] ?? null, 'issuer' => $x['issuer']['CN'] ?? null,
                'validTo' => date('c', $x['validTo_time_t'] ?? 0), 'fingerprint' => $this->certFingerprint]);
        }
        $verifyCert($this->certFingerprint);
        if ($val === 2) {
            try {
                $this->trace?->log('nla', ['user_form' => str_contains($o['user'], '@') ? 'upn' : ($o['domain'] === '.' ? 'local' : ($o['domain'] !== '' ? 'domain' : 'plain'))]);
                $this->serverVersion = CredSsp::authenticate($this->t, Der::subjectPublicKey($cert), $o['user'], $o['domain'], $o['password'], $o['host']);
                $this->trace?->log('nla ok', ['server' => $this->serverVersion ? implode('.', $this->serverVersion) : null]);
            } catch (ProtocolException $e) {
                throw self::nlaFailure($e);
            }
        }

        $this->graphics();
        $this->mcsConnect($val);
        $this->t->send(self::tpkt("\x02\xf0\x80\x04\x01\x00\x01\x00"));       // Erect Domain
        $this->t->send(self::tpkt("\x02\xf0\x80\x28"));                       // Attach User
        $r = new Reader($this->t->recvPdu());
        $r->skip(7);
        if ($r->u8() >> 2 !== 11 || $r->u8() !== 0) {
            throw new ProtocolException('MCS attach user failed', 'protocol');
        }
        $this->userId = $r->u16be();
        $this->trace?->log('mcs attach', ['userId' => $this->userId + 1001, 'join' => [$this->ioChannel => 'io'] + array_flip($this->channelIds)]);
        foreach (array_merge([$this->userId + 1001, $this->ioChannel], array_values($this->channelIds)) as $ch) {
            $this->t->send(self::tpkt("\x02\xf0\x80\x38" . pack('nn', $this->userId, $ch)));
            $r = new Reader($this->t->recvPdu());
            $r->skip(7);
            if ($r->u8() >> 2 !== 15 || $r->u8() !== 0) {
                throw new ProtocolException("MCS channel join $ch failed", 'protocol');
            }
        }
        $this->sendClientInfo();
        $this->trace?->log('client info sent', ['perf' => sprintf('0x%x', $o['perf']), 'compression' => array_search($o['compression'] ?? 0, Bulk::LEVELS, true) ?: 'none',
            'audio' => $o['audio'], 'mic' => !empty($o['mic']), 'tzBias' => $o['tzBias']]);

        $this->activate(self::ACTIVATION_TIMEOUT);
    }

    /** Waits for the Demand Active (licensing comes first), with a limit for the whole wait: other PDUs do not extend it. */
    private function activate(float $timeout): void
    {
        $end = microtime(true) + $timeout;
        while (!$this->active) {
            $left = $end - microtime(true);
            if ($left <= 0) {
                throw new ProtocolException('No activation from the server', 'timeout');
            }
            $this->handle($this->t->recvPdu($left));
        }
    }

    /**
     * Graphics mode: 'auto' takes the graphics pipeline with Windows 8.1 / Server 2012 R2 or later (version 6.3+ seen during NLA),
     * where it costs PHP 0.4-2 % CPU against 6-52 % for compressed bitmaps; xrdp, TLS-only and older servers keep bitmaps.
     */
    private function graphics(): void
    {
        $mode      = $this->o['gfx'] ?? false;
        $v         = $this->serverVersion;
        $this->gfx = $mode === 'auto' ? $v !== null && $v[0] * 100 + $v[1] >= 603 : $mode === true || $mode === 'on';
        if ($this->gfx) {
            $this->o['bpp'] = 32;                    // the graphics pipeline requires 32 bpp
        }
        $this->trace?->log('graphics', ['mode' => is_string($mode) ? $mode : ($mode ? 'on' : 'off'), 'pipeline' => $this->gfx, 'bpp' => $this->o['bpp']]);
    }

    /** Client core data early capabilities: error info, 32 bpp, graphics pipeline, valid connection type. */
    private function earlyFlags(): int
    {
        return 0x0001 | ($this->o['bpp'] === 32 ? 0x0002 : 0) | ($this->gfx ? 0x0100 : 0) | 0x0020;
    }

    private function mcsConnect(int $protocol): void
    {
        $o     = $this->o;
        $name  = substr(str_pad(Text::toUtf16(substr($o['clientName'], 0, 15)), 32, "\0"), 0, 32);
        $color = ['15' => 0x0F, '16' => 0x10, '24' => 0x18, '32' => 0x18][(string)$o['bpp']];
        $core  = pack('VvvvvVV', 0x00080004, $this->width, $this->height, 0xCA01, 0xAA03, $o['kbd'], 2600) . $name
               . pack('VVV', 4, 0, 12) . str_repeat("\0", 64)
               . pack('vvVvvv', 0xCA01, 1, 0, $color, 0x000F, $this->earlyFlags())
               . str_repeat("\0", 64) . pack('CCV', 6, 0, $protocol);   // connection type LAN: Windows gives about 20 % more frames
        $net   = pack('V', count($o['channels']));
        foreach ($o['channels'] as $i => [$chName, $opt]) {
            $net .= str_pad($chName, 8, "\0") . pack('V', $opt);
        }
        $blocks = pack('vv', 0xC001, strlen($core) + 4) . $core
                . pack('vvVV', 0xC002, 12, 0, 0)
                . pack('vv', 0xC003, strlen($net) + 4) . $net;
        if (count($o['monitors']) > 1) { // TS_UD_CS_MONITOR: each monitor is a separate screen in the session
            $mon = pack('VV', 0, count($o['monitors']));
            foreach ($o['monitors'] as [$x, $y, $w, $h, $primary]) {
                $mon .= pack('llllV', $x, $y, $x + $w - 1, $y + $h - 1, $primary ? 1 : 0);
            }
            $blocks .= pack('vv', 0xC005, strlen($mon) + 4) . $mon;
        }
        $len    = strlen($blocks) + 14;
        $gcc    = "\x00\x05\x00\x14\x7c\x00\x01" . pack('n', 0x8000 | $len) . "\x00\x08\x00\x10\x00\x01\xc0\x00Duca"
                . pack('n', 0x8000 | strlen($blocks)) . $blocks;
        $params = static fn(int ...$v) => Der::seq(...array_map([Der::class, 'int'], $v));
        $ci     = Der::tlv(0x7f65, Der::octet("\x01") . Der::octet("\x01") . Der::tlv(0x01, "\xff")
                . $params(34, 2, 0, 1, 0, 1, 65535, 2) . $params(1, 1, 1, 1, 0, 1, 1056, 2)
                . $params(65535, 64535, 65535, 1, 0, 1, 65535, 2) . Der::octet($gcc));
        $this->t->send(self::tpkt("\x02\xf0\x80" . $ci));
        $this->trace?->log('mcs connect', ['width' => $this->width, 'height' => $this->height, 'colorDepth' => $o['bpp'],
            'earlyCapabilityFlags' => sprintf('0x%x', $this->earlyFlags()),
            'channels' => array_map(static fn($c) => $c[0] . sprintf(' 0x%08x', $c[1]), $o['channels']), 'monitors' => count($o['monitors'])]);

        // Connect-Response: find the server data blocks after the "McDn" GCC header
        $resp = $this->t->recvPdu();
        $p    = strpos($resp, 'McDn');
        if ($p === false) {
            throw new ProtocolException('MCS connect response rejected', 'protocol');
        }
        $r = new Reader(substr($resp, $p + 4));
        $r->perLength();
        while ($r->left() >= 4) {
            $type = $r->u16();
            $b    = new Reader($r->bytes($r->u16() - 4));
            $this->trace?->log('server data', ['block' => [0x0C01 => 'core', 0x0C02 => 'security', 0x0C03 => 'network', 0x0C04 => 'messageChannel',
                0x0C08 => 'multitransport'][$type] ?? sprintf('0x%04x', $type), 'data' => Trace::hex($b->data)]);
            if ($type === 0x0C02 && $b->u32() !== 0) {
                throw new ProtocolException('Server requires legacy RDP encryption', 'rdp_security');
            }
            if ($type === 0x0C03) {
                $this->ioChannel = $b->u16();
                $n               = $b->u16();
                foreach ($o['channels'] as $i => [$chName]) {
                    if ($i < $n) {
                        $id                       = $b->u16();
                        $this->channelIds[$chName] = $id;
                        $this->channelOpts[$chName] = $o['channels'][$i][1];
                        foreach ($this->handlers as $h) {
                            if ($h->name() === $chName) {
                                $this->handlers[$id] = $h;
                            }
                        }
                    }
                }
            }
        }
    }

    private function sendClientInfo(): void
    {
        $o     = $this->o;
        $u16z  = static fn(string $s) => Text::toUtf16($s) . "\0\0";
        $flags = 0x1 | 0x2 | 0x8 | 0x10 | 0x20 | 0x40 | 0x100 | 0x20000 | ($o['audio'] ? 0 : 0x80000) | (empty($o['app']) ? 0 : 0x8000)   // INFO_RAIL: RemoteApp
               | (empty($o['mic']) ? 0 : 0x00200000)                                   // INFO_AUDIOCAPTURE: the microphone is offered
               | (empty($o['compression']) ? 0 : 0x80 | ($o['compression'] << 9));   // INFO_COMPRESSION + highest type accepted
        $this->bulk = empty($o['compression']) ? null : new Bulk();
        $dom   = $u16z($o['domain']);
        $usr   = $u16z($o['user']);
        $pwd   = $u16z($o['password']);
        $info  = pack('VVvvvvv', 0, $flags, strlen($dom) - 2, strlen($usr) - 2, strlen($pwd) - 2, 0, 0)
               . $dom . $usr . $pwd . "\0\0\0\0";
        $addr  = $u16z('0.0.0.0');
        $dir   = $u16z('C:\\Windows\\System32\\mstscax.dll');
        $tz    = pack('l', $o['tzBias']) . str_repeat("\0", 64 + 16 + 4 + 64 + 16 + 4);
        $info .= pack('vv', 2, strlen($addr)) . $addr . pack('v', strlen($dir)) . $dir . $tz . pack('VVv', 0, $o['perf'], 0);
        $this->mcsSend($this->ioChannel, pack('vv', 0x0040, 0) . $info);
    }

    /** TS_ORDER_CAPABILITYSET orderSupport: DstBlt, PatBlt (and OpaqueRect), ScrBlt, MemBlt, FastIndex, FastGlyph, GlyphIndex. */
    private static function orderSupport(bool $on): string
    {
        $s = str_repeat("\0", 32);
        foreach ($on ? [0x00, 0x01, 0x02, 0x03, 0x13, 0x18, 0x1B] : [] as $i) {
            $s[$i] = "\1";
        }
        return $s;
    }

    private function confirmActive(): void
    {
        $o   = $this->o;
        $srv = $this->railCaps[24] ?? '';       // RemoteApp: icon caches never above the server's (3 and 12 when it gave none)
        [$iconCaches, $iconEntries] = strlen($srv) >= 7 ? [ord($srv[4]), unpack('v', $srv, 5)[1]] : [3, 12];
        $cap = static fn(int $type, string $d) => pack('vv', $type, strlen($d) + 4) . $d;
        $caps = [
            $cap(1, pack('vvvvvvvvvCC', 1, 3, 0x200, 0, 0, 0x1 | 0x4 | 0x8 | 0x10 | 0x400, 0, 0, 0, 1, 1)),       // general
            $cap(2, pack('vvvvvvvvvCCvv', $o['bpp'], 1, 1, 1, $this->width, $this->height, 0, 1, 1, 0, 0x08, 1, 0)), // bitmap
            $cap(3, str_repeat("\0", 16) . pack('Vvvvvvv', 0, 1, 20, 0, 1, 0, 0x0A) . self::orderSupport($o['orders'])
                    . pack('vvVVvvvv', 0, 0, 0, 230400, 0, 0, 0, 0)),                                           // orders
            $o['orders']
                ? $cap(19, pack('vCC', 0, 0, 3) . pack('VVVVV', 600, 600, 1024, 0, 0) . str_repeat("\0", 12))     // bitmap cache rev. 2: 3 cells
                : $cap(4, str_repeat("\0", 36)),                                                                 // bitmap cache off
            $cap(8, pack('vvv', 1, 25, 25)),                                                                     // pointer
            $cap(13, pack('vvVVVV', 0x1 | 0x4 | 0x8 | 0x10 | 0x20 | 0x100, 0, $o['kbd'], 4, 0, 12) . str_repeat("\0", 64)), // input
            $cap(15, pack('V', 0)),                                                                              // brush
            $cap(16, $o['orders']                                                                                // glyph cache: 10 caches, fragments, level ENCODE
                ? pack('v*', 254, 4, 254, 4, 254, 8, 254, 8, 254, 16, 254, 32, 254, 64, 254, 128, 254, 256, 64, 2048) . pack('vvvv', 256, 256, 3, 0)
                : str_repeat("\0", 40) . pack('Vvv', 0, 0, 0)),
            $cap(17, pack('Vvv', 0, 0, 0)),                                                                      // offscreen off
            $cap(20, pack('VV', 0, 1600)),                                                                       // virtual channel
            $cap(12, pack('vv', 0, 0)),                                                                          // sound
            $cap(9, pack('vv', 0, 0)),                                                                           // share
            $cap(14, pack('vv', 1, 0)),                                                                          // font
            $cap(5, pack('vvvv', 0, 0, 2, 2)),                                                                   // control
            $cap(7, pack('vvvv', 0, 0, 0, 0)),                                                                   // activation
            $cap(10, pack('vv', 6, 0)),                                                                          // color cache
            $cap(26, pack('V', 0x01000000)),                                                                     // multifragment
            $cap(27, pack('v', 1)),                                                                              // large pointer
            ...(empty($o['app']) ? [] : [
                $cap(23, pack('V', 0x01)),                                                                       // RemoteApp: supported
                $cap(24, pack('VCv', 2, min(3, $iconCaches), min(12, $iconEntries))),                           // window list: extended, icon caches
            ]),
        ];
        $body = implode('', $caps);
        $src  = "MSTSC\0";
        $pdu  = pack('Vvvv', $this->shareId, self::IO_SERVER, strlen($src), strlen($body) + 4) . $src
              . pack('vv', count($caps), 0) . $body;
        $this->mcsSend($this->ioChannel, pack('vvv', strlen($pdu) + 6, 0x13, $this->userId + 1001) . $pdu);
        $this->shareData(0x1F, pack('vv', 1, self::IO_SERVER));    // synchronize
        $this->shareData(0x14, pack('vvV', 4, 0, 0));              // control: cooperate
        $this->shareData(0x14, pack('vvV', 1, 0, 0));              // control: request control
        $this->shareData(0x27, pack('vvvv', 0, 0, 3, 0x32));       // font list
    }

    // ------------------------------------------------------------------ receive path

    /** Processes all buffered PDUs; returns false if the server closed the connection. */
    public function poll(): bool
    {
        $alive = $this->t->drain();
        foreach ($this->t->pdus() as $pdu) {
            $this->handle($pdu);
        }
        return $alive;
    }

    private function handle(string $pdu): void
    {
        if (ord($pdu[0]) !== 3) {
            $this->fastPath($pdu);
            return;
        }
        $r = new Reader($pdu);
        $r->skip(4);
        $r->skip($r->u8());                 // X.224 data header
        $mcs = $r->u8() >> 2;
        if ($mcs !== 26) {
            $this->trace?->log('mcs', ['pdu' => $mcs === 8 ? 'disconnectProviderUltimatum' : $mcs]);
        }
        if ($mcs === 8) {
            throw new ProtocolException('Disconnected by server', $this->errorInfo ? 'error_info' : 'disconnected');
        }
        if ($mcs !== 26) {
            return;
        }
        $r->skip(2);
        $channel = $r->u16be();
        $r->skip(1);
        $data = $r->bytes($r->perLength());
        if ($channel === $this->ioChannel) {
            $this->ioPdu($data);
        } elseif (isset($this->handlers[$channel])) {
            $this->channelPdu($channel, $data);
        }
    }

    private function ioPdu(string $data): void
    {
        $r = new Reader($data);
        if (!$this->licensed && ($r->u16() & 0x0080)) {
            $r->skip(2);
            $this->license($r);
            return;
        }
        $r->pos = 0;
        while ($r->left() >= 6) {
            $len = $r->u16();
            if ($len === 0x8000) {   // flow control PDU
                $r->skip(min(6, $r->left()));
                continue;
            }
            $type = $r->u16() & 0x0f;
            $r->skip(2);
            $body = new Reader($r->bytes(max(0, $len - 6)));
            if ($type !== 7) {
                $this->trace?->log('share control', ['type' => [1 => 'demandActive', 3 => 'confirmActive', 6 => 'deactivateAll', 0xA => 'serverRedirection'][$type] ?? $type,
                    'size' => $len]);
            }
            match ($type) {
                1       => $this->demandActive($body),
                6       => $this->active = false,
                7       => $this->dataPdu($body),
                0xA     => throw new ProtocolException('Server redirection not supported', 'redirect'),
                default => null,
            };
        }
    }

    private function license(Reader $r): void
    {
        $type = $r->u8();
        $r->skip(3);
        $this->lic ??= new Licensing($this->o['user'], $this->o['clientName']);
        $this->trace?->log('license', ['msg' => [0x01 => 'licenseRequest', 0x02 => 'platformChallenge', 0x03 => 'newLicense', 0x04 => 'upgradeLicense',
            0xFF => 'errorAlert'][$type] ?? sprintf('0x%02x', $type), 'error' => $type === 0xFF ? Trace::hex(substr($r->data, $r->pos, 8)) : null]);
        [$reply, $done] = $this->lic->handle($type, $r);
        if ($reply !== null) {
            $this->mcsSend($this->ioChannel, pack('vv', 0x0080, 0) . $reply);
        }
        $this->licensed = $done;
    }

    private function demandActive(Reader $r): void
    {
        $this->licensed = true;
        $this->shareId  = $r->u32();
        $srcLen         = $r->u16();
        $r->skip(2 + $srcLen);
        $n = $r->u16();
        $r->skip(2);
        $bpp = $this->o['bpp'];
        for ($i = 0; $i < $n && $r->left() >= 4; $i++) {
            $type = $r->u16();
            $c    = new Reader($r->bytes($r->u16() - 4));
            $this->trace?->log('server capability', ['type' => self::CAPS[$type] ?? $type, 'data' => Trace::hex($c->data)]);
            if ($type === 2) {
                $bpp          = $c->u16();
                $c->skip(6);
                $this->width  = $c->u16();
                $this->height = $c->u16();
            } elseif ($type === 23 || $type === 24) {
                $this->railCaps[$type] = $c->data;   // RemoteApp: the server's Remote Programs and Window List capabilities
            }
        }
        // RemoteApp asked for: without both capability sets, or with RAIL / windows not supported, the client must drop it
        if (!empty($this->o['app']) && (strlen($this->railCaps[23] ?? '') < 4 || !(unpack('V', $this->railCaps[23])[1] & 1)
            || strlen($this->railCaps[24] ?? '') < 7 || unpack('V', $this->railCaps[24])[1] === 0)) {
            throw new ProtocolException('The server does not offer RemoteApp', 'app_unsupported');
        }
        // a desktop the browser could not hold (each side up to 32766 in RDP; 8192 x 8192 pixels in all, four 4K monitors
        // are half of it): a hostile server could make the page allocate gigabytes
        if ($this->width < 1 || $this->height < 1 || $this->width > 32766 || $this->height > 32766 || $this->width * $this->height > 1 << 26) {
            throw new ProtocolException("Desktop of {$this->width} x {$this->height}", 'protocol');
        }
        $this->trace?->log('demand active', ['shareId' => sprintf('0x%x', $this->shareId), 'capabilities' => $n, 'size' => "{$this->width}x{$this->height}", 'bpp' => $bpp,
            'orders' => (bool)$this->o['orders'], 'gfx' => $this->gfx]);
        $this->confirmActive();
        $this->active = true;
        $this->emit(self::F_RESIZE, pack('vvC', $this->width, $this->height, $bpp));
    }

    /**
     * Runs the compressed fast-path updates received since the last call through the shared history. They are forwarded
     * compressed (the browser decompresses them); the gateway only keeps its history in step, for the slow-path PDUs it
     * reads itself (errors, logon). Done after the frames were sent and the browser's input read: decompressing them first
     * held every frame of a batch, and a key pressed meanwhile, 15 ms (MPPC) to 150 ms (NCRUSH).
     */
    public function catchUp(): void
    {
        foreach ($this->backlog as [$data, $comp]) {
            $this->bulk?->decompress($data, $comp);
        }
        $this->backlog = [];
    }

    private function dataPdu(Reader $r): void
    {
        $r->skip(8);
        $type2 = $r->u8();
        $comp  = $r->u8();
        $r->skip(2);
        $this->trace?->count('slow:' . (self::SLOW[$type2] ?? sprintf('0x%02x', $type2)) . ($comp & 0x20 ? ':' . self::COMPR[$comp & 0x0F] : ''), $r->left());
        if ($comp & 0xE0 && $this->bulk) {
            $this->catchUp();                 // the history in order, before this PDU is read from it
            $raw = $r->rest();
            $this->emit(self::F_ZSLOW, chr($comp) . chr($type2) . $raw);   // forwarded compressed: same history in the browser
            if ($type2 === 0x02 || $type2 === 0x1B) {
                $this->bulk->decompress($raw, $comp);
                return;
            }
            $r = new Reader($this->bulk->decompress($raw, $comp));
        }
        switch ($type2) {
            case 0x02: // update
                $kind = $r->u16();
                if ($kind === 0) {                     // orders: same layout as fast-path (count + orders)
                    $r->skip(2);
                    $count = $r->bytes(2);
                    $r->skip(2);
                    $this->emit(self::F_UPDATE, "\0" . $count . $r->rest());
                } elseif ($kind === 1 || $kind === 2) {
                    $r->pos -= 2;
                    $this->emit(self::F_UPDATE, chr($kind) . $r->rest());
                }
                break;
            case 0x1B: // pointer
                $kind = $r->u16();
                $r->skip(2);
                if ($kind === 1) {
                    $this->emit(self::F_UPDATE, chr($r->u32() === 0 ? 5 : 6));
                } else {
                    $code = [3 => 8, 6 => 9, 7 => 10, 8 => 11, 9 => 12][$kind] ?? 0;
                    if ($code) {
                        $this->emit(self::F_UPDATE, chr($code) . $r->rest());
                    }
                }
                break;
            case 0x2F: // set error info
                $this->errorInfo = $r->u32();
                $this->trace?->log('error info', ['code' => sprintf('0x%08x', $this->errorInfo)]);
                if ($this->errorInfo !== 0) {
                    $this->emit(self::F_STATUS, json_encode(['errorInfo' => $this->errorInfo]));
                }
                break;
            case 0x26: // save session info (logon)
                $this->trace?->log('save session info', ['infoType' => [0 => 'logon', 1 => 'logonLong', 2 => 'plainNotify', 3 => 'logonExtended'][$r->u32()] ?? '?']);
                $this->emit(self::F_STATUS, '{"logon":true}');
                break;
        }
    }

    private function fastPath(string $pdu): void
    {
        $this->drawn++;
        $r = new Reader($pdu);
        $r->skip(ord($pdu[1]) & 0x80 ? 3 : 2);
        while ($r->left() >= 3) {
            $h    = $r->u8();
            $code = $h & 0x0f;
            $frag = ($h >> 4) & 3;
            $comp = ($h >> 6) & 2 ? $r->u8() : 0;
            $data = $r->bytes($r->u16());
            $this->trace?->count('fast:' . (self::FAST[$code] ?? $code) . ($comp & 0x20 ? ':' . self::COMPR[$comp & 0x0F] : '') . ($frag ? ':fragment' : ''), strlen($data));
            if ($this->bulk) {            // compressed as received: the browser decompresses and reassembles
                if ($comp & 0xE0) {
                    $this->backlog[] = [$data, $comp];      // only to keep the shared history in step: done after the flush
                }
                if ($comp & 0xE0 || $code <= 2 || ($code >= 5 && $code <= 12 && $code !== 7)) {
                    $this->emit(self::F_ZUPDATE, chr($code) . chr($frag) . chr($comp) . $data);
                }
                continue;
            }
            if ($comp & 0xE0) {
                throw new ProtocolException('Compressed update not negotiated', 'protocol');
            }
            if ($frag === 2) {
                $this->fragment = $data;
                continue;
            }
            if ($frag !== 0) {
                $this->fragment .= $data;
                if (strlen($this->fragment) > self::FRAGMENT_MAX) {
                    throw new ProtocolException('Fast-path update too large', 'protocol');
                }
                if ($frag === 3) {
                    continue;
                }
                $data           = $this->fragment;
                $this->fragment = '';
            }
            if ($code <= 2 || ($code >= 5 && $code <= 12 && $code !== 7)) {
                $this->emit(self::F_UPDATE, chr($code) . $data);
            }
        }
    }

    // ------------------------------------------------------------------ virtual channels

    private function channelPdu(int $id, string $data): void
    {
        if (strlen($data) < 8) {
            throw new ProtocolException('Truncated PDU');
        }
        [1 => $total, 2 => $flags] = unpack('V2', $data);   // length (of the whole message), flags
        $chunk = substr($data, 8);
        $this->trace?->count('channel:' . $this->handlers[$id]->name(), strlen($chunk));
        if (($flags & 3) !== 3) {                 // several chunks: joined once, at the last (joining at each copied it all again)
            if ($flags & 1) {
                if ($total > self::CHANNEL_MAX) {
                    throw new ProtocolException("Channel message of $total bytes", 'protocol');
                }
                $this->chanBuf[$id] = [];
                $this->chanLen[$id] = 0;
                $this->chanTotal[$id] = $total;
            }
            $total = $this->chanTotal[$id] ?? 0;      // the size the first chunk announced (not each chunk's own)
            $this->chanBuf[$id][] = $chunk;
            $this->chanLen[$id]   = ($this->chanLen[$id] ?? 0) + strlen($chunk);
            if ($this->chanLen[$id] > $total) {
                throw new ProtocolException('Channel data longer than announced', 'protocol');
            }
            if (!($flags & 2)) {
                return;
            }
            $chunk = implode('', $this->chanBuf[$id]);
            unset($this->chanBuf[$id], $this->chanLen[$id], $this->chanTotal[$id]);
        }
        if ($this->handlers[$id] instanceof Drdynvc) {
            $this->drawn++;                           // graphics pipeline (and the other dynamic channels)
        }
        $this->handlers[$id]->onData($chunk);
    }

    public function sendChannel(string $name, string $data): void
    {
        $id = $this->channelIds[$name] ?? null;
        if ($id === null) {
            return;
        }
        $total  = strlen($data);
        $chunks = str_split($data, 1600) ?: [''];
        $last   = count($chunks) - 1;
        foreach ($chunks as $i => $c) {
            $flags = ($i === 0 ? 1 : 0) | ($i === $last ? 2 : 0) | ($this->channelOpts[$name] & 0x00200000 ? 0x10 : 0);
            $this->mcsSend($id, pack('VV', $total, $flags) . $c);
        }
    }

    // ------------------------------------------------------------------ send path

    /**
     * Raw fast-path input events (built by the browser), up to 255 per call. $gapMs > 0 marks one typed character: Windows 11
     * apps mix up or drop characters that arrive close together, so what comes next (text or not) waits that long, in order.
     * The pause is kept here because HTTP requests from the browser arrive unevenly spaced.
     */
    public function input(string $events, int $count, int $gapMs = 0): void
    {
        // a typed character's pause, or a reactivation (Deactivate All; a key release dropped there left the key down): wait in order
        if ($this->inputAt > 0 || !$this->active) {
            $this->inputQueue[] = [$events, $count, $gapMs];
            $this->sendInput();
            return;
        }
        if ($count >= 1 && $count <= 255) {
            $hdr = $count < 16 ? chr($count << 2) : "\x00";
            $ext = $count < 16 ? '' : chr($count);
            $len = 2 + strlen($ext) + strlen($events);
            if ($len < 0x7FFF) {                   // 15-bit length: longer wrapped, and the rest reached the server as PDUs
                $this->t->send($hdr . ($len < 0x80 ? chr($len) : pack('n', 0x8000 | ($len + 1))) . $ext . $events);
                $this->drawnAtInput = $this->drawn;
            }
        }
        if ($gapMs > 0) {
            $this->inputAt = microtime(true) + $gapMs / 1000;
        }
    }

    /** Sends the input that is due; returns the seconds until the rest is due, or null when nothing waits. */
    public function sendInput(): ?float
    {
        while ($this->inputQueue) {
            if (!$this->active) {
                return 0.05;                          // Deactivate All: it goes out after the next Demand Active
            }
            if ($this->drawnBefore !== null && $this->drawn > $this->drawnBefore) {
                $this->inputAt = 0.0;                 // the application has drawn the paste
            }
            $wait = $this->inputAt - microtime(true);
            if ($wait > 0) {
                return $wait;
            }
            $this->inputAt     = 0.0;
            $this->drawnBefore = null;
            $next = array_shift($this->inputQueue);
            if (!isset($next[3])) {
                $this->input($next[0], $next[1], $next[2]);
            } elseif ($this->paster !== null) {
                while (isset($this->inputQueue[0][3])) {   // text right behind it: one paste (each Ctrl+V can be lost)
                    $next[3] .= array_shift($this->inputQueue)[3];
                }
                $this->inputAt = INF;                 // the rest waits for inputDone()
                ($this->paster)($next[3]);
            } else {
                array_unshift($this->inputQueue, ...self::unicodeText($next[3]));
            }
        }
        return null;
    }

    /**
     * Text that arrived in one go from an on-screen keyboard (autocorrection, swipe, dictation): pasted through the remote
     * clipboard, in order with the rest of the input. Windows 11 Notepad, Terminal and Explorer read injected characters
     * late and mix them up whatever the pause; a paste arrives whole. Without the clipboard channel, or with $paste false
     * (the option "phone keyboard through the clipboard" off), it is typed instead. A text of the clipboard history is
     * always pasted.
     */
    public function pasteText(string $utf8, bool $paste = true): void
    {
        array_push($this->inputQueue, ...($paste ? [['', 0, 0, $utf8]] : self::unicodeText($utf8)));
        $this->sendInput();
    }

    /**
     * End of a paste (see pasteText()); $fallback: events typed instead when it could not be pasted. After a paste the next
     * input waits until the application draws (it has taken the text): Windows 11 Notepad dropped keys that arrived while
     * its spell checker was still going through the text. Windows gives no other sign; the 2 s are only a limit for when
     * nothing is drawn.
     */
    public function inputDone(array $fallback = [], bool $pasted = false): void
    {
        array_unshift($this->inputQueue, ...$fallback);
        $this->inputAt     = $pasted ? microtime(true) + 2 : 0.0;
        $this->drawnBefore = $pasted ? $this->drawn : null;
        $this->sendInput();
    }

    /** 1: more text to paste is next in the queue, 2: it waits behind other input, 0: none. */
    public function pastePending(): int
    {
        foreach ($this->inputQueue as $i => $e) {
            if (isset($e[3])) {
                return $i === 0 ? 1 : 2;
            }
        }
        return 0;
    }

    /** True once the screen changed after the last input sent: the application has dealt with it. */
    public function drawnSinceInput(): bool
    {
        return $this->drawn > $this->drawnAtInput;
    }

    /** Sends input at once, ahead of what waits behind a paste (its own Ctrl+V); dropped during a reactivation. */
    public function inject(string $events, int $count): void
    {
        if (!$this->active) {
            return;
        }
        $at = $this->inputAt;
        $this->inputAt = 0.0;
        $this->input($events, $count);
        $this->inputAt = $at;
    }

    /** A text as Unicode keyboard events, one character per entry with the typing pause (see input()); TYPE_MAX at most. */
    public static function unicodeText(string $utf8, int $gapMs = 100): array
    {
        $out = [];
        foreach (array_slice(preg_split('//u', $utf8, self::TYPE_MAX + 1, PREG_SPLIT_NO_EMPTY) ?: [], 0, self::TYPE_MAX) as $ch) {
            $ev = '';
            foreach (unpack('v*', Text::toUtf16($ch)) as $u) {
                $ev .= "\x80" . pack('v', $u) . "\x81" . pack('v', $u);
            }
            $out[] = [$ev, intdiv(strlen($ev), 3), $gapMs];
        }
        return $out;
    }

    public function suppressOutput(bool $allow): void
    {
        if ($this->active) {
            $this->shareData(0x23, $allow ? pack('Cx3vvvv', 1, 0, 0, $this->width - 1, $this->height - 1) : pack('Cx3', 0));
        }
    }

    public function refresh(): void
    {
        if ($this->active) {
            $this->shareData(0x21, pack('Cx3vvvv', 1, 0, 0, $this->width - 1, $this->height - 1));
        }
    }

    /**
     * Desktop size asked through Display Control (as Drdynvc::resize() sends it). With the graphics pipeline the server
     * answers without a reactivation, so nothing else updates the size used by Refresh Rect and Suppress Output.
     */
    public function resized(int $w, int $h): void
    {
        if ($this->gfx) {                    // the desktop of several monitors (8192 is each one's limit, applied by Drdynvc)
            $this->width  = max(200, min(32766, $w & ~1));
            $this->height = max(200, min(32766, $h));
        }
    }

    public function disconnect(): void
    {
        try {
            $this->t->send(self::tpkt("\x02\xf0\x80\x21\x80"));
        } catch (ProtocolException) {
        }
        $this->t->close();
    }
}
