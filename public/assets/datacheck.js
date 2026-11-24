// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0

/**
 * Can this browser read the data folder (a web server that does not apply its protection)? What it read goes to the gateway,
 * whose code in it proves it (Exposure::proof): nothing works until it is protected, and the page, loaded again, says why.
 * Once per browser session while it cannot ($pref '0': the browser logs the expected 403/404 answers in its console), on each
 * load while it could. Where the gateway can ask itself, it finds it on its own too. True when it was readable.
 */
export async function checkDataFolder({ api, pref, reload, base = location.href, get = fetch }) {
  if (pref('mudesk-data-check') === '0') return false;
  const read = await Promise.all(['../data/web-check.txt', 'data/web-check.txt'].map((u) =>
    get(new URL(u, base), { cache: 'no-store', credentials: 'omit' }).then(async (r) => (r.ok ? r.text() : ''), () => '')));
  const proof = read.find((x) => x.startsWith('MuDeskClient data folder'));
  pref('mudesk-data-check', proof ? '1' : '0');
  if (!proof) return false;
  const j = await api('data.exposed', { proof }).catch(() => ({}));
  if (j.stopped) reload();                               // (the gateway's own page now: what to do)
  return true;
}
