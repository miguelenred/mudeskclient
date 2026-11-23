<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/**
 * Wake-on-LAN: the "magic packet" (6 bytes 0xFF, then the computer's MAC address 16 times) sent by UDP, usually to a
 * broadcast address, so that a computer that is off or asleep turns on. A broadcast does not cross routers: it reaches the
 * computers of the gateway's own network, so it is for MuDeskClient installed in the office, not on a hosting.
 */
final class WakeOnLan
{
    public const PORT  = 9;                  // the usual one (discard); 7 (echo) is the other
    public const WAIT  = 120;                // seconds the gateway waits for the computer to answer after waking it
    public const RETRY = 30;                 // the packet is sent again this often while waiting
    public const ALL   = '255.255.255.255';  // the whole local network

    /** "AA:BB:CC:DD:EE:FF" from any usual form (colons, hyphens, dots or none; any case), or null when it is not a MAC. */
    public static function mac(string $in): ?string
    {
        $hex = strtoupper((string)preg_replace('/[\s:.\-]/', '', $in));
        return preg_match('/^[0-9A-F]{12}$/', $hex) ? implode(':', str_split($hex, 2)) : null;
    }

    /**
     * Where the packet goes, from "address" or "address:port" (an IPv4 address; the port 1–65535, PORT if left out): [ip,
     * port], [ALL, PORT] for an empty text, or null when it is not valid.
     */
    public static function target(string $in): ?array
    {
        $in = trim($in);
        if ($in === '') {
            return [self::ALL, self::PORT];
        }
        if (!preg_match('/^([0-9.]+)(?::([0-9]{1,5}))?$/', $in, $m) || filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        $port = isset($m[2]) ? (int)$m[2] : self::PORT;
        return $port >= 1 && $port <= 65535 ? [$m[1], $port] : null;
    }

    public static function packet(string $mac): string
    {
        return str_repeat("\xFF", 6) . str_repeat((string)hex2bin(str_replace(':', '', $mac)), 16);
    }

    /** Sends the packet for $mac to $ip:$port (a broadcast address is allowed). */
    public static function send(string $mac, string $ip = self::ALL, int $port = self::PORT): bool
    {
        $ctx = stream_context_create(['socket' => ['so_broadcast' => true]]);
        $s   = @stream_socket_client("udp://$ip:$port", $no, $err, 1, STREAM_CLIENT_CONNECT, $ctx);
        if ($s === false) {
            return false;
        }
        $p  = self::packet($mac);
        $ok = @fwrite($s, $p) === strlen($p);
        fclose($s);
        return $ok;
    }
}
