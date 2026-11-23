<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

use RuntimeException;

/** JSON file storage with locking and atomic replacement. */
final class Store
{
    private static function file(string $name): string
    {
        if (!preg_match('~^[a-z0-9_/-]+$~', $name) || str_contains($name, '..')) {
            throw new RuntimeException('Invalid store name');
        }
        return Config::dir($name . '.json');
    }

    /** @return resource */
    private static function lock(string $file, int $mode)
    {
        $h = fopen($file . '.lock', 'c');
        if ($h === false || !flock($h, $mode)) {
            throw new RuntimeException('Cannot lock store');
        }
        return $h;
    }

    /** Stores that can start again empty when their file is damaged (failed sign-ins): the others stop with an error. */
    private const REBUILT = ['ratelimit'];

    /** Stores written often (failed sign-ins, refused requests): compact JSON. */
    private const COMPACT = ['ratelimit', 'auditlimit'];

    /**
     * The data of $file ([] when it does not exist). A damaged file (cut by a full disk or a crash, edited by hand) stops
     * with an error naming it, instead of an anonymous JSON error, and an empty one is not taken for "no data" (no users
     * would have meant a first run): only the stores in REBUILT start again empty, the damaged file kept aside.
     */
    private static function load(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $raw = file_get_contents($file);
        try {
            if ($raw === false || $raw === '') {
                throw new \JsonException($raw === false ? 'unreadable' : 'empty file');
            }
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {               // "null" was taken for no data: no users, and the setup screen again
                throw new \JsonException('no object or list');
            }
            return $data;
        } catch (\JsonException $e) {
            $name = ltrim(substr($file, strlen(Config::dir('')), -5), '/');
            if (in_array($name, self::REBUILT, true)) {
                @rename($file, $file . '.damaged-' . date('Ymd-His'));
                error_log("MuDeskClient: data file $name.json damaged ({$e->getMessage()}), kept aside and started again");
                return [];
            }
            throw new RuntimeException("Data file $name.json damaged ({$e->getMessage()}): restore it from a backup", 0, $e);
        }
    }

    /**
     * A new id for a record kept as a key of a data file (users, a user's connections): 16 hex digits, never all of them
     * decimal. A key of only digits ("1234567890123456", about 1 in 1,800 ids) comes back from json_decode() as an
     * integer, not the text that was saved: a lookup by that id then compared differently (it showed at random in the tests).
     */
    public static function newId(): string
    {
        do {
            $id = bin2hex(random_bytes(8));
        } while (preg_match('/^[0-9]+$/D', $id));
        return $id;
    }

    public static function read(string $name): array
    {
        $file = self::file($name);
        if (!is_file($file)) {
            return [];
        }
        $l = self::lock($file, LOCK_SH);
        try {
            return self::load($file);
        } finally {
            flock($l, LOCK_UN);
            fclose($l);
        }
    }

    /** Read-modify-write under an exclusive lock. $fn receives the data by reference and may return a value. */
    public static function update(string $name, callable $fn): mixed
    {
        $file = self::file($name);
        $l    = self::lock($file, LOCK_EX);
        try {
            $data   = self::load($file);
            $result = $fn($data);
            $tmp    = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            // the stores written on every failed sign-in or refused request are kept compact (smaller, quicker under their
            // lock); the others indented, to be read by a person
            $pretty = in_array($name, self::COMPACT, true) ? 0 : JSON_PRETTY_PRINT;
            try {
                $json = json_encode($data, $pretty | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {               // (written, "false" was an empty file that replaced the store)
                throw new RuntimeException('Cannot encode store ' . $name . ': ' . $e->getMessage(), 0, $e);
            }
            $ok = file_put_contents($tmp, $json) === strlen($json);   // (a full disk: a part written is no store)
            @chmod($tmp, 0600);                       // before it takes the file's place: never readable by others, even for a moment
            if (!$ok || !rename($tmp, $file)) {
                @unlink($tmp);
                throw new RuntimeException('Cannot write store');
            }
            return $result;
        } finally {
            flock($l, LOCK_UN);
            fclose($l);
        }
    }

    /**
     * Deletes a store under its lock; the lock file stays (removed while another request waited on it, that one locked a
     * file no other request saw on Linux, or could not open it on Windows).
     */
    public static function delete(string $name): void
    {
        $file = self::file($name);
        if (!is_file($file)) {
            return;
        }
        $l = self::lock($file, LOCK_EX);
        try {
            @unlink($file);
        } finally {
            flock($l, LOCK_UN);
            fclose($l);
        }
    }
}
