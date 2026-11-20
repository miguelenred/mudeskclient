# Phone keyboards and Windows 11

[Español](es/TECLADO-MOVIL.md)

## The problem

When typing from the on-screen keyboard of a phone or tablet, Windows 11 Notepad, Terminal and Explorer garble the text:

- they repeat the last letter of a word ("or not" comes out as "tttttt");
- they drop capitals, accents or single letters;
- they lose an Enter or a Ctrl+V.

WordPad, Word, browsers and Windows search receive it fine. The physical keyboard is not affected either.

It is not a MuDeskClient bug. Other programs that send keyboard input to Windows 11 describe it too:

- [nemo-voice-typing #4](https://github.com/Garnet-Owl/nemo-voice-typing/issues/4) and [dictatem #278](https://github.com/JohnJohn4/dictatem/issues/278): dictation;
- [nectarlink #288](https://github.com/thtbee/nectarlink/pull/288) and [relay #20](https://github.com/mohamedmoheyeldin/relay/pull/20): automatic typing;
- [justrdp #532](https://github.com/kihyun1998/justrdp/pull/532): the Windows 11 24H2 console drops Unicode letters sent over RDP, with any client.

Microsoft has not acknowledged or fixed it (November 2026).

## Why it happens

RDP has only two ways to send text: **Unicode letters** or **keys** (scancodes, following the Windows keyboard layout). Microsoft's Windows App on Android uses the same two: Unicode by default, or keys when "Use scancode input when available" is on. Against the same Windows it should have the same problem; it was not tested here, as that needs an Android phone.

Measured against Windows 11 Pro with Notepad 11.2609, Notepad's spell check and autocorrection go through the text in the background. While they work, a key can be lost when another control key (End, Ctrl) or a paste arrives right behind it. A normal letter right behind does not spoil it.

Windows gives no sign of when that work is done. So no client can wait for it exactly; it could only wait a guessed time, which fails depending on how busy the computer is.

## Tests

In Windows 11 Pro Notepad, over RDP from MuDeskClient. Each line was "Hola, ñandú ¿qué? ÁÉÍÓÚ áéíóú Ü ü Zorro Más Pequeño 1234567890. € @# fin", sent word by word as a phone keyboard sends it.

| Test | Result |
|---|---|
| Unicode letters, one every 100 ms, with spell check | 1 of 3 lines wrong |
| The same, without spell check | 3 of 3 lines right |
| Keys of the layout, at a person's pace | 1 of 3 lines wrong (a capital lost) |
| Keys of the layout, all at once | 3 of 3 lines wrong |
| **Pasting** the text through the clipboard | **8 of 8 lines right**, in every round |
| Enter with nothing behind, after a paste | 0 of 8 Enters lost |
| Enter with a letter right behind | 0 of 18 lost |
| Enter with End or Ctrl+End right behind | 2 of 16 lost |
| Idle Notepad, Enter with a paste right behind, with spell check | 3 of 8 lost |
| The same, without spell check | 0 of 8 lost |
| Stress test: pasted line, Enter and the next line with no pause | 1 to 3 of 8 lost (with or without spell check) |
| **Real pace**: 0.3 s to the Enter and 0.4 s to the next word, with spell check | Text 24 of 24 right; Enters 22 of 24 (three rounds). In one, Notepad ignored a Ctrl+V and the text was typed after 10 s |

Tried and dropped:

- spacing the letters further (100 and 150 ms);
- sending keys of the layout instead of letters;
- delaying each key's release;
- an empty check paste before the next key: 2 of 8 Enters were still lost, and the clipboard changed more often.

## What MuDeskClient does

**The "phone keyboard through the clipboard" option**

- It is in each connection's settings: "Automatic", "Always" or "Never".
- On "Automatic" it is ticked by itself on phones and tablets (touch screen without a mouse) and left unticked on computers.
- In the connect dialog it can be ticked or unticked for that session.
- It needs the shared clipboard.
- The ⓘ icon next to the option explains the problem, the tests and the sources inside the application.

**With the option on**

- **Single letters:** go as before, as Unicode, one every 100 ms.
- **Several letters at once** (autocorrection, swipe typing, dictation): they are **pasted**:
  1. If the last thing was copied in Windows, the gateway first reads it (up to 16 MB), in all its formats (text, formatting, image…).
  2. It announces the text on the remote clipboard, kept out of the history (Win+V) and the cloud.
  3. It waits for Windows' answer to that announcement and for the screen to show the last input sent.
  4. It presses Ctrl+V and holds it until the application asks for the text.
  5. Right after each paste it puts the clipboard back as it was, also out of the history (an empty text if there was nothing).
- **Two pastes in a row:** Windows answers the announcement before applying it, gives no sign when it does (with the computer busy it took over 1 s) and did not apply a list equal to the one it had. With two pastes in a row, the second Ctrl+V pasted the previous text again (in the Run box, "control control" instead of "control printers"). Now the clipboard is put back right after each paste and each list carries a format of its own that alternates, so two lists in a row never match; whichever list the application reads, it asks the gateway for the text and gets the new one. Measured on Windows 11 Pro: 21 of 21 pastes in a row right in the Run box and 30 of 30 words in Notepad (before, the bug showed on the first or second try).
- **Texts in a row:** they are joined into one paste.
- **Whatever arrives meanwhile:** waits its turn and goes out once the application has drawn what was pasted.
- **What cannot be put back** (files copied in Windows, or more than 16 MB): the text is typed instead of pasted, so the copied content is not lost.

Each step waits for a sign from Windows, not for a fixed time. There are only limits in case Windows does not answer: 10 s to read what was copied, 2 s for the answer to the announcement and 10 s for the application to ask for the text (past any of them, the text is typed); and if the screen has not changed within 2 s of that answer, Ctrl+V is pressed anyway.

**Limitations**

- Ctrl+V does not mean "paste" in every program (PuTTY, vim): set the option to "Never" for those.
- In the stress test, an Enter followed by more text with no pause can still be lost in Notepad (1 to 3 of 8). At a phone's real pace 2 of 24 were lost.
- Notepad sometimes ignores a Ctrl+V while its spell checker works: the text is typed after 10 s, right but late (1 of 30 words in the last test).
- If Windows is so busy that it has not applied a list announced long before, or if the last thing copied in Windows is an image, a paste could still take the previous content. It did not happen in the tests.

## Recommendation

When typing a lot from a phone in Windows 11 Notepad, turn off **Settings → Spelling → Spell check** and **Autocorrect** in Notepad itself. In the tests above, without spell check the spaced Unicode letters all came out right, and the Enter followed by a paste was never lost with Notepad idle.
