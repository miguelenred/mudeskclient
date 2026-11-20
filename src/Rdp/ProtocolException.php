<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

/** Protocol or connection error. $key is a short code shown (translated) to the user. */
final class ProtocolException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $key = 'protocol')
    {
        parent::__construct($message);
    }
}
