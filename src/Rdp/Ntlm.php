<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

use MuDesk\Text;

/** NTLMv2 client with session security (MS-NLMP), as used inside CredSSP. */
final class Ntlm
{
    private const FLAGS = 0xE2088235; // 56|KEY_EXCH|128|VERSION|ESS|ALWAYS_SIGN|NTLM|SEAL|SIGN|REQUEST_TARGET|UNICODE
    private const REQUIRED = 0x20080030; // 128|ESS|SEAL|SIGN (see authenticate())
    private const KEY_EXCH = 0x40000000;
    /**
     * NTLMSSP_NEGOTIATE_TARGET_INFO: echoed in the AUTHENTICATE message when the challenge has it, as Windows' own clients do.
     * Windows 7 and Server 2008 R2 as first released (build 7600) refuse an NTLMv2 answer without it (a TLS "access denied",
     * whatever the password): NLA with them failed. Later Windows take it either way.
     */
    private const TARGET_INFO = 0x00800000;
    private const VERSION  = "\x06\x01\xb1\x1d\x00\x00\x00\x0f";

    private string $negotiateMsg = '';
    private int $flags = self::FLAGS;
    private string $sendSign = '';
    private string $recvSign = '';
    private ?Rc4 $sendSeal = null;
    private ?Rc4 $recvSeal = null;
    private int $sendSeq = 0;
    private int $recvSeq = 0;
    public string $sessionBaseKey = '';
    /** Server's Windows version from the challenge ([major, minor, build]), when the server sends it */
    public ?array $serverVersion = null;
    public string $exportedKey = '';

    /** MD5 of the channel bindings (RFC 5929 tls-server-end-point) when NTLM goes over TLS to an HTTP server; '' for none */
    public string $bindings = '';

    public function __construct(private string $user, public string $domain, #[\SensitiveParameter] private string $password, private string $spn = '')
    {
    }

    public function negotiate(): string
    {
        return $this->negotiateMsg = "NTLMSSP\0" . pack('VV', 1, self::FLAGS) . str_repeat("\0", 16) . self::VERSION;
    }

    private static function field(string $msg, int $off): string
    {
        $f = unpack('vlen/vmax/Voff', $msg, $off);
        return substr($msg, $f['off'], $f['len']);
    }

    private static function avPairs(string $info): array
    {
        $r     = new Reader($info);
        $pairs = [];
        while ($r->left() >= 4) {
            $id = $r->u16();
            $v  = $r->bytes($r->u16());
            if ($id === 0) {
                break;
            }
            $pairs[] = [$id, $v];
        }
        return $pairs;
    }

    /**
     * Builds the AUTHENTICATE message. $test allows fixed values (time, client challenge, session key) for spec test vectors.
     */
    public function authenticate(string $challengeMsg, array $test = []): string
    {
        if (strlen($challengeMsg) < 48 || !str_starts_with($challengeMsg, "NTLMSSP\0") || unpack('V', $challengeMsg, 8)[1] !== 2) {
            throw new ProtocolException('Invalid NTLM challenge', 'auth');
        }
        $this->flags     = (self::FLAGS | self::TARGET_INFO) & unpack('V', $challengeMsg, 20)[1];
        // what Windows' own client requires: 128-bit keys, extended session security, signing and sealing. Before, a server
        // (or someone in between) could leave them out: the sealing key cut to 5 bytes
        if (($this->flags & self::REQUIRED) !== self::REQUIRED) {
            throw new ProtocolException('NTLM: the server does not offer 128-bit keys, extended session security, signing and sealing', 'auth');
        }
        if ($this->flags & 0x02000000 && strlen($challengeMsg) >= 56) { // NTLMSSP_NEGOTIATE_VERSION: product version at offset 48
            $this->serverVersion = [ord($challengeMsg[48]), ord($challengeMsg[49]), unpack('v', $challengeMsg, 50)[1]];
        }
        $serverChallenge = substr($challengeMsg, 24, 8);
        $pairs           = self::avPairs(self::field($challengeMsg, 40));

        $timestamp = null;
        foreach ($pairs as [$id, $v]) {
            if ($id === 7) {
                $timestamp = $v;
            } elseif ($id === 1 && $this->domain === '.') { // ".\user": local account of the server (its NetBIOS name)
                $this->domain = Text::fromUtf16($v);
            }
        }
        // the MIC announced when the challenge has a timestamp (MS-NLMP 3.1.5.1.2); the channel bindings and the target name
        // whatever it has (before, only with a timestamp: a server leaving it out got an answer bound neither to its
        // certificate nor to its name, easier to relay to a service that checks them only when the client sends them)
        $useMic = $timestamp !== null;
        $pairs  = array_values(array_filter($pairs, static fn($p) => !in_array($p[0], [6, 9, 10], true)));
        if ($useMic) {
            $pairs[] = [6, pack('V', 2)];
        }
        if ($useMic || strlen($this->bindings) === 16) {
            $pairs[] = [10, strlen($this->bindings) === 16 ? $this->bindings : str_repeat("\0", 16)];   // (see Gateway::bindings)
        }
        if ($this->spn !== '') {
            $pairs[] = [9, Text::toUtf16($this->spn)];
        }
        $info = '';
        foreach ($pairs as [$id, $v]) {
            $info .= pack('vv', $id, strlen($v)) . $v;
        }
        $info .= "\0\0\0\0";

        $time       = $test['time'] ?? $timestamp ?? pack('P', (time() + 11644473600) * 10000000);
        $clientChal = $test['client'] ?? random_bytes(8);
        $ntowf      = hash_hmac('md5', self::upper(Text::toUtf16($this->user)) . Text::toUtf16($this->domain),
                          hash('md4', Text::toUtf16($this->password), true), true);
        $temp       = "\x01\x01" . str_repeat("\0", 6) . $time . $clientChal . "\0\0\0\0" . $info . "\0\0\0\0";
        $proof      = hash_hmac('md5', $serverChallenge . $temp, $ntowf, true);
        $nt         = $proof . $temp;
        $lm         = $useMic ? str_repeat("\0", 24) : hash_hmac('md5', $serverChallenge . $clientChal, $ntowf, true) . $clientChal;

        $this->sessionBaseKey = hash_hmac('md5', $proof, $ntowf, true);
        $encKey               = '';
        $this->exportedKey    = $this->sessionBaseKey;
        if ($this->flags & self::KEY_EXCH) {
            $this->exportedKey = $test['key'] ?? random_bytes(16);
            $encKey            = (new Rc4($this->sessionBaseKey))->crypt($this->exportedKey);
        }

        $domain  = Text::toUtf16($this->domain);
        $user    = Text::toUtf16($this->user);
        $ws      = '';
        $payload = '';
        $off     = 88;
        $fields  = '';
        foreach ([$lm, $nt, $domain, $user, $ws, $encKey] as $v) {
            $fields  .= pack('vvV', strlen($v), strlen($v), $off + strlen($payload));
            $payload .= $v;
        }
        $msg = "NTLMSSP\0" . pack('V', 3) . $fields . pack('V', $this->flags) . self::VERSION . str_repeat("\0", 16) . $payload;
        if ($useMic) {
            $mic = hash_hmac('md5', $this->negotiateMsg . $challengeMsg . $msg, $this->exportedKey, true);
            $msg = substr_replace($msg, $mic, 72, 16);
        }

        $k              = $this->exportedKey;
        $this->sendSign = md5($k . "session key to client-to-server signing key magic constant\0", true);
        $this->recvSign = md5($k . "session key to server-to-client signing key magic constant\0", true);
        // SEALKEY (MS-NLMP 3.4.5.3) with NEGOTIATE_128, required (see authenticate()): the whole key
        $this->sendSeal = new Rc4(md5($k . "session key to client-to-server sealing key magic constant\0", true));
        $this->recvSeal = new Rc4(md5($k . "session key to server-to-client sealing key magic constant\0", true));
        return $msg;
    }

    /**
     * What Windows puts in upper case (RtlUpcaseUnicodeChar, the table of NTOWFv2's user name), taken from Windows 11:
     * [first, last, step, difference]: from first to last (every other code with step 2), each code plus the difference.
     * 973 letters: Latin, Greek, Cyrillic, Armenian, Glagolitic, Coptic, fullwidth... Some letters Windows leaves as they
     * are (the final sigma, Georgian, Cherokee): so does the gateway, or their sign-in would fail. Before, only Latin and
     * Cyrillic: a user name with other lower-case letters (Greek) was refused.
     */
    private const UPPER = [
        [0x0061, 0x007A, 1, -32], [0x00E0, 0x00F6, 1, -32], [0x00F8, 0x00FE, 1, -32], [0x00FF, 0x00FF, 1, 121],
        [0x0101, 0x012F, 2, -1], [0x0133, 0x0137, 2, -1], [0x013A, 0x0148, 2, -1], [0x014B, 0x0177, 2, -1],
        [0x017A, 0x017E, 2, -1], [0x0180, 0x0180, 1, 195], [0x0183, 0x0185, 2, -1], [0x0188, 0x0188, 1, -1],
        [0x018C, 0x018C, 1, -1], [0x0192, 0x0192, 1, -1], [0x0195, 0x0195, 1, 97], [0x0199, 0x0199, 1, -1],
        [0x019A, 0x019A, 1, 163], [0x019E, 0x019E, 1, 130], [0x01A1, 0x01A5, 2, -1], [0x01A8, 0x01A8, 1, -1],
        [0x01AD, 0x01AD, 1, -1], [0x01B0, 0x01B0, 1, -1], [0x01B4, 0x01B6, 2, -1], [0x01B9, 0x01B9, 1, -1],
        [0x01BD, 0x01BD, 1, -1], [0x01BF, 0x01BF, 1, 56], [0x01C6, 0x01C6, 1, -2], [0x01C9, 0x01C9, 1, -2],
        [0x01CC, 0x01CC, 1, -2], [0x01CE, 0x01DC, 2, -1], [0x01DD, 0x01DD, 1, -79], [0x01DF, 0x01EF, 2, -1],
        [0x01F3, 0x01F3, 1, -2], [0x01F5, 0x01F5, 1, -1], [0x01F9, 0x021F, 2, -1], [0x0223, 0x0233, 2, -1],
        [0x023C, 0x023C, 1, -1], [0x0242, 0x0242, 1, -1], [0x0247, 0x024F, 2, -1], [0x0250, 0x0250, 1, 10783],
        [0x0251, 0x0251, 1, 10780], [0x0253, 0x0253, 1, -210], [0x0254, 0x0254, 1, -206], [0x0256, 0x0257, 1, -205],
        [0x0259, 0x0259, 1, -202], [0x025B, 0x025B, 1, -203], [0x0260, 0x0260, 1, -205], [0x0263, 0x0263, 1, -207],
        [0x0268, 0x0268, 1, -209], [0x0269, 0x0269, 1, -211], [0x026B, 0x026B, 1, 10743], [0x026F, 0x026F, 1, -211],
        [0x0271, 0x0271, 1, 10749], [0x0272, 0x0272, 1, -213], [0x0275, 0x0275, 1, -214], [0x027D, 0x027D, 1, 10727],
        [0x0280, 0x0280, 1, -218], [0x0283, 0x0283, 1, -218], [0x0288, 0x0288, 1, -218], [0x0289, 0x0289, 1, -69],
        [0x028A, 0x028B, 1, -217], [0x028C, 0x028C, 1, -71], [0x0292, 0x0292, 1, -219], [0x0371, 0x0373, 2, -1],
        [0x0377, 0x0377, 1, -1], [0x037B, 0x037D, 1, 130], [0x03AC, 0x03AC, 1, -38], [0x03AD, 0x03AF, 1, -37],
        [0x03B1, 0x03C1, 1, -32], [0x03C3, 0x03CB, 1, -32], [0x03CC, 0x03CC, 1, -64], [0x03CD, 0x03CE, 1, -63],
        [0x03D7, 0x03D7, 1, -8], [0x03D9, 0x03EF, 2, -1], [0x03F2, 0x03F2, 1, 7], [0x03F8, 0x03F8, 1, -1],
        [0x03FB, 0x03FB, 1, -1], [0x0430, 0x044F, 1, -32], [0x0450, 0x045F, 1, -80], [0x0461, 0x0481, 2, -1],
        [0x048B, 0x04BF, 2, -1], [0x04C2, 0x04CE, 2, -1], [0x04CF, 0x04CF, 1, -15], [0x04D1, 0x0523, 2, -1],
        [0x0561, 0x0586, 1, -48], [0x1D79, 0x1D79, 1, 35332], [0x1D7D, 0x1D7D, 1, 3814], [0x1E01, 0x1E95, 2, -1],
        [0x1EA1, 0x1EFF, 2, -1], [0x1F00, 0x1F07, 1, 8], [0x1F10, 0x1F15, 1, 8], [0x1F20, 0x1F27, 1, 8],
        [0x1F30, 0x1F37, 1, 8], [0x1F40, 0x1F45, 1, 8], [0x1F51, 0x1F57, 2, 8], [0x1F60, 0x1F67, 1, 8],
        [0x1F70, 0x1F71, 1, 74], [0x1F72, 0x1F75, 1, 86], [0x1F76, 0x1F77, 1, 100], [0x1F78, 0x1F79, 1, 128],
        [0x1F7A, 0x1F7B, 1, 112], [0x1F7C, 0x1F7D, 1, 126], [0x1F80, 0x1F87, 1, 8], [0x1F90, 0x1F97, 1, 8],
        [0x1FA0, 0x1FA7, 1, 8], [0x1FB0, 0x1FB1, 1, 8], [0x1FB3, 0x1FB3, 1, 9], [0x1FC3, 0x1FC3, 1, 9],
        [0x1FD0, 0x1FD1, 1, 8], [0x1FE0, 0x1FE1, 1, 8], [0x1FE5, 0x1FE5, 1, 7], [0x1FF3, 0x1FF3, 1, 9],
        [0x214E, 0x214E, 1, -28], [0x2170, 0x217F, 1, -16], [0x2184, 0x2184, 1, -1], [0x24D0, 0x24E9, 1, -26],
        [0x2C30, 0x2C5E, 1, -48], [0x2C61, 0x2C61, 1, -1], [0x2C65, 0x2C65, 1, -10795], [0x2C66, 0x2C66, 1, -10792],
        [0x2C68, 0x2C6C, 2, -1], [0x2C73, 0x2C73, 1, -1], [0x2C76, 0x2C76, 1, -1], [0x2C81, 0x2CE3, 2, -1],
        [0x2D00, 0x2D25, 1, -7264], [0xA641, 0xA65F, 2, -1], [0xA663, 0xA66D, 2, -1], [0xA681, 0xA697, 2, -1],
        [0xA723, 0xA72F, 2, -1], [0xA733, 0xA76F, 2, -1], [0xA77A, 0xA77C, 2, -1], [0xA77F, 0xA787, 2, -1],
        [0xA78C, 0xA78C, 1, -1], [0xFF41, 0xFF5A, 1, -32]
    ];

    /** Uppercase of a UTF-16LE user name, as Windows computes NTOWFv2 (Unicode, not only ASCII: "josé" is "JOSÉ"). */
    private static function upper(string $u): string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (self::UPPER as [$first, $last, $step, $diff]) {
                for ($c = $first; $c <= $last; $c += $step) {
                    $map[$c] = $c + $diff;
                }
            }
        }
        $out = '';
        foreach (unpack('v*', $u) ?: [] as $c) {
            $out .= pack('v', $map[$c] ?? $c);
        }
        return $out;
    }

    /** GSS_WrapEx: 16-byte signature followed by the encrypted message. */
    public function seal(string $msg): string
    {
        $enc = $this->sendSeal->crypt($msg);
        $seq = pack('V', $this->sendSeq++);
        $sum = substr(hash_hmac('md5', $seq . $msg, $this->sendSign, true), 0, 8);
        if ($this->flags & self::KEY_EXCH) {
            $sum = $this->sendSeal->crypt($sum);
        }
        return pack('V', 1) . $sum . $seq . $enc;
    }

    /**
     * GSS_GetMICEx (MS-NLMP 3.4.4): the 16-byte signature of $msg, which is not encrypted (DCE/RPC's packet integrity). The
     * sealing key's RC4 stream is the one Seal uses: signing and sealing go on from each other.
     */
    public function sign(string $msg): string
    {
        $seq = pack('V', $this->sendSeq++);
        $sum = substr(hash_hmac('md5', $seq . $msg, $this->sendSign, true), 0, 8);
        if ($this->flags & self::KEY_EXCH) {
            $sum = $this->sendSeal->crypt($sum);
        }
        return pack('V', 1) . $sum . $seq;
    }

    /** Whether $signature is the server's for $msg (GSS_VerifyMICEx), its sequence number the next one. */
    public function verify(string $msg, string $signature): bool
    {
        $seq = pack('V', $this->recvSeq++);
        $sum = substr(hash_hmac('md5', $seq . $msg, $this->recvSign, true), 0, 8);
        if ($this->flags & self::KEY_EXCH) {
            $sum = $this->recvSeal->crypt($sum);
        }
        return strlen($signature) === 16 && hash_equals(substr($signature, 4, 8), $sum) && substr($signature, 12, 4) === $seq;
    }

    public function unseal(string $data): ?string
    {
        if (strlen($data) < 16) {
            return null;
        }
        $msg = $this->recvSeal->crypt(substr($data, 16));
        $seq = pack('V', $this->recvSeq++);
        $sum = substr(hash_hmac('md5', $seq . $msg, $this->recvSign, true), 0, 8);
        if ($this->flags & self::KEY_EXCH) {
            $sum = $this->recvSeal->crypt($sum);
        }
        return hash_equals(substr($data, 4, 8), $sum) && substr($data, 12, 4) === $seq ? $msg : null;
    }
}
