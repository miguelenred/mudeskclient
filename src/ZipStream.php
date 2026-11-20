<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/**
 * ZIP written as it is sent (stored, no compression: what a shared folder holds is mostly compressed already), so that a
 * folder downloads without a copy on the server. Each file's CRC is read just before it is sent: the local headers carry
 * it (no data descriptors, which some tools do not read) and the length is known before the first byte. Up to 4 GB and
 * 65535 entries (no ZIP64).
 */
final class ZipStream
{
    private const LOCAL = 30;
    private const CENTRAL = 46;
    private const END = 22;

    /**
     * Bytes of the ZIP of $entries ([name in the ZIP, path of the file or null for a folder, size]); null when it does not
     * fit in a ZIP without ZIP64.
     * @param list<array{string, ?string, int}> $entries
     */
    public static function length(array $entries): ?int
    {
        $n = self::END;
        foreach ($entries as [$name, , $size]) {
            $n += self::LOCAL + self::CENTRAL + 2 * strlen($name) + $size;
        }
        return count($entries) > 65535 || $n > 0xFFFFFFFF ? null : $n;
    }

    /**
     * Writes the ZIP of $entries to $out. A file that changed size since it was listed is cut or filled with zeros to the
     * size announced (the length was sent already).
     * @param list<array{string, ?string, int}> $entries
     * @param resource $out
     */
    public static function write(array $entries, $out): void
    {
        $central = '';
        $offset  = 0;
        foreach ($entries as [$name, $path, $size]) {
            $time = $path !== null ? (int)@filemtime($path) : time();
            $in   = $path !== null ? @fopen($path, 'rb') : false;
            $hash = hash_init('crc32b');
            $read = $in !== false ? hash_update_stream($hash, $in, $size) : 0;
            for ($left = $size - $read; $left > 0; $left -= min(1048576, $left)) {   // what is sent instead of what is missing
                hash_update($hash, str_repeat("\0", min(1048576, $left)));
            }
            $crc = (int)hexdec(hash_final($hash));
            if ($in !== false) {
                rewind($in);
            }
            $dos  = self::dosTime($time ?: time());
            $head = pack('vvvVVVVvv', 20, 0x0800, 0, $dos, $crc, $size, $size, strlen($name), 0);   // UTF-8 names
            fwrite($out, pack('V', 0x04034b50) . $head . $name);
            $left = $size;
            while ($in !== false && $left > 0 && ($chunk = fread($in, min(1048576, $left))) !== false && $chunk !== '') {
                fwrite($out, $chunk);
                $left -= strlen($chunk);
            }
            for (; $left > 0; $left -= min(1048576, $left)) {
                fwrite($out, str_repeat("\0", min(1048576, $left)));
            }
            if ($in !== false) {
                fclose($in);
            }
            $central .= pack('VvvvvVVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $dos, $crc, $size, $size, strlen($name), 0, 0, 0, 0,
                $path === null ? 0x10 : 0, $offset) . $name;
            $offset += self::LOCAL + strlen($name) + $size;
        }
        fwrite($out, $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($entries), count($entries), strlen($central), $offset, 0));
    }

    /** MS-DOS time and date in one 32-bit value (time in the low half), local time, from 1980. */
    private static function dosTime(int $t): int
    {
        $d = getdate($t);
        if ($d['year'] < 1980) {
            return 0x00210000;                                  // 1980-01-01 00:00
        }
        if ($d['year'] > 2107) {
            return (int)0xFF9FBF7D;                             // 2107-12-31 23:59:58
        }
        return ($d['hours'] << 11 | $d['minutes'] << 5 | $d['seconds'] >> 1)
            | ((($d['year'] - 1980) << 9 | $d['mon'] << 5 | $d['mday']) << 16);
    }
}
