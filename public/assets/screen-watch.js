// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Runs in the window of another screen of a session (app.js screenView puts it there): that window closes itself once the
// session's window is gone (its tab closed, crashed, or gone to another page), which then could not close it. Checked once
// a second. The session's window also closes it when the session ends or the tab goes (pagehide).
setInterval(() => {
  let alive = false;
  try {
    alive = !!opener && !opener.closed && !!opener.document.querySelector('.session');
  } catch {
    // another site in that tab: gone
  }
  if (!alive) close();
}, 1000);
