// Looks at the Filament admin the way a phone does: for each screen, at phone widths, is anything wider than the screen?
//
//   node scripts/mobile-admin-check.mjs                      measure every screen at 390 x 844 and 360 x 800, print a table
//   node scripts/mobile-admin-check.mjs --assert             exit code 1 when a screen scrolls sideways at a phone width
//   node scripts/mobile-admin-check.mjs --shots docs/mobile-admin-screenshots/after     also save a screenshot of each screen
//   node scripts/mobile-admin-check.mjs --desktop            also record the table column names at 1280 px (for the desktop comparison)
//   node scripts/mobile-admin-check.mjs --json out.json      write the measurements to a file
//
// It talks to a LOCAL copy of the admin (default http://127.0.0.1:8899) that runs on a throwaway SQLite database filled by
// scripts/mobile-admin-fixture.php, signed in with the made-up login that script prints. It never touches the real shop, and it needs no
// package: Chrome (or Edge) is driven over its DevTools socket with Node's own WebSocket.
//
// "Sideways scroll" means: the page itself is wider than the screen (documentElement.scrollWidth > the viewport), or a table's own scroll box
// (.fi-ta-content) holds a table wider than its box, or any other scroll box on the page does (a repeater, tabs, a code block).
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";

const args = process.argv.slice(2);
const flag = (name) => args.includes(name);
const option = (name) => (args.includes(name) ? args[args.indexOf(name) + 1] : null);

const BASE = (process.env.ADMIN_BASE || "http://127.0.0.1:8899").replace(/\/+$/, "");
const EMAIL = process.env.ADMIN_EMAIL || "phone-check@example.test";
const PASSWORD = process.env.ADMIN_PASSWORD || "phone-check-only-1";
const ORDER_ID = process.env.ORDER_ID || "4"; // an order with a courier booking in the fixture
const PRODUCT_ID = process.env.PRODUCT_ID || "1";
const PORT = 9333 + Math.floor(Math.random() * 500);
const CHROME = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].find((c) => c && fs.existsSync(c));

const PHONES = [
  { width: 390, height: 844 },
  { width: 360, height: 800 },
];

// [name, path, needs a signed-in admin]
const SCREENS = [
  ["login", "/admin/login", false],
  ["orders-list", "/admin/orders", true],
  ["order-edit", `/admin/orders/${ORDER_ID}/edit`, true],
  ["products-list", "/admin/products", true],
  ["product-edit", `/admin/products/${PRODUCT_ID}/edit`, true],
  ["customers-list", "/admin/customers", true],
  ["abandoned-checkouts", "/admin/abandoned-checkouts", true],
  ["courier-statuses", "/admin/courier-statuses", true],
  ["reviews", "/admin/product-reviews", true],
  ["coupons", "/admin/coupons", true],
  ["shipping-zones", "/admin/shipping-zones", true],
  ["dashboard", "/admin", true],
  ["system-health", "/admin/system-health", true],
  ["site-settings", "/admin/site-settings", true],
  ["courier-settings", "/admin/courier-settings", true],
];

if (!CHROME) {
  console.error("No Chrome or Edge found (set CHROME_PATH).");
  process.exit(2);
}

// ---- a very small DevTools client

class Cdp {
  constructor(url) {
    this.next = 1;
    this.waiting = new Map();
    this.listeners = [];
    this.socket = new WebSocket(url);
    this.ready = new Promise((resolve, reject) => {
      this.socket.onopen = resolve;
      this.socket.onerror = reject;
    });
    this.socket.onmessage = (event) => {
      const message = JSON.parse(event.data);
      if (message.id && this.waiting.has(message.id)) {
        const { resolve, reject } = this.waiting.get(message.id);
        this.waiting.delete(message.id);
        message.error ? reject(new Error(`${message.error.message}`)) : resolve(message.result);
      } else if (message.method) {
        this.listeners.forEach((listener) => listener(message));
      }
    };
  }

  send(method, params = {}, sessionId) {
    const id = this.next++;
    this.socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
    return new Promise((resolve, reject) => this.waiting.set(id, { resolve, reject }));
  }

  waitFor(method, sessionId, ms = 20000) {
    return new Promise((resolve) => {
      const timer = setTimeout(() => resolve(null), ms);
      const listener = (message) => {
        if (message.method === method && (!sessionId || message.sessionId === sessionId)) {
          clearTimeout(timer);
          this.listeners = this.listeners.filter((entry) => entry !== listener);
          resolve(message.params);
        }
      };
      this.listeners.push(listener);
    });
  }
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function launch() {
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), "mobile-admin-"));
  const child = spawn(CHROME, ["--headless=new", "--disable-gpu", "--no-first-run", `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`, "about:blank"], { stdio: "ignore" });

  for (let attempt = 0; attempt < 60; attempt++) {
    try {
      const version = await (await fetch(`http://127.0.0.1:${PORT}/json/version`)).json();
      const cdp = new Cdp(version.webSocketDebuggerUrl);
      await cdp.ready;
      const { targetId } = await cdp.send("Target.createTarget", { url: "about:blank" });
      const { sessionId } = await cdp.send("Target.attachToTarget", { targetId, flatten: true });
      await cdp.send("Page.enable", {}, sessionId);
      await cdp.send("Runtime.enable", {}, sessionId);

      return { cdp, sessionId, close: () => { child.kill(); try { fs.rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 300 }); } catch { /* Chrome may still hold its files for a moment; the temp folder is harmless */ } } };
    } catch {
      await sleep(500);
    }
  }
  child.kill();
  throw new Error("Chrome did not start.");
}

// ---- the page

const MEASURE = `(() => {
  const vw = window.innerWidth;
  const de = document.documentElement;
  const short = (el) => (el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + '.' + String(el.className && el.className.baseVal !== undefined ? el.className.baseVal : el.className).trim().split(/\\s+/).slice(0, 3).join('.')).slice(0, 90);
  const scrollers = [];
  for (const el of document.querySelectorAll('*')) {
    const style = getComputedStyle(el);
    if ((style.overflowX === 'auto' || style.overflowX === 'scroll') && el.scrollWidth > el.clientWidth + 1 && el.clientWidth > 0 && style.display !== 'none') {
      scrollers.push({ what: short(el), box: el.clientWidth, content: el.scrollWidth, isTable: el.classList.contains('fi-ta-content') });
    }
  }
  const outside = [];
  for (const el of document.body.querySelectorAll('*')) {
    const r = el.getBoundingClientRect();
    if (r.width > 0 && r.right > vw + 1 && getComputedStyle(el).position !== 'fixed') {
      let inside = false;
      for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) { const o = getComputedStyle(p).overflowX; if (o === 'auto' || o === 'scroll' || o === 'hidden' || o === 'clip') { inside = true; break; } }
      if (!inside) outside.push({ what: short(el), right: Math.round(r.right) });
    }
  }
  const fonts = [...document.querySelectorAll('input:not([type=checkbox]):not([type=radio]):not([type=hidden]), select, textarea')].filter((el) => el.offsetParent !== null).map((el) => parseFloat(getComputedStyle(el).fontSize));
  const small = [...document.querySelectorAll('button, a.fi-btn, .fi-ac-btn-action, .fi-ta-actions button, .fi-ta-actions a, .fi-icon-btn, .fi-ta-record a[href], input[type=checkbox]')].filter((el) => el.offsetParent !== null).map((el) => { const r = el.getBoundingClientRect(); return r.height; }).filter((h) => h > 0);
  const modal = document.querySelector('.fi-modal-window:not([style*="display: none"])');
  const m = modal ? modal.getBoundingClientRect() : null;
  return {
    viewport: vw,
    documentScrollWidth: de.scrollWidth,
    bodyScrollWidth: document.body.scrollWidth,
    pageScrollsSideways: de.scrollWidth > vw + 1,
    tableBoxes: document.querySelectorAll('.fi-ta-content').length,
    tableScrollers: scrollers.filter((s) => s.isTable),
    otherScrollers: scrollers.filter((s) => !s.isTable).slice(0, 6),
    outside: outside.slice(0, 6),
    minInputFont: fonts.length ? Math.min(...fonts) : null,
    shortestTouchTarget: small.length ? Math.round(Math.min(...small)) : null,
    modal: m ? { left: Math.round(m.left), right: Math.round(m.right), width: Math.round(m.width) } : null,
    columns: [...document.querySelectorAll('.fi-ta-table thead th')].filter((th) => th.offsetParent !== null).map((th) => th.innerText.trim()).filter(Boolean),
    cards: document.querySelectorAll('.fi-ta-record').length,
    title: (document.querySelector('h1') || {}).innerText || null,
    sidebarOpen: window.Alpine && Alpine.store('sidebar') ? Alpine.store('sidebar').isOpen : null,
    sidebarShown: (() => { const a = document.querySelector('.fi-sidebar'); if (!a) return null; const r = a.getBoundingClientRect(); return r.right > 8 && r.width > 0 && getComputedStyle(a).visibility !== 'hidden'; })(),
  };
})()`;

async function evaluate(page, expression) {
  const { result, exceptionDetails } = await page.cdp.send("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true }, page.sessionId);
  if (exceptionDetails) throw new Error(exceptionDetails.exception?.description || "page script failed");
  return result.value;
}

async function go(page, url) {
  const loaded = page.cdp.waitFor("Page.loadEventFired", page.sessionId, 30000);
  await page.cdp.send("Page.navigate", { url }, page.sessionId);
  await loaded;
  await sleep(2200); // Livewire, Alpine and the charts settle
  // Parts of a page (the relation tables of a record) load when they scroll into view, as they do for a person: scroll down and back first.
  await evaluate(page, "(async () => { for (let y = 0; y < document.body.scrollHeight; y += 350) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 140)); } window.scrollTo(0, 0); })()");
  // A headless window does not always report a scroll as "in view", so any table still waiting to load is asked to load, as scrolling to it would.
  // Only components that hold a table (a relation manager or a table widget), each given 10 seconds: Livewire's $wire answers "function" for any name.
  await evaluate(page, "(async () => { if (window.Livewire) for (const c of Livewire.all()) { if (/relation-manager|widget/i.test(c.name)) { try { await Promise.race([c.$wire.loadTable(), new Promise((r) => setTimeout(r, 10000))]); } catch (e) {} } } })()");
  await sleep(1500);
}

// Filament remembers whether the sidebar is open in the browser (the key "isOpen") and, left alone, a first visit starts with it OPEN. On a phone
// width this script forgets that memory before every page, so each screen is a first visit (what a new phone sees), or, with --sidebar-closed, a
// returning visit by someone who has tapped the sidebar shut (handy for readable screenshots of the page behind it).
let forgetSidebar = null;

async function setViewport(page, width, height) {
  await page.cdp.send("Emulation.setDeviceMetricsOverride", { width, height, deviceScaleFactor: 1, mobile: width < 768 }, page.sessionId);
  await page.cdp.send("Emulation.setTouchEmulationEnabled", { enabled: width < 768 }, page.sessionId);

  if (forgetSidebar !== null) {
    await page.cdp.send("Page.removeScriptToEvaluateOnNewDocument", { identifier: forgetSidebar }, page.sessionId);
    forgetSidebar = null;
  }
  if (width < 768) {
    ({ identifier: forgetSidebar } = await page.cdp.send("Page.addScriptToEvaluateOnNewDocument", { source: flag("--sidebar-closed") ? "try { localStorage.setItem('isOpen', 'false'); } catch (e) {}" : "try { localStorage.removeItem('isOpen'); } catch (e) {}" }, page.sessionId));
  }
}

async function signIn(page) {
  await go(page, `${BASE}/admin/login`);
  await evaluate(page, `(() => {
    const set = (el, value) => { const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set; setter.call(el, value); el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); };
    set(document.querySelector('input[type=email]'), ${JSON.stringify(EMAIL)});
    set(document.querySelector('input[type=password]'), ${JSON.stringify(PASSWORD)});
    document.querySelector('form button[type=submit]').click();
  })()`);

  for (let attempt = 0; attempt < 40; attempt++) {
    await sleep(500);
    if (!(await evaluate(page, "location.pathname")).includes("/login")) return;
  }
  throw new Error("Could not sign in to the local admin (is the server up, and the scratch database filled?).");
}

async function shot(page, dir, name, width) {
  if (!dir) return;
  fs.mkdirSync(dir, { recursive: true });
  const height = Math.min(await evaluate(page, "Math.max(document.documentElement.scrollHeight, 800)"), 5000);
  const { data } = await page.cdp.send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true, clip: { x: 0, y: 0, width, height, scale: 1 } }, page.sessionId);
  fs.writeFileSync(path.join(dir, `${name}-${width}.png`), Buffer.from(data, "base64"));
}

const rows = [];
const onlyListForReport = option("--only") ? option("--only").split(",") : null;
const page = await launch();

try {
  const shots = option("--shots");
  // --only orders-list,order-edit,dialogs : just those screens (dialogs = the four confirmation dialogs); nothing given = every screen and dialog
  const onlyList = option("--only") ? option("--only").split(",") : null;
  const only = onlyList; // eslint-disable-line no-unused-vars

  // The login page is looked at before anyone is signed in.
  for (const phone of onlyList && !onlyList.includes("login") ? [] : PHONES) {
    await setViewport(page, phone.width, phone.height);
    await go(page, `${BASE}/admin/login`);
    const measured = await evaluate(page, MEASURE);
    rows.push({ screen: "login", width: phone.width, ...measured });
    await shot(page, shots, "login", phone.width);
  }

  await setViewport(page, 1280, 900);
  await signIn(page);

  // --eval "<js>" --at /admin/orders --width 390: run one expression on one screen and print it (for looking into a problem).
  if (option("--eval")) {
    await setViewport(page, Number(option("--width") || 390), 844);
    await go(page, `${BASE}${option("--at") || "/admin"}`);
    console.log(JSON.stringify(await evaluate(page, option("--eval")), null, 2));
    page.close();
    process.exit(0);
  }

  for (const [name, route, needsLogin] of SCREENS) {
    if (!needsLogin) continue;
    if (onlyList && !onlyList.includes(name)) continue;

    for (const phone of [...PHONES, ...(flag("--desktop") ? [{ width: 1280, height: 900 }] : [])]) {
      await setViewport(page, phone.width, phone.height);
      await go(page, `${BASE}${route}`);
      const measured = await evaluate(page, MEASURE);
      rows.push({ screen: name, width: phone.width, ...measured });
      if (phone.width < 1280) await shot(page, shots, name, phone.width);
    }
  }

  // Dialogs: opened by their button, measured, closed.
  await setViewport(page, 360, 800);
  const dialogs = [
    ["order-edit: Sync now dialog", `/admin/orders/${ORDER_ID}/edit`, "Sync now"],
    ["courier-statuses: Arm Pathao statuses dialog", "/admin/courier-statuses", "Arm Pathao statuses"],
    ["order-edit: Send to courier dialog", "/admin/orders/1/edit", "Send to courier"],
    ["order-edit: Mark verified dialog", "/admin/orders/2/edit", "Mark verified"],
  ];
  for (const [name, route, label] of onlyList && !onlyList.includes("dialogs") ? [] : dialogs) {
    await go(page, `${BASE}${route}`);
    const opened = await evaluate(page, `(() => { const b = [...document.querySelectorAll('button, a')].find((el) => el.innerText.trim() === ${JSON.stringify(label)} && el.offsetParent !== null); if (!b) return false; b.click(); return true; })()`);
    await sleep(1500);
    const measured = opened ? await evaluate(page, MEASURE) : { missing: true };
    rows.push({ screen: name, width: 360, opened, ...measured });
    if (opened) await shot(page, shots, name.replace(/[^a-z0-9]+/gi, "-").toLowerCase(), 360);
  }
} finally {
  page.close();
}

// ---- report

const failing = rows.filter((r) => (!onlyListForReport || onlyListForReport.some((n) => r.screen === n || r.screen.startsWith(n + ":") || (n === "dialogs" && r.screen.includes("dialog")))) && r.width < 768 && !r.missing && (r.pageScrollsSideways || (r.tableScrollers && r.tableScrollers.length) || (r.otherScrollers && r.otherScrollers.length) || (r.modal && (r.modal.left < 0 || r.modal.right > r.width)) || (r.screen !== "login" && r.sidebarShown)));

console.log("screen".padEnd(46), "width", "page scrollWidth", "table scrolls", "other scrolls", "min input px", "min touch px", "sidebar open on arrival");
for (const r of rows.filter((x) => x.width < 1280)) {
  if (r.missing) {
    console.log(r.screen.padEnd(46), r.width, "(the button was not found)");
    continue;
  }
  console.log(r.screen.padEnd(46), String(r.width).padEnd(5), String(r.documentScrollWidth).padEnd(16), String(r.tableScrollers.length).padEnd(13), String(r.otherScrollers.length).padEnd(13), String(r.minInputFont ?? "-").padEnd(12), String(r.shortestTouchTarget ?? "-").padEnd(13), r.sidebarShown === null ? "-" : r.sidebarShown ? "yes" : "no");
}

for (const r of failing.filter((x) => x.width === 390)) {
  console.log(`\n${r.screen} @ ${r.width}: page ${r.documentScrollWidth}px wide`);
  for (const s of r.tableScrollers) console.log(`  table box ${s.box}px holds ${s.content}px: ${s.what}`);
  for (const s of r.otherScrollers) console.log(`  scroll box ${s.box}px holds ${s.content}px: ${s.what}`);
  for (const s of r.outside.slice(0, 4)) console.log(`  sticks out to ${s.right}px: ${s.what}`);
}

const out = option("--json");
if (out) fs.writeFileSync(out, JSON.stringify(rows, null, 2));

if (flag("--assert")) {
  if (failing.length) {
    console.error(`\n${failing.length} screen(s) scroll sideways on a phone.`);
    process.exit(1);
  }
  console.log("\nNo screen scrolls sideways at 390 or 360 px.");
}
