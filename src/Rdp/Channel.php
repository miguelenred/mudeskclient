<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

/** Static virtual channel handler. onData receives complete (reassembled) messages. */
interface Channel
{
    public function name(): string;

    public function onData(string $data): void;
}
