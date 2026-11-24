// GET /shot?path=/settings&width=1280&full=1 → a PNG of that page in broker view. Only the website reaches this
// (the internal network), and the browser only loads pages from nginx-harness: everything else is blocked.
const http = require('http');
const { chromium } = require('playwright-core');

const SITE = process.env.BROKER_VIEW_URL || 'http://nginx-harness';
const host = new URL(SITE).host;
let browser;

async function shot(path, width, full) {
  browser = browser && browser.isConnected() ? browser : await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  const context = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
  try {
    const page = await context.newPage();
    await page.route('**/*', (route) => (new URL(route.request().url()).host === host ? route.continue() : route.abort()));
    await page.goto(SITE + path, { waitUntil: 'networkidle', timeout: 30000 });
    return await page.screenshot({ fullPage: full, type: 'png' });
  } finally {
    await context.close();
  }
}

http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://browser');
  const path = url.searchParams.get('path') || '/';
  if (url.pathname !== '/shot' || !/^\/[A-Za-z0-9\/_.\-?=&%]*$/.test(path)) {
    res.writeHead(400).end('path is a page of the website');
    return;
  }
  const width = Math.min(1920, Math.max(360, parseInt(url.searchParams.get('width'), 10) || 1280));
  try {
    const png = await shot(path, width, url.searchParams.get('full') === '1');
    res.writeHead(200, { 'Content-Type': 'image/png' }).end(png);
  } catch (e) {
    res.writeHead(502, { 'Content-Type': 'text/plain' }).end(String(e.message || e).slice(0, 500));
  }
}).listen(3000);
