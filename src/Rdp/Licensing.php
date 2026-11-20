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

    public function __construct(private string $user, private string $machine)
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
        [$n, $e] = self::publicKey(self::readBlob($r));

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
              . self::blob(0x0002, self::rsa($pre, $n, $e) . str_repeat("\0", 8))
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

    /** @return array{0:string,1:string} modulus and exponent, big-endian */
    private static function publicKey(string $cert): array
    {
        $r = new Reader($cert);
        if (($r->u32() & 0x7fffffff) === 1) { // proprietary certificate
            $r->skip(8 + 4);
            if ($r->bytes(4) !== 'RSA1') {
                throw new ProtocolException('Invalid licensing certificate', 'license');
            }
            $keyLen = $r->u32();
            $r->skip(8);
            $e = strrev($r->bytes(4));
            $n = strrev(substr($r->bytes($keyLen), 0, $keyLen - 8));
            return [ltrim($n, "\0"), ltrim($e, "\0")];
        }
        $count = $r->u32();
        $der   = '';
        for ($i = 0; $i < $count; $i++) {
            $der = $r->bytes($r->u32());
        }
        // the key is read from the DER, whatever algorithm it declares: certificates from Windows license servers may declare
        // md5WithRSAEncryption, and OpenSSL then gives no public key
        $tbs = new Reader(Der::value(Der::value($der)));            // Certificate -> TBSCertificate
        $f   = [];
        while ($tbs->left() > 0) {
            $f[] = Der::read($tbs);
        }
        $f = ($f[0][0] ?? 0) === 0xA0 ? array_slice($f, 1) : $f;   // optional [0] version
        if (!isset($f[5])) {
            throw new ProtocolException('Invalid licensing certificate', 'license');
        }
        $spki = new Reader($f[5][1]);                               // serial, signature, issuer, validity, subject, key
        Der::read($spki);                                           // AlgorithmIdentifier: not checked
        $key = new Reader(Der::value(substr(Der::read($spki)[1], 1)));   // BIT STRING -> RSAPublicKey
        return [ltrim(Der::read($key)[1], "\0"), ltrim(Der::read($key)[1], "\0")];
    }

    private static function uint(string $be): string
    {
        return Der::tlv(0x02, (ord($be[0]) & 0x80 ? "\0" : "") . $be);
    }

    /** Raw RSA on a little-endian number, result little-endian with modulus length. */
    private static function rsa(string $data, string $n, string $e): string
    {
        $len  = strlen($n);
        if ($len < 48 || $e === '') {                              // the 48-byte premaster secret must fit
            throw new ProtocolException('Invalid licensing certificate', 'license');
        }
        $spki = Der::seq(Der::seq(Der::tlv(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01"), "\x05\x00"),
                         Der::tlv(0x03, "\0" . Der::seq(self::uint($n), self::uint($e))));
        $pem  = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64) . "-----END PUBLIC KEY-----\n";
        if (!openssl_public_encrypt(str_pad(strrev($data), $len, "\0", STR_PAD_LEFT), $out, $pem, OPENSSL_NO_PADDING)) {
            throw new ProtocolException('Licensing RSA failed', 'license');
        }
        return strrev(str_pad($out, $len, "\0", STR_PAD_LEFT));
    }
}
