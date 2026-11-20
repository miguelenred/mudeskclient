<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/**
 * Audit log: one JSON line per event, one file per day (data/audit/YYYY-MM-DD.jsonl).
 * Every line carries "p", the "h" of the line before, and "h", an HMAC (server key) of the line: an edited line no
 * longer matches its "h", and removed lines leave a "p" that does not match the line before; .last names the last line
 * (with a MAC), so lines removed at the end are noticed too. verify() reports all of them; the entries are still shown. Passwords and the contents of the clipboard or of files are never written.
 */
final class Audit
{
    private const DAY      = '/^\d{4}-\d{2}-\d{2}$/';
    private const ANON_IP  = 100;                    // lines an hour about refused requests of someone not signed in, per address
    private const ANON_ALL = 5000;                   // and from all addresses
    private const KEY_HEAD = "<?php exit; ?>\n";      // data/audit-key.php: PHP shows nothing even if the folder were served

    /** $ok: outcome of the event (null when it is not a success/failure kind of event). */
    public static function log(string $event, array $data = [], ?string $user = null, ?bool $ok = null): void
    {
        $sid  = session_id();
        $user = $user ?? Auth::username();
        $rec  = ['t' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'), 'ip' => Http::clientIp(), 'user' => $user === null ? null : substr($user, 0, 256), 'event' => $event]
             + ($ok === null ? [] : ['ok' => $ok])
             + $data
             + ['ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160), 'ws' => $sid === '' || $sid === false ? '' : substr(hash('sha256', $sid), 0, 12)];
        $dir  = Config::dir('audit');
        $lock = fopen($dir . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            return;
        }
        try {
            self::migrate($dir);
            $rec['p'] = substr((string)@file_get_contents($dir . '/.last'), 0, 32);
            $body     = json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $h        = self::mac($body);
            file_put_contents($dir . '/' . date('Y-m-d') . '.jsonl', substr($body, 0, -1) . ',"h":"' . $h . "\"}\n", FILE_APPEND);
            file_put_contents($dir . '/.last', $h . ':' . self::mac('last:' . $h));   // MAC: a shortened log can not name a new last line
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if (random_int(1, 500) === 1) {
            self::purge();
        }
    }

    /**
     * Whether a line about a refused request of someone not signed in may be written: at most ANON_IP an hour from one
     * address and ANON_ALL from all of them (then one line says the rest were left out). Before, each such request wrote
     * a line, without limit and without an account: a day's file could outgrow what reading it at sign-in could hold.
     */
    public static function anonymous(): bool
    {
        $ip    = Http::clientIp();
        $hour  = intdiv(time(), 3600);
        $state = [0, 0];
        $seen  = Store::read('auditlimit');
        if (($seen['hour'] ?? 0) === $hour && (($seen['ip'][$ip] ?? 0) > self::ANON_IP || ($seen['all'] ?? 0) > self::ANON_ALL)) {
            return false;                     // past the limit this hour: nothing more to count (the file was written again each time)
        }
        Store::update('auditlimit', static function (array &$a) use ($ip, $hour, &$state): void {
            if (($a['hour'] ?? 0) !== $hour) {
                $a = ['hour' => $hour, 'all' => 0, 'ip' => []];
            }
            $mine = ($a['ip'][$ip] ?? 0) + 1;
            if ($mine <= self::ANON_IP + 1 && count($a['ip']) < 20000) {
                $a['ip'][$ip] = $mine;
            }
            $state = [$mine, ++$a['all']];
        });
        [$mine, $all] = $state;
        if ($mine === self::ANON_IP + 1 || $all === self::ANON_ALL + 1) {
            self::log('audit_limited', ['per_hour' => $mine > self::ANON_IP ? self::ANON_IP : self::ANON_ALL, 'scope' => $mine > self::ANON_IP ? 'ip' : 'all'],
                null, false);
        }
        return $mine <= self::ANON_IP && $all <= self::ANON_ALL;
    }

    /** Non-empty lines of a day file as number (from 1) => line, up to $size bytes (all with -1), one at a time. */
    private static function lines(string $file, int $size = -1): \Generator
    {
        $h = @fopen($file, 'rb');
        if ($h === false) {
            return;
        }
        $n = 0;
        while (($size < 0 || ftell($h) < $size) && ($l = fgets($h)) !== false) {
            $l = rtrim($l, "\r\n");
            if ($l !== '') {
                yield ++$n => $l;
            }
        }
        fclose($h);
    }

    /**
     * The same, last first, read from the end in blocks of 1 MB: a day of hundreds of MB no longer goes into memory (it
     * stopped sign-ins). $numbered: the line numbers too (one more pass to count them).
     */
    private static function backwards(string $file, bool $numbered = true): \Generator
    {
        $n = $numbered ? self::count($file) : 0;
        $h = @fopen($file, 'rb');
        if ($h === false) {
            return;
        }
        $pos  = (int)fstat($h)['size'];
        $tail = '';
        while ($pos > 0) {
            $len  = min(1048576, $pos);
            $pos -= $len;
            fseek($h, $pos);
            $parts = explode("\n", (string)fread($h, $len) . $tail);
            $tail  = array_shift($parts);                // the start of a line that began in an earlier block
            for ($i = count($parts) - 1; $i >= 0; $i--) {
                if (($l = rtrim($parts[$i], "\r")) !== '') {
                    yield $n-- => $l;
                }
            }
        }
        if (($l = rtrim($tail, "\r")) !== '') {
            yield $n => $l;
        }
        fclose($h);
    }

    /**
     * How many lines lines() gives (those not empty once their CR and LF are cut), counted in blocks of 1 MB: going
     * through lines() took 40 % of a search. Lines with only CRs, empty lines and a first line that is empty are counted
     * apart, by a regular expression, only in a block that has any.
     */
    private static function count(string $file): int
    {
        $h = @fopen($file, 'rb');
        if ($h === false) {
            return 0;
        }
        $n    = 0;
        $tail = '';
        while (($blk = fread($h, 1048576)) !== false && $blk !== '') {
            $blk = $tail . $blk;
            $cut = strrpos($blk, "\n");
            if ($cut === false) {
                $tail = $blk;
                continue;
            }
            $tail = substr($blk, $cut + 1);
            $odd  = $blk[0] === "\n" || (($r = strpos($blk, "\r")) !== false && $r < $cut) || (($e = strpos($blk, "\n\n")) !== false && $e < $cut);
            $n   += $odd ? preg_match_all('/^[^\n]*?[^\r\n]/m', substr($blk, 0, $cut + 1)) : substr_count($blk, "\n", 0, $cut + 1);
        }
        fclose($h);
        return $n + preg_match('/[^\r\n]/', $tail);
    }

    /** Changed fields between two records, as {field: [old, new]}; secret fields only say that they changed. */
    public static function changes(array $old, array $new, array $secret = []): array
    {
        $c = [];
        foreach ($new as $k => $v) {
            if (($old[$k] ?? null) !== $v) {
                $c[$k] = in_array($k, $secret, true) ? 'changed' : [$old[$k] ?? null, $v];
            }
        }
        return $c;
    }

    private static function mac(string $body): string
    {
        return substr(hash_hmac('sha256', $body, Crypto::appKey()), 0, 32);
    }

    /**
     * Monthly files of earlier versions are split into days once. Their lines stay outside the chain; how many there
     * were is kept with a MAC (.legacy), so verify() does not take more unchained lines for old ones.
     */
    private static function migrate(string $dir): void
    {
        foreach (glob($dir . '/[0-9][0-9][0-9][0-9]-[0-9][0-9].jsonl') ?: [] as $f) {
            $days = [];
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
                $days[substr((string)(json_decode($l, true)['t'] ?? basename($f, '.jsonl') . '-01'), 0, 10)][] = $l;
            }
            $n = self::legacy($dir);
            foreach ($days as $d => $lines) {
                $old = (string)@file_get_contents("$dir/$d.jsonl");
                file_put_contents("$dir/$d.jsonl", implode("\n", $lines) . "\n" . $old);
                $n += count($lines);
            }
            file_put_contents("$dir/.legacy", $n . ':' . self::mac('legacy:' . $n));
            unlink($f);
        }
    }

    /** Number of lines of earlier versions (0 if .legacy is missing or altered). */
    private static function legacy(string $dir): int
    {
        [$n, $tag] = explode(':', (string)@file_get_contents($dir . '/.legacy'), 2) + ['0', ''];
        return hash_equals(self::mac('legacy:' . $n), $tag) ? (int)$n : 0;
    }

    /** Deletes the days older than the retention (0 = keep everything) and records it in the log itself. */
    public static function purge(): void
    {
        $keep = (int)Settings::get('audit_retention_days');
        if ($keep <= 0) {
            return;
        }
        $limit = date('Y-m-d', strtotime("-$keep days"));
        $gone  = array_values(array_filter(self::days(), static fn($d) => $d < $limit));
        foreach ($gone as $d) {
            @unlink(Config::dir("audit/$d.jsonl"));
        }
        if ($gone !== []) {
            self::log('audit_purge', ['from' => $gone[count($gone) - 1], 'to' => $gone[0], 'days' => count($gone), 'kept_from' => $limit]);
        }
    }

    /** Days with entries, newest first. */
    public static function days(): array
    {
        $d = array_map(static fn($f) => basename($f, '.jsonl'), glob(Config::dir('audit/[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].jsonl')) ?: []);
        rsort($d);
        return $d;
    }

    /**
     * Search, newest first. $f: from, to (YYYY-MM-DD), user, event (prefix), ip, q (free text), ok ('1'/'0'/'').
     * Returns the page of rows, the number of matches, totals per event and failures of the matches. With $emit the
     * rows of the page go to it one by one, with their line as stored, and are not kept (an export of 20,000 rows took
     * about 45 MB kept together).
     */
    public static function search(array $f, int $offset = 0, int $limit = 100, ?callable $emit = null): array
    {
        $from  = preg_match(self::DAY, (string)($f['from'] ?? '')) ? $f['from'] : date('Y-m-d', strtotime('-6 days'));
        $to    = preg_match(self::DAY, (string)($f['to'] ?? '')) ? $f['to'] : date('Y-m-d');
        $q     = Text::valid(trim((string)($f['q'] ?? '')));
        $user  = Text::valid(trim((string)($f['user'] ?? '')));
        $event = trim((string)($f['event'] ?? ''));
        $ip    = trim((string)($f['ip'] ?? ''));
        $ok    = (string)($f['ok'] ?? '');
        $rows  = [];
        $total = 0;
        $per   = [];
        $fails = 0;
        // the text is looked for in what the viewer shows of a line (see shows()), in upper or lower case of any alphabet;
        // first, quickly, in the raw line, where it must be as JSON writes it (the part after "key=", if any). Looked for
        // in the raw line alone, it was found in the field names ("ok", "event"), the date and the chain's hashes, and
        // not found with quotes, "key=value" or another case of an accented letter
        $qIn     = $q === '' ? null : self::finder($q);
        // plain ASCII text without JSON's punctuation (most searches): one search in the raw line, then rawShows() tells
        // where it is; anything else through rawFinder() and, on the lines found, shows()
        $qFast   = $q !== '' && !preg_match('~[^\x20-\x7e]|["\\\\:,{}\[\]=]~', $q);
        $qRaw    = $q === '' || $qFast ? null : self::rawFinder(str_contains($q, '=') ? substr($q, strpos($q, '=') + 1) : $q);
        $uFast   = $user !== '' && !preg_match('~[^\x20-\x7e]|["\\\\]~', $user);
        $userRaw = $user === '' || $uFast ? null : self::rawFinder($user);
        $userIs  = preg_match('/[\x80-\xff]/', $user) ? static fn(string $u): bool => preg_match('~^' . preg_quote($user, '~') . '$~iu', $u) === 1
                 : static fn(string $u): bool => strcasecmp($u, $user) === 0;
        foreach (self::days() as $d) {
            if ($d > $to || $d < $from) {
                continue;
            }
            foreach (self::backwards(Config::dir("audit/$d.jsonl")) as $num => $l) {
                // text checks first; JSON is decoded only for the rows shown and for the text, user and IP checks.
                // ',"event":"' can only be the top-level key: inside strings quotes are escaped, and 'ok' follows it.
                $at = $qFast ? stripos($l, $q) : 0;
                if ($at === false || ($qRaw !== null && !$qRaw($l)) || ($uFast ? stripos($l, $user) === false : $userRaw !== null && !$userRaw($l))
                    || ($ip !== '' && !str_contains($l, $ip))) {
                    continue;
                }
                $e  = strpos($l, ',"event":"');
                $ev = $e === false ? '' : substr($l, $e + 10, strcspn($l, '"', $e + 10));
                $o  = $e + 11 + strlen($ev);                   // substr, not substr_compare: a cut line must not throw
                $st = $e === false ? null : match (true) {
                    substr($l, $o, 10) === ',"ok":true'  => true,
                    substr($l, $o, 11) === ',"ok":false' => false,
                    default                              => null,
                };
                if (($event !== '' && !str_starts_with($ev, $event)) || ($ok === '1' && $st !== true) || ($ok === '0' && $st !== false)) {
                    continue;
                }
                $show = $total >= $offset && $total < $offset + $limit;
                $hit  = $qIn === null ? true : ($qFast ? self::rawShows($l, $q, $at, $st === null ? -9 : $o + 6) : null);
                if ($hit === false) {
                    continue;
                }
                if ($show || $hit === null || $user !== '' || $ip !== '') {
                    $r = json_decode($l, true);
                    if (!is_array($r) || ($hit === null && !self::shows($r, $qIn)) || ($user !== '' && !$userIs((string)($r['user'] ?? '')))
                        || ($ip !== '' && !str_starts_with((string)($r['ip'] ?? ''), $ip))) {
                        continue;
                    }
                    if ($show) {
                        $r += ['_d' => $d, '_n' => $num];   // day and line: lets the viewer mark lines that verify() reports
                        $emit === null ? $rows[] = $r : $emit($r, $l);
                    }
                }
                $per[$ev] = ($per[$ev] ?? 0) + 1;
                $fails   += $st === false ? 1 : 0;
                $total++;
            }
        }
        arsort($per);
        return ['rows' => $rows, 'total' => $total, 'events' => $per, 'failures' => $fails, 'from' => $from, 'to' => $to];
    }

    /** Fields of a line not looked into by the text search: its date (it has its own filter), outcome and hashes. */
    private const UNSEARCHED = ['t', 'ok', 'ws', 'p', 'h'];
    /** Fields the viewer shows as they are; the others as key=value. */
    private const PLAIN = ['user', 'ip', 'event', 'ua'];

    /** Whether $in finds its text in what the viewer shows of a line: user, IP, event, browser and each detail as key=value. */
    private static function shows(array $r, \Closure $in): bool
    {
        foreach ($r as $k => $v) {
            if (in_array($k, self::UNSEARCHED, true)) {
                continue;
            }
            $plain = in_array($k, self::PLAIN, true);
            $s     = match (true) {
                is_string($v) => $v,
                $v === null   => $plain ? '' : 'null',
                is_bool($v)   => $v ? 'true' : 'false',
                default       => (string)json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            };
            if ($in($plain ? $s : $k . '=' . $s)) {
                return true;
            }
        }
        return false;
    }

    /** Characters of a field's name. */
    private const NAME = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';

    /**
     * The same as shows() told on the raw line, for a text of plain ASCII without JSON's quotes, backslash, punctuation or
     * "=", found first at $p: true when it is in a value shown, false when only in the date, the field names, the outcome
     * (at $okAt) or the hashes, null when that cannot be told quickly (a line with escapes; a field name, where a list or
     * an object is shown with its names): then the line is decoded. Decoding every line found was 50 % slower than the
     * search before; this is as fast.
     */
    private static function rawShows(string $l, string $s, int $p, int $okAt): ?bool
    {
        $from = strpos($l, '","ip":"');              // the end of the date
        $to   = strrpos($l, ',"ws":"');              // the hashes after
        if ($from === false || $to === false || (($b = strpos($l, '\\', $from)) !== false && $b < $to)) {
            return null;
        }
        $n      = strlen($s);
        $unsure = false;
        for ($p = $p > $from ? $p : stripos($l, $s, $from + 2); $p !== false && $p + $n <= $to; $p = stripos($l, $s, $p + 1)) {
            if ($p >= $okAt && $p < $okAt + 5) {
                continue;                            // the outcome's true or false: shown as a colour, not as text
            }
            $k = $p + $n + strspn($l, self::NAME, $p + $n);
            if (($l[$k] ?? '') === '"' && ($l[$k + 1] ?? '') === ':') {   // in a field's name (a value's quote is never
                if (strcspn($l, '{[', $from, $to - $from) < $to - $from) {  // followed by ':'): not shown, unless lists or
                    $unsure = true;                                       // objects shown with their names are there
                }
                continue;
            }
            if (stripos('null', $s) !== false && ($u = strpos($l, '"user":null,')) !== false && $p >= $u + 7 && $p < $u + 11) {
                continue;                            // no user: shown as nothing
            }
            return true;
        }
        return $unsure ? null : false;
    }

    /**
     * A test that a text holds $s in upper or lower case: by bytes for ASCII (as fast as before: a third of the time of
     * Unicode case folding), with Unicode case folding otherwise ("JOSÉ" finds "josé").
     */
    private static function finder(string $s): \Closure
    {
        if (!preg_match('/[\x80-\xff]/', $s)) {
            return static fn(string $in): bool => stripos($in, $s) !== false;
        }
        $re = '~' . preg_quote($s, '~') . '~iu';
        return static fn(string $in): bool => preg_match($re, $in) === 1;
    }

    /** A test that the raw line can hold $s: as JSON writes it inside a string, or as it is (numbers, a list's JSON). */
    private static function rawFinder(string $s): \Closure
    {
        $json = substr((string)json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1);
        $a    = self::finder($json);
        if ($json === $s) {
            return $a;
        }
        $b = self::finder($s);
        return static fn(string $in): bool => $a($in) || $b($in);
    }

    /**
     * The last successful sign-in of $user (time, IP) and the sign-ins that failed with that user name after it (how
     * many, the last one), read newest first back to the day of $since and only until $deadline (hrtime): when it comes
     * first, 'partial' says the count may be short. Lines are matched by their start, written by log() as t, ip, user,
     * event (a regular expression per day: about twice as fast as search()).
     */
    public static function sinceLogin(string $user, int $since, int $deadline = PHP_INT_MAX): array
    {
        $re  = '~^\{"t":"([^"]*)","ip":"([^"]*)","user":' . preg_quote(json_encode($user, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '~')
             . ',"event":"login_(ok|fail)"~i';
        $out = ['login' => null, 'ip' => null, 'failures' => 0, 'failed' => null, 'partial' => false];
        foreach (self::days() as $d) {
            if ($d < date('Y-m-d', $since)) {
                break;
            }
            if (hrtime(true) > $deadline) {
                $out['partial'] = true;
                break;
            }
            foreach (self::backwards(Config::dir("audit/$d.jsonl"), false) as $l) {
                if (!preg_match($re, $l, $m)) {
                    continue;
                }
                [, $t, $ip, $ev] = $m;
                if ($ev === 'ok') {
                    return ['login' => $t, 'ip' => $ip] + $out;
                }
                $out['failures']++;
                $out['failed'] ??= ['t' => $t, 'ip' => $ip];
                if ($out['failures'] % 256 === 0 && hrtime(true) > $deadline) {   // within the fixed time of the answer
                    $out['partial'] = true;
                    return $out;
                }
            }
        }
        return $out;
    }

    /**
     * A search (newest first, up to 20,000 rows) as a JSON file written to $out in pieces of 64 KB: the rows, one per
     * line as stored, then the totals, the filter, and when and by whom it was exported. With $sign its SHA-256 is
     * computed piece by piece as it goes out (no copy of the file) and signed at the end with the server's RSA key,
     * PKCS#1 v1.5 (what "openssl dgst -sha256 -verify" checks): the signature and the SHA-256 come back with the totals
     * and the number of rows sent.
     */
    public static function export(array $f, callable $out, bool $sign): array
    {
        $key = $sign ? self::signingKey() : null;            // before anything is sent: no key is a clear error
        $ctx = $sign ? hash_init('sha256') : null;
        $buf = "{\"rows\":[\n";
        $put = static function (bool $end = false) use (&$buf, $out, $ctx): void {
            if ($end || strlen($buf) >= 65536) {
                $out($buf);
                if ($ctx !== null) {
                    hash_update($ctx, $buf);
                }
                $buf = '';
            }
        };
        $n   = 0;
        $res = self::search($f, 0, 20000, static function (array $r, string $line) use (&$n, &$buf, $put): void {
            $buf .= ($n++ ? ",\n" : '') . $line;
            $put();
        });
        $buf .= "\n]," . substr(json_encode(['total' => $res['total'], 'from' => $res['from'], 'to' => $res['to'],
            'filter' => (object)array_filter($f, 'strlen'), 'exported' => date('c'), 'by' => Auth::username()], Http::JSON), 1) . "\n";
        $put(true);
        $sig = $sha = null;
        if ($ctx !== null) {
            $sha = hash_final($ctx, true);                    // DigestInfo of SHA-256 (DER) + the hash, padded and signed
            if (!openssl_private_encrypt("\x30\x31\x30\x0d\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02\x01\x05\x00\x04\x20" . $sha, $sig, $key, OPENSSL_PKCS1_PADDING)) {
                throw new \RuntimeException('Cannot sign the audit export: ' . openssl_error_string());
            }
            $sha = bin2hex($sha);                             // for the audit: which file was exported
        }
        return ['rows' => $n, 'sig' => $sig, 'sha256' => $sha] + $res;
    }

    /**
     * The server's key for signing exports (RSA 3072: with it a hash computed piece by piece can be signed), made the
     * first time in data/audit-key.php: behind exit, for the owner only. An empty OpenSSL configuration is given (PHP
     * for Windows has none and could not make keys).
     */
    private static function signingKey(): \OpenSSLAsymmetricKey
    {
        $file = Config::dir('audit-key.php');
        if (!is_file($file)) {
            $cnf = (string)tempnam(Config::dir('tmp'), 'cnf');
            $opt = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072, 'config' => $cnf];
            $key = openssl_pkey_new($opt);
            $ok  = $key !== false && openssl_pkey_export($key, $pem, null, $opt);
            @unlink($cnf);
            if (!$ok) {
                throw new \RuntimeException('Cannot create the audit signing key (OpenSSL): ' . openssl_error_string());
            }
            $h = @fopen($file, 'x');                         // first writer wins
            if ($h !== false) {
                fwrite($h, self::KEY_HEAD . $pem);
                fclose($h);
                @chmod($file, 0600);
            }
        }
        for ($i = 0; !str_contains($c = (string)@file_get_contents($file), '-----END') && $i < 100; $i++) {
            usleep(1000);                                     // another request is still writing it
        }
        return openssl_pkey_get_private(substr($c, strlen(self::KEY_HEAD))) ?: throw new \RuntimeException('Invalid audit-key.php');
    }

    /** Public key of the export signatures (PEM), for whoever checks an exported file. */
    public static function publicKey(): string
    {
        return openssl_pkey_get_details(self::signingKey())['key'];
    }

    /**
     * Checks every stored day, oldest first. Returns ok, number of lines, the first day, lines outside the chain
     * (earlier versions) and the problems found: [day, line number, 'changed' | 'missing_before' | 'missing_after'].
     */
    public static function verify(): array
    {
        $dir    = Config::dir('audit');
        $legacy = self::legacy($dir);
        // the end of the check, taken under the writers' lock and only for that moment: the days, the size of the newest
        // and its last line (.last, with its MAC: if that line is not found, lines were removed at the end). Lines
        // written while checking are left for the next check (a line still being written was reported as changed).
        $lock = fopen($dir . '/.lock', 'c');
        if ($lock !== false) {                 // (without it, nothing can be written either)
            flock($lock, LOCK_SH);
        }
        $days = array_reverse(self::days());
        clearstatcache();
        $size = $days === [] ? 0 : (int)filesize("$dir/" . end($days) . '.jsonl');
        [$last, $tag] = explode(':', (string)@file_get_contents($dir . '/.last'), 2) + ['', ''];
        if ($lock !== false) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $seen   = hash_equals(self::mac('last:' . $last), $tag) ? null : false;
        $prev   = null;
        $end    = null;
        $lines  = 0;
        $plain  = 0;
        $bad    = [];
        $count  = 0;
        $add    = static function (array $p) use (&$bad, &$count): void {   // listed up to 1000 (a day of bad lines took its size in memory)
            if ($count++ < 1000) {
                $bad[] = $p;
            }
        };
        foreach ($days as $i => $d) {
            // a line at a time; the newest day up to $size (the line numbers are the same as search())
            foreach (self::lines("$dir/$d.jsonl", $i < count($days) - 1 ? -1 : $size) as $num => $l) {
                $n = $num - 1;
                $lines++;
                $end = [$d, $n + 2];
                if (!preg_match('/^(.*,"p":"([0-9a-f]{32})?"),"h":"([0-9a-f]{32})"\}$/', $l, $m)) {
                    if ($prev === null && $plain < $legacy) {   // lines of earlier versions, written before the chain existed
                        $plain++;
                    } else {
                        $add([$d, $n + 1, 'changed']);
                    }
                    continue;
                }
                if (!hash_equals(self::mac($m[1] . '}'), $m[3])) {
                    $add([$d, $n + 1, 'changed']);
                } elseif ($prev !== null && $m[2] !== $prev) {     // the first kept line may follow purged days
                    $add([$d, $n + 1, 'missing_before']);
                }
                $prev = $m[3];
                $seen ??= $m[3] === $last ? true : null;
            }
        }
        if ($prev !== null && $seen !== true) {
            $add([...$end, 'missing_after']);
        }
        return ['ok' => $count === 0, 'lines' => $lines, 'first' => $days[0] ?? null, 'unchained' => $plain, 'problems' => $bad, 'problem_count' => $count];
    }
}
