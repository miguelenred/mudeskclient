<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

use MuDesk\{Audit, Files, Settings, Text};

/**
 * Device redirection (MS-RDPEFS / MS-RDPEPC):
 *  - drive: the user's shared folder appears as the "MuDesk" drive in the remote session;
 *  - a folder of this computer, chosen in the browser while the session runs (with the shared folder's option): another drive,
 *    whose requests the browser answers (localdrive.js) and the gateway only passes on, keeping the files read, written,
 *    deleted and renamed for the audit;
 *  - printer: the "MuDeskPDF" printer (driver "Microsoft Print To PDF"); each job is offered to the browser to print or download.
 */
final class Rdpdr implements Channel
{
    private const OK = 0;
    private const NO_MORE_FILES = 0x80000006;
    private const UNSUCCESSFUL = 0xC0000001;
    private const INVALID_PARAMETER = 0xC000000D;
    private const NO_SUCH_DEVICE = 0xC000000E;
    private const NO_SUCH_FILE = 0xC000000F;
    private const ACCESS_DENIED = 0xC0000022;
    private const COLLISION = 0xC0000035;
    private const PATH_NOT_FOUND = 0xC000003A;
    private const DISK_FULL = 0xC000007F;
    private const IS_DIRECTORY = 0xC00000BA;
    private const NOT_SUPPORTED = 0xC00000BB;
    private const NOT_EMPTY = 0xC0000101;
    private const NOT_DIRECTORY = 0xC0000103;
    private const DRIVE = 1;
    private const PRINTER = 2;
    private const LOCAL = 3;
    /** Requests to this computer's folder the browser may owe at once (each answered, or the session's devices stall) */
    private const LOCAL_WAIT_MAX = 4096;
    private const PRINT_DRIVER = 'Microsoft Print To PDF';
    private const PRINT_MAX = 200 * 1048576;   // all the print jobs of a session together (kept until it ends)
    /** Quota taken from the user's counter at a time (shared with the other sessions and the file manager: a lock at every write was slow) */
    private const BATCH = 8 * 1048576;

    private string $root;
    private array $handles = [];
    private int $nextId = 1;
    private int $created = 0;                     // files and folders created by this session
    private int $audited = 0;                     // audit lines of this session
    private const MAX_HANDLES = 1024, MAX_STREAMS = 64, MAX_CREATED = 20000, MAX_AUDIT = 2000;
    private const TOO_MANY_FILES = 0xC000011F;    // STATUS_TOO_MANY_OPENED_FILES
    private bool $announced = false;
    private int $quota;                  // bytes this session took in advance and may still write (PHP_INT_MAX: no quota)
    private int $device = self::DRIVE;
    private array $jobs = [];
    private int $jobNo = 0;
    private int $listed = 0;                 // names of the folder listings kept (all handles)
    private const LIST_MAX = 200000;
    private const JOBS_MAX = 500;            // print jobs of a session (each one a file)
    private int $printed = 0;            // bytes of all the print jobs of the session
    /** @var array{name:string, readonly:bool}|null this computer's folder, shared from the browser */
    private ?array $local = null;
    private bool $listSent = false;      // the devices were announced: another one is announced on its own
    /**
     * @var array<int, array{m:int, f:int, p:?string, r:int, x:mixed}> its requests the browser has not answered: completion id =>
     * major, file id, path (a create's, a rename's), the number it went to the browser with, what the audit takes if it is done
     */
    private array $localWaiting = [];
    /** @var array<int, int> its change notifications, never answered (as the shared folder's): completion id => file id */
    private array $localNotify = [];
    /** @var array<int, array{path:string, read:int, written:int, created:bool, overwritten:bool, delete:bool}> its files open, for the audit */
    private array $localFiles = [];
    /** @var array<int, array{class:int, entries:list<string>, i:int, bytes:int}> its folders' listings the browser gave, by FileId */
    private array $localLists = [];
    private int $localListed = 0;        // names of those listings kept (all of them: LIST_MAX at most)
    private int $localListBytes = 0;     // ... and their bytes (LIST_BYTES_MAX at most)
    private const LIST_BYTES_MAX = 32 * 1048576;
    /** A path kept for the audit (bytes of UTF-16): it cuts them to 512 characters; a server's 64 KB paths held hundreds of MB */
    private const LOCAL_PATH_MAX = 2048;
    /** Each request to the browser numbered (in this process: also across the connections made again); its answer must say it */
    private static int $localNo = 0;

    /**
     * Windows shows the shared folder as "<drive name> on <client name>" ("en" in Spanish, its own word): "Carpeta compartida
     * en MuDesk" (before: "MuDesk en MUDESK"). The client name is also Windows' CLIENTNAME and is said with the printer.
     */
    public const CLIENT_NAME = 'MuDesk';

    /** $driveName: the shared folder's name in the session, in the user's language (8-bit text: accents are left out). */
    public function __construct(private Client $c, private string $uid, private bool $drive, private bool $printer, private string $printDir,
                                private string $sid = '', private string $conn = '', private string $driveName = 'Carpeta compartida')
    {
        $this->driveName = trim((string)preg_replace('/[^\x20-\x7E]+/', '', $driveName)) ?: 'MuDesk';
        $this->root  = Files::root($uid);
        $this->quota = Files::quotaLeft($uid) === PHP_INT_MAX ? PHP_INT_MAX : 0;
    }

    /** Gives back the quota taken in advance beyond $keep bytes (all of it when the session ends). */
    public function release(int $keep = 0): void
    {
        if ($this->quota !== PHP_INT_MAX && $this->quota > $keep) {
            Files::charge($this->uid, $keep - $this->quota);
            $this->quota = $keep;
        }
    }

    public function name(): string
    {
        return 'rdpdr';
    }

    private function send(int $packet, string $data): void
    {
        $this->c->sendChannel('rdpdr', pack('vv', 0x4472, $packet) . $data);
    }

    public function onData(string $data): void
    {
        $r = new Reader($data);
        if ($r->u16() !== 0x4472) {
            return;
        }
        $id = $r->u16();
        if ($id !== 0x4952) {
            $this->c->trace?->log('rdpdr', ['msg' => [0x496E => 'serverAnnounce', 0x5350 => 'serverCapabilities', 0x4343 => 'clientIdConfirm',
                0x554C => 'userLoggedOn', 0x6472 => 'deviceReply'][$id] ?? sprintf('0x%04x', $id), 'data' => Trace::hex(substr($data, 4), 64)]);
        }
        switch ($id) {
            case 0x496E: // server announce
                $this->drive   = $this->drive && !in_array('drive', $this->c->denied, true);       // (forbidden by the
                $this->printer = $this->printer && !in_array('printer', $this->c->denied, true);   // gateway's policy)
                $r->skip(4);
                $clientId = $r->u32();
                $this->send(0x4343, pack('vvV', 1, 0x000C, $clientId));
                $name = Text::toUtf16(self::CLIENT_NAME) . "\0\0";
                $this->send(0x434E, pack('VVV', 1, 0, strlen($name)) . $name);
                break;
            case 0x5350: // server capabilities
                $this->send(0x4350, pack('vv', $this->printer ? 3 : 2, 0)
                    . pack('vvVVVvvVVVVVV', 1, 44, 2, 0, 0, 1, 0x000C, 0xFFFF, 0, 0x7, 0, 0, 0)
                    . ($this->printer ? pack('vvV', 2, 8, 1) : '')        // printer capability, version 1
                    . pack('vvV', 4, 8, 2));
                break;
            case 0x4343: // client id confirm
                $this->announce();
                break;
            case 0x554C: // user logged on
                $this->announce();
                break;
            case 0x6472: // device reply
                $this->announced = true;
                break;
            case 0x4952: // I/O request
                if (strlen($data) >= 24 && unpack('V', $data, 4)[1] === self::LOCAL) {
                    $this->localIo($data);
                } else {
                    $this->io($r);
                }
                break;
        }
    }

    private function announce(): void
    {
        if ($this->announced) {
            return;
        }
        $list = [];
        if ($this->drive) {
            $label  = $this->driveName . "\0";   // drive name: 8-bit text (Windows showed the UTF-16 name as "M")
            $list[] = pack('VV', 0x08, self::DRIVE) . str_pad('MUDESK', 8, "\0") . pack('V', strlen($label)) . $label;
        }
        if ($this->printer) {
            $driver = Text::toUtf16(self::PRINT_DRIVER) . "\0\0";
            $name   = Text::toUtf16('MuDeskPDF') . "\0\0";
            $data   = pack('VVVVVV', 0, 0, 0, strlen($driver), strlen($name), 0) . $driver . $name;
            $list[] = pack('VV', 0x04, self::PRINTER) . str_pad('PRN2', 8, "\0") . pack('V', strlen($data)) . $data;
        }
        if ($this->drive && $this->local !== null) {
            $list[] = $this->localEntry();
        }
        if ($list) {
            $this->send(0x4441, pack('V', count($list)) . implode('', $list));
            $this->listSent = true;
        }
    }

    // ------------------------------------------------------------ this computer's folder (answered by the browser)

    /** Its DEVICE_ANNOUNCE: a drive named as the folder ("<name> on MuDesk" in Windows), 8-bit text as the shared folder's. */
    private function localEntry(): string
    {
        $label = $this->local['name'] . "\0";
        return pack('VV', 0x08, self::LOCAL) . str_pad('LOCAL', 8, "\0") . pack('V', strlen($label)) . $label;
    }

    /**
     * A folder of this computer chosen in the browser (its name; read only where the browser cannot write it): announced
     * now, or with the devices when the server asks for them. Only with the shared folder's option (and the gateway's
     * policy that allows drives); one at a time, a new one in place of the one before. Returns whether it was taken.
     */
    public function addLocal(string $name, bool $readonly): bool
    {
        $allowed = $this->drive && !in_array('drive', $this->c->denied, true);
        if (!$allowed) {
            return false;
        }
        $this->removeLocal();
        $name        = trim((string)preg_replace('/[^\x20-\x7E]+/', '', $name)) ?: 'Folder';
        $this->local = ['name' => substr($name, 0, 64), 'readonly' => $readonly];
        $this->audit('local_folder_shared', ['folder' => $this->local['name'], 'read_only' => $readonly]);
        if ($this->listSent) {
            $this->send(0x4441, pack('V', 1) . $this->localEntry());
        }
        return true;
    }

    /**
     * The folder no longer shared (or another one in its place): what it owed answered, then the drive removed. With
     * $connected false (the session ended, or its connection is made again), only its files still open audited: the browser
     * saves what they wrote all the same.
     */
    public function removeLocal(bool $connected = true): void
    {
        if ($this->local === null) {
            return;
        }
        if ($connected) {
            foreach ($this->localWaiting + array_fill_keys(array_keys($this->localNotify), ['m' => 0x0C]) as $cid => $w) {
                $this->send(0x4943, pack('VVV', self::LOCAL, $cid, self::NO_SUCH_DEVICE) . (in_array($w['m'], [0x03, 0x05, 0x0A], true) ? pack('V', 0) : pack('VC', 0, 0)));
            }
        }
        foreach ($this->localFiles as $f) {
            $this->localAudit($f);
        }
        if ($connected && $this->listSent) {
            $this->send(0x444D, pack('VV', 1, self::LOCAL));    // DEVICELIST_REMOVE
        }
        $this->audit('local_folder_unshared', ['folder' => $this->local['name']]);
        [$this->local, $this->localWaiting, $this->localNotify, $this->localFiles, $this->localLists, $this->localListed, $this->localListBytes] = [null, [], [], [], [], 0, 0];
    }

    /**
     * A request to the folder: passed to the browser (F_DRIVE: u32 its number, then the request from its DeviceId on), what
     * the audit needs kept. A change notification is left waiting here, as for the shared folder.
     */
    private function localIo(string $data): void
    {
        ['f' => $fileId, 'c' => $cid, 'm' => $major, 'n' => $minor] = unpack('Vf/Vc/Vm/Vn', $data, 8);
        $this->c->trace?->count(sprintf('rdpdr:local:irp%02x.%x', $major, $minor), strlen($data) - 24);
        $this->device = self::LOCAL;
        if ($this->local === null) {
            $this->reply($cid, self::NO_SUCH_DEVICE, str_repeat("\0", 5));
            return;
        }
        if ($major === 0x0C && $minor === 0x02) {
            // a change notification: never answered, as for the shared folder (the browser could not say when a folder
            // changes); kept only to be answered if the folder goes, forgotten when its file closes, and not counted with
            // the requests the browser owes (a program watching thousands of folders stopped the drive)
            if (count($this->localNotify) < self::LOCAL_WAIT_MAX) {
                $this->localNotify[$cid] = $fileId;
            }
            return;
        }
        if (count($this->localWaiting) >= self::LOCAL_WAIT_MAX) {
            $this->reply($cid, self::TOO_MANY_FILES, str_repeat("\0", 5));
            return;
        }
        $path = $extra = null;
        if ($major === 0x00 && strlen($data) >= 56) {       // create: its path, for the audit; deleted when closed (option)
            $len   = unpack('V', $data, 52)[1];
            $path  = Text::fromUtf16(substr($data, 56, min($len, self::LOCAL_PATH_MAX)));
            $extra = (unpack('V', $data, 48)[1] & 0x1000) !== 0;   // FILE_DELETE_ON_CLOSE
        } elseif ($major === 0x06 && strlen($data) >= 32 && isset($this->localFiles[$fileId])) {   // set information
            ['k' => $class, 'l' => $len] = unpack('Vk/Vl', $data, 24);
            if ($class === 13) {                             // disposition: deleted when closed (no byte, or not 0)
                $extra = ['delete' => $len === 0 || strlen($data) < 57 || ord($data[56]) !== 0];
            } elseif ($class === 20 && strlen($data) >= 64) {   // end of file: emptied at 0
                $extra = ['emptied' => unpack('P', $data, 56)[1] === 0];
            } elseif ($class === 10 && strlen($data) >= 62) {   // rename: ReplaceIfExists, RootDirectory, the new path
                $path = Text::fromUtf16(substr($data, 62, min(unpack('V', $data, 58)[1], self::LOCAL_PATH_MAX)));
            }
        }
        if ($major === 0x0C && $minor === 0x01 && strlen($data) >= 29) {   // a folder listed: one name a request
            $class = unpack('V', $data, 24)[1];
            $l     = $this->localLists[$fileId] ?? null;
            if (ord($data[28]) !== 0) {
                $this->dropList($fileId);                    // (listed anew: the browser gives the new listing)
            } elseif ($l !== null && $l['class'] === $class) {
                // the next name of the listing the browser gave with the first one, answered here: a request each name was a
                // round trip to the browser (a folder of 1,000 files took a minute at 60 ms); at its end, no more names
                $e = $l['entries'][$l['i']] ?? null;
                if ($e !== null) {
                    $this->localLists[$fileId]['i']++;
                }
                $e === null ? $this->reply($cid, self::NO_MORE_FILES, pack('VC', 0, 0)) : $this->reply($cid, self::OK, pack('V', strlen($e)) . $e);
                return;
            }
        } elseif ($major === 0x02) {                         // closed: its listing and its notifications go
            $this->dropList($fileId);
            if ($this->localNotify) {
                $this->localNotify = array_filter($this->localNotify, static fn(int $f): bool => $f !== $fileId);
            }
        }
        $no = self::$localNo = self::$localNo % 0xFFFFFFFF + 1;
        $this->localWaiting[$cid] = ['m' => $major, 'f' => $fileId, 'p' => $path, 'r' => $no, 'x' => $extra];
        $this->c->emit(Client::F_DRIVE, pack('V', $no) . substr($data, 4));
    }

    private function dropList(int $fileId): void
    {
        if (isset($this->localLists[$fileId])) {
            $this->localListed    -= count($this->localLists[$fileId]['entries']);
            $this->localListBytes -= $this->localLists[$fileId]['bytes'];
            unset($this->localLists[$fileId]);
        }
    }

    /**
     * The browser's answer to the first request of a folder's listing, with the rest of its names (u32 the request's number,
     * u32 answer's length, the answer, u32 FileId, u32 information class, u32 count, each name's information as u32 length +
     * bytes): the names kept for the next requests of that listing, answered here; then the answer, as any other.
     */
    public function localList(string $m): void
    {
        if (strlen($m) < 8 || strlen($m) < 8 + ($n = unpack('V', $m, 4)[1]) + 12) {
            return;
        }
        $no   = unpack('V', $m)[1];
        $resp = substr($m, 8, $n);
        $r    = new Reader(substr($m, 8 + $n));
        try {
            [$fileId, $class, $count] = [$r->u32(), $r->u32(), $r->u32()];
            $cid = strlen($resp) >= 8 ? unpack('V', $resp, 4)[1] : -1;
            $w   = $this->localWaiting[$cid] ?? null;
            if ($w !== null && $w['r'] === $no && $w['m'] === 0x0C && $w['f'] === $fileId && $this->localListed + $count <= self::LIST_MAX) {
                [$entries, $bytes] = [[], 0];
                for ($i = 0; $i < $count; $i++) {
                    $bytes += strlen($entries[] = $r->bytes($r->u32()));
                }
                if ($this->localListBytes + $bytes <= self::LIST_BYTES_MAX) {
                    $this->dropList($fileId);
                    $this->localLists[$fileId] = ['class' => $class, 'entries' => $entries, 'i' => 0, 'bytes' => $bytes];
                    $this->localListed        += $count;
                    $this->localListBytes     += $bytes;
                }
            }
        } catch (ProtocolException) {                        // (cut short: the names asked for one by one, as before)
        }
        $this->localAnswer($no, $resp);
    }

    /** The browser's answer to one of them: u32 the request's number, then a Device I/O Response from its DeviceId on. */
    public function localReply(string $m): void
    {
        if (strlen($m) >= 4) {
            $this->localAnswer(unpack('V', $m)[1], substr($m, 4));
        }
    }

    /**
     * Sent to the server if it answers a request waiting, by the number it went with (an answer of a folder shared before, or
     * of a connection before, is left out: the server may have given its CompletionId to another request since). The files
     * read, written, created, emptied and deleted are audited when closed, a rename when made; a deletion when the browser
     * took it.
     */
    private function localAnswer(int $no, string $resp): void
    {
        if (strlen($resp) < 12 || $this->local === null) {
            return;
        }
        ['d' => $dev, 'c' => $cid, 's' => $status] = unpack('Vd/Vc/Vs', $resp);
        $w = $this->localWaiting[$cid] ?? null;
        if ($dev !== self::LOCAL || $w === null || $w['r'] !== $no) {
            return;
        }
        unset($this->localWaiting[$cid]);
        ['m' => $major, 'f' => $fileId, 'p' => $path, 'x' => $extra] = $w;
        $ok   = $status === self::OK;
        $open = isset($this->localFiles[$fileId]);
        if ($major === 0x00 && $ok && strlen($resp) >= 17 && $path !== null) {         // created: FileId, Information
            ['i' => $id, 'x' => $info] = unpack('Vi/Cx', $resp, 12);
            $this->localFiles[$id] = ['path' => '/' . ltrim(str_replace('\\', '/', $path), '/'), 'read' => 0, 'written' => 0, 'created' => $info === 2,
                                      'overwritten' => $info === 3, 'delete' => $extra === true];
        } elseif ($major === 0x03 && $ok && $open && strlen($resp) >= 16) {
            $this->localFiles[$fileId]['read'] += unpack('V', $resp, 12)[1];
        } elseif ($major === 0x04 && $ok && $open && strlen($resp) >= 16) {
            $this->localFiles[$fileId]['written'] += unpack('V', $resp, 12)[1];
        } elseif ($major === 0x06 && $ok && $open && isset($extra['delete'])) {   // (one refused, a folder not empty: not deleted)
            $this->localFiles[$fileId]['delete'] = $extra['delete'];
        } elseif ($major === 0x06 && $ok && $open && !empty($extra['emptied'])) {
            $this->localFiles[$fileId]['overwritten'] = true;
        } elseif ($major === 0x06 && $ok && $open && $path !== null) {
            $to = '/' . ltrim(str_replace('\\', '/', $path), '/');
            $this->audit('local_rename', ['folder' => $this->local['name'], 'from' => $this->localFiles[$fileId]['path'], 'to' => $to]);
            $this->localFiles[$fileId]['path'] = $to;
        } elseif ($major === 0x02 && $open) {                                       // closed (a deletion that failed: not done)
            $this->localAudit($this->localFiles[$fileId], $ok);
            unset($this->localFiles[$fileId]);
        }
        $this->c->trace?->count('rdpdr:local:reply', strlen($resp));
        $this->send(0x4943, $resp);
    }

    /** A file of this computer's folder closed: audited if it was read, written, created, emptied or deleted (not when only looked at). */
    private function localAudit(array $f, bool $ok = true): void
    {
        if ($f['delete']) {
            $this->audit('local_delete', ['folder' => $this->local['name'], 'path' => $f['path']], $ok);
        } elseif ($f['read'] || $f['written'] || $f['created'] || $f['overwritten']) {
            $this->audit('local_file', ['folder' => $this->local['name'], 'path' => $f['path'], 'created' => $f['created'],
                                        'overwritten' => $f['overwritten'], 'read' => $f['read'], 'written' => $f['written']]);
        }
    }

    private function reply(int $completion, int $status, string $data = ''): void
    {
        if ($status !== self::OK && $status !== self::NO_MORE_FILES) {
            $this->c->trace?->count(sprintf('rdpdr:status:0x%08x', $status), 0);
        }
        $this->send(0x4943, pack('VVV', $this->device, $completion, $status) . $data);
    }

    /** Server path ("\dir\file") -> local path, or null if invalid. */
    private function local(string $path): ?string
    {
        $rel = Files::clean($path);
        $p   = $rel === null ? null : $this->root . ($rel === '' ? '' : '/' . $rel);
        return $p !== null && Files::confined($this->root, $p) ? $p : null;   // never out of the folder through a link
    }

    /**
     * Quota used ($bytes > 0; false when the user's has not that much left) or given back (< 0) by this session. Taken from
     * the user's counter a batch at a time; what the session frees beyond two batches goes back to it at once.
     */
    private function charge(int $bytes): bool
    {
        if ($this->quota === PHP_INT_MAX) {
            return true;
        }
        if ($bytes > $this->quota) {
            $need = $bytes - $this->quota;
            if (Files::charge($this->uid, $need + self::BATCH)) {
                $this->quota += $need + self::BATCH;
            } elseif (Files::charge($this->uid, $need)) {        // the last bytes of the quota
                $this->quota += $need;
            } else {
                return false;
            }
        }
        $this->quota -= $bytes;
        if ($this->quota > 2 * self::BATCH) {
            $this->release(self::BATCH);
        }
        return true;
    }

    private static function filetime(int $t): int
    {
        return ($t + 11644473600) * 10000000;
    }

    private static function attrs(string $p): int
    {
        return is_dir($p) ? 0x10 : 0x80;
    }

    private function io(Reader $r): void
    {
        clearstatcache(); // long-running process: file sizes change from the session and from web uploads
        $this->device = $r->u32();
        $fileId       = $r->u32();
        $cid          = $r->u32();
        $major        = $r->u32();
        $minor        = $r->u32();
        $this->c->trace?->count(sprintf('rdpdr:%s:irp%02x.%x', $this->device === self::PRINTER ? 'printer' : 'drive', $major, $minor), $r->left());
        if ($this->device === self::PRINTER ? !$this->printer : ($this->device !== self::DRIVE || !$this->drive)) {
            $this->reply($cid, self::NO_SUCH_DEVICE, str_repeat("\0", 5));   // only the devices announced
            return;
        }
        if ($this->device === self::PRINTER) {
            $this->printIo($r, $fileId, $cid, $major);
            return;
        }
        $h      = $this->handles[$fileId] ?? null;
        try {
            match ($major) {
                0x00    => $this->create($r, $cid),
                0x02    => $this->close($fileId, $cid),
                0x03    => $this->read($r, $fileId, $cid),
                0x04    => $this->write($r, $fileId, $cid),
                0x05    => $this->queryInfo($r, $h, $cid),
                0x06    => $this->setInfo($r, $fileId, $cid),
                0x0A    => $this->volumeInfo($r, $cid),
                0x0C    => $minor === 0x01 ? $this->queryDir($r, $fileId, $cid) : null, // change notifications stay pending
                0x11    => $this->reply($cid, self::OK, str_repeat("\0", 5)),
                0x0E    => $this->reply($cid, self::NOT_SUPPORTED, pack('V', 0)),
                default => $this->reply($cid, self::NOT_SUPPORTED, str_repeat("\0", 5)),
            };
        } catch (ProtocolException $e) {
            throw $e;
        } catch (\Throwable) {
            $this->reply($cid, self::UNSUCCESSFUL, str_repeat("\0", 5));
        }
    }

    /**
     * Drive/printer audit: path inside the user's drive, never file contents. At most MAX_AUDIT lines a session (then one
     * that says so), paths cut to 512 characters: a server looping file operations filled the log under its lock.
     */
    private function audit(string $event, array $d, bool $ok = true): void
    {
        if (++$this->audited > self::MAX_AUDIT) {
            if ($this->audited === self::MAX_AUDIT + 1) {
                Audit::log('drive_audit_cut', ['sid' => $this->sid, 'conn' => $this->conn, 'lines' => self::MAX_AUDIT], null, false);
            }
            return;
        }
        $d = array_map(static fn($v) => is_string($v) && strlen($v) > 512 ? substr($v, 0, 512) . '…' : $v, $d);
        Audit::log($event, ['sid' => $this->sid, 'conn' => $this->conn] + $d, null, $ok);
    }

    private function rel(string $p): string
    {
        return '/' . ltrim(substr($p, strlen($this->root)), '/');
    }

    private function create(Reader $r, int $cid): void
    {
        if (count($this->handles) >= self::MAX_HANDLES) {   // a process has some 8000 file handles for all its sessions
            $this->reply($cid, self::TOO_MANY_FILES, str_repeat("\0", 5));
            return;
        }
        $access = $r->u32();
        $r->skip(8);
        $attrs       = $r->u32();
        $r->skip(4);
        $disposition = $r->u32();
        $options     = $r->u32();
        $path        = Text::fromUtf16($r->bytes($r->u32()));
        $p           = $this->local($path);
        $fail        = pack('VC', 0, 0);
        if ($p === null) {
            $this->reply($cid, self::ACCESS_DENIED, $fail);
            return;
        }
        $exists = file_exists($p);
        $isDir  = is_dir($p);
        $wantDir = (bool)($options & 0x1) || (!$exists && ($attrs & 0x10));
        if (!is_dir(dirname($p))) {
            $this->reply($cid, self::PATH_NOT_FOUND, $fail);
            return;
        }
        if ($exists && $disposition === 2) {
            $this->reply($cid, self::COLLISION, $fail);
            return;
        }
        if (!$exists && in_array($disposition, [1, 4], true)) {
            $this->reply($cid, self::NO_SUCH_FILE, $fail);
            return;
        }
        if ($exists && ($options & 0x1) && !$isDir) {
            $this->reply($cid, self::NOT_DIRECTORY, $fail);
            return;
        }
        if ($exists && ($options & 0x40) && $isDir) {
            $this->reply($cid, self::IS_DIRECTORY, $fail);
            return;
        }
        $info = 1;
        if (!$exists && ++$this->created > self::MAX_CREATED) {   // empty files and folders cost no quota: not millions of them
            $this->reply($cid, self::ACCESS_DENIED, $fail);
            return;
        }
        if (!$exists) {
            $ok   = $wantDir ? @mkdir($p) : @touch($p);
            $info = 2;
            if ($wantDir) {
                $this->audit('drive_mkdir', ['path' => $this->rel($p)], $ok);
            }
            if (!$ok) {
                $this->reply($cid, self::ACCESS_DENIED, $fail);
                return;
            }
        } elseif (!$isDir && in_array($disposition, [0, 4, 5], true)) {
            $was = (int)@filesize($p);                     // emptied first, then its size given back (a file that could not be
            if (@file_put_contents($p, '') === false) {    // emptied gave it back on every open: the quota went to 0)
                $this->reply($cid, self::ACCESS_DENIED, $fail);
                return;
            }
            $this->charge(-$was);
            $info = 3;
        }
        $id                 = $this->nextId++;
        $this->handles[$id] = ['path' => $p, 'fh' => null, 'write' => (bool)($access & 0x40000006), 'delete' => (bool)($options & 0x1000), 'list' => null,
                               'new' => !$exists && !$wantDir, 'truncated' => $info === 3, 'read' => 0, 'written' => 0];
        $this->reply($cid, self::OK, pack('VC', $id, $info));
    }

    private function fh(array &$h, bool $write)
    {
        if ($h['fh'] === null || ($write && !$h['write'])) {
            if ($h['fh']) {
                fclose($h['fh']);
            } elseif (count(array_filter($this->handles, static fn($x) => $x['fh'] !== null)) >= self::MAX_STREAMS) {
                foreach ($this->handles as &$o) {            // the stream opened first is closed (it opens again when used)
                    if ($o['fh'] !== null && $o !== $h) {
                        fclose($o['fh']);
                        $o['fh'] = null;
                        break;
                    }
                }
                unset($o);
            }
            $h['write'] = $h['write'] || $write;
            $h['fh']    = @fopen($h['path'], $h['write'] ? 'c+b' : 'rb') ?: null;
        }
        return $h['fh'];
    }

    private function close(int $id, int $cid): void
    {
        $h = $this->handles[$id] ?? null;
        if ($h) {
            if ($h['fh']) {
                fclose($h['fh']);
            }
            if ($h['delete']) {
                $dir  = is_dir($h['path']);
                $size = $dir ? 0 : (int)@filesize($h['path']);
                $ok   = $dir ? @rmdir($h['path']) : @unlink($h['path']);
                $this->charge($ok ? -$size : 0);
                $this->audit($dir ? 'drive_rmdir' : 'drive_delete', ['path' => $this->rel($h['path'])], $ok);
            } elseif ($h['read'] || $h['written'] || $h['new'] || $h['truncated']) {   // metadata-only opens (Explorer browsing) are not logged
                $this->audit('drive_file', ['path' => $this->rel($h['path']), 'created' => $h['new'], 'overwritten' => $h['truncated'],
                                            'read' => $h['read'], 'written' => $h['written'], 'size' => (int)@filesize($h['path'])]);
            }
            $this->listed -= count($h['list']['names'] ?? []);
            unset($this->handles[$id]);
        }
        $this->reply($cid, self::OK, str_repeat("\0", 5));
    }

    private function read(Reader $r, int $id, int $cid): void
    {
        $len = min($r->u32(), 1048576);
        $off = $r->u64();
        $fh  = isset($this->handles[$id]) && is_file($this->handles[$id]['path']) ? $this->fh($this->handles[$id], false) : null;
        if ($fh === null) {
            $this->reply($cid, self::UNSUCCESSFUL, pack('V', 0));
            return;
        }
        if ($off < 0) {                                     // 2^63 or more (negative in PHP): no data from elsewhere
            $this->reply($cid, self::INVALID_PARAMETER, pack('V', 0));
            return;
        }
        // nothing to read at or past the end, without seeking there: Linux refuses positions past the largest file it can
        // hold (and Windows reads near 2^63), and the size is the open file's, not a stat of its name
        if ($off >= (int)(fstat($fh)['size'] ?? 0)) {
            $this->reply($cid, self::OK, pack('V', 0));
            return;
        }
        if (fseek($fh, $off) !== 0) {                       // refused: no data from elsewhere
            $this->reply($cid, self::INVALID_PARAMETER, pack('V', 0));
            return;
        }
        $data                          = $len > 0 ? (string)fread($fh, $len) : '';
        $this->handles[$id]['read']   += strlen($data);
        $this->reply($cid, self::OK, pack('V', strlen($data)) . $data);
    }

    private function write(Reader $r, int $id, int $cid): void
    {
        $len = $r->u32();
        $off = $r->u64();
        if ($off < 0) {                                     // 2^63 or more: fseek() would fail and fwrite() append, uncharged
            $this->reply($cid, self::INVALID_PARAMETER, pack('VC', 0, 0));
            return;
        }
        $r->skip(20);
        $data = $r->bytes(min($len, $r->left()));
        $fh   = isset($this->handles[$id]) ? $this->fh($this->handles[$id], true) : null;
        // its end past 2^63 (no integer: the session ended with a TypeError), or a position the system refuses (Linux, past
        // the largest file it holds: fwrite() then wrote where the file was, after charging the quota)
        if ($fh !== null && ($off > PHP_INT_MAX - strlen($data) || fseek($fh, $off) !== 0)) {
            $this->reply($cid, self::INVALID_PARAMETER, pack('VC', 0, 0));
            return;
        }
        $grow = $fh === null ? 0 : max(0, $off + strlen($data) - (int)fstat($fh)['size']);
        if ($fh === null || !$this->charge($grow)) {
            if ($fh !== null && empty($this->handles[$id]['full'])) {   // once per file: a server repeating it wrote MB/s of log
                $this->handles[$id]['full'] = true;
                $this->audit('drive_quota_full', ['path' => $this->rel($this->handles[$id]['path'])], false);
            }
            $this->reply($cid, $fh === null ? self::ACCESS_DENIED : self::DISK_FULL, pack('VC', 0, 0));
            return;
        }
        $n            = (int)fwrite($fh, $data);
        $this->handles[$id]['written'] += $n;
        $this->reply($cid, self::OK, pack('VC', $n, 0));
    }

    private function basic(string $p): string
    {
        $t = self::filetime((int)@filemtime($p));
        return pack('PPPPV', $t, $t, $t, $t, self::attrs($p));
    }

    private function standard(string $p): string
    {
        $size = is_file($p) ? (int)filesize($p) : 0;
        return pack('PPVCC', $size, $size, 1, 0, is_dir($p) ? 1 : 0);
    }

    private function queryInfo(Reader $r, ?array $h, int $cid): void
    {
        $class = $r->u32();
        if ($h === null || !file_exists($h['path'])) {
            $this->reply($cid, self::NO_SUCH_FILE, pack('V', 0));
            return;
        }
        $p   = $h['path'];
        $buf = match ($class) {
            4       => $this->basic($p),
            5       => $this->standard($p),
            35      => pack('VV', self::attrs($p), 0),
            default => null,
        };
        $buf === null ? $this->reply($cid, self::NOT_SUPPORTED, pack('V', 0)) : $this->reply($cid, self::OK, pack('V', strlen($buf)) . $buf);
    }

    private function setInfo(Reader $r, int $id, int $cid): void
    {
        $class = $r->u32();
        $len   = $r->u32();
        $r->skip(24);
        $h = $this->handles[$id] ?? null;
        if ($h === null) {
            $this->reply($cid, self::UNSUCCESSFUL, pack('V', 0));
            return;
        }
        $status = self::OK;
        switch ($class) {
            case 19: // allocation
            case 20: // end of file
                $size = $r->u64();
                $cur  = is_file($h['path']) ? (int)filesize($h['path']) : 0;
                $set  = $class === 20 || $size < $cur;     // the allocation only shrinks the file
                if ($size < 0 || ($set && !$this->charge($size - $cur))) {
                    $status = self::DISK_FULL;
                } elseif ($set && !(($fh = $this->fh($this->handles[$id], true)) && ftruncate($fh, $size))) {
                    $this->charge($cur - $size);           // not changed: the quota goes back
                }
                break;
            case 13: // disposition (delete)
                $del = $len === 0 || $r->u8() !== 0;
                if ($del && is_dir($h['path']) && count(@scandir($h['path']) ?: []) > 2) {
                    $status = self::NOT_EMPTY;
                } else {
                    $this->handles[$id]['delete'] = $del;
                }
                break;
            case 10: // rename
                $replace = $r->u8() !== 0;
                $r->skip(1);
                $target = $this->local(Text::fromUtf16($r->bytes($r->u32())));
                // the target may be the file itself under another name: another case (accents too: strcasecmp knows ASCII
                // only) or its 8.3 short name; giving its size back made the quota endless
                $same = $target !== null && file_exists($target) && realpath($target) === realpath($h['path']);
                if ($target === null) {
                    $status = self::ACCESS_DENIED;
                } elseif (file_exists($target) && !$same && !$replace) {
                    $status = self::COLLISION;
                } else {
                    if ($h['fh']) {
                        fclose($h['fh']);
                        $this->handles[$id]['fh'] = null;
                    }
                    $old = is_file($target) && !$same ? (int)filesize($target) : 0;   // a replaced file gives its quota back
                    $ok  = @rename($h['path'], $target);
                    $this->charge($ok ? -$old : 0);
                    $this->audit('drive_rename', ['from' => $this->rel($h['path']), 'to' => $this->rel($target)], $ok);
                    if ($ok) {
                        $this->handles[$id]['path'] = $target;
                    } else {
                        $status = self::ACCESS_DENIED;
                    }
                }
                break;
        }
        $this->reply($cid, $status, pack('V', $len));
    }

    private function volumeInfo(Reader $r, int $cid): void
    {
        $class = $r->u32();
        $limit = $this->quota === PHP_INT_MAX ? 0 : Settings::get('files_quota_mb') * 1048576;
        $free  = max(0, (int)min($limit === 0 ? (int)@disk_free_space($this->root) : $this->quota + Files::quotaLeft($this->uid), 1 << 50));
        $total = $limit === 0 ? $free + Files::usage($this->uid) : max($limit, $free);
        $label = Text::toUtf16($this->driveName);       // the volume label (the drive's properties)
        $fs    = Text::toUtf16('NTFS');
        $buf   = match ($class) {
            1       => pack('PVVCx', self::filetime(time()), 0x4D554445, strlen($label), 0) . $label,
            3       => pack('PPVV', intdiv($total, 4096), intdiv($free, 4096), 8, 512),
            5       => pack('VVV', 0x00000007, 255, strlen($fs)) . $fs,
            7       => pack('PPPVV', intdiv($total, 4096), intdiv($free, 4096), intdiv($free, 4096), 8, 512),
            4       => pack('VV', 7, 0),
            default => null,
        };
        $buf === null ? $this->reply($cid, self::NOT_SUPPORTED, pack('V', 0)) : $this->reply($cid, self::OK, pack('V', strlen($buf)) . $buf);
    }

    private function queryDir(Reader $r, int $id, int $cid): void
    {
        $class   = $r->u32();
        $initial = $r->u8() !== 0;
        $plen    = $r->u32();
        $r->skip(23);
        $pattern = Text::fromUtf16($r->bytes($plen));
        if (!isset($this->handles[$id])) {
            $this->reply($cid, self::UNSUCCESSFUL, pack('VC', 0, 0));
            return;
        }
        if ($initial || $this->handles[$id]['list'] === null) {
            $norm   = str_replace('\\', '/', $pattern);
            $mask   = basename($norm) ?: '*';
            if (strlen($mask) > 255 || substr_count($mask, '*') > 4) {   // a regular expression that would backtrack for every name
                $mask = "\0";
            }
            $dir    = $this->local(dirname($norm) === '.' ? '' : dirname($norm));
            $names  = $dir !== null && is_dir($dir) ? (@scandir($dir) ?: []) : [];
            $regex  = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($mask, '/')) . '$/iD';
            $this->listed -= count($this->handles[$id]['list']['names'] ?? []);
            $list   = array_values(array_filter($names, static fn($n) => (bool)preg_match($regex, $n)));
            if ($this->listed + count($list) > self::LIST_MAX) {   // listings kept across handles: a server listing one big folder
                $this->handles[$id]['list'] = null;              // on 1,000 handles held gigabytes
                $this->reply($cid, self::TOO_MANY_FILES, pack('VC', 0, 0));
                return;
            }
            $this->listed += count($list);
            $this->handles[$id]['list'] = ['dir' => $dir, 'names' => $list, 'i' => 0];   // names walked by index (array_shift was O(n))
        }
        $l    = &$this->handles[$id]['list'];
        $name = $l['names'][$l['i']] ?? null;
        if ($name === null) {
            $this->listed -= count($l['names']);
            $l['names'] = [];                              // done: its names go
            $l['i'] = 0;
            unset($l);
            $this->reply($cid, self::NO_MORE_FILES, pack('VC', 0, 0));
            return;
        }
        $l['i']++;
        $p = $l['dir'] . '/' . $name;
        unset($l);
        $n    = Text::toUtf16($name);
        $t    = self::filetime((int)@filemtime($p));
        $size = is_file($p) ? (int)filesize($p) : 0;
        $common = pack('VVPPPPPPV', 0, 0, $t, $t, $t, $t, $size, $size, self::attrs($p));
        $buf  = match ($class) {
            1       => $common . pack('V', strlen($n)) . $n,
            2       => $common . pack('VV', strlen($n), 0) . $n,
            3       => $common . pack('VVC', strlen($n), 0, 0) . str_repeat("\0", 24) . $n, // no Reserved byte: Windows reads it this way
            12      => pack('VVV', 0, 0, strlen($n)) . $n,
            default => null,
        };
        $buf === null ? $this->reply($cid, self::NOT_SUPPORTED, pack('VC', 0, 0)) : $this->reply($cid, self::OK, pack('V', strlen($buf)) . $buf);
    }

    // ------------------------------------------------------------ printer

    private function printIo(Reader $r, int $fileId, int $cid, int $major): void
    {
        switch ($major) {
            case 0x00: // create: a new print job
                if ($this->jobNo === 0) {               // numbered after those of this session before a reconnection (not overwritten)
                    foreach (glob($this->printDir . '/print-*') ?: [] as $f) {
                        $this->jobNo = max($this->jobNo, (int)substr((string)strrchr($f, '-'), 1));
                    }
                }
                if (count($this->jobs) >= 32 || $this->jobNo >= self::JOBS_MAX) {
                    $this->reply($cid, self::TOO_MANY_FILES, pack('VC', 0, 0));
                    break;
                }
                $id              = ++$this->jobNo;
                $path            = $this->printDir . '/print-' . $id;
                if (!is_dir($this->printDir)) {
                    @mkdir($this->printDir, 0700, true);
                }
                if (@file_put_contents($path, '') === false) {
                    $this->reply($cid, self::ACCESS_DENIED, pack('VC', 0, 0));
                    break;
                }
                $this->jobs[$id] = $path;
                $this->reply($cid, self::OK, pack('VC', $id, 2));
                break;
            case 0x04: // write
                $len = $r->u32();
                $r->skip(28);
                $data = $r->bytes(min($len, $r->left()));
                $path = $this->jobs[$fileId] ?? null;
                if ($path === null || $this->printed + strlen($data) > self::PRINT_MAX) {
                    $this->reply($cid, self::DISK_FULL, pack('VC', 0, 0));
                    break;
                }
                @file_put_contents($path, $data, FILE_APPEND);
                $this->printed += strlen($data);
                $this->reply($cid, self::OK, pack('VC', strlen($data), 0));
                break;
            case 0x02: // close: job finished
                $path = $this->jobs[$fileId] ?? null;
                unset($this->jobs[$fileId]);
                if ($path !== null) {
                    clearstatcache(true, $path);
                }
                if ($path !== null && (int)@filesize($path) > 0) {
                    $head = (string)file_get_contents($path, false, null, 0, 64);
                    $type = match (true) {
                        str_starts_with($head, '%PDF')                                     => 'pdf',
                        str_starts_with($head, '%!PS') || str_contains($head, '@PJL')       => 'ps',
                        str_starts_with($head, "PK\x03\x04")                               => 'xps',
                        default                                                            => 'prn',
                    };
                    $this->audit('print_job', ['job' => $fileId, 'size' => filesize($path), 'type' => $type]);
                    $this->c->emit(Client::F_PRINT, json_encode(['job' => $fileId, 'size' => filesize($path), 'type' => $type]));
                }
                $this->reply($cid, self::OK, str_repeat("\0", 5));
                break;
            default:
                $this->reply($cid, self::OK, pack('V', 0));
        }
    }
}
