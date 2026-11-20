<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

/**
 * RDP bulk decompression: the type in the compression flags of each block chooses MPPC 64 KB (1), NCRUSH (2, RDP 6.0)
 * or XCRUSH (3, RDP 6.1). The server never uses a type above the one the client announced; each keeps its own history.
 */
final class Bulk
{
    /** Types the client can announce (Client Info flags, CompressionTypeMask). */
    public const LEVELS = ['mppc' => 1, 'rdp6' => 2, 'rdp61' => 3];

    /** Level to announce for the 'bulk_compression' setting: true or 'mppc', 'rdp6', 'rdp61'; false or anything else = 0 (off). */
    public static function level(mixed $setting): int
    {
        return $setting === true ? 1 : (self::LEVELS[$setting] ?? 0);
    }

    private ?Mppc $mppc = null;
    private ?Ncrush $ncrush = null;
    private ?Xcrush $xcrush = null;

    public function decompress(string $data, int $flags): string
    {
        if (!($flags & Mppc::COMPRESSED) && !($flags & 0x0F)) {   // not compressed and no history to update
            return $data;
        }
        return match ($flags & 0x0F) {
            1       => ($this->mppc ??= new Mppc())->decompress($data, $flags),
            2       => ($this->ncrush ??= new Ncrush())->decompress($data, $flags),
            3       => ($this->xcrush ??= new Xcrush())->decompress($data, $flags),
            default => throw new ProtocolException('Bulk compression type ' . ($flags & 0x0F) . ' not supported', 'protocol'),
        };
    }
}
