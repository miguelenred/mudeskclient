# Performance: bandwidth, CPU and memory per connection

[Español](es/RENDIMIENTO.md)

What **one** connection costs depending on the codec and colour quality, separating what **PHP** spends (the gateway, on the hosting) from what **the browser** spends (decoding and composing the screen).

## How it was measured

- **Server:** Windows 11 Pro 23H2 with NLA, 1024x768 desktop, minimal visual effects (no wallpaper or themes, as the default connection) and LAN connection type.
- **Tool:** `node tests/bench.mjs` (it can be repeated against any server). The gateway is `tests/rdp_probe.php` in `PIPE` mode, which uses the same classes as `rdp.php` and receives input, resizes and frame acknowledgements as with the browser. The browser is replaced by Node.js running **the same decoders** as the browser (`gfx.js`, `orders.js`, `bulk.js`, `rdpgfx.js`, `rfx.js`), and the desktop is composed in memory as the canvas would.
- **Computer:** server, gateway and decoders on the same 2-core PC. CPU percentages are of **one core** (100 % = a whole core) and compete with each other: in a real deployment the server, the hosting and the browser are different machines and each figure will be the same or better.
- **Workloads** (each measurement starts with the session open and the workload running):
  - **Idle:** still desktop (only the clock and the pointer).
  - **Text:** a console listing `C:\Windows` non-stop (text scrolling on almost the whole screen: the hardest case for text).
  - **Window:** an Explorer window dragged in circles (what is noticed most while working).
  - **Animation:** the "Ribbons" screensaver in full screen (the closest to video).
- **Columns:**
  - *Server → PHP:* TCP/TLS bytes received from the RDP server.
  - *PHP → browser:* bytes of the frames the gateway sends to the browser (without HTTP headers; RDP compression is forwarded as is).
  - *PHP: CPU / peak RAM:* user + system CPU of the PHP process during the measurement, and `memory_get_peak_usage(true)` of the connection.
  - *Browser: decoding CPU:* time of the JavaScript decoders (in the browser they run in a Web Worker and do not block input) plus composition. It does not include painting on the canvas nor H.264 decoding, done by WebCodecs (usually on the GPU).
  - *Browser: decoder RAM:* JavaScript and buffer memory added by the decoders and the in-memory desktop (roughly what the tab grows).
  - *Frames/s:* only exists in the graphics pipeline (bitmaps are not sent as frames).

To repeat a measurement:

```sh
BPP=16 COMPRESS=3 NOORDERS=1 node tests/bench.mjs host 3389 user password drag 15
GFX=avc BPP=32 NOORDERS=1 node tests/bench.mjs host 3389 user password anim 15
```

(`COMPRESS`: 0 none, 1 MPPC, 2 NCRUSH, 3 XCRUSH; without `NOORDERS` drawing orders are on; `GFX=1` graphics pipeline, `GFX=avc` with H.264. The workloads type into the Run dialog of a Spanish Windows session.)

## Results

### Idle (still desktop, 10 s)

| Mode | Server → PHP (KB/s) | PHP → browser (KB/s) | PHP: CPU | PHP: peak RAM | Browser: decoding CPU | Browser: decoder RAM | Frames/s |
|---|---:|---:|---:|---:|---:|---:|---:|
| Bitmaps 16 bpp, no compression | 0 | 0 | 0.2 % | 2 MB | 0.0 % | 3 MB | — |
| Bitmaps 16 bpp, MPPC | 0 | 0 | 0.2 % | 2 MB | 0.0 % | 10 MB | — |
| Bitmaps 16 bpp, NCRUSH | 0 | 0 | 0.0 % | 2 MB | 0.0 % | 8 MB | — |
| Bitmaps 16 bpp, XCRUSH (default) | 0 | 0 | 0.0 % | 2 MB | 0.0 % | 11 MB | — |
| Bitmaps 15 bpp, XCRUSH | 0 | 0 | 0.6 % | 4 MB | 0.0 % | 6 MB | — |
| Bitmaps 24 bpp, XCRUSH | 0 | 0 | 0.0 % | 4 MB | 0.0 % | 10 MB | — |
| Bitmaps 32 bpp, XCRUSH | 0 | 0 | 0.0 % | 6 MB | 0.0 % | 16 MB | — |
| 16 bpp, XCRUSH + drawing orders | 0 | 0 | 0.0 % | 2 MB | 0.0 % | 8 MB | — |
| Graphics pipeline (ClearCodec, RemoteFX progressive) | 0 | 0 | 0.0 % | 2 MB | 0.0 % | 18 MB | — |
| Graphics pipeline + H.264 | 0 | 0 | 0.0 % | 2 MB | 0.0 % | 20 MB | — |

### Scrolling text (console listing C:\Windows, 15 s)

| Mode | Server → PHP (KB/s) | PHP → browser (KB/s) | PHP: CPU | PHP: peak RAM | Browser: decoding CPU | Browser: decoder RAM | Frames/s |
|---|---:|---:|---:|---:|---:|---:|---:|
| Bitmaps 16 bpp, no compression | 3,143 | 3,142 | 1.9 % | 2 MB | 18.1 % | 39 MB | — |
| Bitmaps 16 bpp, MPPC | 481 | 481 | 30.2 % | 2 MB | 15.6 % | 82 MB | — |
| Bitmaps 16 bpp, NCRUSH | 197 | 196 | 7.9 % | 2 MB | 5.9 % | 52 MB | — |
| Bitmaps 16 bpp, XCRUSH (default) | 119 | 119 | 6.5 % | 8 MB | 7.4 % | 75 MB | — |
| Bitmaps 15 bpp, XCRUSH | 278 | 278 | 16.0 % | 8 MB | 14.1 % | 79 MB | — |
| Bitmaps 24 bpp, XCRUSH | 208 | 208 | 12.7 % | 8 MB | 20.9 % | 87 MB | — |
| Bitmaps 32 bpp, XCRUSH | 260 | 259 | 14.1 % | 8 MB | 20.4 % | 100 MB | — |
| 16 bpp, XCRUSH + drawing orders | 119 | 119 | 6.4 % | 8 MB | 8.9 % | 90 MB | — |
| Graphics pipeline (ClearCodec, RemoteFX progressive) | 60 | 59 | 0.8 % | 2 MB | 7.1 % | 44 MB | 20.9 |
| Graphics pipeline + H.264 | 38 | 38 | 0.6 % | 2 MB | 4.2 % | 29 MB | 11.5 |

### Window moved in circles (15 s)

| Mode | Server → PHP (KB/s) | PHP → browser (KB/s) | PHP: CPU | PHP: peak RAM | Browser: decoding CPU | Browser: decoder RAM | Frames/s |
|---|---:|---:|---:|---:|---:|---:|---:|
| Bitmaps 16 bpp, no compression | 1,224 | 1,224 | 1.7 % | 2 MB | 9.6 % | 34 MB | — |
| Bitmaps 16 bpp, MPPC | 203 | 203 | 12.2 % | 2 MB | 8.6 % | 69 MB | — |
| Bitmaps 16 bpp, NCRUSH | 328 | 328 | 15.3 % | 2 MB | 16.0 % | 74 MB | — |
| Bitmaps 16 bpp, XCRUSH (default) | 150 | 150 | 6.5 % | 8 MB | 8.9 % | 66 MB | — |
| Bitmaps 15 bpp, XCRUSH | 170 | 170 | 8.6 % | 8 MB | 10.0 % | 79 MB | — |
| Bitmaps 24 bpp, XCRUSH | 503 | 503 | 24.2 % | 8 MB | 14.6 % | 86 MB | — |
| Bitmaps 32 bpp, XCRUSH | 424 | 424 | 18.2 % | 8 MB | 15.0 % | 88 MB | — |
| 16 bpp, XCRUSH + drawing orders | 60 | 60 | 2.7 % | 6 MB | 8.2 % | 80 MB | — |
| Graphics pipeline (ClearCodec, RemoteFX progressive) | 35 | 34 | 2.1 % | 2 MB | 8.5 % | 60 MB | 19.4 |
| Graphics pipeline + H.264 | 113 | 111 | 0.4 % | 2 MB | 11.4 % | 45 MB | 13.9 |

### Full-screen animation (Ribbons screensaver, 15 s)

| Mode | Server → PHP (KB/s) | PHP → browser (KB/s) | PHP: CPU | PHP: peak RAM | Browser: decoding CPU | Browser: decoder RAM | Frames/s |
|---|---:|---:|---:|---:|---:|---:|---:|
| Bitmaps 16 bpp, no compression | 5,400 | 5,398 | 2.7 % | 2 MB | 24.5 % | 40 MB | — |
| Bitmaps 16 bpp, MPPC | 862 | 862 | 38.2 % | 10 MB | 10.2 % | 80 MB | — |
| Bitmaps 16 bpp, NCRUSH | 886 | 885 | 37.4 % | 12 MB | 16.5 % | 86 MB | — |
| Bitmaps 16 bpp, XCRUSH (default) | 907 | 907 | 44.0 % | 16 MB | 10.6 % | 100 MB | — |
| Bitmaps 15 bpp, XCRUSH | 799 | 798 | 40.2 % | 16 MB | 12.4 % | 97 MB | — |
| Bitmaps 24 bpp, XCRUSH | 1,222 | 1,221 | 52.2 % | 14 MB | 14.0 % | 111 MB | — |
| Bitmaps 32 bpp, XCRUSH | 1,152 | 1,151 | 52.5 % | 16 MB | 13.8 % | 107 MB | — |
| 16 bpp, XCRUSH + drawing orders | 751 | 750 | 35.5 % | 14 MB | 9.6 % | 92 MB | — |
| Graphics pipeline (ClearCodec, RemoteFX progressive) | 174 | 171 | 1.4 % | 2 MB | 105.3 % | 110 MB | 17.1 |
| Graphics pipeline + H.264 | 201 | 197 | 0.8 % | 2 MB | 41.9 % + H.264 in hardware | 64 MB | 22.3 |

The workloads are not exactly repeatable (the console lists at a different speed depending on free CPU): differences under 30 % between two modes are not significant.

## Conclusions

- **PHP (the hosting).** With compressed bitmaps PHP decompresses everything to keep the history shared with the server: 6–15 % of a core with text or windows and 35–52 % with full-screen animation. Without compression it spends only 2–3 %, but sends 6 to 26 times more data than XCRUSH. **With the graphics pipeline it spends 0.4–2 % in every case**: the mode that allows the most sessions per server. PHP memory per connection is 2–16 MB.
- **Bandwidth.** The graphics pipeline uses the least with text (38–60 KB/s against 119 with XCRUSH) and when dragging windows (35 KB/s against 150; 60 with drawing orders). With the animation, 170–200 KB/s against 750–1200 for compressed bitmaps and 5.4 MB/s uncompressed. Idle costs nothing: the server only sends what changes.
- **Browser.** Decoding CPU is moderate (4–25 %) except for the animation with RemoteFX progressive (≈100 % of a core on this computer, already after making that decoder 5.6 times faster; in the browser it runs in a Web Worker and the canvas composes faster than this test). With H.264 it drops to 42 % because WebCodecs decodes the video (usually on the GPU). Decoder memory is 30 to 110 MB.
- **Colour depth.** 16 bpp is the best trade-off: 24 and 32 bpp nearly double traffic and PHP CPU with bitmaps; 15 bpp does not save noticeably against 16. The graphics pipeline always works at 32 bpp and still uses less.
- **Compression.** XCRUSH (offered by default) gives the least traffic with text and windows; MPPC and NCRUSH cost more PHP CPU for less saving.
- **Drawing orders** (on by default): with XCRUSH, dragging a window goes from 150 to 60 KB/s and PHP CPU from 6.5 to 2.7 %. No leftovers: after dragging windows and scrolling text, at 16 and 32 bpp, the screen does not change a single pixel when the server is asked to repaint it all (`REFRESH_CHECK=1`).
- **H.264.** With this Windows (no graphics card, sharing 2 cores with the test itself) Windows encodes H.264 in software and gives somewhat fewer frames when dragging windows or scrolling text, though it offloads the browser. With the animation it gives more frames (22 against 17) with much less browser CPU. On a server with a graphics card or more cores it is expected to win in every case.

## Recommendation by server

| Server | Best option | Why |
|---|---|---|
| Windows 8.1 / Server 2012 R2 or later | Graphics pipeline, with H.264 when the browser decodes it | Least traffic and almost no PHP CPU; H.264 frees the browser CPU (key on phones) |
| Windows 7, 8, Server 2008 R2 / 2012 | 16-bit bitmaps with XCRUSH and drawing orders | No useful graphics pipeline (or no H.264) |
| Windows XP, Server 2003 | 16-bit bitmaps with MPPC (the only compression they have) and drawing orders | No graphics pipeline; with *Standard RDP Security* PHP also decrypts everything with RC4 |
| xrdp | 16-bit bitmaps with MPPC (all it supports) and drawing orders | xrdp 0.9 has no graphics pipeline |

The **Graphics: Automatic** option (the default) chooses this way: graphics pipeline (with H.264 when the browser decodes it) when the NLA sign-in reports Windows 8.1 / Server 2012 R2 or later, and bitmaps at the connection colour depth otherwise. A RemoteApp connection always uses bitmaps and drawing orders (no graphics pipeline).

## Other measurements (second review)

| What | Before | After |
|---|---:|---:|
| RemoteFX progressive, two rectangles in corners of the screen: decoded boxes | 8.3 MB | 33 KB (a box per rectangle) |
| The same: worker / main thread in Chrome | 0.93 / 17.8 ms | 0.51 / 6.7 ms |
| Shared drive with 3000 files: opening it (PHP) | 205–238 ms | 0.2–1.4 ms |
| The same: free-space queries (PHP) | 213–233 ms | 0.1–0.4 ms |
| Audit export of 20 000 rows: PHP memory | about 71 MB | about 20 MB (streamed row by row) |
| The same, JSON: time | 0.32–0.36 s (unsigned) | 0.21–0.23 s (signed) |
| Folder tree of the file explorer, 1,000 folders and 20,000 files (Windows) | 2.4 s (every folder) | 64 ms (open folders only; listing a folder 33 ms, as before) |
| Audit, a day of 60 MB (150,000 lines): PHP memory of a search, the sign-in notice and *Verify integrity* | 128 MB | 3.7 MB (read a line at a time) |
| The same: time of a search / the sign-in notice / *Verify integrity* | 0.27–0.49 / 0.10–0.13 / 0.90–1.03 s | 0.31–0.41 / 0.002 / 0.95–1.47 s |

When the rectangles cover at least half of their bounding box, progressive decoding is unchanged (one box). The shared drive reads the per-user quota counter instead of adding up the folder.

## Third review: latency of the picture and the input

Measured on this computer (the browser's sending on browser doubles with a virtual clock: each request to the gateway takes 200 ms ± 30 %, a mouse move every 16 ms, 30 frames/s).

| What | Before | After |
|---|---:|---:|
| A batch of compressed updates (the FreeRDP fixtures) reaching the browser: MPPC 40.5 KB / NCRUSH 107 KB / XCRUSH 21 KB | after 20 / 128 / 97 ms | after 0.11 / 0.07 / 0.39 ms (the same decompression, done after) |
| Graphics-pipeline frame acknowledgements while the mouse moves: longest time without one | 771 ms | 259–299 ms |
| A key pressed while the mouse moves: wait, median / most | 31 / 120 ms | 0 / 21 ms |
| Input reaching the session on a Windows server | up to 20 ms (queue file) | at once (TCP port of this computer) |
| Listing a folder in the file manager, 20,000 files in the user's folder | 7.5–17 s (the whole folder walked) | milliseconds (the quota counter; walked when opened or refreshed) |
| Saving a connection of the administrator for 100 users | 1.1 s | about 2 ms |
| One copy of 64 × 16 pixels within a 1920 × 1080 screen (a console line scrolling) | 6.7 ms (the canvas copied whole) | 12 µs (through a scratch canvas) |
| 600 console scrolls in an 800 × 600 window on 1080p, drawing time | 5.5–6.3 s | 1.2–1.3 s |
| RemoteFX progressive animation (1366 frames): the page's own thread busy | 6.6–7.2 s | 2.0–2.6 s |
| The same recording decoded in the worker | 2.08–2.09 s | 1.70–1.90 s (the same pixels: same hash) |
| Audit search on a day of 110,000 lines (the text now looked for in what a line shows), best of 12: no text found / a user / a connection name / an event / a reason / one letter on every line | 130 / 158 / 156 / 199 / 162 / 229 ms | 111 / 137 / 149 / 184 / 166 / 329 ms |

The canvas figures were measured in Edge drawing in software (this computer has no graphics card). Keeping the bitmap cache as image data instead of one canvas per entry was ten times faster there, but not adopted: on a graphics card each put uploads the whole image again, and that could not be measured here.

## Fourth review (1.1): decoders, delivery to the page and slow computers

Measured on the test computer: 2 virtual CPUs and no graphics card (Edge draws the canvas in software). "Before" and "after" are the code before and after this review, run in turns on the same data; runs vary by ±10–20 %, so smaller differences are noise. Every decoder gives the same output before and after (the same bytes, the same pixels).

**The gateway (PHP)**, decompressing recorded updates of a FreeRDP server (an animation, scrolling text and a dragged window, with each compression) and the test vectors of FreeRDP's compressors:

| What | Before → after |
|---|---|
| MPPC decompression | 2.0–2.5 times faster |
| XCRUSH decompression: an animation at 16 and 32 bits / scrolling text and a dragged window / its test vectors (its level 2 is MPPC) | 2.1–2.2 / 1.8 / 11.6 times faster; its level 1 (the copies from its 2 MB history) 13–19 % faster |
| NCRUSH decompression | 1.18–1.37 times faster |
| RC4, 1 MB in pieces of 12 bytes to 16 KB | 1.35–1.86 times faster |
| Standard RDP Security: 1 MB of server PDUs opened (RC4 and MAC), in pieces of 20 bytes to 16 KB | 1.26–1.84 times faster |
| PHP memory of a session | 0.04–0.08 MB more at its peak |
| A turn of the session's loop (Windows) | 15.6–17 µs → 7.3–9.3 µs (new input connections looked for only when the listening socket is readable, or 20 ms after the last look) |
| A request of input or frame acknowledgement (Windows, PHP's built-in server) | about 15 ms without OPcache, 5 ms with it ([HOSTING](HOSTING.md#requirements)) |

**The browser's decoders** (the page's decoders run by Node.js on recorded streams, as `tests/bench.mjs` does; the time to decode each recording, best of 7):

| Recording | Before | After |
|---|---:|---:|
| 16-bit bitmaps: a console listing / another recording / an animation | 684 / 518 / 188 ms | 510 / 342 / 130 ms |
| A dragged window at 24 / 15 bits | 162 / 38 ms | 99 / 27 ms |
| 32-bit bitmaps (planar) | 460 ms | 331 ms |
| Graphics pipeline, planar: a dragged window / text | 436 / 112 ms | 321 / 75 ms |
| Graphics pipeline, uncompressed | 72 ms | 41 ms |
| RemoteFX: dequantization of one component | 5.83 µs | 2.58 µs |
| RemoteFX progressive | 4,249 ms | 3,317 ms (22 % less) |
| The whole graphics pipeline, two progressive animations | 4,014 / 1,107 ms | 2,912 / 838 ms |
| ZGFX | 280 ms | 130 ms |
| ClearCodec (with its NSCodec), a Windows 11 animation | 324 ms | 294 ms |
| XCRUSH in the worker: an animation / a dragged window | 323 / 280 ms | 203 / 188 ms |
| MPPC, scrolling text | 16.3 ms | 12.3 ms |
| NCRUSH, its test vectors | 3.08 ms | 2.39 ms |

Their memory: at most 256 KB of scratch space per buffer in `gfx.js` and about 20 KB of tables in `rfx.js`, with fewer garbage collections.

**Delivery to the page** (Edge, the page's own thread):

| What | Before | After |
|---|---:|---:|
| A graphics-pipeline operation of 2, 4 and 8 areas at 1080p (a fill, cache to surface, surface to surface) | 5.5–43 ms | 0.2–0.6 ms |
| An H.264 frame of 2, 4 and 8 areas | 15–109 ms | 4.7–6.4 ms |
| Bitmaps of four recordings (the 16-bit console listing first): the page's thread busy | 371 / 463 / 407 / 233 ms | 210 / 235 / 265 / 151 ms |
| The same: the whole replay | 2.6 / 3.0 / 2.2 / 1.5 s | 2.0 / 2.2 / 1.7 / 1.1 s |

The graphics pipeline draws an operation of several areas, and an H.264 frame, once through all of them (each drawing of a frame converted the whole of it); the bitmaps of an update reach the page together, in messages of 64 bitmaps and about 4 million pixels at most (see [ARCHITECTURE](ARCHITECTURE.md#screen-path)).

**Slow computers** (Edge limited to 25 % and 16.7 % of the CPU: a quarter and a sixth; the recordings sent as fast as the page takes them, or at their own pace):

| What | Before (25 % / 16.7 %) | After (25 % / 16.7 %) |
|---|---:|---:|
| A 1024 × 768 animation through the graphics pipeline, 138 frames: frames per second | 15.2 / 9.6 | 18.4 / 12.3 |
| The same, 401 frames | 18.4 / 9.7 | 19.9 / 12.3 |
| A 16-bit console listing (24,388 bitmaps): time to show all of it | 9.4 / 16.5 s | 5.4 / 8.8 s |
| Bitmaps sent at their pace: from their arrival to their drawing, median | 1,072 / 1,842 ms | 617 / 683 ms |

With a server that paces its frames by the acknowledgements (the graphics pipeline), their latency stays bounded (it does not grow as the session goes on), and from the bytes' arrival to the pixels drawn takes 19–21 ms (median).

Known limit: a burst of hundreds of full-screen scrolls from an old server (ScrBlt orders) queues on the page until it is drawn; the wait is bounded by the burst, it does not grow beyond it. Holding the server back while the page catches up was tried and dropped: 12–25 % less throughput.

## Microphone

Measured on the test computer (Windows 11 Pro, Edge with its simulated microphone, 30 s of *Settings → Sound → Test your microphone*):

| What | Measured |
|---|---|
| Format Windows 11 asks for | 16-bit PCM, 44.1 kHz stereo (the only uncompressed one it offers), packets of 441 frames (10 ms) |
| Traffic upwards while a program records | 176 KB/s (1.4 Mbit/s); nothing while none records |
| Requests from the page | about 10 a second (pieces of 100 ms), on the same 4 request slots as the input; median 22 ms, 90 % under 36 ms |
| Audio sent by the page / given to Windows | 29.0 s / 29.3 s in 30 s (the first half second is the browser opening the microphone); 10-second blocks of 9.9–10.1 s |
| From the remote program asking to the first audio | about half a second (the browser opening the microphone; asked once by the browser the first time) |
| Level measured by Windows | 99 % with the simulated microphone's tone (3 % before, with no microphone) |

The page resamples in an audio worklet (a 4th-order low-pass filter first when the rate goes down) and joins the pieces waiting to be sent, at most a second: on a slow network the remote computer hears a little later, never more and more later.

## Camera and a folder of this computer (1.1)

Measured on the test computer (Windows 11 Pro), through the dev server on the same computer:

| What | Measured |
|---|---|
| Camera: format Windows chose | Motion JPEG 640 × 480 at 30 a second (the first offered); one Sample Request at a time |
| Camera: pictures and traffic upwards | Edge's simulated camera: 19–24 pictures a second, about 8 KB each, 150–185 KB/s; Firefox's: about 12 a second, about 6 KB. A real camera's pictures are larger (an estimated 30–50 KB at 640 × 480, about 0.5–1.5 MB/s); a slower connection gets fewer pictures, never later ones |
| Camera: the input meanwhile | not held up: the pictures go on a lane of their own, out of order, two requests at most |
| Camera: the page in another tab | the same rate: the pictures are paced by a worker's timer (a hidden page's own timers run once a second at most) |
| Folder: listing 155 files (PowerShell `Get-ChildItem`) | 12.96 s one name per request (each a round trip to the browser, about 84 ms here), 1.0 s with the names sent with the first one and answered by the gateway |
| Folder: a file read or written | in pieces of up to 1 MB (a read) or as Windows writes them; what a program writes is saved in the browser's folder when it closes the file, or before it is read (the browser reads only what is saved), and the next write copies the file first: a program reading and writing the same large file by turns is slow there. A file emptied when opened is not copied first |
