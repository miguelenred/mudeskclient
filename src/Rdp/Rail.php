<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

use MuDesk\Text;

/**
 * RemoteApp (MS-RDPERP): the "rail" static virtual channel. After the server's handshake the client answers with its own,
 * its status and its system parameters (the work area: the browser window). The program (Client Execute) is asked for once
 * the server has sent its windows (the desktop order "arc completed", which the browser reads in the drawing updates and
 * reports with ready()): the server only takes it then. Without that report it is asked for after EXEC_WAIT seconds, and
 * once more if the server was not yet watching the desktop. Reconnected after the connection dropped, or connected again
 * from a page where the program was open, it is asked for only if its windows did not come back with the desktop: no
 * second one. The browser asks here to activate, minimize,
 * maximize, restore or close a window. A program the server refuses ends the session with a reason the page explains.
 * When the program's last window closes, the browser ends the session (as Remote Desktop Connection does: Windows keeps it).
 */
final class Rail implements Channel
{
    private const EXEC = 0x0001, ACTIVATE = 0x0002, SYSPARAM = 0x0003, SYSCOMMAND = 0x0004, HANDSHAKE = 0x0005,
        WINDOWMOVE = 0x0008, CLIENTSTATUS = 0x000B, HANDSHAKE_EX = 0x0013, CLOAK = 0x0015, EXEC_RESULT = 0x0080;
    private const SPI_SETMOUSEBUTTONSWAP = 0x0021, SPI_SETDRAGFULLWINDOWS = 0x0025, SPI_SETWORKAREA = 0x002F,
        SPI_SETHIGHCONTRAST = 0x0043, SPI_SETKEYBOARDPREF = 0x0045, SPI_SETKEYBOARDCUES = 0x100B;
    /** Height of the page's taskbar (its windows, at the bottom: app.css .rail-taskbar), left out of the work area. */
    public const TASKBAR = 40;
    /** System commands the browser may ask for: minimize, maximize, restore, close. */
    public const COMMANDS = [0xF020, 0xF030, 0xF120, 0xF060];
    /** Server Execute Result codes -> the reason the session ends with (RAIL_EXEC_E_*). */
    private const EXEC_ERRORS = [1 => 'app_failed', 2 => 'app_failed', 3 => 'app_not_allowed', 5 => 'app_not_found', 6 => 'app_failed',
        7 => 'app_locked'];

    private const EXEC_WAIT = 5.0;

    private bool $started = false;
    private bool $executed = false;
    private bool $retried = false;
    private float $execAt = 0;
    private int $w = 0;
    private int $h = 0;

    /**
     * $app: the program (a path, or a published alias "||Name"); $args: its arguments; $again: a reconnection after the
     * connection dropped (the server is told); $resume: the program was open in the same page before. With either, it is
     * started only if its windows did not come back.
     */
    public function __construct(private Client $c, private string $app, private string $args = '', private bool $again = false,
        private bool $resume = false)
    {
    }

    public function name(): string
    {
        return 'rail';
    }

    public function onData(string $data): void
    {
        if (strlen($data) < 4) {
            throw new ProtocolException('RAIL: short PDU', 'protocol');
        }
        [1 => $type, 2 => $len] = unpack('v2', $data);
        $body = substr($data, 4, max(0, $len - 4));
        $this->c->trace?->count('rail:' . $type, strlen($data));
        switch ($type) {
            case self::HANDSHAKE:
            case self::HANDSHAKE_EX:
                $this->start();
                break;
            case self::EXEC_RESULT:
                if (strlen($body) < 10) {
                    throw new ProtocolException('RAIL: short execute result', 'protocol');
                }
                ['result' => $result, 'raw' => $raw] = unpack('vflags/vresult/Vraw', $body);
                $this->c->trace?->log('rail exec result', ['result' => $result, 'raw' => sprintf('0x%08x', $raw)]);
                if ($result === 1 && !$this->retried) {   // RAIL_EXEC_E_HOOK_NOT_LOADED: the server was not watching the desktop yet
                    [$this->retried, $this->executed, $this->execAt] = [true, false, microtime(true) + 2];
                } elseif ($result !== 0) {
                    throw new ProtocolException("RemoteApp refused ($result)", self::EXEC_ERRORS[$result] ?? 'app_failed');
                }
                break;
            case self::CLOAK:                    // a window hidden or shown again (virtual desktops, cloaked windows)
                if (strlen($body) >= 5) {
                    ['id' => $id, 'on' => $on] = unpack('Vid/Con', $body);
                    $this->c->emit(Client::F_RAIL, (string)json_encode(['cloak' => $id, 'on' => $on !== 0]));
                }
                break;
            default:                             // system parameters, min/max, move/size, z-order, application ids: not needed
                break;
        }
    }

    /** The answer to the server's handshake: ours, our status and the system parameters (the program comes with ready()). */
    private function start(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        $this->c->trace?->log('rail handshake', ['again' => $this->again, 'resume' => $this->resume]);
        $this->send(self::HANDSHAKE, pack('V', 0x1DB0));                    // build number (as FreeRDP)
        $this->send(self::CLIENTSTATUS, pack('V', $this->again ? 0x2 : 0)); // TS_RAIL_CLIENTSTATUS_AUTORECONNECT when reconnecting
        $this->sysParam(self::SPI_SETHIGHCONTRAST, pack('VV', 0x7E, 2) . "\0\0");   // high contrast off: flags, empty scheme
        $this->sysParam(self::SPI_SETMOUSEBUTTONSWAP, "\0");
        $this->sysParam(self::SPI_SETKEYBOARDPREF, "\0");
        $this->sysParam(self::SPI_SETDRAGFULLWINDOWS, "\1");
        $this->sysParam(self::SPI_SETKEYBOARDCUES, "\0");
        [$w, $h] = $this->w ? [$this->w, $this->h] : [$this->c->width, $this->c->height];   // the page's monitor, if known
        [$this->w, $this->h] = [0, 0];
        $this->workArea($w, $h);
        $this->execAt = microtime(true) + self::EXEC_WAIT;
    }

    /**
     * The server has sent its windows (desktop order "arc completed", read by the browser; $open: how many of them are shown
     * or minimized): the program is asked for. Connected again with its windows back, it is still running there: not again.
     */
    public function ready(int $open = 0): void
    {
        if (!$this->started || $this->executed) {
            return;
        }
        if (($this->again || $this->resume) && $open > 0) {
            $this->executed = true;
            $this->c->trace?->log('rail exec skipped', ['open' => $open]);
            return;
        }
        $this->exec('desktop');
    }

    /**
     * Called from the session loop: the program asked for anyway when the browser did not report the desktop in time; when
     * reconnecting, it is left as it was (without the report, whether it is still running there is not known).
     */
    public function tick(): void
    {
        if (!$this->started || $this->executed || microtime(true) < $this->execAt) {
            return;
        }
        if ($this->again && !$this->retried) {
            $this->executed = true;
            $this->c->trace?->log('rail exec skipped', ['by' => 'timer']);
            return;
        }
        $this->exec('timer');
    }

    /** $by: what let it go (the browser's report of the desktop, or the wait), for the diagnostic trace. */
    private function exec(string $by): void
    {
        $this->executed = true;
        $exe  = Text::toUtf16($this->app);
        $args = Text::toUtf16($this->args);
        $this->send(self::EXEC, pack('v4', 0x8, strlen($exe), 0, strlen($args)) . $exe . $args);   // TS_RAIL_EXEC_FLAG_EXPAND_ARGUMENTS
        $this->c->trace?->log('rail exec', ['app' => $this->app, 'args' => $this->args !== '', 'by' => $by]);
    }

    /**
     * The work area: the page's monitor (the browser window; with several screens, the primary monitor, at 0,0: the one of the
     * page) but its taskbar at the bottom, so that maximized windows fill the window and leave the taskbar free. The other
     * screens' monitors keep all of theirs (Windows' default). Before the handshake it is kept for it.
     */
    public function workArea(int $w, int $h): void
    {
        if ($w === $this->w && $h === $this->h) {
            return;
        }
        [$this->w, $this->h] = [$w, $h];
        if ($this->started) {
            $this->sysParam(self::SPI_SETWORKAREA, pack('v4', 0, 0, $w, max(1, $h - self::TASKBAR)));
        }
    }

    /**
     * Several screens: the size of the page's monitor, the primary one (the page makes its screen the primary monitor, so that
     * the program opens there and its work area starts at 0,0), as Display Control takes it; null without one.
     * $mons: [[x, y, w, h, primary], ...] (Drdynvc::monitors).
     */
    public static function mainMonitor(array $mons): ?array
    {
        foreach ($mons as [, , $w, $h, $primary]) {
            if ($primary) {
                return [max(200, min(8192, $w & ~1)), max(200, min(8192, $h))];
            }
        }
        return null;
    }

    /**
     * A command of the browser (JSON): {a: 'ready', open} the server's windows are all there (open: how many are shown or
     * minimized); {a: 'activate', id} brings a window
     * to the front; {a: 'sys', id, c} minimizes, maximizes, restores or closes it. Anything else is ignored.
     */
    public function command(string $json): void
    {
        $m  = json_decode($json, true);
        if (is_array($m) && ($m['a'] ?? '') === 'ready') {
            $this->ready(is_int($m['open'] ?? null) && $m['open'] > 0 ? min($m['open'], 0xFFFF) : 0);
            return;
        }
        $id = is_array($m) && is_int($m['id'] ?? null) && $m['id'] >= 0 && $m['id'] <= 0xFFFFFFFF ? $m['id'] : null;
        if (!$this->started || $id === null) {
            return;
        }
        if (($m['a'] ?? '') === 'activate') {
            $this->send(self::ACTIVATE, pack('VC', $id, 1));
        } elseif (($m['a'] ?? '') === 'sys' && in_array($m['c'] ?? null, self::COMMANDS, true)) {
            $this->send(self::SYSCOMMAND, pack('Vv', $id, $m['c']));
        }
    }

    private function sysParam(int $param, string $body): void
    {
        $this->send(self::SYSPARAM, pack('V', $param) . $body);
    }

    private function send(int $type, string $body): void
    {
        $this->c->sendChannel('rail', pack('vv', $type, 4 + strlen($body)) . $body);
    }
}
