// Looks at the Batch ADM1 screens in a real browser (headless Chrome over its DevTools socket, like scripts/mobile-admin-check.mjs):
// upload previews (existing and fresh files), the login branding, the profile photo, and Orders in Dhaka time. Screenshots go to docs/adm1-screenshots/ (not committed).
//
//   node scripts/adm1-check.mjs uploads               every upload field of a few screens: is the picture drawn, how big, any sideways scroll
//   node scripts/adm1-check.mjs uploads --fresh       also choose a new file in the category thumbnail field and look at its preview
//   node scripts/adm1-check.mjs shots                 screenshots at 390 and 1280 px of the Orders list, an order and a product
//   node scripts/adm1-check.mjs login                 the login page at 360, 390 and 1280 px, light and dark
//   ADMIN_BASE=http://localhost:8899                  open the admin on another host than APP_URL (the cross-host case behind the missing previews)
//
// It talks to a LOCAL copy of the admin (default http://127.0.0.1:8899) on a throwaway SQLite database filled by scripts/mobile-admin-fixture.php. It never touches the
// real shop and needs no package.
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const HERE = path.dirname(fileURLToPath(import.meta.url));

const args = process.argv.slice(2);
const mode = args[0] || "uploads";
const flag = (name) => args.includes(name);
const option = (name) => (args.includes(name) ? args[args.indexOf(name) + 1] : null);
const BASE = (process.env.ADMIN_BASE || "http://127.0.0.1:8899").replace(/\/+$/, "");
const EMAIL = process.env.ADMIN_EMAIL || "phone-check@example.test";
const PASSWORD = process.env.ADMIN_PASSWORD || "phone-check-only-1";
const PORT = 9333 + Math.floor(Math.random() * 500);
const SHOTS = path.join(HERE, "..", "docs", "adm1-screenshots");
const CHROME = [process.env.CHROME_PATH, "C:/Program Files/Google/Chrome/Application/chrome.exe", "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "/usr/bin/google-chrome", "/usr/bin/chromium"].find((c) => c && fs.existsSync(c));

if (!CHROME) {
  console.error("No Chrome or Edge found (set CHROME_PATH).");
  process.exit(2);
}

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
      } else if (message.method) this.listeners.forEach((listener) => listener(message));
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
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), "adm1-"));
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
      await cdp.send("DOM.enable", {}, sessionId);

      return { cdp, sessionId, close: () => { child.kill(); try { fs.rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 300 }); } catch { /* harmless temp folder */ } } };
    } catch {
      await sleep(500);
    }
  }
  child.kill();
  throw new Error("Chrome did not start.");
}

async function evaluate(page, expression) {
  const { result, exceptionDetails } = await page.cdp.send("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true }, page.sessionId);
  if (exceptionDetails) throw new Error(exceptionDetails.exception?.description || "page script failed");
  return result.value;
}

async function setViewport(page, width, height = 844, dark = false) {
  await page.cdp.send("Emulation.setDeviceMetricsOverride", { width, height, deviceScaleFactor: 1, mobile: width < 768 }, page.sessionId);
  await page.cdp.send("Emulation.setTouchEmulationEnabled", { enabled: width < 768 }, page.sessionId);
  await page.cdp.send("Emulation.setEmulatedMedia", { features: [{ name: "prefers-color-scheme", value: dark ? "dark" : "light" }] }, page.sessionId);
}

async function go(page, url, settle = 2500) {
  const loaded = page.cdp.waitFor("Page.loadEventFired", page.sessionId, 30000);
  await page.cdp.send("Page.navigate", { url }, page.sessionId);
  await loaded;
  await sleep(settle);
  await evaluate(page, "(async () => { for (let y = 0; y < document.body.scrollHeight; y += 350) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 120)); } window.scrollTo(0, 0); })()");
  await evaluate(page, "(async () => { if (window.Livewire) for (const c of Livewire.all()) { if (/relation-manager|widget/i.test(c.name)) { try { await Promise.race([c.$wire.loadTable(), new Promise((r) => setTimeout(r, 10000))]); } catch (e) {} } } })()");
  await sleep(1200);
}

async function signIn(page) {
  await go(page, `${BASE}/admin/login`, 1500);
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
  throw new Error("Could not sign in to the local admin.");
}

async function shot(page, name, width) {
  fs.mkdirSync(SHOTS, { recursive: true });
  const height = Math.min(await evaluate(page, "Math.max(document.documentElement.scrollHeight, 800)"), 4200);
  const { data } = await page.cdp.send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true, clip: { x: 0, y: 0, width, height, scale: 1 } }, page.sessionId);
  fs.writeFileSync(path.join(SHOTS, `${name}-${width}.png`), Buffer.from(data, "base64"));
}

// What every upload field on the page shows: its items, whether each has a drawn picture, its size, and whether the page scrolls sideways.
const UPLOADS = `(async () => {
  await new Promise((r) => setTimeout(r, 1500));
  const vw = window.innerWidth;
  const fields = [...document.querySelectorAll('.fi-fo-file-upload')].map((f) => {
    const label = ((f.closest('.fi-fo-field-wrp') || f).querySelector('label') || {}).innerText || '';
    const items = [...f.querySelectorAll('.filepond--item')].map((i) => {
      const preview = i.querySelector('.filepond--image-preview-wrapper');
      const r = i.getBoundingClientRect();
      const buttons = [...i.querySelectorAll('button')].map((b) => Math.round(Math.min(b.getBoundingClientRect().width, b.getBoundingClientRect().height)));
      return { name: (i.querySelector('.filepond--file-info-main') || {}).innerText || i.innerText.trim().slice(0, 40), drawn: !!i.querySelector('canvas'), w: Math.round(r.width), h: Math.round(r.height), buttons };
    });
    const own = [...f.parentElement.querySelectorAll('[data-upload-preview]')].map((p) => ({ size: Math.round(p.getBoundingClientRect().width), placeholder: p.hasAttribute('data-upload-placeholder') }));
    return { label: label.trim().slice(0, 40), items, own };
  });
  return { viewport: vw, sideways: document.documentElement.scrollWidth > vw + 1, fields: fields.filter((f) => f.items.length || f.own.length) };
})()`;

async function main() {
  const page = await launch();
  try {
    if (mode === "login") {
      // The login page, signed out, at three widths and in light and dark: the logo (visible one) height and centring, the tab title, any sideways scroll.
      for (const dark of [false, true]) {
        for (const width of [360, 390, 1280]) {
          await setViewport(page, width, width < 768 ? 844 : 900, dark);
          await go(page, `${BASE}/admin/login`, 1800);
          const found = await evaluate(page, `(() => {
            const vw = innerWidth;
            const logos = [...document.querySelectorAll('.fi-simple-layout .fi-logo, .fi-simple-layout .fi-logo img')].filter((el) => el.offsetParent !== null);
            const logo = logos.find((el) => el.tagName === 'IMG') || logos[0];
            const r = logo ? logo.getBoundingClientRect() : null;
            const plate = document.querySelector('.fi-simple-layout img.fi-brand-plate');
            return {
              title: document.title,
              logo: r ? { h: Math.round(r.height), w: Math.round(r.width), centreOffset: Math.round((r.left + r.width / 2) - vw / 2) } : null,
              logoCss: logo ? (() => { const cs = getComputedStyle(logo); const p = logo.parentElement; return { height: cs.height, maxHeight: cs.maxHeight, shrink: cs.flexShrink, parent: p.className.slice(0, 80), parentH: Math.round(p.getBoundingClientRect().height) }; })() : null,
              onPlate: !!plate && plate.offsetParent !== null,
              heading: ((document.querySelector('h1') || {}).innerText || '').trim(),
              hasApiWord: document.documentElement.innerHTML.includes('Anaiza Nest API'),
              sideways: document.documentElement.scrollWidth > vw + 1,
            };
          })()`);
          console.log(`login ${dark ? "dark " : "light"} ${width}px:`, JSON.stringify(found));
          await shot(page, `login-${dark ? "dark" : "light"}`, width);
        }
      }
      return;
    }

    await setViewport(page, 1280, 900);
    await signIn(page);

    if (mode === "uploads") {
      const screens = [
        ["category (existing thumbnail, missing banner)", "/admin/categories/1/edit"],
        ["site settings", "/admin/site-settings"],
        ["product", "/admin/products/1/edit"],
      ];
      for (const width of [1280, 390, 360]) {
        await setViewport(page, width, width < 768 ? 844 : 900);
        for (const [name, url] of screens) {
          await go(page, `${BASE}${url}`);
          const found = await evaluate(page, UPLOADS);
          console.log(`\n${name} at ${width}px: sideways=${found.sideways}`);
          for (const f of found.fields) console.log("  ", JSON.stringify(f));
        }
      }
      if (flag("--fresh")) {
        await setViewport(page, 1280, 900);
        await go(page, `${BASE}/admin/categories/1/edit`);
        const doc = await page.cdp.send("DOM.getDocument", { depth: -1 }, page.sessionId);
        const found = await page.cdp.send("DOM.querySelectorAll", { nodeId: doc.root.nodeId, selector: ".fi-fo-file-upload input[type=file]" }, page.sessionId);
        const sample = path.resolve(HERE, "..", "storage", "app", "public", "products", "01M2SX3WNF690XNPQNJQ27Y12V.jpeg");
        // What the browser asked the server while the file was chosen (upload requests and anything that failed).
        await page.cdp.send("Network.enable", {}, page.sessionId);
        const seen = [];
        page.cdp.listeners.push((m) => {
          if (m.sessionId !== page.sessionId) return;
          if (m.method === "Network.responseReceived" && /upload|livewire\/update|storage/.test(m.params.response.url)) seen.push(`${m.params.response.status} ${m.params.response.url.slice(0, 110)}`);
          if (m.method === "Network.loadingFailed") seen.push(`FAILED ${m.params.errorText} ${m.params.type}`);
        });
        // Every upload field in turn: choose the new file in it, as a person would, and say what the field shows a few seconds later.
        for (let index = 0; index < found.nodeIds.length; index++) {
          const before = await evaluate(page, `document.querySelectorAll('.fi-fo-file-upload')[${index}].querySelectorAll('.filepond--item').length`);
          await page.cdp.send("DOM.setFileInputFiles", { nodeId: found.nodeIds[index], files: [sample] }, page.sessionId);
          await sleep(3500);
          const state = await evaluate(page, `[...document.querySelectorAll('.fi-fo-file-upload')[${index}].querySelectorAll('.filepond--item')].map((i) => ({ state: i.getAttribute('data-filepond-item-state'), name: ((i.querySelector('.filepond--file-info-main') || {}).innerText || '').slice(0, 24), drawn: i.querySelectorAll('canvas').length > 0, h: Math.round(i.getBoundingClientRect().height) }))`);
          console.log(`  field ${index} (had ${before}):`, JSON.stringify(state));
        }
        console.log("requests:", JSON.stringify(seen.slice(0, 12)));
      }
    }

    if (mode === "profile") {
      // My profile, as a person does it: initials first, then add a photo, replace it, remove it. Every request to another host is listed (there must be none),
      // and the old files are asked for again after a replace and a remove (they must be gone).
      await setViewport(page, 1280, 900);
      await page.cdp.send("Network.enable", {}, page.sessionId);
      const hosts = new Set();
      page.cdp.listeners.push((m) => {
        if (m.sessionId === page.sessionId && m.method === "Network.requestWillBeSent") hosts.add(new URL(m.params.request.url).host);
      });
      const menuAvatar = () => evaluate(page, "(() => { const i = document.querySelector('.fi-user-menu img'); return i ? { src: i.getAttribute('src').slice(0, 60), w: Math.round(i.getBoundingClientRect().width), h: Math.round(i.getBoundingClientRect().height) } : null; })()");
      const status = (u) => evaluate(page, `fetch(${JSON.stringify(u)}, { cache: 'no-store' }).then((r) => r.status)`);
      const choose = async (file) => {
        const doc = await page.cdp.send("DOM.getDocument", { depth: -1 }, page.sessionId);
        const input = await page.cdp.send("DOM.querySelector", { nodeId: doc.root.nodeId, selector: ".fi-fo-file-upload input[type=file]" }, page.sessionId);
        await page.cdp.send("DOM.setFileInputFiles", { nodeId: input.nodeId, files: [file] }, page.sessionId);
        // Wait until every picture in the field has finished uploading (a person sees "Upload complete" before pressing Save).
        for (let attempt = 0; attempt < 40; attempt++) {
          await sleep(500);
          if (await evaluate(page, "(() => { const items = [...document.querySelectorAll('.filepond--item')]; return items.length > 0 && items.every((i) => i.getAttribute('data-filepond-item-state') === 'processing-complete'); })()")) break;
        }
        await sleep(800);
      };
      const save = async () => {
        await evaluate(page, "[...document.querySelectorAll('button[type=submit]')].find((b) => /Save changes/i.test(b.innerText) && b.offsetParent !== null).click()");
        await sleep(2500);
      };
      const avatarUrl = () => evaluate(page, "(() => { const i = document.querySelector('.fi-user-menu img'); const s = i ? i.getAttribute('src') : ''; return s.startsWith('data:') ? null : s; })()");
      const sample = (name) => path.resolve(HERE, "..", "storage", "app", "public", "products", name);

      await go(page, `${BASE}/admin/profile`);
      console.log("1 no photo, menu avatar:", JSON.stringify(await menuAvatar()));
      await shot(page, "profile-initials", 1280);

      await choose(sample("01M2SX3WNF690XNPQNJQ27Y12V.jpeg"));
      console.log("  after choosing:", JSON.stringify(await evaluate(page, "[...document.querySelectorAll('.filepond--item')].map((i) => ({ state: i.getAttribute('data-filepond-item-state'), text: i.innerText.replace(/\\s+/g, ' ').trim().slice(0, 50) }))")));
      await save();
      console.log("  after save, messages:", JSON.stringify(await evaluate(page, "[...document.querySelectorAll('.fi-fo-field-wrp-error-message, .fi-no-notification, [role=alert]')].map((e) => e.innerText.replace(/\\s+/g, ' ').trim().slice(0, 120))")));
      await go(page, `${BASE}/admin/profile`);
      const first = await avatarUrl();
      console.log("2 after upload, menu avatar:", JSON.stringify(await menuAvatar()), "file status:", await status(first));
      console.log("  field:", JSON.stringify((await evaluate(page, UPLOADS)).fields));
      await shot(page, "profile-photo", 1280);

      // A replace, as a person does it: take the old picture out of the field, choose the new one, Save.
      await evaluate(page, "document.querySelector('.fi-fo-file-upload .filepond--action-remove-item').click()");
      await sleep(1500);
      await choose(sample("01M2SX3WNF690XNPQNJQ27Y12V.jpeg"));
      await save();
      await go(page, `${BASE}/admin/profile`);
      const second = await avatarUrl();
      console.log("3 after replace, new file differs:", second !== first, "| old file status:", await status(first), "| new file status:", await status(second));

      await evaluate(page, "document.querySelector('.fi-fo-file-upload .filepond--action-remove-item').click()");
      await sleep(1500);
      await save();
      await go(page, `${BASE}/admin/profile`);
      console.log("4 after remove, menu avatar:", JSON.stringify(await menuAvatar()), "| removed file status:", await status(second));

      for (const width of [390, 360]) {
        await setViewport(page, width, 844);
        await go(page, `${BASE}/admin/profile`);
        const phone = await evaluate(page, "({ sideways: document.documentElement.scrollWidth > innerWidth + 1, topbarAvatar: !!document.querySelector('.fi-topbar .fi-user-menu img') })");
        console.log(`5 profile at ${width}px:`, JSON.stringify(phone));
        await shot(page, "profile", width);
      }
      console.log("hosts contacted during the whole session:", JSON.stringify([...hosts]));
    }

    if (mode === "multi") {
      // Several pictures in one field (the media library's "Upload files" window): do they sit as a small grid, and do the buttons keep a 44 px hit area?
      const samples = ["01M2SX3WNF690XNPQNJQ27Y12V.jpeg", "01M2SX3WNF690XNPQNJQ27Y12V.jpeg", "01M2SX3WNF690XNPQNJQ27Y12V.jpeg"].map((n) => path.resolve(HERE, "..", "storage", "app", "public", "products", n));
      for (const width of [1280, 390, 360]) {
        await setViewport(page, width, width < 768 ? 844 : 900);
        await go(page, `${BASE}/admin/media-library`);
        await evaluate(page, "[...document.querySelectorAll('button')].find((b) => /Upload files/.test(b.innerText)).click()");
        await sleep(1800);
        const doc = await page.cdp.send("DOM.getDocument", { depth: -1 }, page.sessionId);
        const input = await page.cdp.send("DOM.querySelector", { nodeId: doc.root.nodeId, selector: ".fi-modal input[type=file]" }, page.sessionId);
        await page.cdp.send("DOM.setFileInputFiles", { nodeId: input.nodeId, files: samples }, page.sessionId);
        await sleep(6000);
        const found = await evaluate(page, `(() => {
          const items = [...document.querySelectorAll('.fi-modal .filepond--item')].map((i) => { const r = i.getBoundingClientRect(); return { x: Math.round(r.left), y: Math.round(r.top), w: Math.round(r.width), h: Math.round(r.height), drawn: i.querySelectorAll('canvas').length > 0 }; });
          const buttons = [...document.querySelectorAll('.fi-modal .filepond--item button.filepond--file-action-button')].filter((b) => getComputedStyle(b).visibility !== 'hidden').slice(0, 3).map((b) => { const r = b.getBoundingClientRect(); const a = getComputedStyle(b, '::after'); const inset = parseFloat(a.left) || 0; return { visual: Math.round(r.width), hitArea: Math.round(r.width - 2 * inset) }; });
          const modal = document.querySelector('.fi-modal-window').getBoundingClientRect();
          return { items, buttons, modalRight: Math.round(modal.right), viewport: innerWidth, sideways: document.documentElement.scrollWidth > innerWidth + 1 };
        })()`);
        console.log(`\nseveral pictures at ${width}px:`, JSON.stringify(found));
        await shot(page, "multi-upload", width);
      }
    }

    if (mode === "shots") {
      const screens = [["orders-list", "/admin/orders"], ["order-edit", "/admin/orders/4/edit"], ["product-edit", "/admin/products/1/edit"]];
      for (const width of [390, 1280]) {
        await setViewport(page, width, width < 768 ? 844 : 900);
        for (const [name, url] of screens) {
          await go(page, `${BASE}${url}`);
          await shot(page, name, width);
        }
      }
      console.log(`Screenshots saved in ${SHOTS}`);
    }
  } finally {
    page.close();
  }
}

main().catch((error) => {
  console.error(error.message);
  process.exit(1);
});
