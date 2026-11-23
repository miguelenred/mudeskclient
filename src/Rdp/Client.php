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
    public const F_RESET   = 14; // the connection made again (a guest joined a shared session): every decoder starts anew
    public const F_POINTER = 15; // to the guests only: u16 x, u16 y, where the owner's mouse is
    public const F_CAM     = 16; // camera: u8 1 + u16 width + u16 height + u8 pictures a second (send JPEG pictures so), or u8 0 (stop)
    public const F_DRIVE   = 17; // this computer's folder: a Device I/O Request for it (from its DeviceId on), answered by the browser

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
    /** @var array<int,true> channels whose message is being left out (larger than the memory can hold) */
    private array $chanSkip = [];
    /** @var list<Channel> the channels added, before the server gives them their ids */
    private array $added = [];
    /** @var list<array{0:string,1:int,2:int,3?:string}> input waiting behind typed text or a paste (see input(), pasteText()) */
    private array $inputQueue = [];
    private float $inputAt = 0.0;        // input waits until then: a typed character's pause, or INF while a paste is under way
    /** pastes text through the remote clipboard (Cliprdr::typeText), which then calls inputDone() */
    public ?\Closure $paster = null;
    /** @var list<string> device redirections the gateway's policy forbids (Gateway::DEVICES): their channels not offered */
    public array $denied = [];
    /** @var bool the server refused the Kerberos ticket: NTLM only from then on (its next connections too) */
    private bool $ntlmOnly = false;
    /** Standard RDP Security (Windows XP, Server 2003, or a later Windows set to it), once its keys are made */
    private ?RdpSecurity $sec = null;
    /** @var bool the server refused TLS (SSL_NOT_ALLOWED_BY_SERVER): asked again without it */
    private bool $legacyOnly = false;
    private bool $licenseEncrypt = false;      // the server asked for the client's licensing PDUs encrypted (SEC_LICENSE_ENCRYPT_CS)
    /** @var array{0:int,1:string,2:string}|null the server's encryption method, random and certificate (Server Security Data) */
    private ?array $serverSec = null;
    /** NLA made with Kerberos (else NTLM) */
    public bool $kerberos = false;
    /** @var string|null Standard RDP Security in use (no TLS: Windows XP, Server 2003): its encryption, for the audit */
    public ?string $rdpSecurity = null;
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
        $this->added[] = $ch;                    // (mapped to its id once the server gives it: ids below the count were handled before)
    }

    /**
     * Bytes a message may take now: what the memory limit leaves, over 6 (a message is copied a few times on its way: its
     * pieces, joined, read by its channel, sent on). A larger one ended the session with "Allowed memory size exhausted".
     */
    public static function memoryRoom(): int
    {
        $l = trim((string)ini_get('memory_limit'));
        $n = (int)$l * (['k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower(substr($l, -1))] ?? 1);
        return $n <= 0 ? PHP_INT_MAX : intdiv(max(0, $n - memory_get_usage()), 6);
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

    /**
     * Sends $data on an MCS channel. $secFlags: the basic security header's flags it needs (SEC_INFO_PKT, SEC_LICENSE_PKT;
     * TLS and NLA only carry it for them). With Standard RDP Security every PDU is encrypted, with its header and signature;
     * licensing PDUs only when the server asked for it.
     */
    private function mcsSend(int $channel, string $data, int $secFlags = 0): void
    {
        if ($this->sec !== null && !($secFlags & RdpSecurity::SEC_LICENSE_PKT && !$this->licenseEncrypt)) {
            $data = $this->sec->seal($secFlags, $data);
        } elseif ($secFlags !== 0 || $this->sec !== null) {
            $data = pack('vv', $secFlags, 0) . $data;
        }
        $this->t->send(self::tpkt("\x02\xf0\x80\x64" . pack('nn', $this->userId, $channel) . "\x70" . self::perLen(strlen($data)) . $data));
    }

    private function shareData(int $type2, string $payload): void
    {
        $data = pack('VCCvCCv', $this->shareId, 0, 1, strlen($payload), $type2, 0, 0) . $payload;
        $this->mcsSend($this->ioChannel, pack('vvv', strlen($data) + 6, 0x17, $this->userId + 1001) . $data);
    }

    // ------------------------------------------------------------------ connection sequence

    /** @param callable(string):void $verifyCert receives the SHA-256 certificate fingerprint; throw to abort */
    /**
     * RDP_PRECONNECTION_PDU_V2 (MS-RDPEPS 2.2.1.2): cbSize, Flags 0, Version 2, Id, cchPCB and the UTF-16 text with its
     * terminating null, which cchPCB counts (as in the specification's examples). For Hyper-V the text is the virtual
     * machine's id, with ";EnhancedMode=1" for an enhanced session.
     */
    public static function preconnection(string $pcb, int $id = 0): string
    {
        $w = $pcb === '' ? '' : Text::toUtf16($pcb) . "\0\0";
        return pack('VVVVv', 18 + strlen($w), 0, 2, $id, intdiv(strlen($w), 2)) . $w;
    }

    public function connect(callable $verifyCert): void
    {
        $o       = $this->o;
        // through a Remote Desktop Gateway ('gateway': it opens the tunnel to the computer), or straight to it
        $this->t = isset($o['gateway']) ? Transport::through(($o['gateway'])($o['host'], (int)$o['port'])) : new Transport($o['ip'], $o['port']);
        // the device redirections the gateway's policy forbids: their channels not offered (the clipboard; the drives and
        // printers, whose channel goes when both are; Rdpdr leaves out the one forbidden), as Windows' own client does
        $this->denied = $this->t->gw?->denied ?? [];
        if ($this->denied !== []) {
            $no = fn(string $d): bool => in_array($d, $this->denied, true);
            $this->o['channels'] = array_values(array_filter($this->o['channels'], static fn(array $c): bool =>
                !($c[0] === 'cliprdr' && $no('clipboard')) && !($c[0] === 'rdpdr' && $no('drive') && $no('printer'))));
            $no('clipboard') && $this->paster = null;   // (typed character by character instead)
            $this->trace?->log('gateway policy', ['denied' => $this->denied]);
        }

        if (($o['vm'] ?? '') !== '') {
            // the console of a Hyper-V virtual machine (what VMConnect does): the Preconnection PDU names the machine, then
            // TLS and NLA with the Hyper-V host's account at once, without the X.224 negotiation (the host does not answer it)
            $this->t->send(self::preconnection($o['vm']));
            $val = 2;
            $this->trace?->log('vmconnect', ['pcb' => $o['vm']]);
        } else {
            // X.224 Connection Request with RDP negotiation (TLS, or TLS+NLA; after a server refused TLS, Standard RDP Security:
            // then without the negotiation request, as Windows XP's server does not know it)
            $want   = isset($o['rdstls']) ? 4 | 1 : ($this->legacyOnly ? 0 : ($o['security'] === 'tls' ? 1 : 3));   // (RDSTLS, or TLS, as Windows)
            // a connection broker's routing token (the load balance info of the connection, or the one the broker gave when
            // it sent the client to this computer), else the user name as cookie
            $lb     = (string)($o['lb'] ?? '');
            $cookie = $lb !== '' ? (str_ends_with($lb, "\r\n") ? $lb : $lb . "\r\n") : 'Cookie: mstshash=' . preg_replace('/[^A-Za-z0-9._-]/', '', substr($o['user'], 0, 9)) . "\r\n";
            $neg    = $want === 0 ? '' : pack('CCvV', 1, 0, 8, $want);
            $x      = "\xe0\x00\x00\x00\x00\x00" . $cookie . $neg;
            if (strlen($x) > 254) {                    // (its length is one byte: a longer token made a broken request)
                throw new ProtocolException('Routing token too long', 'routing_token');
            }
            $this->t->send(self::tpkt(chr(strlen($x)) . $x));
            $r = new Reader($this->t->recvPdu());
            $r->skip(5);
            if ($r->u8() !== 0xD0) {
                throw new ProtocolException('Unexpected X.224 response', 'protocol');
            }
            $r->skip(5);
            // no negotiation in the answer (Windows XP, Server 2003 before SP1), or PROTOCOL_RDP chosen: Standard RDP Security,
            // only when the connection's security is automatic (as Windows' own client: its key pinned like a certificate)
            [$type, $val] = [0, 0];
            if ($r->left() >= 8) {
                $type = $r->u8();
                $r->skip(3);
                $val = $r->u32();
            }
            if ($type === 3 && ($val === 2 || $val === 3) && $o['security'] === 'auto' && !$this->legacyOnly) {
                $this->trace?->log('x224', ['requested' => $want, 'refused' => $val === 2 ? 'SSL_NOT_ALLOWED_BY_SERVER' : 'SSL_CERT_NOT_ON_SERVER',
                    'then' => 'Standard RDP Security']);
                // (SSL_NOT_ALLOWED_BY_SERVER, or SSL_CERT_NOT_ON_SERVER: a server without a certificate; before, that one ended
                // the session): asked again without TLS, as Windows does
                $this->t->close();
                $this->legacyOnly = true;
                $this->connect($verifyCert);
                return;
            }
            if ($type === 3) {
                throw new ProtocolException("Negotiation failure $val", [1 => 'tls_required', 2 => 'rdp_security', 5 => 'nla_required'][$val] ?? 'protocol');
            }
            if ($val === 0 && $o['security'] !== 'auto') {
                throw new ProtocolException('Server only supports legacy RDP security', 'rdp_security');
            }
            if ($val & ~$want) {                     // not asked for (RDSTLS, HYBRID_EX...): never taken on
                throw new ProtocolException("Server chose protocol $val, not offered", 'protocol');
            }
            $this->trace?->log('x224', ['requested' => $want, 'selected' => $val, 'meaning' => [0 => 'Standard RDP Security', 1 => 'TLS', 2 => 'NLA (CredSSP)', 4 => 'RDSTLS',
                8 => 'NLA extended (HYBRID_EX)'][$val] ?? '?']);
            // (RDSTLS too: the broker's password for that computer, after the broker's own NLA; before, refused as no NLA)
            if ($o['security'] === 'nla' && $val !== 2 && !($val === 4 && isset($o['rdstls']))) {
                throw new ProtocolException('Server does not offer NLA', 'nla_unavailable');
            }
        }

        if ($val !== 0) {
            try {
                $cert = $this->t->startTls();
            } catch (ProtocolException $e) {
                if ($val !== 1 || $o['security'] !== 'auto' || $this->legacyOnly) {
                    throw $e;
                }
                // no TLS this server and OpenSSL share (Windows Server 2003 offers RC4 and triple DES, which OpenSSL 3 leaves
                // out): Standard RDP Security instead, as with a server that refuses TLS
                $this->trace?->log('tls', ['failed' => $e->getMessage(), 'then' => 'Standard RDP Security']);
                $this->t->close();
                $this->legacyOnly = true;
                $this->connect($verifyCert);
                return;
            }
            $this->certFingerprint = (string)openssl_x509_fingerprint($cert, 'sha256');
            if ($this->trace) {
                $x = openssl_x509_parse($cert) ?: [];
                $this->trace->log('tls', $this->t->tlsInfo() + ['subject' => $x['subject']['CN'] ?? null, 'issuer' => $x['issuer']['CN'] ?? null,
                    'validTo' => date('c', $x['validTo_time_t'] ?? 0), 'fingerprint' => $this->certFingerprint]);
            }
            $verifyCert($this->certFingerprint);
        }
        if ($val === 4) {
            $this->rdstls($o['rdstls'] ?? throw new ProtocolException('Server chose RDSTLS, not offered', 'protocol'));
        }
        if ($val === 2) {
            try {
                $this->trace?->log('nla', ['user_form' => str_contains($o['user'], '@') ? 'upn' : ($o['domain'] === '.' ? 'local' : ($o['domain'] !== '' ? 'domain' : 'plain'))]);
                $this->serverVersion = CredSsp::authenticate($this->t, Der::subjectPublicKey($cert), $o['user'], $o['domain'], $o['password'], $o['host'],
                                                             true, !$this->ntlmOnly);
                $this->kerberos      = CredSsp::$kerberos;
                $this->trace?->log('nla ok', ['server' => $this->serverVersion ? implode('.', $this->serverVersion) : null, 'with' => $this->kerberos ? 'kerberos' : 'ntlm']);
            } catch (ProtocolException $e) {
                if ($e->key === 'kerberos' && !$this->ntlmOnly) {
                    // the server refused the Kerberos ticket: connected again, and signed in with NTLM (as Windows does)
                    $this->trace?->log('nla kerberos refused', ['error' => $e->getMessage()]);
                    $this->t->close();
                    $this->ntlmOnly = true;
                    $this->connect($verifyCert);
                    return;
                }
                throw self::nlaFailure($e);
            }
        }

        $this->graphics();
        $this->mcsConnect($val);
        if ($val === 0 && $this->serverSec === null) {
            // Standard RDP Security that encrypts nothing (no security data, or encryption method 0): the password would go in
            // the clear, and no key would be checked (a man in the middle posing as an old server, or a server set to none)
            throw new ProtocolException('Standard RDP Security without encryption', 'rdp_unencrypted');
        }
        if ($val === 0) {
            // Standard RDP Security: the server's key pinned as a certificate is (the SHA-256 of its modulus and exponent, each
            // with its length: before, "n \0 e" let another modulus and exponent give the same string, and so the same pin)
            $key = RdpSecurity::publicKey($this->serverSec[2]);
            $this->certFingerprint = RdpSecurity::pin(...$key);
            $this->rdpSecurity     = [1 => '40-bit', 2 => '128-bit', 8 => '56-bit', 0x10 => 'FIPS'][$this->serverSec[0]] ?? 'unknown';
            $this->trace?->log('rdp security', ['method' => $this->rdpSecurity,
                'level' => $this->serverSec[3], 'key_bits' => strlen($key[0]) * 8, 'fingerprint' => $this->certFingerprint]);
            $verifyCert($this->certFingerprint);
        }
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
        if ($this->serverSec !== null) {
            // Security Exchange PDU: the client random encrypted with the server's key (little-endian, 8 bytes of padding)
            [$method, $serverRandom, $serverCert] = $this->serverSec;
            [$n, $e] = RdpSecurity::publicKey($serverCert);
            $random  = random_bytes(32);
            $enc     = RdpSecurity::rsa($random, $n, $e) . str_repeat("\0", 8);
            $this->mcsSend($this->ioChannel, pack('V', strlen($enc)) . $enc, RdpSecurity::SEC_EXCHANGE_PKT);
            $this->sec = new RdpSecurity($method, $random, $serverRandom);
            $this->sec->level = $this->serverSec[3];
        }
        $this->sendClientInfo();
        $this->trace?->log('client info sent', ['perf' => sprintf('0x%x', $o['perf']), 'compression' => array_search($o['compression'] ?? 0, Bulk::LEVELS, true) ?: 'none',
            'audio' => $o['audio'], 'mic' => !empty($o['mic']), 'tzBias' => $o['tzBias']]);

        $this->activate(self::ACTIVATION_TIMEOUT);
    }

    /**
     * RDSTLS (MS-RDPBCGR 2.2.17), after a broker's redirection whose password is encrypted for the target: the server's
     * capabilities (version 1 offered), then the password credentials (the redirection's GUID and password as they came, the
     * user and domain as null-terminated UTF-16; lengths of 16 bits, little-endian), and its result.
     */
    private function rdstls(array $cred): void
    {
        [$version, $type, $data, $versions] = array_values(unpack('v4', $this->t->recv(8)));
        if ($version !== 1 || $type !== 1 || $data !== 1 || !($versions & 1)) {
            throw new ProtocolException(sprintf('RDSTLS: capabilities %d/%d/%d/%d', $version, $type, $data, $versions), 'protocol');
        }
        $str = static fn(string $s): string => pack('v', strlen($w = Text::toUtf16($s) . "\0\0")) . $w;
        $this->t->send(pack('vvv', 1, 2, 1) . pack('v', strlen($cred['guid'])) . $cred['guid'] . $str($this->o['user']) . $str($this->o['domain'])
            . pack('v', strlen($cred['password'])) . $cred['password']);
        ['v' => $version, 't' => $type, 'd' => $data, 'r' => $result] = unpack('vv/vt/vd/Vr', $this->t->recv(10));
        $this->trace?->log('rdstls', ['result' => sprintf('0x%08x', $result)]);
        if ($version !== 1 || $type !== 4 || $data !== 1) {
            throw new ProtocolException('RDSTLS: no authentication response', 'protocol');
        }
        if ($result !== 0) {
            throw new ProtocolException(sprintf('RDSTLS: result 0x%x', $result), [0x52E => 'auth_failed', 0x530 => 'account_restricted',
                0x532 => 'password_expired', 0x533 => 'account_disabled', 0x773 => 'password_expired', 0x775 => 'account_locked'][$result] ?? 'redirect_auth');
        }
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
        // (signed in with Kerberos, which says no version: a Windows of a domain, with the AES that Windows Server 2008 R2 and
        // later give; the graphics pipeline taken as with 6.3)
        $this->gfx = $mode === 'auto' ? ($v !== null ? $v[0] * 100 + $v[1] >= 603 : $this->kerberos) : $mode === true || $mode === 'on';
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
        // TS_UD_CS_SEC: with Standard RDP Security, the encryption methods taken (40, 128, 56-bit, FIPS)
        $blocks = pack('vv', 0xC001, strlen($core) + 4) . $core
                . pack('vvVV', 0xC002, 12, $protocol === 0 ? 0x1B : 0, 0)
                // TS_UD_CS_CLUSTER: redirection supported (version 5); after a broker's redirection, the session to join
                . pack('vvVV', 0xC004, 12, 0x01 | (0x04 << 2) | (!empty($o['redirectSession']) ? 0x02 : 0), (int)($o['redirectSession'] ?? 0))
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
            if ($type === 0x0C02 && ($method = $b->u32()) !== 0) {
                if ($protocol !== 0) {              // (TLS and NLA carry no encryption of their own)
                    throw new ProtocolException('Server requires legacy RDP encryption', 'rdp_security');
                }
                $level = $b->u32();
                [$randomLen, $certLen] = [$b->u32(), $b->u32()];
                if ($randomLen !== 32 || $certLen < 16 || $certLen > 16384) {
                    throw new ProtocolException('Standard RDP Security: bad server security data', 'protocol');
                }
                $this->serverSec = [$method, $b->bytes(32), $b->bytes($certLen), $level];
            }
            if ($type === 0x0C03) {
                $this->ioChannel = $b->u16();
                $n               = $b->u16();
                foreach ($o['channels'] as $i => [$chName]) {
                    if ($i < $n) {
                        $id                       = $b->u16();
                        $this->channelIds[$chName] = $id;
                        $this->channelOpts[$chName] = $o['channels'][$i][1];
                        foreach ($this->added as $h) {
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
        // the console of a Hyper-V machine: the host's account signed in with NLA; the session (enhanced, the guest's own)
        // is not given it, nor logged on with it automatically
        $vm    = ($o['vm'] ?? '') !== '';
        $flags = $vm ? $flags & ~0x8 : $flags;
        $dom   = $u16z($vm ? '' : $o['domain']);
        $usr   = $u16z($vm ? '' : $o['user']);
        $pwd   = $u16z($vm ? '' : $o['password']);
        if (isset($o['redirectCookie']) && strlen($o['redirectCookie']) >= 2) {   // after a broker's redirection: its cookie, as it gave it
            $pwd = substr($o['redirectCookie'], -2) === "\0\0" ? $o['redirectCookie'] : $o['redirectCookie'] . "\0\0";
        }
        $info  = pack('VVvvvvv', 0, $flags, strlen($dom) - 2, strlen($usr) - 2, strlen($pwd) - 2, 0, 0)
               . $dom . $usr . $pwd . "\0\0\0\0";
        $addr  = $u16z('0.0.0.0');
        $dir   = $u16z('C:\\Windows\\System32\\mstscax.dll');
        $tz    = pack('l', $o['tzBias']) . str_repeat("\0", 64 + 16 + 4 + 64 + 16 + 4);
        $info .= pack('vv', 2, strlen($addr)) . $addr . pack('v', strlen($dir)) . $dir . $tz . pack('VVv', 0, $o['perf'], 0);
        $this->mcsSend($this->ioChannel, $info, RdpSecurity::SEC_INFO_PKT);
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
        if ($this->sec !== null) {
            // Standard RDP Security: every PDU with its security header, decrypted (in order: RC4 is one stream) when encrypted
            [$flags, $data] = $this->sec->open($data);
            if ($channel === $this->ioChannel && $flags & RdpSecurity::SEC_LICENSE_PKT) {
                $this->licenseEncrypt = $this->licenseEncrypt || (bool)($flags & RdpSecurity::SEC_LICENSE_ENCRYPT);
                $this->license(new Reader($data));
                return;
            }
            if ($channel === $this->ioChannel && $flags & RdpSecurity::SEC_REDIRECTION_PKT) {
                $this->redirect(new Reader("\0\0" . $data));   // (Standard Security's: no padding before the packet)
                return;
            }
        }
        if ($channel === $this->ioChannel) {
            $this->ioPdu($data);
        } elseif (isset($this->handlers[$channel])) {
            $this->channelPdu($channel, $data);
        }
    }

    private function ioPdu(string $data): void
    {
        $r = new Reader($data);
        // a licensing PDU (SEC_LICENSE_PKT, then a known message type and its size), not a share-control PDU of 128 bytes
        if ($this->sec === null && !$this->licensed && ($r->u16() & 0x0080) && strlen($data) >= 8 && in_array(ord($data[4]), [1, 2, 3, 4, 0xFF], true)
            && unpack('v', $data, 6)[1] === strlen($data) - 4) {
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
                0xA     => $this->redirect($body),
                default => null,
            };
        }
    }

    /** The Server Redirection PDU (enhanced security): the session is made again elsewhere, unless it only informs. */
    private function redirect(Reader $body): void
    {
        $info = self::redirection(substr($body->data, $body->pos));
        $this->trace?->log('server redirection', ['flags' => sprintf('0x%x', $info['flags']), 'session' => $info['session'],
            'to' => implode(' ', self::redirectTargets($info, false)) ?: '(the same computer)',
            'routing_token' => $info['lb'] !== null, 'user' => $info['user'] !== null, 'cookie' => $info['cookie'] !== null]);
        if (!($info['flags'] & 0x80)) {                   // (LB_NOREDIRECT: said to the client, not a redirection)
            throw new Redirection($info);
        }
    }

    /**
     * RDP_SERVER_REDIRECTION_PACKET after the 2 bytes of padding (MS-RDPBCGR 2.2.13.1): the session id, the flags, and each
     * field its flag announces, in this order, as [u32 length][bytes]: target address, load balance info (the routing token
     * to send), user, domain, password (a cookie for the target), target FQDN, NetBIOS name, TSV URL, redirection GUID,
     * target certificate, target addresses.
     */
    public static function redirection(string $data): array
    {
        $r = new Reader($data);
        $r->skip(2);                                      // pad2Octets
        if ($r->u16() !== 0x0400) {                       // SEC_REDIRECTION_PKT
            throw new ProtocolException('Invalid redirection packet', 'protocol');
        }
        $r->skip(2);                                      // its length
        $info  = ['session' => $r->u32(), 'flags' => $flags = $r->u32(), 'address' => null, 'addresses' => [], 'fqdn' => null, 'netbios' => null,
                  'lb' => null, 'user' => null, 'domain' => null, 'cookie' => null, 'cert' => null, 'guid' => null, 'pk' => null];
        $field = static fn() => $r->bytes(min($r->u32(), 65536));
        // (UTF-16 up to its first null; before, a pattern cut the trailing ones first: on a long run of zeros it gave up, and
        // the session ended with an internal error, or took minutes of CPU without the PCRE JIT)
        $text  = static fn(string $b): string => Text::fromUtf16(strlen($b) % 2 ? substr($b, 0, -1) : $b);
        foreach ([0x1 => 'address', 0x2 => 'lb', 0x4 => 'user', 0x8 => 'domain', 0x10 => 'cookie', 0x100 => 'fqdn', 0x200 => 'netbios',
                  0x1000 => null, 0x8000 => 'guid', 0x10000 => 'cert', 0x800 => 'addresses'] as $bit => $k) {
            if (!($flags & $bit)) {
                continue;
            }
            $v = $field();
            if ($k === 'lb' || $k === 'cookie' || $k === 'guid') {
                $info[$k] = $v;                           // (bytes: the routing token, the password cookie, the redirection GUID)
            } elseif ($k === 'cert') {
                $info['cert'] = self::targetCertificate($text($v));
            } elseif ($k === 'addresses') {
                $a = new Reader($v);
                for ($n = $a->u32(), $i = 0; $i < $n && $i < 32 && $a->left() >= 4; $i++) {
                    $info['addresses'][] = $text($a->bytes(min($a->u32(), $a->left())));
                }
            } elseif ($k !== null) {
                $info[$k] = $text($v);
            }
        }
        if ($flags & 0x4000) {                            // LB_PASSWORD_IS_PK_ENCRYPTED: for the target only, through RDSTLS
            [$info['pk'], $info['cookie']] = [$info['cookie'], null];
        }
        if (strlen((string)$info['user']) > 512 || strlen((string)$info['domain']) > 512) {   // (more than the Client Info can carry)
            throw new ProtocolException('Invalid redirection packet', 'protocol');
        }
        return $info;
    }

    /**
     * The SHA-256 fingerprint of the target's certificate the broker vouches for (TargetCertificate: a Target Certificate
     * Container in base64, MS-RDPBCGR 2.2.13.1.2: elements of [type, encoding, size, data], the certificate being the one of
     * type ELEMENT_TYPE_CERTIFICATE (32) in DER (1)); null when there is none.
     */
    public static function targetCertificate(string $base64): ?string
    {
        $c = base64_decode((string)preg_replace('~\s+~', '', $base64), true);
        for ($o = 0; is_string($c) && strlen($c) - $o >= 12;) {
            ['t' => $t, 'e' => $e, 'n' => $n] = unpack('Vt/Ve/Vn', $c, $o);
            if ($n > strlen($c) - $o - 12) {
                return null;
            }
            if ($t === 32 && $e === 1 && $n > 0) {
                return hash('sha256', substr($c, $o + 12, $n));   // (as openssl_x509_fingerprint gives it: of the DER)
            }
            $o += 12 + $n;
        }
        return null;
    }

    /**
     * The password a broker gives in its redirection, when it is a password and not an opaque cookie (MS-RDPBCGR 2.2.13.1: not
     * encrypted, Unicode text with its terminating null): printable text only, or null.
     */
    public static function cookiePassword(?string $cookie): ?string
    {
        if ($cookie === null || strlen($cookie) < 4 || strlen($cookie) % 2 || substr($cookie, -2) !== "\0\0") {
            return null;
        }
        $u = array_values(unpack('v*', substr($cookie, 0, -2)));
        foreach ($u as $i => $c) {
            $hi = $c >= 0xD800 && $c <= 0xDBFF;
            if ($c < 0x20 || ($c >= 0x7F && $c < 0xA0) || ($c >= 0xDC00 && $c <= 0xDFFF && !(($u[$i - 1] ?? 0) >= 0xD800 && ($u[$i - 1] ?? 0) <= 0xDBFF))
                || ($hi && !(($u[$i + 1] ?? 0) >= 0xDC00 && ($u[$i + 1] ?? 0) <= 0xDFFF)) || $c === 0xFFFE || $c === 0xFFFF) {
                return null;                              // (a control character, a broken surrogate: binary, not text)
            }
        }
        return Text::fromUtf16(substr($cookie, 0, -2));
    }

    /**
     * The computers a redirection sends the client to, best first; [] for the same one (a routing token without a target
     * address: MS-RDPBCGR 1.3.8, the token goes back to the server that sent it). By address first, as Windows' own client
     * does; by name first ($byName) through a gateway, whose policies name the computers.
     */
    public static function redirectTargets(array $info, bool $byName): array
    {
        if (($info['lb'] ?? null) !== null && !($info['flags'] & 0x1)) {
            return [];
        }
        $names = [$info['fqdn'] ?? null, $info['netbios'] ?? null];
        $addrs = [$info['address'] ?? null, ...($info['addresses'] ?? [])];
        $out   = [];
        foreach ($byName ? [...$names, ...$addrs] : [...$addrs, ...$names] as $t) {
            if (is_string($t) && ($t = trim($t)) !== '' && strlen($t) <= 253 && !preg_match('~[\x00-\x20\x7f]~', $t)
                && !in_array(strtolower($t), array_map('strtolower', $out), true)) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * The options of the connection made again after $info's redirection (to $host at $ip): the routing token only back to
     * the server that sent it (no target address), the session only when one was given, its user and domain when not empty
     * (an empty domain too with a user of the form user@domain, which carries its own). Its password, when it gives one as
     * text and the user's own is not known (signed in on the broker's logon screen, without NLA), signs in with NLA there.
     */
    public static function redirected(array $o, array $info, string $host, string $ip): array
    {
        $o['host'] = $host;
        $o['ip']   = $ip;
        $o['lb']   = $info['flags'] & 0x1 ? '' : (string)($info['lb'] ?? '');
        unset($o['redirectSession']);
        if ($info['session'] !== 0) {
            $o['redirectSession'] = $info['session'];
        }
        $upn         = str_contains((string)($info['user'] ?? ''), '@') && ($info['domain'] ?? null) !== null;
        $o['user']   = ($info['user'] ?? '') !== '' ? $info['user'] : $o['user'];
        $o['domain'] = ($info['domain'] ?? '') !== '' || $upn ? $info['domain'] : $o['domain'];
        $o['redirectCookie'] = $info['cookie'] ?? null;
        if (($o['password'] ?? '') === '' && ($p = self::cookiePassword($info['cookie'] ?? null)) !== null) {
            $o['password'] = $p;
        }
        // a password encrypted with the target's key (LB_PASSWORD_IS_PK_ENCRYPTED): given to it with RDSTLS, as it came,
        // with the redirection's GUID (MS-RDPBCGR 2.2.17)
        unset($o['rdstls']);
        if (($info['pk'] ?? null) !== null) {
            $o['rdstls'] = ['guid' => (string)($info['guid'] ?? ''), 'password' => $info['pk']];
        }
        return $o;
    }

    private function license(Reader $r): void
    {
        $type = $r->u8();
        $r->skip(3);
        $this->lic ??= new Licensing($this->o['user'], $this->o['clientName'], $this->serverSec[2] ?? '');
        $this->trace?->log('license', ['msg' => [0x01 => 'licenseRequest', 0x02 => 'platformChallenge', 0x03 => 'newLicense', 0x04 => 'upgradeLicense',
            0xFF => 'errorAlert'][$type] ?? sprintf('0x%02x', $type), 'error' => $type === 0xFF ? Trace::hex(substr($r->data, $r->pos, 8)) : null]);
        [$reply, $done] = $this->lic->handle($type, $r);
        if ($reply !== null) {
            $this->mcsSend($this->ioChannel, $reply, RdpSecurity::SEC_LICENSE_PKT);
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
        $head = ord($pdu[1]) & 0x80 ? 3 : 2;
        if (ord($pdu[0]) & 0x80) {                    // FASTPATH_OUTPUT_ENCRYPTED: decrypted even when not drawn (RC4 is one stream)
            if ($this->sec === null) {
                throw new ProtocolException('Encrypted fast-path update without Standard RDP Security', 'protocol');
            }
            $pdu  = str_repeat("\0", $head) . $this->sec->fastOpen(substr($pdu, $head), (bool)(ord($pdu[0]) & 0x40));
        } elseif ($this->sec !== null && $this->sec->level >= 2) {
            throw new ProtocolException('Standard RDP Security: a fast-path update in the clear', 'protocol');   // (see RdpSecurity::$level)
        }
        if (!$this->active) {
            return;                                   // before the activation (or between two): not a picture to draw, never piled up
        }
        $this->drawn++;
        $r = new Reader($pdu);
        $r->skip($head);
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
                unset($this->chanSkip[$id]);
                if ($total > self::memoryRoom()) {    // left out, the session goes on (a clipboard copy that does not fit is not pasted)
                    $this->chanSkip[$id] = true;
                    unset($this->chanBuf[$id], $this->chanLen[$id], $this->chanTotal[$id]);
                    $this->trace?->log('channel message left out', ['channel' => $this->handlers[$id]->name(), 'bytes' => $total, 'memory_limit' => ini_get('memory_limit')]);
                    error_log("MuDeskClient: a message of $total bytes of channel " . $this->handlers[$id]->name() . ' was left out: more than the memory limit leaves (memory_limit ' . ini_get('memory_limit') . ')');
                }
            }
            if (isset($this->chanSkip[$id])) {
                if ($flags & 2) {
                    unset($this->chanSkip[$id]);
                }
                return;
            }
            if ($flags & 1) {
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
        if ($this->sec !== null && $count >= 1) {
            $this->sealedInput($events, $count);
        } elseif ($count >= 1 && $count <= 255) {
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

    /**
     * Fast-path input with Standard RDP Security: FASTPATH_INPUT_ENCRYPTED, the signature (FIPS: its information first) and
     * the events encrypted, at most 15 a PDU so that their count stays in the header (each event's size from its code).
     */
    private function sealedInput(string $events, int $count): void
    {
        $sizes = [0 => 2, 1 => 7, 2 => 7, 3 => 1, 4 => 3, 5 => 7, 6 => 5];   // (scancode, mouse, mouse X, sync, unicode, relative mouse, QoE)
        $all   = [];
        for ($p = 0, $n = strlen($events); $p < $n && count($all) < $count;) {
            $size  = $sizes[ord($events[$p]) >> 5] ?? throw new ProtocolException('Unknown input event', 'protocol');
            $all[] = substr($events, $p, $size);
            $p    += $size;
        }
        foreach (array_chunk($all, 15) as $chunk) {
            [$sig, $c] = $this->sec->fastSeal(implode('', $chunk));
            $len = 2 + strlen($sig) + strlen($c);
            $len = $len < 0x80 ? chr($len) : pack('n', 0x8000 | ($len + 1));
            $this->t->send(chr((count($chunk) << 2) | 0x80) . $len . $sig . $c);
        }
        $this->drawnAtInput = $this->drawn;
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

    /** How many inputs wait (typed text with its pauses, pastes): a guest's typing is not taken past a few. */
    public function queued(): int
    {
        return count($this->inputQueue);
    }

    /** Releases Ctrl, Shift, Alt and Win (both sides): a guest given control left with one of them held. */
    public function releaseModifiers(): void
    {
        $ev = '';
        foreach ([[0x1D, 0], [0x1D, 2], [0x2A, 0], [0x36, 0], [0x38, 0], [0x38, 2], [0x5B, 2], [0x5C, 2]] as [$sc, $ext]) {
            $ev .= chr($ext | 1) . chr($sc);           // fast-path key event, released
        }
        $this->input($ev, 8);
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

    /** Where the last mouse event of fast-path input events leaves the pointer (u16 x, u16 y), or null with none. */
    public static function mouseAt(string $events): ?string
    {
        $at = null;
        for ($o = 0, $n = strlen($events); $o < $n;) {
            $code = ord($events[$o]) >> 5;            // key 2 bytes, mouse and extended mouse 7, synchronize 1, Unicode 3
            $len  = [2, 7, 7, 1, 3][$code] ?? 0;
            if ($len === 0 || $o + $len > $n) {
                break;
            }
            if ($code === 1 || $code === 2) {
                $at = substr($events, $o + 3, 4);
            }
            $o += $len;
        }
        return $at;
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
