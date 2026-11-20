<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/** UI texts live in public/assets/i18n.js; the server only returns error keys. */
final class I18n
{
    public const LANGS = ['es', 'en'];

    /** The first of our languages in an Accept-Language header, else English (browserLang() in i18n.js does the same). */
    public static function accept(string $header): string
    {
        foreach (explode(',', $header) as $l) {
            $l = strtolower(substr(trim($l), 0, 2));
            if (in_array($l, self::LANGS, true)) {
                return $l;
            }
        }
        return 'en';
    }
}
