<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

use MuDesk\Text;

/**
 * Camera (MS-RDPECAM, video capture redirection): this computer's camera on the remote one, with the microphone (the same
 * connection option, off by default). On the enumeration channel the client says its version and its one camera, the
 * browser's. The server opens that camera's channel, several times at once (Windows 11 opens two or more and spreads its
 * requests across them): the camera is one, its state shared by them all, and each request is answered on the channel it
 * came on. The server activates the camera, asks its streams and formats, starts one and asks for each picture (one answer
 * each). The pictures are JPEG (Motion JPEG) the browser makes from its camera at the size and rate the server chose;
 * Windows decodes them for the programs that want them uncompressed (DecodingRequired). Only the newest picture waits: a slow
 * network shows fewer pictures, never later ones. Windows 10 1803 and later, Server 2019 and later.
 */
final class Rdpecam
{
    public const ENUMERATOR = 'RDCamera_Device_Enumerator';
    public const DEVICE     = 'RDCamera_Device_0';
    private const VERSION = 2;                 // the highest: version 2 adds the camera's properties (none here)
    private const SUCCESS = 0x01, ERROR = 0x02, SELECT_VERSION = 0x03, SELECT_VERSION_REPLY = 0x04, DEVICE_ADDED = 0x05,
        ACTIVATE = 0x07, DEACTIVATE = 0x08, STREAM_LIST = 0x09, STREAM_LIST_REPLY = 0x0A, MEDIA_LIST = 0x0B, MEDIA_LIST_REPLY = 0x0C,
        CURRENT_MEDIA = 0x0D, CURRENT_MEDIA_REPLY = 0x0E, START = 0x0F, STOP = 0x10, SAMPLE = 0x11, SAMPLE_REPLY = 0x12,
        SAMPLE_ERROR = 0x13, PROPERTY_LIST = 0x14, PROPERTY_LIST_REPLY = 0x15, PROPERTY_VALUE = 0x16, SET_PROPERTY = 0x18;
    private const UNEXPECTED = 1, INVALID_MESSAGE = 2, INVALID_REQUEST = 4, INVALID_STREAM = 5, INVALID_MEDIA_TYPE = 6, ITEM_NOT_FOUND = 8;
    private const MJPG = 0x02;
    /** The sizes offered, Motion JPEG at 30 pictures a second, the first the current one: the browser scales its camera to the one chosen */
    public const SIZES = [[640, 480], [1280, 720], [640, 360], [320, 240]];
    /** Largest picture taken from the browser */
    public const SAMPLE_MAX = 2 * 1048576;
    /** Sample Requests kept waiting at most (the server asks for one at a time; past this, the oldest are answered with an error) */
    private const ASKED_MAX = 64;

    private int $version = 1;          // the one the server chose
    /** @var array<int, true> the camera's channels open */
    private array $channels = [];
    /** @var array<int, true> the channels that activated the camera (it stays active while one of them has) */
    private array $active = [];
    private ?array $media = null;      // [width, height, pictures a second] while its stream runs
    /** @var list<int> the channel of each Sample Request not answered yet, oldest first */
    private array $asked = [];
    private ?string $picture = null;   // the newest picture from the browser, not sent yet
    private bool $failed = false;      // the browser could not open its camera (for this stream)
    private int $run = 0;              // the number the browser was told to start with: its pictures say it (u8)
    /** Each start numbered in this process (also across the connections made again): a picture of one before is left out */
    private static int $runs = 0;

    /**
     * $sendEnum(message) / $sendDevice(channel id, message) send on the enumeration channel and on one of the camera's;
     * $name is the camera's in Windows, in the language of the page.
     */
    public function __construct(private Client $c, private \Closure $sendEnum, private \Closure $sendDevice, private string $name)
    {
    }

    /** The enumeration channel opened: the client speaks first, with the highest version it has. */
    public function enumOpened(): void
    {
        $this->c->trace?->log('camera version', ['offered' => self::VERSION]);
        ($this->sendEnum)(pack('CC', self::VERSION, self::SELECT_VERSION));
    }

    /** A message on the enumeration channel: the server's version, then the camera announced. */
    public function onEnum(string $msg): void
    {
        if (strlen($msg) < 2 || ord($msg[1]) !== self::SELECT_VERSION_REPLY) {
            $this->c->trace?->log('camera enumeration message', ['data' => Trace::hex(substr($msg, 0, 32))]);
            return;
        }
        $this->version = max(1, min(self::VERSION, ord($msg[0])));
        $this->c->trace?->log('camera device added', ['version' => $this->version, 'name' => $this->name]);
        ($this->sendEnum)(pack('CC', $this->version, self::DEVICE_ADDED) . Text::toUtf16($this->name) . "\0\0" . self::DEVICE . "\0");
    }

    /** A channel of the camera opened. */
    public function opened(int $ch): void
    {
        $this->channels[$ch] = true;
    }

    /** A channel of the camera closed: its activation and its requests go; the stream ends when no channel has the camera active. */
    public function closed(int $ch): void
    {
        unset($this->channels[$ch], $this->active[$ch]);
        $this->asked = array_values(array_filter($this->asked, static fn(int $x): bool => $x !== $ch));
        if (!$this->active) {
            $this->stop();
        }
    }

    /** A request on a channel of the camera: answered there (a malformed one with an error), a Sample Request when there is a picture. */
    public function onDevice(int $ch, string $msg): void
    {
        $this->channels[$ch] = true;
        if (strlen($msg) < 2) {
            $this->error($ch, self::INVALID_MESSAGE);
            return;
        }
        $id     = ord($msg[1]);
        $stream = strlen($msg) >= 3 ? ord($msg[2]) : null;
        $this->c->trace?->count('camera:msg' . $id, strlen($msg));
        if ($id !== self::SAMPLE) {                   // (the diagnostic trace: each request but the pictures')
            $this->c->trace?->log('camera request', ['channel' => $ch, 'message' => $id, 'data' => Trace::hex(substr($msg, 0, 40))]);
        }
        switch ($id) {
            case self::ACTIVATE:
                $this->active[$ch] = true;
                $this->reply($ch, self::SUCCESS);
                break;
            case self::DEACTIVATE:                    // (another channel may still have it active: the stream goes on then)
                unset($this->active[$ch]);
                if (!$this->active) {
                    $this->stop();
                }
                $this->reply($ch, self::SUCCESS);
                break;
            case self::STOP:
                $this->stop();
                $this->reply($ch, self::SUCCESS);
                break;
            case self::STREAM_LIST:                   // one stream: color, capture, selected, not shared between programs
                $this->reply($ch, self::STREAM_LIST_REPLY, pack('vCCC', 0x0001, 0x01, 1, 0));
                break;
            case self::MEDIA_LIST:
                if ($stream === null) {
                    $this->error($ch, self::INVALID_MESSAGE);
                } elseif ($stream !== 0) {
                    $this->error($ch, self::INVALID_STREAM);
                } else {
                    $this->reply($ch, self::MEDIA_LIST_REPLY, implode('', array_map(static fn(array $s): string => self::mediaType($s[0], $s[1], 30), self::SIZES)));
                }
                break;
            case self::CURRENT_MEDIA:
                if ($stream === null) {
                    $this->error($ch, self::INVALID_MESSAGE);
                } elseif ($stream !== 0) {
                    $this->error($ch, self::INVALID_STREAM);
                } else {
                    $this->reply($ch, self::CURRENT_MEDIA_REPLY, $this->media !== null ? self::mediaType(...$this->media) : self::mediaType(self::SIZES[0][0], self::SIZES[0][1], 30));
                }
                break;
            case self::START:
                $this->start($ch, substr($msg, 2));
                break;
            case self::SAMPLE:
                $this->sample($ch, $stream);
                break;
            case self::PROPERTY_LIST:            // version 2: no property (brightness, zoom...) can be set on the browser's camera
                $this->version >= 2 ? $this->reply($ch, self::PROPERTY_LIST_REPLY) : $this->error($ch, self::INVALID_MESSAGE);
                break;
            case self::PROPERTY_VALUE:
            case self::SET_PROPERTY:
                $this->error($ch, $this->version >= 2 ? self::ITEM_NOT_FOUND : self::INVALID_MESSAGE);
                break;
            default:
                $this->c->trace?->log('camera unknown message', ['id' => $id]);
                $this->error($ch, self::INVALID_MESSAGE);
        }
    }

    /** MEDIA_TYPE_DESCRIPTION: Motion JPEG, decoded by Windows for the programs that need it, square pixels. */
    private static function mediaType(int $w, int $h, int $fps): string
    {
        return pack('CVVVVVVC', self::MJPG, $w, $h, $fps, 1, 1, 1, 0x01);
    }

    /** Start Streams: one START_STREAM_INFO for stream 0, in Motion JPEG of a size offered; the browser starts its camera. */
    private function start(int $ch, string $infos): void
    {
        if (strlen($infos) !== 27) {
            $this->error($ch, strlen($infos) % 27 === 0 && $infos !== '' ? self::INVALID_STREAM : self::INVALID_MESSAGE);
            return;
        }
        $m = unpack('Cstream/Cformat/Vw/Vh/Vnum/Vden', $infos);
        if ($m['stream'] !== 0) {
            $this->error($ch, self::INVALID_STREAM);
            return;
        }
        if ($m['format'] !== self::MJPG || !in_array([$m['w'], $m['h']], self::SIZES, true) || $m['num'] === 0 || $m['den'] === 0) {
            $this->error($ch, self::INVALID_MEDIA_TYPE);
            return;
        }
        $media = [$m['w'], $m['h'], max(1, min(30, intdiv($m['num'] + intdiv($m['den'], 2), $m['den'])))];
        $this->active[$ch] = true;                    // (started there: active at least as long as that channel)
        $again = $this->failed;                       // (it could not open its camera: asked again, the user may allow it now)
        [$this->asked, $this->picture, $this->failed] = [[], null, false];
        if ($media !== $this->media || $again) {
            $this->media = $media;
            $this->run   = self::$runs = (self::$runs + 1) & 0xFF;
            $this->c->trace?->log('camera start', ['channel' => $ch, 'size' => "{$media[0]}x{$media[1]}", 'fps' => $media[2]]);
            $this->c->emit(Client::F_CAM, pack('CvvCC', 1, $media[0], $media[1], $media[2], $this->run));
        }
        $this->reply($ch, self::SUCCESS);
    }

    /** A Sample Request: the newest picture if there is one, else answered when the next comes (an error if there can be none). */
    private function sample(int $ch, ?int $stream): void
    {
        $error = match (true) {
            $stream === null      => self::INVALID_MESSAGE,
            $stream !== 0         => self::INVALID_STREAM,
            $this->media === null => self::INVALID_REQUEST,
            $this->failed         => self::UNEXPECTED,
            default               => 0,
        };
        if ($error !== 0) {
            $this->reply($ch, self::SAMPLE_ERROR, pack('CV', $stream ?? 0, $error));
        } elseif ($this->picture !== null) {
            $this->send($ch, $this->picture);
            $this->picture = null;
        } else {
            $this->asked[] = $ch;
            if (count($this->asked) > self::ASKED_MAX) {
                $this->reply(array_shift($this->asked), self::SAMPLE_ERROR, pack('CV', 0, self::UNEXPECTED));
            }
        }
    }

    private function send(int $ch, string $jpeg): void
    {
        $this->c->trace?->count('camera:sent', strlen($jpeg));
        $this->reply($ch, self::SAMPLE_REPLY, "\0" . $jpeg);
    }

    /** A picture from the browser (JPEG) of the start numbered $run: to the oldest request waiting, else kept in place of the one before. */
    public function fromBrowser(int $run, string $jpeg): void
    {
        if ($this->media === null || $run !== $this->run || $jpeg === '' || strlen($jpeg) > self::SAMPLE_MAX) {
            return;                                   // stopped meanwhile, of a start before (another size), or not a picture
        }
        if ($this->asked) {
            $this->send(array_shift($this->asked), $jpeg);
        } else {
            $this->picture = $jpeg;
        }
    }

    /** The browser could not open its camera (not allowed, none, in use): the requests waiting, and the next, answered so. */
    public function browserFailed(int $run): void
    {
        if ($this->media === null || $run !== $this->run) {
            return;
        }
        $this->failed = true;
        $this->c->trace?->log('camera failed', ['waiting' => count($this->asked)]);
        foreach ($this->asked as $ch) {
            $this->reply($ch, self::SAMPLE_ERROR, pack('CV', 0, self::UNEXPECTED));
        }
        $this->asked = [];
    }

    /** The stream stopped (or the camera deactivated, or the session's camera turned off): the browser lets go of its camera. */
    public function stop(): void
    {
        if ($this->media !== null) {
            $this->c->emit(Client::F_CAM, "\0");
            $this->c->trace?->log('camera stop', []);
        }
        [$this->media, $this->asked, $this->picture, $this->failed] = [null, [], null, false];
    }

    private function reply(int $ch, int $id, string $body = ''): void
    {
        if ($id !== self::SAMPLE_REPLY) {
            $this->c->trace?->log('camera reply', ['channel' => $ch, 'message' => $id, 'data' => Trace::hex(substr($body, 0, 40))]);
        }
        ($this->sendDevice)($ch, pack('CC', $this->version, $id) . $body);
    }

    private function error(int $ch, int $code): void
    {
        $this->reply($ch, self::ERROR, pack('V', $code));
    }
}
