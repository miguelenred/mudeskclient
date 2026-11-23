<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

use MuDesk\{Audit, Config, Text};

/**
 * Clipboard redirection (MS-RDPECLIP): text, formatted text (HTML), images (DIB) and files in both directions.
 *
 * Browser -> server messages (first byte):
 *   'X' utf-8 text | 'H' u32 length + utf-8 text + the same text as CF_HTML ("HTML Format", built by the browser)
 *   'B' image uploaded as tmp/<sid>/image.dib | 'F' JSON [{name,size}] files uploaded as tmp/<sid>/<n> | 'D' download the remote files
 * Server -> browser messages (frame F_CLIP, first byte):
 *   'A' local data announced | 'L' new remote list | 'T' UTF-16LE text | 'H' CF_HTML (after its 'T') | 'I' DIB image | 'F' JSON file list
 *   'C' u32 index + file data chunk | 'E' u32 index (file complete) | 'Z' all files done | 'R' JSON error
 *   'P' an application is pasting the files from the browser (it asked for their list)
 */
final class Cliprdr implements Channel
{
    private const FMT_TEXT = 13;
    private const FMT_DIB = 8;
    private const FMT_FILES = 0xC0F1;   // registered ids we announce
    private const FMT_CONTENTS = 0xC0F2;
    private const FMT_EFFECT = 0xC0F3;
    private const FMT_HTML = 0xC0F4;
    private const NO_HISTORY = [0xC0F5 => 'ExcludeClipboardContentFromMonitorProcessing', 0xC0F6 => 'CanIncludeInClipboardHistory',
        0xC0F7 => 'CanUploadToCloudClipboard'];   // typed text stays out of the clipboard history and the cloud clipboard
    private const TURN = [0xC0F8 => 'MuDeskClient 1', 0xC0F9 => 'MuDeskClient 2'];   // one of them in turn: see announce()
    private const CHUNK = 524288;
    private const KEEP_MAX = 16777216;  // larger remote contents are not put back after typing: the text is typed instead

    private bool $long = false;
    private bool $ready = false;        // Monitor Ready received: format lists may be sent
    private string $localText = '';
    private string $localHtml = '';
    private ?string $localImage = null;
    private array $localFiles = [];
    /** remote formats: id => name */
    private array $remote = [];
    private ?int $requested = null;
    private ?int $remoteHtml = null;    // id of the remote "HTML Format"
    private string $owner = '';         // who put what is on the clipboard: 'local' (browser) or 'remote'
    /** text being typed by pasting (see typeText()): text, step, deadline, clipboard to put back (see state()) */
    private ?array $typing = null;
    private int $turn = 0;              // quiet lists so far (see TURN)
    private int $lists = 0;             // our format lists Windows has not answered yet (it answers each one, in order)
    private array $acks = [];           // for each of them: whether its answer goes to the browser ('A', it announced it)
    /** what was copied on the remote side, offered again after typing: our format id => [name, data] */
    private ?array $offer = null;
    private array $read = [];           // remote formats read so far for $offer: id => data
    private array $toRead = [];         // remote formats still to read
    private bool $unkeepable = false;   // the remote clipboard cannot be put back (files, too large): text is typed instead
    private bool $reqRead = false;      // the pending data request is for $toRead
    private bool $stale = false;        // the pending data request belongs to an older remote format list
    private array $queue = [];
    /** pending remote file download */
    private array $files = [];
    private ?array $dl = null;

    public function __construct(private Client $c, private string $sid, private string $conn = '')
    {
    }

    private int $audited = 0;
    private float $requestedAt = 0.0;

    /** Clipboard audit: type and size, never the contents; at most 2,000 lines a session (a server copying in a loop). */
    private function audit(string $event, array $d): void
    {
        if (++$this->audited > 2000) {
            return;
        }
        Audit::log($event, ['sid' => $this->sid, 'conn' => $this->conn] + $d + ($this->audited === 2000 ? ['note' => 'later clipboard events not logged'] : []), null, true);
    }

    public function name(): string
    {
        return 'cliprdr';
    }

    public static function tmpDir(string $sid): string
    {
        return Config::dir('tmp/' . $sid);
    }

    private function send(int $type, int $flags, string $data = ''): void
    {
        $this->c->sendChannel('cliprdr', pack('vvV', $type, $flags, strlen($data)) . $data);
    }

    private function toBrowser(string $msg): void
    {
        $this->c->emit(Client::F_CLIP, $msg);
    }

    // ------------------------------------------------------------ from the server

    public function onData(string $data): void
    {
        $r     = new Reader($data);
        $type  = $r->u16();
        $flags = $r->u16();

        $body  = new Reader($r->bytes(min($r->u32(), $r->left())));
        if ($type !== 5 && $type !== 9) {               // data messages are only counted by the channel statistics
            $this->c->trace?->log('clipboard', ['msg' => [1 => 'monitorReady', 2 => 'formatList', 3 => 'formatListResponse', 4 => 'dataRequest',
                7 => 'capabilities', 8 => 'fileContentsRequest', 10 => 'lockClipData', 11 => 'unlockClipData'][$type] ?? $type, 'flags' => $flags,
                'size' => strlen($body->data)] + ($type === 7 || $type === 4 ? ['data' => Trace::hex($body->data, 32)] : [])
                + ($this->typing !== null ? ['typing' => $this->typing['step']] : []));
        } elseif (($flags & 2) !== 0) {
            $this->c->trace?->log('clipboard', ['msg' => $type === 5 ? 'dataResponse' : 'fileContentsResponse', 'status' => 'FAIL']);
        }
        switch ($type) {
            case 7: // capabilities
                $n = $body->u16();
                $body->skip(2);
                for ($i = 0; $i < $n && $body->left() >= 4; $i++) {
                    $t   = $body->u16();
                    $cap = new Reader($body->bytes($body->u16() - 4));
                    if ($t === 1) {
                        $cap->skip(4);
                        $this->long = (bool)($cap->u32() & 0x2);
                    }
                }
                break;
            case 1: // monitor ready
                [$this->ready, $this->lists, $this->acks] = [true, 0, []];   // lists sent before are not answered
                $this->send(7, 0, pack('vvvvVV', 1, 0, 1, 12, 2, 0x2 | 0x4 | 0x8 | 0x20));
                $this->announce();
                break;
            case 2: // remote format list
                $this->send(3, 1);
                $this->remoteFormats($body);
                break;
            case 3: // response to our format list
                $this->lists = max(0, $this->lists - 1);
                $step        = $this->typing['step'] ?? null;
                if (array_shift($this->acks)) {
                    $this->toBrowser('A');
                }
                if ($step === 'announce' && $this->lists === 0) {   // the answer to this text's list
                    if (($flags & 1) === 0) {
                        $this->finish();
                        break;
                    }
                    $this->typing['step']  = 'ready';
                    $this->typing['until'] = microtime(true) + 2;
                    $this->tick();
                }
                break;
            case 4: // data request
                $this->dataRequest($body->u32());
                break;
            case 5: // data response
                $this->dataResponse(($flags & 1) === 1, $body->rest());
                break;
            case 8: // file contents request
                $this->contentsRequest($body);
                break;
            case 9: // file contents response
                $this->contentsResponse(($flags & 1) === 1, $body);
                break;
        }
    }

    private function remoteFormats(Reader $r): void
    {
        $this->stale  = $this->requested !== null;      // its answer belongs to the previous list
        $this->remote = [];
        $d            = $r->data;
        while ($r->left() >= 4 && count($this->remote) < 1000) {   // (11 million in a 64 MB list took 450 MB and 25 s)
            $id = $r->u32();
            if ($this->long) {
                $end = $r->pos;
                while ($end + 1 < strlen($d) && ($d[$end] !== "\0" || $d[$end + 1] !== "\0")) {
                    $end += 2;
                }
                $name = Text::fromUtf16(substr($d, $r->pos, $end - $r->pos));
                $r->skip(min($r->left(), $end - $r->pos + 2));
            } else {
                $name = rtrim(Text::fromUtf16($r->bytes(32)), "\0");
            }
            $this->remote[$id] = $name;
        }
        $this->c->trace?->log('clipboard formats', ['long' => $this->long, 'formats' => array_map(static fn($id, $n) => $n === '' ? $id : "$id $n", array_keys($this->remote), $this->remote)]);
        if ($this->dl !== null) {                       // a download of the previous files stops here
            $this->toBrowser('R' . json_encode(['error' => 'file']));
        }
        $this->toBrowser('L');                          // new remote list: an 'H' without a 'T' before it has no text
        $this->dl    = null;
        $this->queue = [];
        $this->owner = 'remote';
        $this->offer = null;
        [$this->read, $this->toRead, $this->unkeepable] = [[], [], false];
        if ($this->typing !== null) {                    // copied on the remote side while typing: that stays
            $this->typing['saved'] = null;
            $this->finish();
        }
        $files       = array_search('FileGroupDescriptorW', $this->remote, true);
        $html        = array_search('HTML Format', $this->remote, true);
        $this->remoteHtml = $html === false ? null : $html;
        if ($files !== false) {
            $this->queue[] = $files;
        } elseif (isset($this->remote[self::FMT_TEXT]) || $this->remoteHtml !== null) {
            if (isset($this->remote[self::FMT_TEXT])) {
                $this->queue[] = self::FMT_TEXT;
            }
            if ($this->remoteHtml !== null) {
                $this->queue[] = $this->remoteHtml;      // formatting too (Word, browsers), right after its text
            }
        } elseif (isset($this->remote[self::FMT_DIB]) || isset($this->remote[17])) {
            $this->queue[] = self::FMT_DIB;
        }
        $this->next();
    }

    private function next(): void
    {
        if ($this->requested === null && ($this->queue || $this->toRead)) {
            $this->reqRead   = !$this->queue;               // what the browser shows goes first
            $this->requested = $this->reqRead ? array_shift($this->toRead) : array_shift($this->queue);
            $this->requestedAt = microtime(true);
            $this->send(4, 0, pack('V', $this->requested));
        }
    }

    private function dataResponse(bool $ok, string $data): void
    {
        $fmt     = $this->stale ? null : $this->requested;
        $forRead = $this->reqRead;
        [$this->requested, $this->stale] = [null, false];
        if ($ok && $fmt !== null && ($forRead || in_array($fmt, $this->toRead, true)) && ($this->typing['step'] ?? '') === 'read') {
            $this->read[$fmt] = $data;                      // kept to put the remote clipboard back (see typeText())
            $this->toRead     = array_values(array_diff($this->toRead, [$fmt]));
            if (array_sum(array_map('strlen', $this->read)) > self::KEEP_MAX) {
                $this->toRead = [];                         // too much to put back: no more reading, typed (see remoteRead())
            }
        }
        if ($ok && $fmt !== null && !$forRead) {
            if ($fmt === self::FMT_TEXT) {
                $this->audit('clip_from_remote', ['type' => 'text', 'chars' => max(0, intdiv(strlen(rtrim($data, "\0")) + 1, 2))]);
                $this->toBrowser('T' . $data);
            } elseif ($fmt === $this->remoteHtml) {
                $this->audit('clip_from_remote', ['type' => 'html', 'bytes' => strlen($data)]);
                $this->toBrowser('H' . $data);
            } elseif ($fmt === self::FMT_DIB) {
                $this->audit('clip_from_remote', ['type' => 'image', 'bytes' => strlen($data)]);
                $this->toBrowser('I' . $data);
            } else {
                $this->fileList($data);
            }
        }
        $this->next();
        if (($this->typing['step'] ?? '') === 'read') {
            $this->remoteRead();
        }
    }

    private function fileList(string $data): void
    {
        $r           = new Reader($data);
        $n           = $r->u32();
        $this->files = [];
        for ($i = 0; $i < $n && $r->left() >= 592; $i++) {
            $d             = new Reader($r->bytes(592));
            $d->skip(36);
            $attr          = $d->u32();
            $d->skip(24);
            $hi            = $d->u32();
            $lo            = $d->u32();
            // relative path inside the copy: no "..", "." or drive letters (they would reach the browser's ZIP)
            $name          = implode('\\', array_diff(preg_split('~[\\\\/:]+~', Text::fromUtf16($d->bytes(520)), -1, PREG_SPLIT_NO_EMPTY), ['.', '..']));
            $this->files[] = ['name' => $name, 'size' => $hi * 4294967296 + $lo, 'dir' => (bool)($attr & 0x10)];
        }
        $this->audit('clip_from_remote', ['type' => 'files', 'count' => count($this->files),   // (the first 50 names, each cut)
            'files' => array_map(static fn($f) => Text::cut($f['name'], 260) . ($f['dir'] ? '/' : ' (' . $f['size'] . ')'), array_slice($this->files, 0, 50))]);
        $this->toBrowser('F' . json_encode($this->files, JSON_UNESCAPED_UNICODE));
    }

    // ------------------------------------------------------------ remote files -> browser

    private function requestChunk(): void
    {
        while ($this->dl !== null && $this->dl['i'] < count($this->files)) {
            $f    = $this->files[$this->dl['i']];
            $size = min(PHP_INT_MAX, $f['size']);      // 2^63 bytes or more is a float: ends where an empty answer puts the position
            if (!$f['dir'] && $this->dl['pos'] < $size) {
                $len = (int)min(self::CHUNK, $size - $this->dl['pos']);
                $this->send(8, 0, pack('VlVVVV', $this->dl['stream'], $this->dl['i'], 2,
                    $this->dl['pos'] & 0xffffffff, $this->dl['pos'] >> 32, $len));
                return;
            }
            $this->toBrowser('E' . pack('V', $this->dl['i']));
            $this->dl['i']++;
            $this->dl['pos'] = 0;
        }
        if ($this->dl !== null) {
            $this->toBrowser('Z');
            $this->dl = null;
        }
    }

    private function contentsResponse(bool $ok, Reader $r): void
    {
        if ($this->dl === null) {
            return;
        }
        if (!$ok || $r->u32() !== $this->dl['stream']) {
            $this->toBrowser('R' . json_encode(['error' => 'file']));
            $this->dl = null;
            return;
        }
        $data = $r->rest();
        if ($data === '') { // nothing more to read: skip to the next file
            $this->dl['pos'] = PHP_INT_MAX;
        } else {
            $this->toBrowser('C' . pack('V', $this->dl['i']) . $data);
            $this->dl['pos'] += strlen($data);
        }
        $this->requestChunk();
    }

    // ------------------------------------------------------------ local data -> server

    /**
     * Announces our clipboard; $quiet keeps it out of the clipboard history and the cloud clipboard (typing, putting back);
     * $ack: the browser announced it and waits for the answer ('A'). Nothing goes out before Monitor Ready.
     */
    private function announce(bool $quiet = false, bool $ack = false): void
    {
        if (!$this->ready) {
            return;
        }
        $formats = [];
        if ($this->offer !== null) {
            $formats = array_map(static fn(array $f) => $f[0], $this->offer);
        } elseif ($this->localFiles) {
            $formats = [self::FMT_FILES => 'FileGroupDescriptorW', self::FMT_CONTENTS => 'FileContents', self::FMT_EFFECT => 'Preferred DropEffect'];
        } elseif ($this->localImage !== null) {
            $formats = [self::FMT_DIB => ''];
        } elseif ($this->localText !== '') {
            $formats = [self::FMT_TEXT => ''] + ($this->localHtml !== '' ? [self::FMT_HTML => 'HTML Format'] : []);
        }
        if ($quiet && $this->long && $formats) {
            $formats += array_diff(self::NO_HISTORY, $formats);
            $turn     = array_keys(self::TURN)[$this->turn++ & 1];
            $formats += [$turn => self::TURN[$turn]];
        }
        $this->lists++;
        $this->acks[] = $ack;
        $list = '';
        foreach ($formats as $id => $name) {
            $list .= pack('V', $id) . ($this->long
                ? Text::toUtf16($name) . "\0\0"
                : substr(str_pad(Text::toUtf16($name), 32, "\0"), 0, 32));
        }
        $this->send(2, 0, $list);
    }

    private function dataRequest(int $fmt): void
    {
        $step = $this->typing['step'] ?? '';
        if ($fmt === self::FMT_TEXT && $step === 'paste') {
            $this->send(5, 1, $this->localText);           // the application is pasting: the text, then the keys up
            $this->pasted();
            return;
        }
        $data = match (true) {
            $this->offer !== null => $this->offer[$fmt][1] ?? (isset(self::NO_HISTORY[$fmt]) ? pack('V', 0) : null),
            $fmt === self::FMT_TEXT && $this->localText !== '' => $this->localText,
            $fmt === self::FMT_HTML && $this->localHtml !== '' => $this->localHtml,
            $fmt === self::FMT_DIB && $this->localImage !== null => (string)@file_get_contents($this->localImage),
            $fmt === self::FMT_FILES && $this->localFiles !== [] => $this->descriptors(),
            $fmt === self::FMT_EFFECT => pack('V', 1),
            isset(self::NO_HISTORY[$fmt]) => pack('V', 0),
            default => null,
        };
        $this->send(5, $data === null ? 2 : 1, (string)$data);
        if ($fmt === self::FMT_FILES && $data !== null && $this->offer === null) {
            $this->toBrowser('P');                         // files dropped on the session: the paste there worked
        }
    }

    private function descriptors(): string
    {
        $out = pack('V', count($this->localFiles));
        foreach ($this->localFiles as $f) {
            $name = substr(str_pad(Text::toUtf16($f['name']), 520, "\0"), 0, 518) . "\0\0";
            $out .= pack('V', 0x4 | 0x20 | 0x40 | 0x4000) . str_repeat("\0", 32) . pack('V', 0x80)
                  . str_repeat("\0", 16) . pack('P', (time() + 11644473600) * 10000000)
                  . pack('VV', $f['size'] >> 32, $f['size'] & 0xffffffff) . $name;
        }
        return $out;
    }

    private function contentsRequest(Reader $r): void
    {
        $stream = $r->u32();
        $index  = $r->i32();
        $flags  = $r->u32();
        $lo     = $r->u32();
        $hi     = $r->u32();
        $len    = min($r->u32(), 4 * 1048576);
        if ($hi > 0x7FFFFFFF) {                          // an offset past what a file can have: refused (it read from 0)
            $this->send(9, 2, pack('V', $stream));
            return;
        }
        $pos    = $lo | ($hi << 32);
        $f      = $this->localFiles[$index] ?? null;
        if ($f === null) {
            $this->send(9, 2, pack('V', $stream));
            return;
        }
        if ($flags & 1) {
            $this->send(9, 1, pack('VP', $stream, $f['size']));
            return;
        }
        if ($pos === 0) {
            $this->audit('clip_file_to_remote', ['file' => $f['name'], 'size' => $f['size']]);
        }
        $h = @fopen($f['path'], 'rb');
        if ($h === false || fseek($h, $pos) !== 0) {     // gone, or no such place: no bytes from elsewhere
            $h !== false && fclose($h);
            $this->send(9, 2, pack('V', $stream));
            return;
        }
        $data = $len > 0 ? (string)fread($h, $len) : '';
        fclose($h);
        $this->send(9, 1, pack('V', $stream) . $data);
    }

    // ------------------------------------------------------------ typing by pasting

    /**
     * Types text by pasting it (Client::pasteText), for the "phone keyboard through the clipboard" option: Windows 11
     * Notepad, Terminal and Explorer read injected keys late and mix up characters that arrive together. Each step waits
     * for Windows: the answer to the announcement, the screen showing the last input sent, then Ctrl+V held until the
     * application asks for the text. Windows answers an announcement before it takes it, gives no sign when it does, and
     * did not take one equal to the clipboard it had: a second paste in a row pasted the first text again, which
     * Windows keeps once an application has read it. So the clipboard is put back right after each paste (nothing
     * copied: an empty text) and each list carries one of TURN in turn: whichever of our lists the application reads,
     * it asks us, and gets the text being typed. Only when Windows is so busy that it has not taken a list announced
     * long before can it still paste the previous one. What was copied on the remote side is read first (all its
     * formats) to be put back; files, or more than 16 MB (KEEP_MAX), cannot be put back, so the text is typed instead. Time limits
     * only apply when Windows does not answer: then the text is typed.
     */
    public function typeText(string $utf8): void
    {
        $this->typing = ['text' => $utf8, 'step' => 'read', 'until' => microtime(true) + 10, 'saved' => $this->state()];
        $this->c->trace?->log('typing', ['chars' => preg_match_all('/./su', $utf8), 'owner' => $this->owner]);
        if ($this->typing['saved'][4] === 'remote' && $this->typing['saved'][5] === null) {   // copied on the remote side: read it
            if ($this->unkeepable || in_array('FileGroupDescriptorW', $this->remote, true)) {
                $this->unkeepable = true;
                $this->finish();
                return;
            }
            $this->read   = [];
            $this->toRead = [];
            foreach (array_keys($this->remote) as $id) {
                if (!((($id === 1 || $id === 7) && isset($this->remote[13])) || (($id === 2 || $id === 17) && isset($this->remote[8])))) {
                    $this->toRead[] = $id;                 // ANSI text and bitmaps are made by Windows from the others
                }
            }
            $this->next();
            $this->remoteRead();
            return;
        }
        $this->announceText();
    }

    /** Once the remote formats are read: what is put back after typing, then the text is announced. */
    private function remoteRead(): void
    {
        if ($this->toRead || ($this->requested !== null && $this->reqRead)) {
            return;
        }
        if (($this->remote && !$this->read) || array_sum(array_map('strlen', $this->read)) > self::KEEP_MAX) {
            $this->unkeepable = true;                       // nothing could be read, or too much: typed
            $this->finish();
            return;
        }
        $offer = [];
        $n     = 0;
        foreach ($this->read as $id => $data) {
            $offer[$id < 0xC000 ? $id : 0xC100 + $n++] = [$this->remote[$id], $data];   // registered formats get our ids
        }
        $this->read               = [];
        $this->typing['saved'][5] = $offer;
        $this->announceText();
    }

    private function announceText(): void
    {
        $this->typing['step']  = 'announce';
        $this->typing['until'] = microtime(true) + 2;
        [$this->localText, $this->localHtml, $this->localImage, $this->localFiles, $this->offer]
            = [Text::toUtf16($this->typing['text']) . "\0\0", '', null, [], null];
        $this->announce(true);
    }

    /** Clipboard as it is now: text, HTML, image, files, owner, remote clipboard offered again. */
    private function state(): array
    {
        return [$this->localText, $this->localHtml, $this->localImage, $this->localFiles, $this->owner, $this->offer];
    }

    /** Called on every loop of the session. */
    public function tick(): void
    {
        if ($this->requested !== null && microtime(true) - $this->requestedAt > 30) {
            [$this->requested, $this->stale] = [null, false];   // never answered (or left out, too large): the next copies are read again
            $this->next();
        }
        $t = $this->typing;
        if ($t !== null && $t['step'] === 'ready' && ($this->c->drawnSinceInput() || microtime(true) > $t['until'])) {
            // Ctrl+V once the application has drawn the last input sent (Notepad dropped a Ctrl+V right behind an Enter
            // while its spell checker went through the line), held until it asks for the text: these Windows 11 controls
            // read injected keys late and dropped a V already released, or read it without Ctrl. Windows does not repeat
            // keys injected by RDP. Notepad took over 3 s while its spell checker went through a long paste: 10 s.
            $this->typing['step']  = 'paste';
            $this->typing['until'] = microtime(true) + 10;
            $this->c->inject("\x00\x1d\x00\x2f", 2);
            $this->c->trace?->log('typing', ['ctrl+v' => true]);
        } elseif ($t !== null && $t['step'] !== 'ready' && microtime(true) > $t['until']) {
            $this->finish();                                // Windows did not answer: typed instead
        }
    }

    /** The application has the text: keys up, and the input behind goes out once it has drawn it (Client::inputDone). */
    private function pasted(): void
    {
        $this->c->inject("\x01\x2f\x01\x1d", 2);            // V and Ctrl up
        $this->c->trace?->log('typing', ['pasted' => true, 'pending' => $this->c->pastePending()]);
        $saved        = $this->typing['saved'];
        $this->typing = null;
        $this->putBack($saved);
        $this->c->inputDone([], true);
    }

    /** Text not pasted (no answer, or something else was copied meanwhile): typed instead. */
    private function finish(): void
    {
        $t            = $this->typing;
        $this->typing = null;
        $this->c->trace?->log('typing', ['end' => $t['step'], 'pending' => $this->c->pastePending()]);
        if ($t['step'] === 'paste') {
            $this->c->inject("\x01\x2f\x01\x1d", 2);        // V and Ctrl up
        }
        if ($t['step'] === 'read') {                        // nothing announced yet
            [$this->read, $this->toRead] = [[], []];
            $this->unkeepable = $this->unkeepable || $t['saved'] !== null;   // a new remote copy is read next time
        } else {
            $this->putBack($t['saved']);
        }
        $this->c->inputDone(Client::unicodeText($t['text']));
    }

    /**
     * Puts the clipboard back after each paste ($saved null: something else was copied meanwhile): what was pasted, now
     * kept by Windows, is not left there for the next Ctrl+V (see typeText()). Nothing copied becomes an empty text.
     */
    private function putBack(?array $saved): void
    {
        if ($saved !== null) {
            [$this->localText, $this->localHtml, $this->localImage, $this->localFiles, $this->owner, $this->offer] = $saved;
            if ($this->localText === '' && $this->localImage === null && !$this->localFiles && !$this->offer) {
                $this->localText = "\0\0";
            }
            $this->announce(true);
        }
    }

    /** Message from the browser (see class comment). */
    public function fromBrowser(string $m): void
    {
        if (in_array($m[0] ?? '', ['X', 'H', 'B', 'F'], true)) {
            if ($this->typing !== null) {                  // copied in the browser while typing: that stays
                $this->typing['saved'] = null;
                $this->finish();
            }
            [$this->owner, $this->offer] = ['local', null];
        }
        clearstatcache();
        $dir = self::tmpDir($this->sid);
        switch ($m[0] ?? '') {
            case 'X':
            case 'H':
                $n = $m[0] === 'H' ? (int)unpack('V', substr($m, 1, 4) . "\0\0\0\0")[1] : strlen($m) - 1;
                $o = $m[0] === 'H' ? 5 : 1;
                $this->localText  = Text::toUtf16(substr($m, $o, $n)) . "\0\0";
                $this->localHtml  = $m[0] === 'H' ? substr($m, $o + $n) : '';
                $this->localImage = null;
                $this->localFiles = [];
                $this->audit('clip_to_remote', ['type' => $this->localHtml !== '' ? 'html' : 'text', 'chars' => intdiv(strlen($this->localText), 2) - 1]);
                $this->announce(false, true);
                break;
            case 'B':
                $this->localImage = is_file($dir . '/image.dib') ? $dir . '/image.dib' : null;
                $this->localFiles = [];
                $this->audit('clip_to_remote', ['type' => 'image', 'bytes' => $this->localImage ? filesize($this->localImage) : 0]);
                $this->announce(false, true);
                break;
            case 'F':
                $this->localFiles = [];
                $this->localImage = null;
                foreach ((array)json_decode(substr($m, 1), true) as $i => $f) {   // [{name, size}, ...]: other entries are skipped
                    $path = $dir . '/' . (int)$i;
                    if (is_scalar($f['name'] ?? null) && is_file($path)) {
                        $this->localFiles[] = ['name' => basename(str_replace('\\', '/', (string)$f['name'])), 'size' => filesize($path), 'path' => $path];
                    }
                }
                $this->audit('clip_to_remote', ['type' => 'files', 'files' => array_map(static fn($f) => $f['name'] . ' (' . $f['size'] . ')', $this->localFiles)]);
                $this->announce(false, true);
                break;
            case 'D':
                if ($this->files) {
                    $this->audit('clip_files_download', ['files' => array_values(array_map(static fn($f) => $f['name'],
                        array_filter($this->files, static fn($f) => !$f['dir'])))]);
                    $this->dl = ['i' => 0, 'pos' => 0, 'stream' => random_int(1, 0x7fffffff)];
                    $this->requestChunk();
                }
                break;
        }
    }
}
