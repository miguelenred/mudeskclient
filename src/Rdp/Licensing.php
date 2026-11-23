<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

/** Client side of the RDP licensing exchange (MS-RDPELE), needed by RDS session hosts. */
final class Licensing
{
    private string $clientRandom = '';
    private string $serverRandom = '';
    private string $macKey = '';
    private string $encKey = '';

    /** $serverCert: the certificate of Standard RDP Security's server data, for a License Request without one ('' with TLS). */
    public function __construct(private string $user, private string $machine, private string $serverCert = '')
    {
    }

    /** Handles a server licensing message. Returns [reply PDU or null, finished]. */
    public function handle(int $type, Reader $r): array
    {
        return match ($type) {
            0x01    => [$this->newLicenseRequest($r), false],
            0x02    => [$this->challengeResponse($r), false],
            0x03, 0x04 => [null, true],
            0xFF    => $this->error($r),
            default => throw new ProtocolException("Unknown licensing message $type", 'license'),
        };
    }

    private function error(Reader $r): array
    {
        $code = $r->u32();
        if ($code !== 7) { // STATUS_VALID_CLIENT
            throw new ProtocolException("Licensing error $code", 'license');
        }
        return [null, true];
    }

    private static function blob(int $type, string $data): string
    {
        return pack('vv', $type, strlen($data)) . $data;
    }

    private static function readBlob(Reader $r): string
    {
        $r->skip(2);
        return $r->bytes($r->u16());
    }

    private static function pdu(int $type, string $body): string
    {
        return pack('CCv', $type, 0x83, strlen($body) + 4) . $body;
    }

    private static function salted(string $s, string $i, string $r1, string $r2): string
    {
        return md5($s . sha1($i . $s . $r1 . $r2, true), true);
    }

    private function newLicenseRequest(Reader $r): string
    {
        $this->serverRandom = $r->bytes(32);
        $r->skip(4);                          // product version
        $r->skip($r->u32());                  // company name
        $r->skip($r->u32());                  // product id
        self::readBlob($r);                   // key exchange list
        // the server's key, as Standard RDP Security reads it; a request without it (it may leave it out when Standard RDP
        // Security already encrypts: MS-RDPELE 2.2.2.1) takes that one's (before: a truncated PDU, and the session ended)
        $cert = self::readBlob($r);
        [$n, $e] = RdpSecurity::publicKey($cert === '' ? $this->serverCert : $cert);

        $this->clientRandom = random_bytes(32);
        $pre                = random_bytes(48);
        $master             = '';
        $blob               = '';
        foreach (['A', 'BB', 'CCC'] as $i) {
            $master .= self::salted($pre, $i, $this->clientRandom, $this->serverRandom);
        }
        foreach (['A', 'BB', 'CCC'] as $i) {
            $blob .= self::salted($master, $i, $this->serverRandom, $this->clientRandom);
        }
        $this->macKey = substr($blob, 0, 16);
        $this->encKey = md5(substr($blob, 16, 16) . $this->clientRandom . $this->serverRandom, true);

        $body = pack('VV', 1, 0x04010000) . $this->clientRandom
              . self::blob(0x0002, RdpSecurity::rsa($pre, $n, $e) . str_repeat("\0", 8))
              . self::blob(0x000F, $this->user . "\0") . self::blob(0x0010, $this->machine . "\0");
        return self::pdu(0x13, $body);
    }

    private function challengeResponse(Reader $r): string
    {
        if ($this->encKey === '') {
            throw new ProtocolException('Platform challenge before the license request', 'license');
        }
        $r->skip(4);
        $challenge = (new Rc4($this->encKey))->crypt(self::readBlob($r));
        $data      = pack('vvvv', 0x0100, 0x0100, 0x0003, strlen($challenge)) . $challenge;
        $hwid      = pack('V', 2) . substr(hash('sha256', $this->machine, true), 0, 16);
        $body      = self::blob(0x0009, (new Rc4($this->encKey))->crypt($data))
                   . self::blob(0x0009, (new Rc4($this->encKey))->crypt($hwid)) . $this->mac($data . $hwid);
        return self::pdu(0x15, $body);
    }

    private function mac(string $data): string
    {
        $inner = sha1($this->macKey . str_repeat("\x36", 40) . pack('V', strlen($data)) . $data, true);
        return md5($this->macKey . str_repeat("\x5c", 48) . $inner, true);
    }
}
