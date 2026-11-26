<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

use MuDesk\Text;

/**
 * Remote Desktop Gateway (MS-TSGU, HTTP transport), in plain PHP: TLS to the gateway, the user signed in over HTTP (Kerberos
 * when the domain's KDC can be reached, else NTLM; with the channel binding of the gateway's certificate, for gateways with
 * Extended Protection), the connection upgraded to a WebSocket (Windows Server 2016 and later) or, where the gateway does not
 * take it (Windows Server 2012 and 2012 R2, a proxy that leaves the upgrade out), the older transport of two HTTPS
 * connections: RDG_OUT_DATA brings the gateway's packets as a stream, RDG_IN_DATA takes the client's as HTTP chunks. Then the
 * gateway's own messages (handshake, tunnel and its consent message, tunnel authorization and its device policy, channel to
 * the remote computer, service messages) and the RDP bytes in DATA packets. A gateway without the HTTP transport (Windows
 * Server 2008 and 2008 R2) is spoken to with RPC over HTTP instead (GatewayRpc): the same steps as remote procedure calls.
 *
 * PHP cannot put TLS on a stream of its own making, and the RDP connection has its own TLS inside the tunnel: so the RDP
 * client gets one end of a pair of sockets connected over this computer's loopback (inner(): a TCP socket, on which PHP's
 * TLS works; a pair made by stream_socket_pair() takes no TLS at all), and pump() moves what it writes there into the tunnel
 * and what comes out of the tunnel back to it. Transport calls pump() while it waits, and the session's loop on each turn.
 *
 * Tested through an open-source gateway in front of Windows 11 (a whole session; both transports; NTLM and Kerberos), and in
 * the tests against tests/fixtures/rdg_server.php, written from the specification; the RPC transport against
 * tests/fixtures/rpc_gateway.php (an independent client goes through it too); not yet against Windows Server's own.
 */
final class Gateway
{
    private const PATH = '/remoteDesktopGateway/';
    private const WAIT = 8.0;                  // TCP, TLS and each HTTP answer
    private const AUTH_WAIT = 90.0;             // the tunnel and its authorization: the gateway checks the user's policies, maybe
                                                // with a second factor approved on a phone (its RADIUS waits up to a minute)
    private const CHANNEL_WAIT = 30.0;          // the channel: the gateway reaches the computer and checks its policies first
    private const KEEPALIVE_EVERY = 60.0;       // a keep-alive after this long without sending (as Windows' own client: idle
                                                // connections are cut by the proxies and balancers in front of gateways)
    private const CHUNK = 16384;               // RDP bytes per DATA packet (its length is 16 bits)
    private const OUT_HIGH = 1 << 20;           // what waits for the gateway: over it, the client's bytes are left in the pair
    private const IN_MAX = 8 << 20;             // what waits for the client: over it, the gateway's are left in its socket
    private const QUARANTINED = 0x800759ED;     // E_PROXY_QUARANTINE_ACCESSDENIED: a tunnel authorized all the same
    private const NAP_DENIED = 0x800759DB;      // E_PROXY_NAP_ACCESSDENIED: the user not allowed by its policies (CAP)
    private const CAPS_MISMATCH = 0x800759E9;   // E_PROXY_CAPABILITYMISMATCH: it wants a capability this client lacks
    private const CAPS = 0x0C;                  // consent messages and service messages (HTTP_CAPABILITY_MESSAGING_*)
    private const MESSAGES_MAX = 8;             // service messages kept until the session shows them
    // the older transport: the handshake response, the first packet after the stream's few random bytes (10 in practice)
    private const HANDSHAKE_HEAD = "\x02\x00\x00\x00\x12\x00\x00\x00";

    use GatewayRpc;                             // (the RPC transport: Windows Server 2008 and 2008 R2)

    /** The device redirections a gateway's policy may forbid (HTTP_TUNNEL_REDIR_DISABLE_*, MS-TSGU 2.2.5.3.8), by bit. */
    public const DEVICES = [0x01 => 'drive', 0x02 => 'printer', 0x04 => 'port', 0x08 => 'clipboard', 0x10 => 'pnp'];

    // packet types (MS-TSGU 2.2.5.3.x, HTTP_PACKET_HEADER.packetType)
    private const HANDSHAKE_REQUEST = 0x01, HANDSHAKE_RESPONSE = 0x02, TUNNEL_CREATE = 0x04, TUNNEL_RESPONSE = 0x05,
        TUNNEL_AUTH = 0x06, TUNNEL_AUTH_RESPONSE = 0x07, CHANNEL_CREATE = 0x08, CHANNEL_RESPONSE = 0x09, DATA = 0x0A,
        SERVICE_MESSAGE = 0x0B, REAUTH = 0x0C, KEEPALIVE = 0x0D, CLOSE_CHANNEL = 0x10, CLOSE_CHANNEL_RESPONSE = 0x11;

    /** @var resource|null the loopback listener the pairs are made with (one per process: closing one costs 0.5 s on Windows) */
    private static $listener = null;

    /** @var resource the TLS connection to the gateway (with the older transport: its OUT channel, the gateway's packets) */
    public $sock;
    /** @var resource|null the older transport's IN channel (the client's packets, in chunks) */
    private $inSock = null;
    /** @var resource the end of the pair the RDP client uses */
    private $inner;
    /** @var resource the end of the pair this side reads and writes */
    private $outer;
    public string $certFingerprint = '';
    /** @var list<string> the device redirections its policy forbids for this connection (of DEVICES): never offered */
    public array $denied = [];
    /** @var list<string> its service messages not yet shown (the session shows them on the page) */
    public array $messages = [];
    /** 'websocket', 'http' (the older transport of two connections) or 'rpc' (RPC over HTTP); how the user signed in: 'kerberos' or 'ntlm' */
    public string $transport = 'websocket';
    public string $auth = '';
    /**
     * Tests against a real gateway only: the transport taken whatever it offers ('http': the older one of two connections,
     * as Windows Server 2012 and 2012 R2 have; 'rpc': RPC over HTTP, as 2008 and 2008 R2), else null (the best it has).
     */
    public static ?string $only = null;
    /** @var array<string,string> its cookies (a balancer in front of several gateways keeps the sign-in on one of them) */
    private array $cookies = [];
    private Kerberos|false|null $krb = null;    // its Kerberos ticket (null: not tried yet; false: none to be had, or refused)
    private string $connId = '';                // RDG-Connection-Id: the same on both channels of the older transport
    private bool $legacy = false;
    private bool $seed = false;                 // the older transport: the OUT stream's first bytes not yet skipped
    private bool $outChunked = false;           // the older transport through a proxy that chunks the OUT stream
    private int $chunkLeft = -1;                // (its current chunk: bytes left; -1 its size line next, -2 its CRLF)
    private float $lastSent = 0.0;              // when something last went into the tunnel (0: not open yet)
    private string $in = '';                    // bytes from the gateway not yet framed
    private string $msg = '';                   // a WebSocket message being joined (fragments)
    private string $pkt = '';                   // gateway packets not yet complete
    /** @var list<array{0:int,1:string}> whole gateway packets not yet handled */
    private array $queue = [];
    private string $out = '';                   // frames (chunks) waiting to be written to the gateway
    private string $toInner = '';               // RDP bytes waiting to be given to the client
    private bool $closed = false;               // the tunnel ended (closed by the gateway, its channel closed)
    private bool $eof = false;                  // the gateway's connection is gone
    private bool $closeSent = false;            // a WebSocket close frame sent
    private bool $ending = false;               // closing: the gateway's answer waited for (no channel made again meanwhile)
    private bool $shut = false;                 // the client told the end (what came before it given first)
    private float $waited = 0.0;

    /**
     * Opens the tunnel to $target:$targetPort through the gateway $gwHost (at $ip:$port), signed in as $user of $domain.
     * $verifyCert(fingerprint) may refuse the gateway's certificate (it throws), before anything of the user is sent;
     * $consent(text) shows the gateway's consent message and says whether the user accepted it (none: declined).
     * Throws ProtocolException: gateway_unreachable, gateway_auth (name or password refused), gateway_denied (the user not
     * allowed by its policies, or to that computer, or it does not answer from the gateway), gateway_consent (its message
     * not accepted), gateway_unsupported (a gateway with neither the HTTP transport nor RPC over HTTP, one that takes neither
     * NTLM nor a Kerberos this server can get, or one that wants a capability this client lacks), the KDC's answers about the
     * user (auth_failed, account_locked...), gateway (anything else).
     */
    public function __construct(string $gwHost, string $ip, int $port, string $user, string $domain, #[\SensitiveParameter] string $password,
                                string $target, int $targetPort, string $clientName, ?callable $verifyCert = null, ?callable $consent = null)
    {
        [$this->sock, $cert] = self::connect($gwHost, $ip, $port);
        $this->certFingerprint = (string)openssl_x509_fingerprint($cert, 'sha256');
        if ($verifyCert !== null) {
            $verifyCert($this->certFingerprint, (string)openssl_x509_fingerprint($cert, 'sha1'));
        }
        Transport::tlsWorked(self::tlsKey($gwHost, $port), Transport::tlsProtocol($this->sock));   // (once its certificate is the one pinned)
        // the Host header: an IPv6 address in brackets, and the port when it is not HTTPS's own
        $hostHeader   = (str_contains($gwHost, ':') ? "[$gwHost]" : $gwHost) . ($port !== 443 ? ":$port" : '');
        $this->connId = vsprintf('{%s-%s-%s-%s-%s}', self::guidParts());
        $creds        = [$gwHost, $user, $domain, $password, self::bindings($cert)];
        if (self::$only === 'rpc') {
            $this->rpcOpen($hostHeader, $gwHost, $ip, $port, $user, $domain, $password, $creds[4], $target, $targetPort, $clientName, $consent);
            $this->pair();
            return;
        }
        try {
            $status = $this->signIn('RDG_OUT_DATA', $hostHeader, self::$only !== 'http', ...$creds);
        } catch (ProtocolException $e) {
            if (!$this->noHttpTransport) {
                throw $e;
            }
            // no HTTP transport (Windows Server 2008, 2008 R2): RPC over HTTP
            $this->rpcOpen($hostHeader, $gwHost, $ip, $port, $user, $domain, $password, $creds[4], $target, $targetPort, $clientName, $consent);
            $this->pair();
            return;
        }
        if ($status === 200) {
            // the older transport: this connection brings the gateway's packets (after a few random bytes), a second one,
            // signed in too, takes the client's in chunks
            $this->legacy    = true;
            $this->transport = 'http';
            $this->seed      = true;
            [$s, $cert2] = self::connect($gwHost, $ip, $port);
            if (!hash_equals($this->certFingerprint, (string)openssl_x509_fingerprint($cert2, 'sha256'))) {
                fclose($s);
                throw new ProtocolException('Gateway: its second connection has another certificate', 'gateway');
            }
            [$outSock, $outIn, $this->sock, $this->in] = [$this->sock, $this->in, $s, ''];
            if ($this->signIn('RDG_IN_DATA', $hostHeader, false, ...$creds) !== 200) {
                throw new ProtocolException('Gateway: the IN channel was not opened', 'gateway');
            }
            // the request that carries the client's packets: on its own (a gateway may read it as one piece), and unanswered
            $this->write('RDG_IN_DATA ' . self::PATH . " HTTP/1.1\r\nHost: $hostHeader\r\nAccept: */*\r\nCache-Control: no-cache\r\nPragma: no-cache\r\n"
                . "Connection: Keep-Alive\r\nRDG-Connection-Id: {$this->connId}\r\nUser-Agent: MS-RDGateway/1.0\r\n" . $this->cookieHeader()
                . "Transfer-Encoding: chunked\r\n\r\n");
            [$this->inSock, $this->sock, $this->in] = [$s, $outSock, $outIn];
        }
        $this->open($target, $targetPort, $clientName, $consent);
        $this->pair();
    }

    /**
     * A TLS connection to the gateway (SNI with its name): [socket, its certificate]. TLS 1.2 or
     * later first; TLS 1.0 and triple DES on a new connection only to a gateway never seen with it (see Transport::tlsSeen()).
     */
    private static function connect(string $gwHost, string $ip, int $port): array
    {
        $byName = @inet_pton($gwHost) === false;
        $key    = self::tlsKey($gwHost, $port);
        $seen   = Transport::tlsSeen($key);
        for ($legacy = $seen === 'legacy'; ; $legacy = true) {
            // (its certificate pinned, as a computer's: OpenSSL's security level 1, as Transport's, for a Windows Server 2008 R2
            // or 2012 gateway with a 1024-bit certificate, which a level 2 system refused; 0 only for TLS 1.0, which needs it)
            $ctx  = stream_context_create(['socket' => ['tcp_nodelay' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false,
                'allow_self_signed' => true, 'capture_peer_cert' => true, 'SNI_enabled' => $byName, 'security_level' => $legacy ? 0 : Transport::SECURITY_LEVEL,
                'ciphers' => Transport::tlsCiphers($legacy)] + ($byName ? ['peer_name' => $gwHost] : [])]);
            $addr = str_contains($ip, ':') ? "[$ip]" : $ip;
            // the TCP connection, then the handshake (both within the wait: PHP applies a client's connection timeout to it)
            $s    = @stream_socket_client("tcp://$addr:$port", $errno, $err, self::WAIT, STREAM_CLIENT_CONNECT, $ctx);
            if ($s === false) {
                throw new ProtocolException("Gateway: $err ($errno)", 'gateway_unreachable');
            }
            stream_set_blocking($s, true);
            if (!@stream_socket_enable_crypto($s, true, Transport::tlsMethods($legacy))) {
                $why = error_get_last()['message'] ?? '';
                fclose($s);
                if (!$legacy && $seen === null) {
                    continue;
                }
                throw new ProtocolException('Gateway: TLS handshake failed: ' . $why, 'gateway_unreachable');
            }
            stream_set_blocking($s, false);           // (every wait is a select with a limit: a stalled gateway holds nothing)
            $cert = stream_context_get_params($s)['options']['ssl']['peer_certificate'] ?? null;
            if (!$cert instanceof \OpenSSLCertificate) {
                throw new ProtocolException('Gateway: no certificate', 'gateway');
            }
            return [$s, $cert];
        }
    }

    /** The gateway's key in Transport's memory of how TLS went. */
    private static function tlsKey(string $gwHost, int $port): string
    {
        return 'gw:' . strtolower($gwHost) . ':' . $port;
    }

    /** The socket the RDP client reads and writes as if it were the remote computer's. */
    public function inner()
    {
        return $this->inner;
    }

    /**
     * The pair: the client's end connected to the listener, and the connection it accepted as this side's. Only that one:
     * another process of this computer connecting to the listener meanwhile is let go.
     */
    private function pair(): void
    {
        if (!is_resource(self::$listener)) {
            self::$listener = @stream_socket_server('tcp://127.0.0.1:0', $en, $es, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                stream_context_create(['socket' => ['tcp_nodelay' => true]])) ?: null;
        }
        $ctx = stream_context_create(['socket' => ['tcp_nodelay' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false,
            'allow_self_signed' => true, 'capture_peer_cert' => true, 'security_level' => Transport::SECURITY_LEVEL, 'SNI_enabled' => false]]);   // (as Transport's)
        $in = self::$listener !== null ? @stream_socket_client('tcp://' . stream_socket_get_name(self::$listener, false), $en, $es, 2, STREAM_CLIENT_CONNECT, $ctx) : false;
        $out = false;
        for ($i = 0; $in !== false && $i < 4; $i++) {
            $c = @stream_socket_accept(self::$listener, 2, $peer);
            if ($c === false) {
                break;
            }
            if ($peer === stream_socket_get_name($in, false)) {
                $out = $c;
                break;
            }
            fclose($c);
        }
        if ($in === false || $out === false) {
            throw new ProtocolException('Gateway: no local socket pair', 'gateway');
        }
        [$this->inner, $this->outer] = [$in, $out];
        stream_set_blocking($this->outer, false);
        stream_set_blocking($this->inner, false);
    }

    // ------------------------------------------------------------------ HTTP: signed in (Kerberos, NTLM), upgraded

    /**
     * The channel binding of the gateway's certificate (RFC 5929 tls-server-end-point: the certificate hashed with its
     * signature's hash, SHA-256 for MD5 and SHA-1; MD5 of the GSS structure, as NTLM and Kerberos carry it): a gateway with
     * Extended Protection refuses a sign-in without it. An RSASSA-PSS signature names its hash in its parameters.
     */
    public static function bindings(\OpenSSLCertificate $cert): string
    {
        openssl_x509_export($cert, $pem);
        $der  = (string)base64_decode((string)preg_replace('~-----[^-]+-----|\s+~', '', $pem));
        $sig  = (string)(openssl_x509_parse($cert)['signatureTypeSN'] ?? '');
        $algo = preg_match('~sha-?(224|384|512)(?![\d/-])~i', $sig, $m) ? 'sha' . $m[1] : 'sha256';
        // RSASSA-PSS (OID 1.2.840.113549.1.1.10): its parameters, SEQUENCE { [0] AlgorithmIdentifier { the hash's OID } ... }
        $pss = strpos($der, "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x0A");
        if ($pss !== false && preg_match('~^\x30.\xA0.\x30.\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02([\x01-\x04])~s', substr($der, $pss + 11, 24), $m)) {
            $algo = [1 => 'sha256', 2 => 'sha384', 3 => 'sha512', 4 => 'sha224'][ord($m[1])];
        }
        $app  = 'tls-server-end-point:' . hash($algo, $der, true);
        return md5(pack('VVVVV', 0, 0, 0, 0, strlen($app)) . $app, true);
    }

    private function cookieHeader(): string
    {
        return $this->cookies !== [] ? 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies)) . "\r\n" : '';
    }

    /**
     * $verb (RDG_OUT_DATA, with the WebSocket upgrade; RDG_IN_DATA) signed in on this connection: with Kerberos when a ticket
     * for HTTP/<gateway> can be had (one round, the gateway's AP-REP checked when it sends it), else with NTLM in two rounds
     * (the NTLM scheme; a gateway that offers only Negotiate gets the same NTLM messages under that name, which it takes).
     * The cookies it sets go back with each request. Returns 101 (a WebSocket) or 200 (the older transport; the IN channel).
     */
    private function signIn(string $verb, string $hostHeader, bool $upgrade, string $gwHost, string $user, string $domain,
                            #[\SensitiveParameter] string $password, string $bindings): int
    {
        $key     = base64_encode(random_bytes(16));
        $request = fn(string $auth): string => "$verb " . self::PATH . " HTTP/1.1\r\nHost: $hostHeader\r\nAccept: */*\r\nCache-Control: no-cache\r\nPragma: no-cache\r\n"
            . ($upgrade ? "Connection: Upgrade\r\nUpgrade: websocket\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: $key\r\n" : "Connection: Keep-Alive\r\n")
            . "RDG-Connection-Id: {$this->connId}\r\nUser-Agent: MS-RDGateway/1.0\r\nContent-Length: 0\r\n" . $this->cookieHeader() . "Authorization: $auth\r\n\r\n";
        $done = function (int $status, array $head) use ($upgrade, $key): ?int {
            if ($status === 101 && $upgrade && strtolower(trim($head['upgrade'][0] ?? '')) === 'websocket'
                && trim($head['sec-websocket-accept'][0] ?? '') === base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true))) {
                return 101;
            }
            if ($status === 200) {                    // (OUT: a gateway without WebSockets, Windows Server 2012 R2 and older)
                if ($upgrade) {                       // (its stream chunked by a proxy; the IN channel's 200 says nothing of it)
                    $this->outChunked = stripos(implode(',', $head['transfer-encoding'] ?? []), 'chunked') !== false;
                }
                return 200;
            }
            if ($status === 101) {
                throw new ProtocolException('Gateway: HTTP 101, not a WebSocket', 'gateway');
            }
            if (in_array($status, [404, 405, 501], true)) {   // (no HTTP transport: one that speaks only RPC over HTTP)
                $this->noHttpTransport = true;
                throw new ProtocolException("Gateway: HTTP $status, no HTTP transport", 'gateway_unsupported');
            }
            return null;
        };
        // Kerberos first (as Windows' Negotiate), when this server can get a ticket for the gateway
        $krb = $this->krb ??= Kerberos::forService($user, $domain, $password, 'HTTP', $gwHost) ?? false;
        if ($krb !== false) {
            $this->write($request('Negotiate ' . base64_encode($krb->initToken($bindings))));
            [$status, $head] = $this->response(true);
            if ($status !== 401) {
                foreach ($head['www-authenticate'] ?? [] as $v) {   // its AP-REP (mutual authentication), when it sends it
                    if (preg_match('~^Negotiate\s+([A-Za-z0-9+/=]+)$~i', trim($v), $m)) {
                        $krb->accept((string)base64_decode($m[1], true));
                    }
                }
                if (($r = $done($status, $head)) !== null) {
                    $this->auth = 'kerberos';
                    return $r;
                }
                throw new ProtocolException("Gateway: HTTP $status to the sign-in", 'gateway');
            }
            $this->krb = false;                       // (401: its ticket not taken there; NTLM on the same connection, as
        }                                             // Windows' own client falls back)
        foreach (['NTLM', 'Negotiate'] as $scheme) {
            $ntlm           = new Ntlm($user, $domain, $password, 'HTTP/' . $gwHost);
            $ntlm->bindings = $bindings;
            $this->write($request("$scheme " . base64_encode($ntlm->negotiate())));
            [$status, $head] = $this->response();
            $challenge = null;
            $offered   = [];
            foreach ($head['www-authenticate'] ?? [] as $v) {
                foreach (explode(',', $v) as $one) {      // (several schemes may come in one header: "Negotiate, NTLM")
                    if (preg_match('~^(\w+)(?:\s+([A-Za-z0-9+/=]+))?$~', trim($one), $m)) {
                        $offered[] = strtolower($m[1]);
                        $tok = isset($m[2]) && strcasecmp($m[1], $scheme) === 0 ? (string)base64_decode($m[2], true) : '';
                        if (($at = strpos($tok, "NTLMSSP\0")) !== false) {
                            $challenge = substr($tok, $at);   // (raw, or inside a Negotiate token)
                        }
                    }
                }
            }
            if ($status === 401 && $challenge === null && $scheme === 'NTLM' && in_array('negotiate', $offered, true) && !in_array('ntlm', $offered, true)) {
                continue;                             // not the NTLM scheme: the same messages under Negotiate
            }
            if ($status === 401 && $challenge === null) {   // (Kerberos only, a ticket this server could not get; Basic...)
                throw new ProtocolException('Gateway: no NTLM sign-in (' . implode(', ', $offered) . ')', 'gateway_unsupported');
            }
            if ($status !== 401) {
                $done($status, $head);                // (one that speaks only RPC over HTTP said as such)
                throw new ProtocolException("Gateway: HTTP $status to the sign-in", 'gateway');
            }
            $this->write($request("$scheme " . base64_encode($ntlm->authenticate($challenge))));
            [$status, $head] = $this->response(true);
            if ($status === 401) {
                throw new ProtocolException('Gateway: the name or the password was refused', 'gateway_auth');
            }
            if (($r = $done($status, $head)) !== null) {
                $this->auth = 'ntlm';
                return $r;
            }
            throw new ProtocolException("Gateway: HTTP $status to the sign-in", 'gateway');
        }
        throw new ProtocolException('Gateway: no sign-in', 'gateway');
    }

    private static function guidParts(): array
    {
        $h = strtoupper(bin2hex(random_bytes(16)));
        return [substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12)];
    }

    /**
     * An HTTP response: [status, headers (lower-case name => values)]; its body (Content-Length or chunked) read and left
     * out, except after a 101 or, with $headersOnly, a 200 (a stream follows, or a body nobody reads).
     */
    private function response(bool $headersOnly = false): array
    {
        $end = microtime(true) + self::WAIT;
        while (($at = strpos($this->in, "\r\n\r\n")) === false) {
            if (strlen($this->in) > 65536 || microtime(true) > $end) {
                throw new ProtocolException('Gateway: no HTTP answer', 'gateway');
            }
            $this->read($end);
        }
        $lines    = explode("\r\n", substr($this->in, 0, $at));
        $this->in = substr($this->in, $at + 4);
        $status   = preg_match('~^HTTP/1\.[01] (\d{3})~', $lines[0], $m) ? (int)$m[1] : 0;
        $head     = [];
        foreach (array_slice($lines, 1) as $l) {
            [$k, $v] = explode(':', $l, 2) + [1 => ''];
            $head[strtolower(trim($k))][] = trim($v);
        }
        foreach ($head['set-cookie'] ?? [] as $c) {    // its name and value, as given (its attributes are the gateway's business)
            if (preg_match('~^([!#-\'*+.0-9A-Z^-z|\~-]{1,64})=("[!#-+\--:<-\[\]-\~]{0,1024}"|[!#-+\--:<-\[\]-\~]{0,1024})(?:;|$)~', $c, $m)
                && (isset($this->cookies[$m[1]]) || count($this->cookies) < 16)) {
                $this->cookies[$m[1]] = $m[2];
            }
        }
        if ($status === 101 || ($headersOnly && $status === 200)) {
            return [$status, $head];              // (what follows is the WebSocket's, or the tunnel's stream)
        }
        if (stripos(implode(',', $head['transfer-encoding'] ?? []), 'chunked') !== false) {
            for ($o = 0, $total = 0; ;) {             // chunks: [hex size][CRLF][data][CRLF], the last of size 0 (no trailers)
                while (($nl = strpos($this->in, "\r\n", $o)) === false || ($nl - $o) > 16) {
                    if ($nl !== false || strlen($this->in) - $o > 64 || microtime(true) > $end) {
                        throw new ProtocolException('Gateway: HTTP body cut', 'gateway');
                    }
                    $this->read($end);
                }
                $n = self::chunkSize(substr($this->in, $o, $nl - $o));
                if (($total += $n) > 1048576) {
                    throw new ProtocolException('Gateway: HTTP body too large', 'gateway');
                }
                while (strlen($this->in) < $nl + 2 + $n + 2) {
                    if (microtime(true) > $end) {
                        throw new ProtocolException('Gateway: HTTP body cut', 'gateway');
                    }
                    $this->read($end);
                }
                $o = $nl + 2 + $n + 2;
                if ($n === 0) {
                    break;
                }
            }
            $this->in = substr($this->in, $o);
            return [$status, $head];
        }
        $len = $head['content-length'][0] ?? '0';
        if (!preg_match('~^\d{1,7}$~D', $len) || ($len = (int)$len) > 1048576) {   // (a longer body was cut, and its rest read as the next answer)
            throw new ProtocolException('Gateway: HTTP body too large', 'gateway');
        }
        while (strlen($this->in) < $len) {
            if (microtime(true) > $end) {
                throw new ProtocolException('Gateway: HTTP body cut', 'gateway');
            }
            $this->read($end);
        }
        $this->in = substr($this->in, $len);
        return [$status, $head];
    }

    // ------------------------------------------------------------------ the gateway's messages

    /**
     * Handshake, tunnel (its consent message shown to the user, who accepts it or not), its authorization (its device policy
     * read) and the channel to the remote computer (MS-TSGU 3.3.5). An HRESULT with its high bit set is a refusal;
     * E_PROXY_QUARANTINE_ACCESSDENIED on the authorization is not (the tunnel is given all the same).
     */
    private function open(string $target, int $port, string $clientName, ?callable $consent): void
    {
        $this->send(self::HANDSHAKE_REQUEST, pack('CCvv', 1, 0, 0, 0));   // version 1.0, client version 0, no extended authentication
        $r = $this->expect(self::HANDSHAKE_RESPONSE, 4, self::WAIT);
        if (($e = unpack('V', $r)[1]) & 0x80000000) {
            throw new ProtocolException(sprintf('Gateway: handshake refused (0x%08x)', $e), 'gateway');
        }
        $this->send(self::TUNNEL_CREATE, pack('Vvv', self::CAPS, 0, 0));   // consent and service messages, no PAA cookie
        $r = $this->expect(self::TUNNEL_RESPONSE, 10, self::AUTH_WAIT);
        if (($e = unpack('V', $r, 2)[1]) & 0x80000000) {
            throw new ProtocolException(sprintf('Gateway: tunnel refused (0x%08x)', $e),
                [self::NAP_DENIED => 'gateway_denied', self::CAPS_MISMATCH => 'gateway_unsupported'][$e] ?? 'gateway');
        }
        if (($text = self::consentMessage($r)) !== null && ($consent === null || !$consent($text))) {
            throw new ProtocolException('Gateway: its consent message was not accepted', 'gateway_consent');
        }
        $name = Text::toUtf16($clientName) . "\0\0";
        $this->send(self::TUNNEL_AUTH, pack('vv', 0, strlen($name)) . $name);
        $r = $this->expect(self::TUNNEL_AUTH_RESPONSE, 8, self::AUTH_WAIT);
        if ((($e = unpack('V', $r)[1]) & 0x80000000) && $e !== self::QUARANTINED) {
            throw new ProtocolException(sprintf('Gateway: not authorized (0x%08x)', $e), 'gateway_denied');
        }
        if (unpack('v', $r, 4)[1] & 0x1 && strlen($r) >= 12) {         // HTTP_TUNNEL_AUTH_RESPONSE_FIELD_REDIR_FLAGS, the first
            $this->denied = self::deniedBy(unpack('V', $r, 8)[1]);       // of its optional fields: the policy of its devices
        }
        $host = Text::toUtf16($target) . "\0\0";
        $this->send(self::CHANNEL_CREATE, pack('CCvv', 1, 0, $port, 3) . pack('v', strlen($host)) . $host);   // one resource, RDP
        $r = $this->expect(self::CHANNEL_RESPONSE, 8, self::CHANNEL_WAIT);
        if (($e = unpack('V', $r)[1]) & 0x80000000) {
            throw new ProtocolException(sprintf('Gateway: the computer is not allowed or does not answer (0x%08x)', $e), 'gateway_denied');
        }
        $this->lastSent = microtime(true);
    }

    /**
     * The consent message of a tunnel response (MS-TSGU 2.2.10.20/21): after its fixed part, the optional fields its flags
     * announce, in order: tunnel id (4 bytes), capabilities (4), a statement-of-health request (a nonce, 16 bytes by the
     * specification and 20 by other clients: the size whose fields end where the packet ends is the right one, then the
     * server's certificate [u16 size][bytes]), and the consent message ([u16 size][UTF-16LE], its ending null left out).
     */
    public static function consentMessage(string $r): ?string
    {
        if (strlen($r) < 10) {
            throw new ProtocolException('Gateway: tunnel response cut', 'gateway');
        }
        $fields = unpack('v', $r, 6)[1];
        if (!($fields & 0x10)) {
            return null;
        }
        $o = 10 + ($fields & 0x1 ? 4 : 0) + ($fields & 0x2 ? 4 : 0);
        if ($fields & 0x4) {
            $at = null;
            foreach ([16, 20] as $nonce) {
                $p = $o + $nonce;
                if (strlen($r) >= $p + 4 && ($q = $p + 2 + unpack('v', $r, $p)[1]) + 2 <= strlen($r) && $q + 2 + unpack('v', $r, $q)[1] === strlen($r)) {
                    $at = $q;
                    break;
                }
            }
            // neither ends at the packet's end (padding after it): the specification's 16-byte nonce; before, the nonce was
            // read as the message's length, and its bytes shown as the terms
            if ($at === null && (strlen($r) < $o + 16 + 2 || ($at = $o + 16 + 2 + unpack('v', $r, $o + 16)[1]) + 2 > strlen($r))) {
                throw new ProtocolException('Gateway: tunnel response cut', 'gateway');
            }
            $o = $at;
        }
        if (strlen($r) < $o + 2) {
            throw new ProtocolException('Gateway: tunnel response cut', 'gateway');
        }
        return self::text(substr($r, $o + 2, unpack('v', $r, $o)[1]));
    }

    /**
     * UTF-16LE text of a gateway message, up to its first null (64 KB at most). Before, a pattern cut the ending nulls first:
     * on a long run of them it gave up, and a consent message was left out as empty.
     */
    private static function text(string $b): string
    {
        $b = substr($b, 0, 65536 & ~1);
        return Text::fromUtf16(strlen($b) % 2 ? substr($b, 0, -1) : $b);
    }

    /**
     * The device redirections forbidden by a gateway's redirection flags (as Windows' own client takes them): none when it
     * enables all, every one when it disables all, else those of each DISABLE bit.
     */
    public static function deniedBy(int $flags): array
    {
        if ($flags & 0x80000000) {                   // HTTP_TUNNEL_REDIR_ENABLE_ALL
            return [];
        }
        return array_values(array_filter(self::DEVICES, static fn(int $bit) => ($flags & 0x40000000) || ($flags & $bit), ARRAY_FILTER_USE_KEY));
    }

    /** A gateway packet: header (type, reserved, length with the header) and its body. */
    public static function packet(int $type, string $body): string
    {
        return pack('vvV', $type, 0, 8 + strlen($body)) . $body;
    }

    /** A packet as it goes to the gateway: a WebSocket frame, or with the older transport an HTTP chunk of the IN channel. */
    private function wrap(string $packet): string
    {
        if ($this->rpc) {
            return $this->rpcWrap($packet);
        }
        return $this->legacy ? sprintf("%X\r\n", strlen($packet)) . $packet . "\r\n" : self::frame($packet);
    }

    private function send(int $type, string $body): void
    {
        $this->write($this->wrap(self::packet($type, $body)));
    }

    /** A service message kept to show (a few at most); a reauthentication message let be (the gateway then ends the session). */
    private function control(int $t, string $body): bool
    {
        if ($t === self::SERVICE_MESSAGE) {
            if (strlen($body) >= 2 && count($this->messages) < self::MESSAGES_MAX) {
                $this->messages[] = self::text(substr($body, 2, unpack('v', $body)[1]));
            }
            return true;
        }
        return $t === self::REAUTH;
    }

    /** The body of the next packet, which must be of $type and at least $min bytes (keep-alives answered meanwhile). */
    private function expect(int $type, int $min, float $wait): string
    {
        $end = microtime(true) + $wait;
        for (;;) {
            $this->incoming();
            while ($this->queue !== []) {
                [$t, $body] = array_shift($this->queue);
                if ($t === self::KEEPALIVE) {
                    $this->send(self::KEEPALIVE, '');
                    continue;
                }
                if ($this->control($t, $body)) {
                    continue;
                }
                if ($t !== $type || strlen($body) < $min) {
                    throw new ProtocolException("Gateway: packet $t where $type was expected", 'gateway');
                }
                return $body;
            }
            if ($this->closed) {                       // (a close frame, the stream's end: no answer will come)
                throw new ProtocolException('Gateway: it closed the connection', 'gateway');
            }
            if (microtime(true) > $end) {
                throw new ProtocolException('Gateway: no answer', 'gateway');
            }
            $this->flush();                                    // (pongs, a close's answer: sent while waiting too)
            $this->read($end);
        }
    }

    /** What came from the gateway, into whole packets on the queue (WebSocket frames, or the older transport's stream). */
    private function incoming(): void
    {
        if ($this->rpc) {
            $this->rpcIncoming();
            return;
        }
        if ($this->legacy) {
            $this->stream();
        } else {
            $this->unframe();
        }
        if (!$this->seed) {
            $this->packets();
        }
    }

    /** The whole gateway packets that came, onto the queue (one cut at the end: cutting each one cost the square of the data). */
    private function packets(): void
    {
        $len = strlen($this->pkt);
        for ($o = 0; $len - $o >= 8;) {
            ['t' => $t, 'n' => $n] = unpack('vt/x2/Vn', $this->pkt, $o);
            if ($n < 8 || $n > 16 << 20) {
                throw new ProtocolException("Gateway: packet of $n bytes", 'gateway');
            }
            if ($len - $o < $n) {
                break;
            }
            $this->queue[] = [$t, substr($this->pkt, $o + 8, $n - 8)];
            $o += $n;
        }
        if ($o > 0) {
            $this->pkt = substr($this->pkt, $o);
        }
    }

    /**
     * The older transport's OUT stream into packets: unchunked first when a proxy chunks it (one cut at the end), its first
     * random bytes left out (up to the handshake response, within the first 128 bytes).
     */
    private function stream(): void
    {
        if (!$this->outChunked) {
            $this->pkt .= $this->in;
            $this->in   = '';
        } else {
            $in  = $this->in;
            $len = strlen($in);
            $o   = 0;
            while ($o < $len) {
                if ($this->chunkLeft > 0) {
                    $take = min($this->chunkLeft, $len - $o);
                    $this->pkt .= substr($in, $o, $take);
                    $o += $take;
                    $this->chunkLeft -= $take;
                    if ($this->chunkLeft > 0) {
                        break;
                    }
                    $this->chunkLeft = -2;
                } elseif ($this->chunkLeft === -2) {
                    if ($len - $o < 2) {
                        break;
                    }
                    $o += 2;
                    $this->chunkLeft = -1;
                } else {
                    if (($nl = strpos($in, "\r\n", $o)) === false) {
                        if ($len - $o > 64) {
                            throw new ProtocolException('Gateway: bad chunk', 'gateway');
                        }
                        break;
                    }
                    $n = self::chunkSize(substr($in, $o, min(64, $nl - $o)));
                    $o = $nl + 2;
                    if ($n === 0) {                     // (the stream's end)
                        $this->closed = true;
                        $o = $len;
                        break;
                    }
                    $this->chunkLeft = $n;
                }
            }
            $this->in = substr($in, $o);
        }
        if ($this->seed) {
            if (($at = strpos($this->pkt, self::HANDSHAKE_HEAD)) !== false && $at <= 128) {
                $this->pkt  = substr($this->pkt, $at);
                $this->seed = false;
            } elseif (strlen($this->pkt) > 128 + 8) {
                throw new ProtocolException('Gateway: no handshake on its stream', 'gateway');
            }
        }
    }

    /**
     * The size of an HTTP chunk from its line (extensions after ';' left out): 1 to 8 hex digits. Before, any text went to
     * hexdec(): 16 digits wrapped to a negative size that moved the parser backwards for ever (a worker held at full CPU),
     * and other text gave PHP notices.
     */
    private static function chunkSize(string $line): int
    {
        $hex = trim(explode(';', $line, 2)[0]);
        if (!preg_match('~^[0-9A-Fa-f]{1,8}$~D', $hex)) {
            throw new ProtocolException('Gateway: bad chunk size', 'gateway');
        }
        return (int)hexdec($hex);
    }

    // ------------------------------------------------------------------ WebSocket (RFC 6455)

    /** A frame from the client (masked, as RFC 6455 wants): binary by default. */
    public static function frame(string $data, int $op = 2): string
    {
        $n    = strlen($data);
        $head = chr(0x80 | $op) . ($n < 126 ? chr(0x80 | $n) : ($n < 65536 ? chr(0x80 | 126) . pack('n', $n) : chr(0x80 | 127) . pack('J', $n)));
        $mask = random_bytes(4);
        return $head . $mask . ($data ^ str_repeat($mask, intdiv($n, 4) + 1));
    }

    /** Whole frames from the gateway into gateway packets (one cut at the end); ping answered, close answered and taken as the end. */
    private function unframe(): void
    {
        $in  = $this->in;
        $len = strlen($in);
        for ($o = 0; $len - $o >= 2;) {
            $b0 = ord($in[$o]);
            $b1 = ord($in[$o + 1]);
            $n  = $b1 & 0x7F;
            $p  = $o + 2;
            if ($n === 126) {
                if ($len - $o < 4) {
                    break;
                }
                $n = unpack('n', $in, $o + 2)[1];
                $p = $o + 4;
            } elseif ($n === 127) {
                if ($len - $o < 10) {
                    break;
                }
                $n = unpack('J', $in, $o + 2)[1];
                $p = $o + 10;
            }
            if ($n < 0 || $n > 16 << 20) {
                throw new ProtocolException("Gateway: frame of $n bytes", 'gateway');
            }
            $mask = '';
            if ($b1 & 0x80) {                        // (a server never masks; taken anyway)
                if ($len - $p < 4) {
                    break;
                }
                $mask = substr($in, $p, 4);
                $p   += 4;
            }
            if ($len - $p < $n) {
                break;
            }
            $data = substr($in, $p, $n);
            $o    = $p + $n;
            if ($mask !== '') {
                $data = substr($data ^ str_repeat($mask, intdiv($n, 4) + 1), 0, $n);
            }
            $op = $b0 & 0x0F;
            if ($op >= 0x8 && ($n > 125 || !($b0 & 0x80))) {    // a control frame: 125 bytes at most, never in pieces (RFC 6455 5.5)
                throw new ProtocolException('Gateway: bad control frame', 'gateway');
            }
            if ($op === 0x0 || $op === 0x1 || $op === 0x2) {   // data (and its continuations): a whole message once FIN
                if ($this->msg === '' && ($b0 & 0x80)) {
                    $this->pkt .= $data;
                    continue;
                }
                $this->msg .= $data;
                if (strlen($this->msg) > 16 << 20) {
                    throw new ProtocolException('Gateway: message too large', 'gateway');
                }
                if ($b0 & 0x80) {
                    $this->pkt .= $this->msg;
                    $this->msg  = '';
                }
            } elseif ($op === 0x8) {                             // close: answered with its code, the end
                $this->closed = true;
                if (!$this->closeSent) {
                    $this->out      .= self::frame(substr($data, 0, 2), 0x8);
                    $this->closeSent = true;
                }
            } elseif ($op === 0x9 && strlen($this->out) < self::OUT_HIGH) {   // ping: pong with its data
                // (not while much waits to go: pings sent faster than the pongs left piled them up, while the tunnel's
                // handshake read without writing, until the PHP process ran out of memory; RFC 6455 lets some go unanswered)
                $this->out .= self::frame($data, 0xA);
            }
        }
        if ($o > 0) {
            $this->in = substr($in, $o);
        }
    }

    // ------------------------------------------------------------------ the RDP bytes, both ways

    /**
     * Moves what the RDP client wrote into the tunnel (DATA packets) and what came out of it back to the client, without
     * waiting: what cannot be written now waits for the next turn. With much waiting for the gateway, the client's bytes
     * stay in the pair (its writes then wait); with much waiting for the client, the gateway's stay in its socket. Once the
     * tunnel ended, what came before the end is given to the client, and then the end itself (its socket sees it closed,
     * as a remote computer's). False once all of that is done.
     */
    public function pump(): bool
    {
        if (!$this->closed) {
            while (strlen($this->out) < self::OUT_HIGH && ($d = @fread($this->outer, 65536)) !== false && $d !== '') {
                foreach (str_split($d, self::CHUNK) as $c) {
                    $this->out .= $this->wrap(self::packet(self::DATA, pack('v', strlen($c)) . $c));
                }
                $this->lastSent = microtime(true);
            }
            if ($this->lastSent > 0 && microtime(true) - $this->lastSent >= self::KEEPALIVE_EVERY) {
                $this->out     .= $this->wrap(self::packet(self::KEEPALIVE, ''));   // a quiet session: kept alive
                $this->lastSent = microtime(true);
            }
        }
        $this->flush();
        for ($got = 0; !$this->eof && $got < 4194304 && strlen($this->toInner) < self::IN_MAX && strlen($this->out) < 2 * self::OUT_HIGH; $got += strlen($d)) {
            $d = @fread($this->sock, 65536);
            if ($d === false || $d === '') {
                if (feof($this->sock)) {
                    $this->eof = true;               // (the end once what came before it is read: see below)
                }
                break;
            }
            $this->in .= $d;
        }
        $this->incoming();
        if ($this->eof) {                            // (not when that end was an OUT channel made again: its last PDU switched)
            $this->closed = true;
        }
        foreach ($this->queue as [$t, $body]) {
            if ($t === self::DATA && strlen($body) >= 2) {
                $this->toInner .= substr($body, 2, unpack('v', $body)[1]);
            } elseif ($t === self::KEEPALIVE && !$this->closed) {   // the gateway's keep-alive: answered
                $this->out .= $this->wrap(self::packet(self::KEEPALIVE, ''));
            } elseif ($t === self::CLOSE_CHANNEL && !$this->closed) {   // the gateway ends the channel: said back, then closed
                $this->out   .= $this->wrap(self::packet(self::CLOSE_CHANNEL_RESPONSE, pack('V', 0)));
                $this->closed = true;
            } else {
                $this->control($t, $body);
            }
        }
        $this->queue = [];
        if ($this->toInner !== '') {
            $n = @fwrite($this->outer, $this->toInner);
            if ($n) {
                $this->toInner = substr($this->toInner, $n);
            }
        }
        $this->flush();
        if ($this->closed && $this->toInner === '' && !$this->shut) {
            $this->shut = true;                      // all of it given: the client reads it, then the end
            @stream_socket_shutdown($this->outer, STREAM_SHUT_WR);
        }
        return !$this->shut;
    }

    /** The gateway's socket while it can bring something (to wait on with the client's). */
    public function readable(): array
    {
        // (nor while much waits for the gateway: each of its pings and keep-alives is answered there, and a gateway that sends
        // them without reading grew it until the memory ran out)
        return $this->eof || strlen($this->toInner) >= self::IN_MAX || strlen($this->out) >= 2 * self::OUT_HIGH ? [] : [$this->sock];
    }

    /** The socket frames are written to (the older transport's IN channel), while some wait for it. */
    public function writable(): array
    {
        if ($this->rpc && !$this->eof) {
            return $this->rpcWritable();
        }
        return $this->out !== '' && !$this->eof ? [$this->target()] : [];
    }

    /** Where the client's packets go: the connection, or the IN channel once it is open (not an OUT channel's sign-in). */
    private function target()
    {
        return ($this->legacy || $this->rpc) && $this->inSock !== null && !$this->rpcLeg ? $this->inSock : $this->sock;
    }

    /** Waits up to $timeout seconds for the gateway (to read from it, or to write what waits). */
    public function wait(float $timeout): void
    {
        $r = $this->readable();
        $w = $this->writable();
        $e = null;
        if ($r === [] && $w === []) {
            usleep((int)($timeout * 1e6));
            return;
        }
        @stream_select($r, $w, $e, 0, (int)($timeout * 1e6));
    }

    /**
     * The end: what the client still wrote (its Disconnect Provider Ultimatum) goes into the tunnel first, then the channel
     * closed and the WebSocket's close frame (each only if the gateway did not end them already), and the gateway's answer
     * waited for, all within a second.
     */
    public function close(): void
    {
        if (!is_resource($this->sock)) {
            return;
        }
        try {
            if (!$this->eof) {
                while (!$this->closed && is_resource($this->outer) && ($d = @fread($this->outer, 65536)) !== false && $d !== '') {
                    foreach (str_split($d, self::CHUNK) as $c) {
                        $this->out .= $this->wrap(self::packet(self::DATA, pack('v', strlen($c)) . $c));
                    }
                }
                $asked = !$this->closed && ($bye = $this->wrap(self::packet(self::CLOSE_CHANNEL, pack('V', 0)))) !== '';
                $asked && $this->out .= $bye;
                if (!$this->legacy && !$this->rpc && !$this->closeSent) {
                    $this->out      .= self::frame(pack('n', 1000), 0x8);
                    $this->closeSent = true;
                }
                for ($end = microtime(true) + 1.0; $this->out !== '' && microtime(true) < $end && $this->flush();) {
                    $this->out !== '' && $this->wait(0.05);
                }
                // its answer waited for (the channel's close answered, its pipe's end, its close frame, or its connection's
                // end), in that second, what comes meanwhile read and left: closed at once, a gateway could take the end of one
                // connection before the close the other one carries (the RPC transport's two channels, the older transport's
                // two connections), or a connection closed with something unread (reset) before what came last on it; the
                // channel's close then lost (before, it was, at times). No channel made again meanwhile.
                for ($this->ending = true; $asked && !$this->closed && !$this->eof && microtime(true) < $end;) {
                    $this->wait(min(0.05, max(0.0, $end - microtime(true))));
                    $d = @fread($this->sock, 65536);
                    if ($d !== false && $d !== '') {
                        $this->in .= $d;
                    } elseif (feof($this->sock)) {
                        $this->eof = true;
                    }
                    $this->incoming();
                    foreach ($this->queue as [$t]) {
                        $t === self::CLOSE_CHANNEL_RESPONSE && $this->closed = true;
                    }
                    $this->queue = [];
                    $this->flush();                      // (what it brought answered: the RPC transport's acknowledgements)
                }
            }
        } catch (\Throwable) {                         // (an end: nothing of it may fail the session's own)
        }
        foreach ([$this->outer, $this->inner, $this->inSock, $this->sock, $this->succ, $this->inNext[0] ?? null, $this->inPrev, $this->inDone] as $s) {
            if (is_resource($s)) {
                @fclose($s);
            }
        }
        $this->closed = $this->eof = true;
    }

    /** Reads what the gateway sent, waiting until $end at most (a second at a time: rdp.php checks the browser meanwhile). */
    private function read(float $end): void
    {
        $r = [$this->sock];
        $w = $e = null;
        $t = max(0.0, min(1.0, $end - microtime(true)));
        if (@stream_select($r, $w, $e, 0, (int)($t * 1e6)) > 0) {
            $d = @fread($this->sock, 65536);
            if (($d === false || $d === '') && feof($this->sock)) {
                throw new ProtocolException('Gateway: connection closed', 'gateway');
            }
            $this->in .= (string)$d;
        }
        if (Transport::$waiting !== null && microtime(true) - $this->waited >= 1.0) {
            $this->waited = microtime(true);
            (Transport::$waiting)();
        }
    }

    /** Writes $data to the gateway, waiting for it to be taken (up to WAIT seconds). */
    private function write(string $data): void
    {
        $this->out .= $data;
        $end        = microtime(true) + self::WAIT;
        while ($this->out !== '') {
            if (microtime(true) > $end) {
                throw new ProtocolException('Gateway: write timeout', 'gateway');
            }
            if (!$this->flush()) {
                throw new ProtocolException('Gateway: connection lost', 'gateway');
            }
            if ($this->out !== '') {
                $r = $e = null;
                $w = [$this->target()];
                @stream_select($r, $w, $e, 0, 200000);
            }
        }
    }

    /** Writes what it can of what waits for the gateway; false once its connection is lost (the tunnel then ended). */
    private function flush(): bool
    {
        if ($this->eof) {
            return false;
        }
        if ($this->rpc && !$this->rpcLeg) {             // (the RPC transport's IN channel: as long as its length allows)
            return $this->rpcFlush();
        }
        if ($this->out === '') {
            return true;
        }
        $s = $this->target();
        $n = @fwrite($s, $this->out);
        if ($n === false || ($n === 0 && feof($s))) {
            $this->eof = $this->closed = true;          // (the client is told the end as when the gateway ends it)
            return false;
        }
        if ($n > 0) {
            $this->out = substr($this->out, $n);
        }
        return true;
    }
}
