<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

use MuDesk\Text;

/**
 * The RPC transport of a Remote Desktop Gateway (MS-TSGU over MS-RPCH, RPC over HTTP v2): what Windows Server 2008 and
 * 2008 R2 speak (later ones too). Two HTTPS connections signed in with NTLM: the IN channel carries everything the client
 * sends in the body of an RPC_IN_DATA request, the OUT channel everything the gateway sends in the body of an RPC_OUT_DATA
 * response. On them, RTS PDUs make the virtual connection (CONN/A1, B1, A3, C2) and keep its flow
 * (FlowControlAck); DCE/RPC (MS-RPCE) binds to TsProxyRpcInterface with NTLM at packet integrity (each request signed), and
 * the calls TsProxyCreateTunnel (its consent message), AuthorizeTunnel (the device policy), MakeTunnelCall (service
 * messages, only when the gateway takes them), CreateChannel, SetupReceivePipe (the computer's bytes, a response PDU each)
 * and SendToServer (the client's). Each channel is made again before its length (1 GiB) is used up: the OUT channel when the
 * gateway asks, the IN channel by this client once half of it is sent (a large file copied to the remote computer, files
 * pasted, the microphone. Before, it never was: past 1 GiB the gateway read the rest as a new request, and the session ended).
 *
 * Used by Gateway, whose connection, HTTP and pumping it shares: the same packets go through wrap() (DATA, KEEPALIVE,
 * CLOSE_CHANNEL become SendToServer, an RTS ping, CloseChannel) and come back on its queue (the pipe's bytes as DATA).
 */
trait GatewayRpc
{
    private bool $rpc = false;
    private bool $rpcLeg = false;            // an HTTP sign-in leg on the OUT channel's socket (written to it, not to IN)
    private bool $noHttpTransport = false;   // the gateway answered the HTTP transport with 404/405/501
    private ?Ntlm $rpcSign = null;           // the bind's NTLM: each request signed with it
    private int $callId = 1;
    private int $maxXmit = 4088;
    private string $vcCookie = '';
    private string $outCookie = '';
    private int $rpcRecv = 0;                // RPC bytes received on this OUT channel (flow control)
    private int $rpcAvail = 65536;
    private string $tunnelCtx = '';
    private string $channelCtx = '';
    private int $pipeCall = 0;               // the call whose responses are the computer's bytes
    private int $msgCall = 0;                // the pending TsProxyMakeTunnelCall (its response: a gateway message)
    private int $msgCalls = 0;
    private int $rpcCaps = 0;                // the capabilities both sides have (consent 0x04, service messages 0x08...)
    private int $rpcState = 0;               // the virtual connection: 0 waiting for CONN/A3, 1 for CONN/C2, 2 open
    /** @var array<int,string> the stub of each call being received (its fragments joined) */
    private array $rpcParts = [];
    /** @var array<int,true> calls answered in order (their answers kept) */
    private array $rpcWaiting = [];
    /** @var array<int,array{0:?string,1:?int}> answers kept: [stub, fault status] */
    private array $rpcDone = [];
    /** @var array{0:string,1:string,2:string,3:int,4:string,5:string,6:string,7:string,8:string} [host header, gateway, ip, port, user, domain, password, bindings, session id] */
    private array $rpcHttp = [];
    private $succ = null;                    // the OUT channel being made again: its socket
    private string $succCookie = '';
    private int $recycle = 0;                // 0 none; 1 its A3 sent; 2 R2's A6 answered (B3 next); 3 R1's A6 answered (A10 next)
    private bool $rpcHeaders = false;        // the new OUT channel's HTTP answer still to read
    // an IN channel's length (its Content-Length, CONN/B1's lifetime): 1 GiB, as Windows' own client (the tests lower it)
    private static int $inLifetime = 0x40000000;
    private string $inCookie = '';           // the IN channel's cookie (its successor names it)
    private int $inSent = 0;                 // bytes written on this IN channel after its HTTP request (RTS and RPC PDUs)
    private int $inOwn = 0;                  // of what waits ($out), the first bytes taken for this IN channel (whole PDUs)
    /** @var array{0?:resource,1?:string,2?:int} the IN channel made again until IN_R1/A4 makes it the default: [socket, cookie, bytes written] */
    private array $inNext = [];
    private $inPrev = null;                  // the old IN channel while its last PDUs and IN_R1/A5 are written
    private string $inPrevOut = '';          // (those bytes)
    private $inDone = null;                  // an old IN channel all written (the gateway closes it; closed here later)

    private static function rpcUuid(string $id): string     // a GUID's wire form (its first three fields little-endian)
    {
        $h = str_replace('-', '', $id);
        return strrev((string)hex2bin(substr($h, 0, 8))) . strrev((string)hex2bin(substr($h, 8, 4))) . strrev((string)hex2bin(substr($h, 12, 4)))
            . hex2bin(substr($h, 16));
    }

    /** A connection-oriented PDU's common header (MS-RPCE 2.2.2.1, data representation little-endian, ASCII, IEEE). */
    private static function rpcHeader(int $type, int $flags, int $length, int $authLength, int $callId): string
    {
        return pack('CCCCVvvV', 5, 0, $type, $flags, 0x10, $length, $authLength, $callId);
    }

    /** An RTS PDU (MS-RPCH 2.2.4): its flags and commands [type, body]. */
    private static function rts(int $flags, array $commands): string
    {
        $body = pack('vv', $flags, count($commands));
        foreach ($commands as [$type, $value]) {
            $body .= pack('V', $type) . $value;
        }
        return self::rpcHeader(0x14, 0x03, 16 + strlen($body), 0, 0) . $body;
    }

    /** One of the channels' HTTP requests (MS-RPCH 2.1.2.1), to the gateway's own RPC endpoint (localhost:3388). */
    private function rpcRequestLine(string $verb, int $length, string $auth): string
    {
        [$host, , , , , , , , $sid] = $this->rpcHttp;
        return "$verb /rpc/rpcproxy.dll?localhost:3388 HTTP/1.1\r\nHost: $host\r\nAccept: application/rpc\r\nUser-Agent: MSRPC\r\n"
            . "Cache-Control: no-cache\r\nConnection: Keep-Alive\r\nPragma: ResourceTypeUuid=44e265dd-7daf-42cd-8560-3cdb6e7a2729, SessionId=$sid\r\n"
            . "Content-Length: $length\r\n" . $this->cookieHeader() . "Authorization: $auth\r\n\r\n";
    }

    /** A new TLS connection to the gateway, its certificate the one already checked. */
    private function rpcConnect()
    {
        [, $gwHost, $ip, $port] = $this->rpcHttp;
        [$s, $cert] = self::connect($gwHost, $ip, $port);
        if (!hash_equals($this->certFingerprint, (string)openssl_x509_fingerprint($cert, 'sha256'))) {
            fclose($s);
            throw new ProtocolException('Gateway: another connection has another certificate', 'gateway');
        }
        return $s;
    }

    /**
     * One channel signed in on $this->sock (NTLM, under the NTLM or the Negotiate scheme, with the channel binding): its
     * first request without a body, the gateway's challenge, then the request with its length and, right behind it, $body.
     */
    private function rpcSignIn(string $verb, int $length, string $body): void
    {
        [, $gwHost, , , $user, $domain, $password, $bindings] = $this->rpcHttp;
        $this->rpcLeg = true;
        try {
            foreach (['NTLM', 'Negotiate'] as $scheme) {
                $ntlm           = new Ntlm($user, $domain, $password, 'HTTP/' . $gwHost);
                $ntlm->bindings = $bindings;
                $this->write($this->rpcRequestLine($verb, 0, "$scheme " . base64_encode($ntlm->negotiate())));
                [$status, $head] = $this->response();
                [$challenge, $offered] = [null, []];
                foreach ($head['www-authenticate'] ?? [] as $v) {
                    foreach (explode(',', $v) as $one) {
                        if (preg_match('~^(\w+)(?:\s+([A-Za-z0-9+/=]+))?$~', trim($one), $m)) {
                            $offered[] = strtolower($m[1]);
                            $tok = isset($m[2]) && strcasecmp($m[1], $scheme) === 0 ? (string)base64_decode($m[2], true) : '';
                            if (($at = strpos($tok, "NTLMSSP\0")) !== false) {
                                $challenge = substr($tok, $at);
                            }
                        }
                    }
                }
                if ($status === 401 && $challenge === null && $scheme === 'NTLM' && in_array('negotiate', $offered, true) && !in_array('ntlm', $offered, true)) {
                    continue;
                }
                if ($status !== 401 || $challenge === null) {
                    throw new ProtocolException("Gateway: RPC over HTTP sign-in, HTTP $status", $status === 404 ? 'gateway_unsupported' : 'gateway');
                }
                $this->write($this->rpcRequestLine($verb, $length, "$scheme " . base64_encode($ntlm->authenticate($challenge))) . $body);
                return;
            }
        } finally {
            $this->rpcLeg = false;
        }
    }

    /**
     * The tunnel through a gateway of the RPC transport (MS-TSGU 3.1.1, 3.2): both channels, the virtual connection, the
     * bind, then as the HTTP transport's open(): tunnel (its consent message), authorization (the device policy), channel
     * to $target:$port; the pipe of its bytes asked for.
     */
    private function rpcOpen(string $hostHeader, string $gwHost, string $ip, int $port, string $user, string $domain, #[\SensitiveParameter] string $password,
                             string $bindings, string $target, int $targetPort, string $clientName, ?callable $consent): void
    {
        [$this->rpc, $this->transport, $this->auth] = [true, 'rpc', 'ntlm'];
        @fclose($this->sock);                    // (the first connection: no HTTP transport there)
        $this->rpcHttp = [$hostHeader, $gwHost, $ip, $port, $user, $domain, $password, $bindings, strtolower(vsprintf('%s-%s-%s-%s-%s', self::guidParts()))];
        [$this->vcCookie, $this->inCookie, $this->outCookie, $group] = [random_bytes(16), random_bytes(16), random_bytes(16), random_bytes(16)];
        // the IN channel: CONN/B1 the start of its body, of its length (no answer while it goes well)
        $this->sock = $this->rpcConnect();
        $this->in   = '';
        $b1 = self::rts(0, [[6, pack('V', 1)], [3, $this->vcCookie], [3, $this->inCookie], [4, pack('V', self::$inLifetime)], [5, pack('V', 300000)],
            [12, $group]]);
        $this->rpcSignIn('RPC_IN_DATA', self::$inLifetime, $b1);
        [$in, $this->inSent] = [$this->sock, strlen($b1)];
        // the OUT channel: CONN/A1, then its answer and the stream of the gateway's PDUs
        $this->sock = $this->rpcConnect();
        $this->in   = '';
        $this->rpcSignIn('RPC_OUT_DATA', 76, $this->connA1($this->outCookie));
        $this->inSock = $in;
        $this->rpcAnswer();
        $this->rpcUntil(fn(): bool => $this->rpcState === 2, self::WAIT, 'its virtual connection');
        $this->rpcBind($user, $domain, $password);

        // TsProxyCreateTunnel: version 1.1, NAP capabilities (idle timeout, consent and service messages, reauthentication),
        // then the MS-RPCE verification trailer (header signing; the interface and its transfer syntax), as Windows sends it
        $tsgu = self::rpcUuid('44e265dd-7daf-42cd-8560-3cdb6e7a2729') . pack('V', 0x00030001);
        $ndr  = self::rpcUuid('8a885d04-1ceb-11c9-9fe8-08002b104860') . pack('V', 2);
        $stub = pack('VVV', 0x5643, 0x5643, 0x00020000) . pack('vv', 0x5452, 0x5643) . pack('VV', 0x00020004, 1) . pack('vvvv', 1, 1, 0, 0)
              . pack('VVVV', 1, 1, 1, 0x1E) . "\x8A\xE3\x13\x71\x02\xF4\x36\x71" . pack('vvV', 1, 4, 1) . pack('vv', 0x4002, 0x28) . $tsgu . $ndr;
        [$r, $fault] = $this->rpcCall(1, $stub, self::AUTH_WAIT);
        $this->rpcTunnel($r, $fault, $consent);

        // TsProxyAuthorizeTunnel: this client's name (a statement of health: none)
        $name = Text::toUtf16($clientName) . "\0\0";
        $n    = intdiv(strlen($name), 2);
        $stub = $this->tunnelCtx . pack('VVVVVVVV', 0x5152, 0x5152, 0x00020000, 0, 0x00020004, $n, 0x00020008, 0) . pack('VVV', $n, 0, $n)
              . str_pad($name, (strlen($name) + 3) & ~3, "\0") . pack('V', 0);
        [$r, $fault] = $this->rpcCall(2, $stub, self::AUTH_WAIT);
        $this->rpcAuthorized($r, $fault);

        // TsProxyMakeTunnelCall, left waiting for the gateway's next message (only when it takes them: Windows Server 2008 has none)
        if ($this->rpcCaps & 0x08) {
            $this->rpcAskMessage();
        }
        // TsProxyCreateChannel: one resource name, the RDP port
        $host = Text::toUtf16($target) . "\0\0";
        $n    = intdiv(strlen($host), 2);
        $stub = $this->tunnelCtx . pack('VVVvv', 0x00020000, 1, 0, 0, 0) . pack('vv', 3, $targetPort) . pack('VV', 1, 0x00020004) . pack('VVV', $n, 0, $n)
              . str_pad($host, (strlen($host) + 3) & ~3, "\0");
        [$r, $fault] = $this->rpcCall(4, $stub, self::CHANNEL_WAIT);
        $e = strlen((string)$r) >= 28 ? unpack('V', (string)$r, strlen((string)$r) - 4)[1] : null;
        if ($fault !== null || $e === null || $e & 0x80000000) {
            throw new ProtocolException(sprintf('Gateway: the computer is not allowed or does not answer (0x%08x)', $fault ?? $e ?? 0), 'gateway_denied');
        }
        $this->channelCtx = substr((string)$r, 0, 20);
        // TsProxySetupReceivePipe: its answers are the computer's bytes, until the end (never waited for)
        $this->out     .= $this->rpcPdu(8, $this->channelCtx);
        $this->pipeCall = $this->callId;
        $this->flush();
        $this->lastSent = microtime(true);
    }

    /** CONN/A1 (and its form for a new OUT channel): version, the virtual connection's cookie, the channel's, the window. */
    private function connA1(string $channelCookie): string
    {
        return self::rts(0, [[6, pack('V', 1)], [3, $this->vcCookie], [3, $channelCookie], [0, pack('V', 65536)]]);
    }

    /** The OUT channel's HTTP answer (200, its stream after it); a 401 is the name or password refused. */
    private function rpcAnswer(): void
    {
        [$status, $head] = $this->response(true);
        if ($status !== 200) {
            throw new ProtocolException("Gateway: RPC over HTTP, HTTP $status", [401 => 'gateway_auth', 404 => 'gateway_unsupported'][$status] ?? 'gateway');
        }
        $this->outChunked = stripos(implode(',', $head['transfer-encoding'] ?? []), 'chunked') !== false;
        $this->chunkLeft  = -1;
    }

    /** Reads (and sends what waits) until $done, within $wait seconds. */
    private function rpcUntil(callable $done, float $wait, string $what): void
    {
        for ($end = microtime(true) + $wait; ;) {
            $this->flush();
            $this->incoming();
            if ($done()) {
                return;
            }
            if ($this->closed || $this->eof) {
                throw new ProtocolException("Gateway: it ended before $what", 'gateway');
            }
            if (microtime(true) > $end) {
                throw new ProtocolException("Gateway: no answer ($what)", 'gateway');
            }
            $this->read($end);
        }
    }

    /**
     * The bind to TsProxyRpcInterface 1.3 (NDR, and bind-time feature negotiation) with NTLM at packet integrity: the bind
     * with its NEGOTIATE, the bind_ack with the CHALLENGE, rpc_auth_3 with the AUTHENTICATE (not answered).
     */
    private function rpcBind(string $user, string $domain, #[\SensitiveParameter] string $password): void
    {
        $ntlm = new Ntlm($user, $domain, $password);
        $neg  = $ntlm->negotiate();
        $tsgu = self::rpcUuid('44e265dd-7daf-42cd-8560-3cdb6e7a2729') . pack('V', 0x00030001);
        $body = pack('vvV', 0x0FF8, 0x0FF8, 0) . pack('CCv', 2, 0, 0)
              . pack('vCC', 0, 1, 0) . $tsgu . self::rpcUuid('8a885d04-1ceb-11c9-9fe8-08002b104860') . pack('V', 2)
              . pack('vCC', 1, 1, 0) . $tsgu . self::rpcUuid('6cb71c2c-9812-4540-0300-000000000000') . pack('V', 1) . pack('CCCCV', 0x0A, 5, 0, 0, 0);
        $call = ++$this->callId;
        $this->out .= self::rpcHeader(0x0B, 0x17, 16 + strlen($body) + strlen($neg), strlen($neg), $call) . $body . $neg;
        $this->rpcWaiting[$call] = true;
        $this->rpcUntil(fn(): bool => isset($this->rpcDone[$call]), self::WAIT, 'the bind');
        [$ack] = $this->rpcDone[$call];
        unset($this->rpcDone[$call], $this->rpcWaiting[$call]);
        $ack = (string)$ack;
        if (strlen($ack) < 28 || ord($ack[2]) !== 0x0C) {
            throw new ProtocolException('Gateway: its RPC bind refused', 'gateway');
        }
        $authLen = unpack('v', $ack, 10)[1];
        $r = new Reader($ack);
        $r->skip(18);
        $this->maxXmit = max(256, min(4088, $r->u16()));       // (what it receives: what this client sends)
        $r->skip(4);
        $r->skip($r->u16());
        $r->skip((4 - $r->pos % 4) % 4);
        if ($r->u8() < 1) {
            throw new ProtocolException('Gateway: its RPC bind without a result', 'gateway');
        }
        $r->skip(3);
        if ($r->u16() !== 0) {                                   // (the first context, NDR: accepted)
            throw new ProtocolException('Gateway: its RPC interface not taken', 'gateway_unsupported');
        }
        $challenge = substr($ack, -$authLen);
        if ($authLen < 32 || !str_starts_with($challenge, "NTLMSSP\0")) {
            throw new ProtocolException('Gateway: its RPC bind without an NTLM challenge', 'gateway');
        }
        $auth = $ntlm->authenticate($challenge);
        $call = ++$this->callId;
        $this->out .= self::rpcHeader(0x10, 0x13, 28 + strlen($auth), strlen($auth), $call) . pack('vv', 0x0FF8, 0x0FF8) . pack('CCCCV', 0x0A, 5, 0, 0, 0) . $auth;
        $this->rpcSign = $ntlm;
        $this->flush();
    }

    /**
     * A request (MS-RPCE 2.2.2.11) for $opnum with $stub: context 0, its sec_trailer 4-aligned and the NTLM signature of
     * all of it before (header, body and trailer: header signing, as both sides support it).
     */
    private function rpcPdu(int $opnum, string $stub): string
    {
        $call = ++$this->callId;
        $pad  = (4 - strlen($stub) % 4) % 4;
        $data = self::rpcHeader(0x00, 0x03, 24 + strlen($stub) + $pad + 8 + 16, 16, $call) . pack('Vvv', strlen($stub), 0, $opnum) . $stub
              . str_repeat("\0", $pad) . pack('CCCCV', 0x0A, 5, $pad, 0, 0);
        return $data . $this->rpcSign->sign($data);
    }

    /** Sends a request and waits for its answer: [stub, fault status (null: none)]. */
    private function rpcCall(int $opnum, string $stub, float $wait): array
    {
        $this->out .= $this->rpcPdu($opnum, $stub);
        $call = $this->callId;
        $this->rpcWaiting[$call] = true;
        $this->rpcUntil(fn(): bool => isset($this->rpcDone[$call]), $wait, "the answer to call $opnum");
        $done = $this->rpcDone[$call];
        unset($this->rpcDone[$call], $this->rpcWaiting[$call]);
        return $done;
    }

    /** TsProxyMakeTunnelCall left waiting for the gateway's next message (32 at most in a session). */
    private function rpcAskMessage(): void
    {
        if ($this->msgCalls++ < 32 && !$this->closed) {
            $this->out .= $this->rpcPdu(3, $this->tunnelCtx . pack('V', 1) . pack('VVVV', 0x4752, 0x4752, 0x00020000, 1));
            $this->msgCall = $this->callId;
        }
    }

    /**
     * The tunnel's answer (MS-TSGU 2.2.9.2.1.5/6, walked as NDR): the capabilities both sides have, its consent message (shown:
     * the tunnel goes on only if the user accepts it; one only to show is kept with the service messages), its context.
     */
    private function rpcTunnel(?string $r, ?int $fault, ?callable $consent): void
    {
        $r = (string)$r;
        $e = strlen($r) >= 8 ? unpack('V', $r, strlen($r) - 4)[1] : null;
        if ($fault !== null || $e === null || $e & 0x80000000 || strlen($r) < 36) {
            throw new ProtocolException(sprintf('Gateway: tunnel refused (0x%08x)', $fault ?? $e ?? 0),
                [self::NAP_DENIED => 'gateway_denied', self::CAPS_MISMATCH => 'gateway_unsupported'][$e ?? 0] ?? 'gateway');   // (no status: 0, not a null key)
        }
        $this->tunnelCtx = substr($r, -28, 20);
        $p = new Reader($r);
        $p->skip(4);
        $packetId = $p->u32();
        $p->skip(8 + 4);                                     // (switch, its pointer, flags)
        $certLen  = $p->u32();
        $certPtr  = $p->u32();
        $p->skip(16);                                        // (the nonce: a statement of health is not sent)
        $vcPtr    = $p->u32();
        [$msgType, $present, $msgPtr] = [0, 0, 0];
        if ($packetId === 0x4350) {                          // TSG_PACKET_CAPS_RESPONSE: a message slot (Windows fills it, empty or not)
            $p->skip(4);
            $msgType = $p->u32();
            $present = $p->u32();
            $p->skip(4);
            $msgPtr  = $p->u32();
        }
        if ($certPtr !== 0) {                                // (the certificate chain for quarantine: not used)
            $p->skip(8);
            $p->skip(min($p->left(), 2 * $p->u32()));
            $p->skip((4 - $p->pos % 4) % 4);
        }
        if ($vcPtr !== 0) {
            $p->skip(8);
            $num = $p->u32();
            $p->skip(8);                                     // (versions and quarantine capabilities, aligned)
            $p->skip(4);                                     // (the array's count)
            for ($i = 0; $i < $num && $i < 8; $i++) {
                $p->skip(8);
                $this->rpcCaps = $p->u32();
            }
        }
        if ($msgPtr !== 0 && ($msgType === 1 || $msgType === 2)) {
            [$text, $mustConsent] = self::rpcString($p);
            if ($present !== 0 && $text !== '') {
                if ($msgType === 1 && $mustConsent && ($consent === null || !$consent($text))) {
                    throw new ProtocolException('Gateway: its consent message was not accepted', 'gateway_consent');
                }
                if ($msgType === 2 || !$mustConsent) {
                    $this->messages[] = $text;
                }
            }
        }
    }

    /**
     * A TSG_PACKET_STRING_MESSAGE from its fixed part on: [text, consent mandatory]; no text when its length or pointer is
     * 0 (an empty slot). Its string read as conformant-varying (count, offset, count: what Windows sends), or as conformant
     * only when what follows says so.
     */
    private static function rpcString(Reader $p): array
    {
        $p->skip(4);                                         // (display mandatory)
        $mustConsent = $p->u32() !== 0;
        $length      = $p->u32();
        if ($p->u32() === 0 || $length === 0) {
            return ['', $mustConsent];
        }
        $max = $p->u32();
        $n   = $max;
        if ($p->left() >= 8 && unpack('V', $p->data, $p->pos)[1] === 0 && unpack('V', $p->data, $p->pos + 4)[1] <= $max) {
            $p->skip(4);
            $n = $p->u32();
        }
        $b = $p->bytes(min($p->left(), 2 * min($n, 32768)));
        return [self::text($b), $mustConsent];
    }

    /**
     * The authorization's answer (TSG_PACKET_RESPONSE): its device policy, as the HTTP transport's flags; refused unless it
     * is E_PROXY_QUARANTINE_ACCESSDENIED (a tunnel all the same).
     */
    private function rpcAuthorized(?string $r, ?int $fault): void
    {
        $r = (string)$r;
        $e = strlen($r) >= 8 ? unpack('V', $r, strlen($r) - 4)[1] : null;
        $packetId = strlen($r) >= 8 ? unpack('V', $r, 4)[1] : 0;
        if ($fault !== null || $e === null || $packetId === self::NAP_DENIED || (($e & 0x80000000) && $e !== self::QUARANTINED)) {
            throw new ProtocolException(sprintf('Gateway: not authorized (0x%08x)', $fault ?? ($packetId === self::NAP_DENIED ? $packetId : $e) ?? 0), 'gateway_denied');
        }
        if ($packetId === 0x5052 && strlen($r) >= 64) {
            $v = array_values(unpack('l8', $r, 32));         // enable all, disable all, drive, printer, port, (reserved), clipboard, plug and play
            $flags = ($v[0] ? 0x80000000 : 0) | ($v[1] ? 0x40000000 : 0) | ($v[2] ? 0x01 : 0) | ($v[3] ? 0x02 : 0) | ($v[4] ? 0x04 : 0)
                   | ($v[6] ? 0x08 : 0) | ($v[7] ? 0x10 : 0);
            $this->denied = self::deniedBy($flags);
        }
    }

    /** The PDUs that came on the OUT channel (one cut at the end): RTS, answers, the pipe's bytes, faults. */
    private function rpcIncoming(): void
    {
        // this IN channel half used: a new one (when is the client's choice, MS-RPCH 3.2.2.4.2; Windows' own waits until 4 KB
        // are left: half leaves time for the gateway's answer while the session goes on)
        if ($this->inSent >= self::$inLifetime >> 1 && $this->inSock !== null && $this->inNext === [] && $this->inPrev === null
            && $this->rpcState === 2 && !$this->closed && !$this->eof && !$this->ending) {
            $this->rpcInRecycle();
        }
        if ($this->rpcHeaders) {                             // a new OUT channel: its HTTP answer first
            if (strpos($this->in, "\r\n\r\n") === false) {
                if (strlen($this->in) > 65536) {             // (before, kept growing for ever)
                    throw new ProtocolException('Gateway: no HTTP answer on its new OUT channel', 'gateway');
                }
                return;
            }
            $this->rpcHeaders = false;
            $this->rpcAnswer();
        }
        $this->stream();
        $len = strlen($this->pkt);
        for ($o = 0; $len - $o >= 16;) {
            $n = unpack('v', $this->pkt, $o + 8)[1];
            if ($n < 16) {
                throw new ProtocolException("Gateway: RPC PDU of $n bytes", 'gateway');
            }
            if ($len - $o < $n) {
                break;
            }
            $switched = $this->rpcOne(substr($this->pkt, $o, $n));
            $o += $n;
            if ($switched) {                                 // (the old OUT channel ended: what came after its end is not read)
                $o = $len;
            }
        }
        $this->pkt = $o > 0 ? substr($this->pkt, $o) : $this->pkt;
    }

    /** One PDU of the gateway; true when it was the last of an OUT channel made again (the new one read from then on). */
    private function rpcOne(string $p): bool
    {
        $type = ord($p[2]);
        if ($type === 0x14) {
            return $this->rpcRts($p);
        }
        // flow control (MS-RPCH 3.2.1.4): RPC PDUs counted, the gateway told when half its window is used
        $this->rpcRecv  += strlen($p);
        $this->rpcAvail -= strlen($p);
        if ($this->rpcAvail < 32768) {
            $this->out     .= self::rts(0x0002, [[13, pack('V', 3)], [1, pack('VV', $this->rpcRecv, 65536) . $this->outCookie]]);
            $this->rpcAvail = 65536;
        }
        $flags = ord($p[3]);
        $call  = unpack('V', $p, 12)[1];
        if ($type === 0x0C || $type === 0x0D) {              // bind_ack, bind_nak: kept whole, for the call waiting for it only
            if (isset($this->rpcWaiting[$call])) {           // (before, any number of them: the memory gone)
                $this->rpcDone[$call] = [$p, null];
            }
            return false;
        }
        if ($type === 0x03) {                                // a fault: of a call waited for, or the tunnel's end
            $status = strlen($p) >= 28 ? unpack('V', $p, 24)[1] : 0;
            if (isset($this->rpcWaiting[$call])) {
                $this->rpcDone[$call] = [null, $status];
            } elseif ($call === $this->pipeCall || $call !== $this->msgCall) {
                $this->closed = true;
            }
            return false;
        }
        if ($type !== 0x02 || strlen($p) < 24) {
            return false;
        }
        $auth = unpack('v', $p, 10)[1];
        $end  = strlen($p);
        if ($auth > 0) {                                     // (its sec_trailer, signature and padding left out)
            $tr  = $end - $auth - 8;
            $end = $tr >= 24 ? $tr - ord($p[$tr + 2]) : 24;
        }
        $stub = substr($p, 24, max(0, $end - 24));
        if ($call === $this->pipeCall && $call !== 0) {      // the computer's bytes; a 4-byte last one: the pipe's end
            if (($flags & 0x02) && strlen($stub) === 4) {
                $this->closed = true;
            } elseif ($stub !== '') {
                $this->queue[] = [self::DATA, pack('v', strlen($stub)) . $stub];
            }
            return false;
        }
        if (!isset($this->rpcWaiting[$call]) && ($call === 0 || $call !== $this->msgCall)) {
            if (($flags & 0x03) === 0x03 && strlen($stub) === 4 && unpack('V', $stub)[1] !== 0) {
                $this->closed = true;                        // (SendToServer refused: the end)
            }
            return false;                                    // (no call waits for it: nothing kept; before, any call's fragments were)
        }
        $this->rpcParts[$call] = ($this->rpcParts[$call] ?? '') . $stub;
        if (strlen($this->rpcParts[$call]) > 1 << 20) {
            throw new ProtocolException('Gateway: RPC answer too large', 'gateway');
        }
        if (!($flags & 0x02)) {
            return false;
        }
        $stub = $this->rpcParts[$call];
        unset($this->rpcParts[$call]);
        if (isset($this->rpcWaiting[$call])) {
            $this->rpcDone[$call] = [$stub, null];
        } else {
            $this->rpcMessage($stub);
        }
        return false;
    }

    /** The answer to TsProxyMakeTunnelCall: a service message kept to show (a consent message too), then the next one asked for. */
    private function rpcMessage(string $r): void
    {
        $this->msgCall = 0;
        if (strlen($r) < 40 || unpack('V', $r)[1] === 0) {   // (none: cancelled, or refused)
            return;
        }
        $type = unpack('V', $r, 20)[1];
        if (($type === 1 || $type === 2) && unpack('V', $r, 24)[1] !== 0 && unpack('V', $r, 32)[1] !== 0) {
            try {
                $p = new Reader($r);
                $p->skip(36);
                [$text] = self::rpcString($p);
                $text !== '' && count($this->messages) < self::MESSAGES_MAX && $this->messages[] = $text;
            } catch (ProtocolException) {
            }
        }
        $this->rpcAskMessage();                              // (a reauthentication message, 3, let be: the gateway ends the session)
    }

    /**
     * An RTS PDU of the OUT channel: the connection's A3 and C2, pings (let be), the IN channel's new one taken (IN_R1/A4,
     * IN_R2/A4), the OUT channel made again (R1 and R2).
     */
    private function rpcRts(string $p): bool
    {
        if (strlen($p) < 20) {
            return false;
        }
        ['f' => $flags, 'n' => $n] = unpack('vf/vn', $p, 16);
        $cmds = [];
        $o    = 20;
        for ($i = 0; $i < $n && $o + 4 <= strlen($p); $i++) {
            $t    = unpack('V', $p, $o)[1];
            $size = [0 => 4, 1 => 24, 2 => 4, 3 => 16, 4 => 4, 5 => 4, 6 => 4, 7 => 0, 9 => 0, 10 => 0, 12 => 16, 13 => 4, 14 => 4][$t] ?? null;
            if ($t === 8 && $o + 8 <= strlen($p)) {
                $size = 4 + unpack('V', $p, $o + 4)[1];
            } elseif ($t === 11 && $o + 8 <= strlen($p)) {
                $size = 4 + (unpack('V', $p, $o + 4)[1] === 0 ? 4 : 16) + 12;
            }
            if ($size === null) {
                break;
            }
            $cmds[] = $t;
            $o += 4 + $size;
        }
        if ($this->rpcState === 0 && $flags === 0 && $cmds === [2]) {          // CONN/A3
            $this->rpcState = 1;
        } elseif ($this->rpcState === 1 && $flags === 0 && in_array(0, $cmds, true)) {   // CONN/C2: open
            $this->rpcState = 2;
        } elseif ($this->inNext !== [] && $flags === 0 && ($cmds === [13, 6, 0, 2] || $cmds === [13]) && unpack('V', $p, 24)[1] === 0) {
            $this->rpcInSwitch();                                                   // IN_R1/A4, IN_R2/A4 (to the client)
        } elseif ($this->rpcState === 2 && ($flags & 0x0004) && $this->recycle === 0 && !$this->ending) {   // OUT_R1/A2, OUT_R2/A2:
            $this->rpcRecycle();                                                    // a new OUT channel wanted (not when closing)
        } elseif ($this->recycle === 1 && $flags === 0 && in_array(10, $cmds, true)) {     // OUT_R2/A6: OUT_R2/C1 on the new one, A7 on IN
            $this->rpcWriteTo($this->succ, self::rts(0x0001, [[7, '']]));
            $this->out    .= self::rts(0x0010, [[13, pack('V', 2)], [3, $this->succCookie], [6, pack('V', 1)]]);
            $this->recycle = 2;
        } elseif ($this->recycle === 1 && $flags === 0x0010) {                      // OUT_R1/A6: OUT_R1/A7 on IN
            $this->out    .= self::rts(0x0010, [[13, pack('V', 2)], [3, $this->succCookie]]);
            $this->recycle = 3;
        } elseif (($this->recycle === 2 && ($flags & 0x0020)) || ($this->recycle === 3 && $flags === 0 && in_array(10, $cmds, true))) {
            if ($this->recycle === 3) {                      // OUT_R1/A10: OUT_R1/A11 on the new one
                $this->rpcWriteTo($this->succ, self::rts(0, [[10, '']]));
            }
            @fclose($this->sock);                            // OUT_R2/B3 (or R1's A10): the old OUT channel's end, the new one's stream next
            // (its close, or the end of its chunked stream from a proxy, is not the tunnel's: before, that one closed it)
            [$this->sock, $this->succ, $this->in, $this->pkt, $this->eof, $this->closed] = [$this->succ, null, '', '', false, false];
            [$this->outCookie, $this->rpcRecv, $this->rpcAvail, $this->recycle, $this->rpcHeaders] = [$this->succCookie, 0, 65536, 0, true];
            return true;
        }
        return false;
    }

    /** A new OUT channel (the gateway asked: its lifetime nearly used): signed in, OUT_R1/A3 (the same as R2's) its body. */
    private function rpcRecycle(): void
    {
        $this->succCookie = random_bytes(16);
        // (what waits for the IN channel set aside meanwhile: its sign-in flushes what is to be written to the new OUT
        // channel; before, acknowledgements and requests waiting went there, ahead of its HTTP request: refused, the end)
        [$old, $oldIn, $oldChunked, $oldLeft, $oldOut] = [$this->sock, $this->in, $this->outChunked, $this->chunkLeft, $this->out];
        $this->sock = $this->rpcConnect();
        [$this->in, $this->out] = ['', ''];
        try {
            $this->rpcSignIn('RPC_OUT_DATA', 120, self::rts(0x0004, [[6, pack('V', 1)], [3, $this->vcCookie], [3, $this->outCookie], [3, $this->succCookie],
                [0, pack('V', 65536)]]));
            $this->succ = $this->sock;
        } finally {
            [$this->sock, $this->in, $this->outChunked, $this->chunkLeft, $this->out] = [$old, $oldIn, $oldChunked, $oldLeft, $oldOut . $this->out];
        }
        $this->recycle = 1;
    }

    /** Writes all of $data to $sock (a new OUT channel's few bytes), within WAIT seconds. */
    private function rpcWriteTo($sock, string $data): void
    {
        for ($end = microtime(true) + self::WAIT; $data !== '';) {
            $n = @fwrite($sock, $data);
            if ($n === false || microtime(true) > $end) {
                throw new ProtocolException('Gateway: its new OUT channel lost', 'gateway');
            }
            $data = substr($data, $n);
            if ($data !== '') {
                $r = $e = null;
                $w = [$sock];
                @stream_select($r, $w, $e, 0, 100000);
            }
        }
    }

    /**
     * A new IN channel (this one half used: MS-RPCH 3.2.2.5.12): signed in as the first, its body begun with IN_R1/A1
     * (version, the connection's cookie, this channel's, the new one's; IN_R2/A1 is the same). This one is still written to
     * until the gateway's IN_R1/A4 (or R2's) comes on the OUT channel.
     */
    private function rpcInRecycle(): void
    {
        $cookie = random_bytes(16);
        $a1     = self::rts(0x0004, [[6, pack('V', 1)], [3, $this->vcCookie], [3, $this->inCookie], [3, $cookie]]);
        // (what waits for the IN channel, and the OUT channel's reading, set aside meanwhile, as when the OUT channel is made
        // again; what is left of a sign-in cut short is not sent on the IN channel)
        [$old, $oldIn, $oldChunked, $oldLeft, $oldOut] = [$this->sock, $this->in, $this->outChunked, $this->chunkLeft, $this->out];
        $new = $this->sock = $this->rpcConnect();
        [$this->in, $this->out] = ['', ''];
        try {
            $this->rpcSignIn('RPC_IN_DATA', self::$inLifetime, $a1);
            [$this->inNext, $new] = [[$new, $cookie, strlen($a1)], null];
        } finally {
            $new !== null && @fclose($new);
            [$this->sock, $this->in, $this->outChunked, $this->chunkLeft, $this->out] = [$old, $oldIn, $oldChunked, $oldLeft, $oldOut];
        }
    }

    /**
     * IN_R1/A4 (or R2's, the same to the client: MS-RPCH 3.2.2.5.5): the new IN channel is the default. What was taken for
     * the old one is written there first, then IN_R1/A5 (the new one's cookie), its last PDU; what waits after it goes to the
     * new one only then (plugged till then): nothing lost, doubled or out of order, the signatures' sequence numbers going on
     * across both. (Its receive window and timeout are not used: this client sends without flow control on the IN channel.)
     */
    private function rpcInSwitch(): void
    {
        [$sock, $cookie, $sent] = $this->inNext;
        $this->inPrevOut = substr($this->out, 0, $this->inOwn) . self::rts(0, [[3, $cookie]]);
        $this->out       = substr($this->out, $this->inOwn);
        [$this->inPrev, $this->inSock, $this->inCookie, $this->inSent, $this->inOwn, $this->inNext] = [$this->inSock, $sock, $cookie, $sent, 0, []];
    }

    /**
     * Writes what it can of what waits for the IN channel; false once its connection is lost (the tunnel then ended). An old
     * IN channel's last PDUs first, then the default one's: whole PDUs only, as far as its length allows (room kept for
     * IN_R1/A5), the rest waiting for its new one (before, it was never made again: past its 1 GiB the gateway read what
     * followed as a new request, and the session ended).
     */
    private function rpcFlush(): bool
    {
        if ($this->inPrev !== null) {
            $n = @fwrite($this->inPrev, $this->inPrevOut);
            if ($n === false || ($n === 0 && feof($this->inPrev))) {
                $this->eof = $this->closed = true;
                return false;
            }
            $this->inPrevOut = substr($this->inPrevOut, $n);
            if ($this->inPrevOut !== '') {
                return true;
            }
            // all written: the gateway closes it. Closed here later (the one before it now), not at once: a close with
            // something unread resets the connection, which could lose its last bytes
            is_resource($this->inDone) && @fclose($this->inDone);
            [$this->inDone, $this->inPrev] = [$this->inPrev, null];
        }
        if ($this->out === '' || $this->inSock === null) {
            return true;
        }
        $this->rpcTake();
        if ($this->inOwn === 0) {
            return true;
        }
        $n = @fwrite($this->inSock, $this->inOwn === strlen($this->out) ? $this->out : substr($this->out, 0, $this->inOwn));
        if ($n === false || ($n === 0 && feof($this->inSock))) {
            $this->eof = $this->closed = true;
            return false;
        }
        $this->out     = substr($this->out, $n);
        $this->inOwn  -= $n;
        $this->inSent += $n;
        return true;
    }

    /** Of what waits (whole PDUs), those that still fit in this IN channel taken for it, IN_R1/A5's 40 bytes kept free. */
    private function rpcTake(): void
    {
        $len = strlen($this->out);
        $max = self::$inLifetime - 40 - $this->inSent;
        for ($o = $this->inOwn; $len - $o >= 10;) {
            $n = max(16, unpack('v', $this->out, $o + 8)[1]);
            if ($o + $n > $max) {
                break;
            }
            $o = min($len, $o + $n);
        }
        $this->inOwn = $o;
    }

    /** The IN channel while something can be written to it now: none while what waits is for a new one (a select would not wait). */
    private function rpcWritable(): array
    {
        if ($this->inPrev !== null) {
            return [$this->inPrev];
        }
        if ($this->out === '' || $this->inSock === null) {
            return [];
        }
        $this->rpcTake();
        return $this->inOwn > 0 ? [$this->inSock] : [];
    }

    /**
     * A packet of the HTTP transport as this one sends it: the client's bytes as TsProxySendToServer requests (one buffer
     * each, its lengths big-endian, small enough to need no fragment), a keep-alive as an RTS ping, the channel's close as
     * TsProxyCloseChannel; nothing for the rest.
     */
    private function rpcWrap(string $packet): string
    {
        $type = unpack('v', $packet)[1];
        $body = substr($packet, 8);
        if ($type === self::DATA && strlen($body) >= 2) {
            $out = '';
            foreach (str_split(substr($body, 2, unpack('v', $body)[1]), max(1, $this->maxXmit - 80)) as $c) {
                $out .= $this->rpcPdu(9, $this->channelCtx . pack('NNN', strlen($c) + 4, 1, strlen($c)) . $c);
            }
            return $out;
        }
        if ($type === self::KEEPALIVE) {
            return self::rts(0x0001, []);
        }
        if ($type === self::CLOSE_CHANNEL && $this->channelCtx !== '') {
            return $this->rpcPdu(6, $this->channelCtx);
        }
        return '';
    }
}
