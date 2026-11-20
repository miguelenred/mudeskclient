// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Interface language from the browser: Spanish or English, English for any other language.
// node tests/e2e_lang.mjs <baseUrl of a running copy>
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright-core');
const [base] = process.argv.slice(2);

const browser = await chromium.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
let fail = 0;
for (const [locale, want] of [['es-ES', 'es'], ['es-MX', 'es'], ['en-GB', 'en'], ['fr-FR', 'en'], ['sv-SE', 'en']]) {
  const ctx = await browser.newContext({ locale });
  const page = await ctx.newPage();
  await page.goto(base + '/index.php');
  await page.waitForSelector('input[name=username]');
  const got = await page.evaluate(() => document.documentElement.lang);
  const ok = got === want;
  fail += ok ? 0 : 1;
  console.log(`${ok ? 'OK  ' : 'FAIL'} browser ${locale} -> interface ${got}`);
  await ctx.close();
}
await browser.close();
process.exit(fail ? 1 : 0);
