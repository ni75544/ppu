// Minimal W3C WebDriver client. No npm dependencies: Node 24's global fetch
// talks to the DDEV `selenium-chrome` service over the shared docker network,
// so this runs with `ddev exec node …` and nothing is installed anywhere.
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

const HUB = process.env.WEBDRIVER_URL || 'http://selenium-chrome:4444';

async function call(method, path, body) {
  const res = await fetch(HUB + path, {
    method,
    headers: { 'content-type': 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const text = await res.text();
  let json = null;
  try {
    json = text ? JSON.parse(text) : null;
  }
  catch {
    // A non-JSON body is an error page; the raw text is the diagnostic.
  }
  if (!res.ok) {
    const detail = json && json.value
      ? (json.value.message || json.value.error)
      : text.slice(0, 400);
    throw new Error(`WebDriver ${method} ${path} -> ${res.status}: ${detail}`);
  }
  return json ? json.value : null;
}

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export class Browser {

  constructor(sessionId) {
    this.id = sessionId;
  }

  static async open() {
    const value = await call('POST', '/session', {
      capabilities: {
        alwaysMatch: {
          browserName: 'chrome',
          acceptInsecureCerts: true,
          'goog:loggingPrefs': { browser: 'ALL' },
          'goog:chromeOptions': {
            args: [
              '--headless=new',
              '--no-sandbox',
              '--disable-dev-shm-usage',
              '--disable-gpu',
              '--ignore-certificate-errors',
              '--window-size=1440,900',
            ],
          },
        },
      },
    });
    return new Browser(value.sessionId);
  }

  send(method, path, body) {
    return call(method, `/session/${this.id}${path}`, body);
  }

  get(url) {
    return this.send('POST', '/url', { url });
  }

  js(script, args = []) {
    return this.send('POST', '/execute/sync', { script, args });
  }

  jsAsync(script, args = []) {
    return this.send('POST', '/execute/async', { script, args });
  }

  /**
   * Sizes the *viewport*, correcting for the browser's own chrome.
   *
   * setWindowRect sizes the window, not the document, so a 390px request lands
   * at ~374px of layout width. A mobile assertion measured against the wrong
   * width is worse than no assertion, so the inner size is measured and the
   * difference added back once.
   */
  async viewport(width, height) {
    await this.send('POST', '/window/rect', { width, height, x: 0, y: 0 });
    const inner = await this.js('return [window.innerWidth, window.innerHeight];');
    const dw = width - inner[0];
    const dh = height - inner[1];
    if (dw !== 0 || dh !== 0) {
      await this.send('POST', '/window/rect', {
        width: width + dw,
        height: height + dh,
        x: 0,
        y: 0,
      });
    }
    return this.js('return [window.innerWidth, window.innerHeight];');
  }

  async screenshot(path) {
    const base64 = await this.send('GET', '/screenshot');
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, Buffer.from(base64, 'base64'));
    return path;
  }

  /**
   * Drains the browser console. Chromedriver clears the buffer on read, so this
   * is called once per checkpoint and the entries accumulated by the caller.
   */
  async consoleLog() {
    try {
      return (await this.send('POST', '/se/log', { type: 'browser' })) || [];
    }
    catch {
      return [];
    }
  }

  async until(label, fn, { timeout = 12000, interval = 200 } = {}) {
    const deadline = Date.now() + timeout;
    let last = null;
    for (;;) {
      last = await fn();
      if (last) {
        return last;
      }
      if (Date.now() > deadline) {
        throw new Error(`Timed out after ${timeout}ms waiting for: ${label}`);
      }
      await sleep(interval);
    }
  }

  quit() {
    return this.send('DELETE', '').catch(() => null);
  }

}
