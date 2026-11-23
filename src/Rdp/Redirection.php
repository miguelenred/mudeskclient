<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

/**
 * The server (a connection broker) sent the client elsewhere (MS-RDPBCGR 2.2.13, Server Redirection PDU): the session is
 * connected again to $info's target, with its routing token and session id (see Client::redirected()).
 */
final class Redirection extends \RuntimeException
{
    /** @param array{session:int, flags:int, address:?string, addresses:list<string>, fqdn:?string, netbios:?string, lb:?string, user:?string, domain:?string, cookie:?string} $info */
    public function __construct(public readonly array $info)
    {
        parent::__construct('Server redirection');
    }
}
