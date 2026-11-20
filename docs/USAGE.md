# User guide

[Español](es/USO.md)

## First start

The first visit asks for the one-time **setup code** (in `data/setup-code.php` on the server; it stops anyone else from claiming a fresh install) and creates the **administrator** account. Passwords need at least 12 characters and 72 bytes at most (72 plain letters; accented letters and symbols take more), at least 4 different characters, and must not be a common password nor contain the user name. The administrator creates the other users with a temporary password, which they must change at their first sign-in.

The language (Spanish or English) is changed with the selector in the header, at the top right; it is saved in each user's profile. Before signing in it is picked on the sign-in screen. In *Profile*, **Automatic** follows the browser's language. The pages end with a link to the project's site.

## Connections

Each user keeps their own connections (*Connections → New connection*). Those marked **From the administrator** were made by the administrator for you: you connect with them and keep your own password and options for them, but cannot change or delete them.

**Import .rdp** creates connections from files of Remote Desktop Connection: one file opens the form filled in to check and save; several are saved at once. What MuDesk does not take is said there (a RemoteApp file without its program: the whole desktop opens; an RD gateway; a start program; the saved password, which only the Windows that saved it can read). A RemoteApp file with its program becomes a RemoteApp connection, and exporting keeps the program. Imported connections get the Spanish keyboard layout, as new ones: check it. The download button of each connection exports it as an **.rdp file** for Remote Desktop Connection (without its password).

In the form, *Keyboard layout* and the fields after it in this table (all but the RemoteApp ones) are in the folded section *Advanced options*.

| Field | Notes |
|---|---|
| Name, group | Groups organise the list |
| Computer, port | IP or host name; 3389 by default |
| Remote user, domain | The user field also accepts `DOMAIN\user`, `HOST\user`, `.\user` (local account of the remote computer) and `user@domain` (UPN: sent as is, the domain field is then ignored) |
| Remote password | Optional and **not recommended** (whoever gets into the account can connect without knowing it; the form says so). Encrypted with a key derived from the user's password; leave empty to be asked when connecting. The administrator can forbid it (*Settings*) |
| Keyboard layout | Must match the **physical** keyboard of the user (keys are sent as scancodes) |
| Color quality | 16 bits is recommended (half the bandwidth of 32 bits) |
| Security | Automatic (NLA when available), NLA only, TLS only |
| When the window is resized | Change the remote resolution, or scale the picture |
| Clipboard, shared folder, audio, microphone, MuDeskPDF printer | Defaults for the connect dialog (the microphone off unless ticked) |
| Phone keyboard through the clipboard | Automatic (on for phones and tablets), Always or Never: see [Phones and tablets](#phones-and-tablets) |
| Graphics | **Automatic (recommended)**: with Windows 8.1 / Server 2012 R2 or later (the version is seen when signing in with NLA) it uses the graphics pipeline with H.264 when the browser decodes it, and with other servers (xrdp, older Windows, TLS only) bitmaps at the chosen colour depth. **Graphics pipeline and H.264**: always the graphics pipeline (forces 32 bpp; less traffic and almost no CPU on the web server). **Bitmaps only**: never the graphics pipeline. See [PERFORMANCE](PERFORMANCE.md) |
| Program (RemoteApp), arguments | In the section *RemoteApp: one program instead of the desktop*, folded for a desktop connection and unfolded for one with a program (click its title to fold or unfold it). Only that program, in its own windows over the page (see *RemoteApp*); empty: the whole desktop |
| Ask for the options when connecting | Shows the connect dialog every time |
| Visual effects | Wallpaper, themes, font smoothing, animations, window contents while dragging |
| Server certificate | Saved on the first connection; if it changes, the session window warns and offers **Trust and connect**; "Forget certificate" clears it |

**Connect** opens the session in a new tab, so several sessions can be open at once. When the server already has as many sessions as the administrator allows for all users together, the session window says the server is busy: try again later.

## Connect dialog

Before each session (unless "remember" was chosen) you choose: remote audio, this computer's microphone on the remote one, clipboard, phone keyboard through the clipboard, shared folder, MuDeskPDF printer, graphics (not for a RemoteApp, which says which program it opens), color quality and **use all my screens** (Chrome/Edge: only with several screens; Firefox and Safari: the other screen is looked for, ticked already once one was found; not on phones and tablets; its **ⓘ** says what this browser asks first: the permission to manage windows on all the displays in Chrome and Edge, pop-ups allowed in Firefox and Safari). Connections without a saved password also ask for the credentials here, with **Save password (not recommended)**: it is stored only once the connection works. A wrong password asks for them again. Under *Advanced options*, **Diagnostic mode** records a technical trace of that session for the administrator (see Administration). The X at its top right closes the window; if the browser does not let the page close it, it goes back to the connections (also the **Close** of the window that says the session was disconnected).

## RemoteApp: a program on its own

With **Program (RemoteApp)** filled in (its path on the remote computer, such as `C:\Windows\System32\notepad.exe`, or the name it was published with, such as `Excel`: MuDesk adds the `||` that Windows expects before it), the connection shows only that program: its windows, each one where it is, over the page, without the remote desktop. They are moved, resized and closed with their own title bars, and the tab title says which one is active.
- A taskbar at the bottom of the page has a button for each window: click one to bring it to the front (also when it is minimized), or click the active one to minimize it. Maximized windows leave it in view.
- The **Windows** button of the toolbar lists them too: click one to bring it to the front, or minimize, maximize or close it.
- The remote computer must allow the program: Windows Server with Remote Desktop Services publishes RemoteApp programs; on Windows 10/11 Pro it is allowed in its registry (see [HOSTING](HOSTING.md#remote-computers)). Otherwise the session says so ("does not allow this program as a RemoteApp"); it also says when the program is not found or could not be opened, when the remote session is locked, and when the computer does not offer RemoteApp at all (connect without a program).
- When the program is closed (its last window), the session ends about 3 s later and says so; **Reconnect** opens the program again. Windows keeps the session disconnected, as with Remote Desktop Connection, until its time limits log it off. If the program was still running there, **Reconnect** brings its windows back without opening a second one.
- Until the program's first window comes, a spinner in the middle of the page (and the taskbar) says it is opening: some programs take a while. After 15 s it says it is taking longer than usual. If the server ends the session meanwhile (it does after some 12 s without a program, also while one is starting), the page connects again by itself and keeps waiting; after 90 s without the program it says so, with **Reconnect**.
- On several screens (see *Several screens*), each window of the program shows on the screen where it is; the session's screen is the remote primary one, so the program opens there, beside its taskbar.
- This version uses bitmaps (not the graphics pipeline or H.264), and the remote desktop always follows the window (the *When the window is resized* option does not apply).

## Session toolbar

The toolbar hides itself at the top; move the mouse there or tap the small tab to show it. Drag it by its grip (at its left) or its title to move it to a side (or use the arrow keys on the grip); it stays there for the next sessions in this browser.

Next to the name, the **quality indicator** shows every second the frames per second on screen, the input latency to the gateway and the data received (for example `24 fps · 35 ms · 120 KB/s`). The dot is green below 100 ms, amber up to 250 ms and red above; with the toolbar hidden, the small tab takes that colour when it is not green.

| Button | Action |
|---|---|
| Full screen | Full screen with keyboard capture (Win, Alt+Tab… where the browser allows it). With several screens, it offers to use all of them (see *Several screens*) |
| Ctrl+Alt+Del, Windows key | Send these keys |
| On-screen keyboard | On phones and tablets, opens the device keyboard. With a mouse, opens and closes a keyboard of our own, used with the mouse (see *On-screen keyboard*) |
| Special keys | Esc, Tab, sticky Ctrl/Alt/Shift/Win, arrows, Home/End, PgUp/PgDn, Del, Backspace, Enter, F1–F12 |
| Mute | Remote audio on/off |
| Screenshot | **Full screen** (the whole remote desktop; with several screens, all of them; with RemoteApp, the program's windows on the session's screen) or **Select an area** (drag a frame over the remote picture; Esc or right click cancels). Only the remote picture, without the toolbar, the panels or the pointer, at its real size even when the window shows it smaller. Then: **Save** (PNG), **Copy** (to this device's clipboard) or **To the remote computer** (to its clipboard, to paste it there with Ctrl+V; with the shared clipboard on) |
| Clipboard | Text copied remotely appears here; type or paste text and send it to the remote clipboard. Below it, the **history** (like Win+V) |
| Files | Send files to the remote clipboard (then Ctrl+V in Explorer) and download files copied remotely (ZIP when there are several) |
| Screens | Lists the screens: **Open** adds a screen's window and its remote monitor, **Close** removes them; in Firefox, **Look for other screens** |
| Windows | RemoteApp: the program's windows (see *RemoteApp*) |
| Disconnect | Leaves the Windows session running (disconnected) |
| Hide toolbar | Tucks the toolbar away (it comes back with the pointer at the top, or the small tab) |

**Mute** is there when the session has remote audio, **Clipboard** and **Files** when it has the shared clipboard, **Screens** when it uses several screens (or, in Firefox and Safari on a computer, to look for them), **Windows** with RemoteApp.

**Microphone.** With **This computer's microphone on the remote one** ticked, the remote computer hears this computer's microphone: Windows shows it there as *Remote Audio*. The microphone is only opened while a program of the remote computer records (a call, a dictation...); the first time, the browser asks whether the page may use it (allow it: the icon next to the address changes it later), and while it is open the browser shows its own recording sign. If the browser does not allow it, the page has no microphone, the page is not opened with `https://`, or the remote Windows forbids audio recording, the session goes on without it (the first three are said on the page; for the last one see [HOSTING](HOSTING.md#remote-computers)). The **ⓘ** beside the box in the connect dialog says all this, with how to allow audio recording on the remote Windows: step by step in the policy editor (`gpedit.msc`, an MMC console) or with a command for the registry, and the Windows Audio service on Windows Server.

## Clipboard

- **Text and images**: Ctrl+V in the session sends the local clipboard first; what is copied remotely is copied locally when the browser allows it (always visible in the Clipboard panel).
- **Formatted text**: text copied in Word, Outlook or a browser keeps bold, colours, tables and links both ways (it also goes as HTML, the Windows "HTML Format"). Each application pastes the format it supports: Notepad and WordPad, just the text. The Clipboard panel always sends plain text.
- **Files**: drag them onto the remote screen and drop them where you want them pasted: on the desktop, in an open Explorer folder, in a mail being written… They are uploaded, put on the remote clipboard and pasted with a click on that point and Ctrl+V. The gateway says when the application asks for them: "N file(s) pasted". If it does not within 10 s, they stay on the remote clipboard to be pasted by hand with Ctrl+V.
  - On the desktop, Windows puts them in the first free slot (not exactly where they were dropped).
  - The click is a real click: dropping them on a button presses it.
  - The Files panel only puts them on the remote clipboard (Ctrl+V wherever you want): choose them or drop them on its dashed zone. It lists the files sent last and their state: being sent, on the remote clipboard, pasted, or no longer there once something else was copied. Folders cannot go through the clipboard (use the shared folder). Files copied remotely are offered as a download.
- **History** (Clipboard panel), like Win+V:
  - It keeps the last 25 texts and images that went through the shared clipboard, either way, newest first, with where they came from ("From the remote computer" or "From this device") and the time. Copying the same again moves it up.
  - **Paste** (text only) pastes it into the active application of the remote computer: the gateway puts it on the remote clipboard, presses Ctrl+V and leaves the clipboard as it was, as with the phone keyboard (see [MOBILE-KEYBOARD](MOBILE-KEYBOARD.md)).
  - **Copy** makes it the clipboard on both sides, to paste it with Ctrl+V wherever you want.
  - It lives only in that window's memory (never sent to the gateway nor stored) and is cleared when the window closes; items can be removed one by one or all at once. Texts up to 1 MB and images up to 4 MB.

## Shared folder

*Shared folder* in the web interface manages a personal folder on the web server; in the session it is a drive that Windows Explorer lists under "Redirected drives and folders" as **Shared folder on MuDesk** (in the language of the page; Windows adds its own "on"). The administrator sets the quota; the bar at the top shows how much is used.

It works like a file explorer:

- **Folder tree** on the left (a folder is read when it is opened), the folder's contents on the right in **Details** (name, modified, size; click a heading to sort) or **Icons** (images with a thumbnail), a path bar, **Parent folder** and a search box for the folder. On a phone the tree opens with the **Folders** button.
- **Upload**: **Upload files**, **Upload folder**, or drag files and folders from the computer onto the list (the folder shown) or onto any folder of the list, the tree or the path bar. Dragged folders keep their structure, empty folders included (the **Upload folder** button cannot send empty ones: browsers do not give them). When something with the same name is already there it asks: **Replace** (folders are merged, files overwritten), **Skip** or **Cancel**. The status bar shows the progress, with an X to cancel; the upload carries on while you move to another folder, and leaving the page asks first.
- **Organise**: drag items onto a folder (list, tree or path bar) to move them; holding them over a closed folder of the tree opens it. **New folder** is created and named at once; **Rename** (F2) edits the name in place; **Delete** (Del) asks first, folders with everything in them.
- **Select**: click, Ctrl+click, Shift+click, the boxes, a frame drawn with the mouse on an empty part of the list, Ctrl+A. Keys: arrows, Home/End, Enter opens, Backspace or Alt+↑ goes up, typing jumps to the name. Right click opens a menu.
- **Download**: one file as it is; several items or a folder in one ZIP, written by the server as it is sent (no copy on the server; up to 4 GB and 65,535 items). In Chrome and Edge, items dragged onto the computer's desktop are downloaded there. A double click opens a folder, downloads a file, or shows an image in a new tab.
- The folders of the tree have their own actions: right click (Open, New folder inside, Download as ZIP, Rename, Delete), F2 renames in place, Del deletes after asking, and they can be dragged onto another folder. When the folder you are in is renamed or moved the page goes with it; when it is deleted, the page goes to its parent.
- On a phone or tablet a tap opens; the boxes select (then a tap adds to the selection).

## MuDeskPDF printer

Printing to **MuDeskPDF** in the remote session sends the document to the browser as PDF: a notice offers **Print** (browser print dialog, any local printer) or **Download**. Browsers cannot see local printers directly, which is why printing goes through PDF. Documents that are not PDF (PostScript, XPS) can only be downloaded.

## Several screens

> In **Chrome and Edge** (and other Chromium-based browsers) the *Window Management* API lists the screens. **Firefox and Safari do not have it**: the other screen **is looked for** by opening a window next to the current one (see *Firefox and Safari*, below); the connect dialog says so. **Full screen** always covers the screen the window is on.

Chrome and Edge (Window Management API): tick **Use all my screens**, allow the permission, and each screen becomes a separate monitor of the remote session (not one stretched desktop). The current window goes full screen and, in the same click, the other screen's window opens.
- **A screen is part of the remote desktop only while its window is open.** On connecting, the remote computer has this window's monitor and that of each window already open; opening another screen's window adds its monitor, and closing it removes it, and Windows moves its programs to the screens left. So no program opens on a screen nobody sees. The change is made without reconnecting (Display Control; servers without it reconnect). Until the server takes the new monitor, its window shows black and sends no mouse input.
- The other screens' windows close with the session's window: when the session ends, when its tab is closed or reloaded, or when it shows something else.
- **Full screen** also opens the other screen's window when it is closed (or open them from the **Screens** panel); in each new window press its **Full screen** button. That window has a bar at the top (it comes back with the pointer at the top edge) with **Full screen** / **Exit full screen** and **Close this screen**. Chromium allows one thing per click (full screen or a new window), except a window opened right after asking for full screen on a given screen: that is why it is asked that way. Once connected, the **Screens** panel opens by itself while a screen's window is closed.
- If the session was connected without that option and the computer has several screens, **Full screen** offers **Use all of them** (the session reconnects with a remote monitor on each screen) or **Only this one**.
- With **When the window is resized: Change the remote resolution** (the default), each remote monitor takes the size of its window and follows it: going full screen, resizing the window… with no black margins and no reconnection (Display Control; servers without it reconnect with the new size). The monitors stay side by side (or one above the other), as the screens. With the other modes, each window shows its monitor whole and in proportion.
- Dragging from one screen to the other (a window, an icon) works: while the button is held, the remote point comes from the pointer's position on the screen and the window under it.
- With a **RemoteApp** connection, each screen's window shows the page with the program's windows that are on that screen. The taskbar stays in the session's window, whose screen is the remote primary one.

### Firefox and Safari

Without the API, the page cannot ask for the list of screens, but it can know which screen each of its windows is on. So it looks for the other one:
1. Tick **Use all my screens** when connecting (or press **Screens** → **Look for other screens** in the toolbar, once connected).
2. A window opens right at the corner of the next screen: to the right; or where the other screen was found the time before; or, when the session window is not on the main screen, towards the main one. If there is a screen there, the window lands on it and tells where it is and its size; Firefox puts it back on the current screen when there is none.
3. Found, the session connects (or reconnects) with a remote monitor on each screen, placed as on the desktop, and that window fills the other screen and shows it. Press **Full screen** in each window. Closing that window takes its monitor out of the remote desktop; it comes back with **Screens** → **Open**.
4. If it is not found (for example, the other screen is to the left of the main one), that window says so: take it to the other screen and press **Use this screen**. This browser remembers it and next time looks there directly (and **Full screen** offers to use them all).

Pop-ups must be allowed for the site: browsers let a page open one window per click, which is why one place is tried each time. Two screens are used. Tested in Firefox 160 with two 1920×1080 screens; also in Edge 157, and in Chrome after its permission prompt. Safari has never been tested (no Apple device).

## On-screen keyboard

On a computer with a mouse, the **On-screen keyboard** button opens a full keyboard over the session (Esc, F1–F12, Del, digits, letters and punctuation, Tab, Caps Lock, Shift, Ctrl, Win, Alt, AltGr, Space, Backspace, Enter, arrows; the right Alt is AltGr with the Spanish layout). It works with any server, xrdp included.
- Each key is sent as the physical key: the keyboard layout of the remote session gives its character, as with a real keyboard (dead keys such as ´ and AltGr included).
- Shift, Ctrl, Alt, AltGr and Win stay pressed until the next key (Ctrl + C, Win + E, Shift + 4 = $…), are shown pressed and a line over the keyboard says so. To send one alone, press it again: Windows acts when it is released (Win alone opens Start).
- The labels follow the layout of the connection: Spanish or English (US). With other layouts, the browser's own when it gives them (Chrome, Edge), else the English ones.
- It moves anywhere in the window by dragging it with the mouse from its four-arrow grip or its edge (keys do not move it); it never leaves the window and, opened again, it is where it was left. **X** closes it.

## Phones and tablets

| Gesture | Action |
|---|---|
| Tap | Left click |
| Long press | Right click |
| Drag | Drag with the left button |
| Two fingers up/down | Scroll |
| Pinch | Zoom the picture; drag with two fingers to move around |
| Tap a text box | The device keyboard opens |

With the "Phone keyboard through the clipboard" option (in the connection settings; on "Automatic" it is ticked by itself on phones and tablets, and it can be unticked in the connect dialog), text the phone keyboard sends in one go (autocorrection, swipe typing, dictation) is pasted through the remote clipboard, because Windows 11 Notepad, Terminal and Explorer garble letters that arrive close together. The clipboard is then left as it was (an image copied in Windows included) and the text does not show in the clipboard history (Win+V). Single letters go as before. In programs where Ctrl+V is not paste (PuTTY, vim), set the option to "Never". The ⓘ icon next to the option explains the problem, the tests that found it and the sources. More in [Phone keyboards and Windows 11](MOBILE-KEYBOARD.md).

The keyboard opens when, after the tap, Windows shows the text cursor (the I-beam). It also shows over text that cannot be edited (web pages, read-only documents); close it with the device's button. If it does not open, use the **On-screen keyboard** button on the bar.

## Installing as an app

On phones and computers, browsers that allow it can install MuDeskClient as an app (for example *Install app* in the browser menu, or *Add to Home Screen* on iPhone and iPad, not tested: no Apple device). It opens in its own window with its icon. It is the same web application: there is no service worker and nothing is kept for offline use.

## After an update

When MuDesk is updated on the server, the page loads the new files the next time it is opened. A page that was already open says so at the bottom (**Reload**, or X to close the notice) the next time it asks the server for its state: when you sign in or out, when its session expires, or after changing the language, the password or a second factor. Reloading disconnects a remote session open in it.

## Session expired

The web session closes after the idle time the administrator sets (30 minutes by default) and, in any case, after 12 hours. When it does, a **Session expired** window says why (no activity, the 12 hours, a password change, the account disabled…). **OK** takes you to the sign-in screen and, once signed in again, back where you were.
- The page notices on its own, without waiting for a click: when the session should end, it asks the gateway. That question does not count as activity, so it does not keep an idle session open. If you are using it in another tab, the session stays open and nothing is shown.
- Using a remote session in that window counts as activity and keeps the web session open. If it ends anyway (no input for the idle time, or the 12 hours), the remote session ends with it and the window says so at once.
- Signing out, or the session ending, also ends the remote sessions opened from it. Signing out in one tab ends the other tabs of the same browser too, and each says so.
- Changing your password ends the remote sessions of your other sign-ins; the window where you changed it goes on.
- When your password is asked again (to add a passkey or an authenticator app, change the password or remove a second factor), five wrong ones close the session.

## Profile

- **Passkeys**: each one is "Passwordless" (you sign in with it alone, with **Sign in with a passkey**) or "Second step" (you sign in with your password, then confirm with it). The kind depends on the device and browser: passwordless only if they can keep with the passkey the key that encrypts your remote passwords (the PRF extension); MuDesk says so when you add it, and the list shows it.
- **Password, language, two-step verification (TOTP or passkeys).** A TOTP code works once: a code already used, such as the one that turned 2FA on, is refused and the message asks you to wait for the next one.
- **Your open remote sessions**: the sessions of your user that are running; **End** closes one.
- **Trusted browsers**: the browsers you have signed in from, with when each was added and last used, the browser and the IP. From them you can sign in even while your user is locked by failed attempts of others. **Forget** removes one and **Forget the others** all but this one; their cookies stop working. Removals go to the audit log.
- After signing in, a notice shows your last sign-in (date and IP) and the failed attempts with your user since then, read from the audit log (up to 90 days). If they were not you, change your password and check your trusted browsers. Once closed, the notice stays closed.

When the administrator requires 2FA and you do not have it yet, you sign in and only see your profile until you add TOTP or a passkey.

## Administration

- **Users**: create, enable/disable, delete, **Administrator rights** (a box, unticked for a new user; there can be several administrators, never none: the last enabled one cannot be disabled, demoted or deleted), concurrent sessions, reset password (saved remote passwords are lost, and passkeys then only sign in as a second step after the password), remove 2FA. Disabling or deleting a user, or resetting their password, ends their sessions, remote ones included.
- **Connections**: the administrator's connections, each one for the users ticked or for **all users** (also those created later), who cannot change or delete them; without a Windows user name, each user types theirs when connecting, and each one keeps their own password for it. Below, every user's own connections, which the administrator can change or delete (their saved password can only be forgotten; it is also forgotten when the computer, port, user, domain or security changes). **Import .rdp** works here too: one file (or a new connection) opens with **All users** ticked; several files are saved for nobody yet ("Nobody sees them yet"), to edit and choose their users.
- **Settings**: client IP and RDP target allow-lists, sign-in lock-out, web and remote idle time-outs, sessions per user, **RDP sessions at once (all users)** (20 by default, 0 = no limit; each session holds a PHP process, so keep it below what the server has: see [HOSTING](HOSTING.md#capacity)), reconnection attempts when the connection is lost (10 by default, 0 = none; waits of about 1, 2, 3, 5, 8 and then 10 s: after the first, up to half as long again at random), 2FA required, audit retention, shared-folder quota (one per user for the drive of all their sessions, the file manager and clipboard files) and **Allow saving the password of RDP connections** (on by default). Turning it off, once confirmed, erases the saved RDP passwords of every user (`rdp_passwords_erased` in the audit log, with how many); the connection form loses its password field and the connect dialog its "Save password" box: it is asked for when connecting.
  - With the allowed remote computers on **Allow all**, the server itself (127.0.0.0/8, ::1), link-local addresses (169.254.0.0/16, where clouds keep their metadata, and fe80::/10), the other cloud metadata addresses (100.100.100.200, fd00:ec2::254) and 0.0.0.0/8 or :: are never reached, nor their ::ffff:…, ::a.b.c.d or 64:ff9b:: forms; to reach them, use **Only the list** and list them. Administration shows a warning while "Allow all" is set.
  - To require 2FA for every user, the administrator must have it first; otherwise the setting is refused.
- **Diagnostics**: traces of the sessions started in diagnostic mode (or of every session with *Settings → Diagnostic mode in every session*): TLS/NLA negotiation, server data and capabilities, licensing, channels and their messages, audio formats (and the microphone's: offered, taken and opened), clipboard formats, device redirection, graphics pipeline (versions, commands and codecs counted in the browser), message counts and sizes every 10 s, errors of the gateway and of the browser, end reason and server error code. Never screen, keys, clipboard or file contents, passwords or keys. Download or delete them; they are removed after 7 days (8 MB at most per session). Downloads and deletions go to the audit log. From the command line: `TRACE=1 php tests/rdp_probe.php …`. Useful to send when something fails with a server.
- **Active sessions**: who is connected where; end a session.
- **Audit log**: everything that happens, one line per event with date (ms), user, IP, browser and outcome (succeeded/failed with the reason). Never passwords or contents.
  - Sign-in: each attempt with method (password, TOTP, passkey) and failure reason (`unknown_user`, `bad_password`, `disabled`, `bad_code`, `locked_ip`/`locked_user`/`locked_global`…), setup, logout, sessions closed (idle, maximum time, other browser, password changed, user disabled or deleted), CSRF and IP refusals, refused requests.
  - Profile and administration: password, language, 2FA and passkeys, trusted browsers added (`device_add`) and forgotten (`device_forget`); users, settings and connections with old → new values (saved passwords only as "changed"); session termination (by an administrator or by its owner); audit searches, exports and verifications.
  - Remote sessions: options used (screen size and monitors, colours, audio, microphone, clipboard, drive, printer, H.264, compression), certificate trusted or changed, start, resizes, end with reason, duration and bytes; refusals (`rdp_denied`, for example `busy` when the server is full).
  - Clipboard (each copy, both directions: type and size, file names and sizes), shared drive (each file read or written with bytes, created folders, renames, deletions; plain browsing is not logged), web file manager (uploads, downloads and ZIPs with the names, folders, moves and renames as `file_move`, deletions; thumbnails not logged), print jobs and their downloads.
  - Search by dates, user, event (prefix: `login` finds every sign-in event), IP, free text and outcome; the text is looked for in what a line shows (user, IP, event, browser and each detail as `key=value`, such as `reason=unknown_user`), in upper or lower case of any alphabet, never in the field names, the date or the chain's hashes; summary per event (click to filter); details of a line; export of the filtered results to CSV or JSON (up to 20 000 rows).
  - **Signed export**: the JSON export comes in a ZIP with `audit.json` and `audit.json.sig`, a signature made with the server's RSA key; each row is the line as stored. The CSV is not signed. To check a file, open *Checking the signed exports* (below the export buttons), save the public key as `mudesk-audit-key.pem`, unzip the export and run `openssl dgst -sha256 -verify mudesk-audit-key.pem -signature audit.json.sig audit.json`: it answers "Verified OK", or fails if the file was changed. The key is made the first time (about 2 s) and kept in `data/audit-key.php`.
  - Kept 90 days by default; *Settings → Days of audit log to keep* (0 = no limit). The purge is itself recorded.
  - **Verify integrity**: every line is signed and chained to the previous one (HMAC with the server key). Nothing is hidden: changed or inserted lines and missing lines are listed and marked in red. It checks up to the moment it starts; lines written meanwhile are left for the next check. Someone with the server key (`data/secret.php`) could rewrite the whole chain, so keep a copy of the logs elsewhere if you need stronger proof.
