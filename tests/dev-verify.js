#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const root = process.cwd();
const baseUrl = process.env.ATTRACTOR_DEV_BASE_URL || 'http://127.0.0.1:8080';
const outDir = path.join(root, '.scratch/verification/SPRINT-002/phase4/dev');
const screenshotPath = path.join(outDir, 'make-dev-proof.png');
const logPath = path.join(outDir, 'make-dev-verify.log');

fs.mkdirSync(outDir, { recursive: true });
fs.writeFileSync(logPath, '');

function log(line) {
  fs.appendFileSync(logPath, line + '\n');
  process.stdout.write(line + '\n');
}

async function main() {
  const consoleErrors = [];
  const pageErrors = [];
  const responseErrors = [];

  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 1320, height: 900 } });
  const page = await context.newPage();

  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      consoleErrors.push(msg.text());
    }
  });
  page.on('pageerror', (error) => {
    pageErrors.push(String(error && error.message ? error.message : error));
  });
  page.on('response', (response) => {
    const status = response.status();
    if (status < 400) {
      return;
    }
    const url = response.url();
    if (!url.startsWith(baseUrl)) {
      return;
    }
    responseErrors.push(`${status} ${url}`);
  });

  try {
    await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });

    await page.getByRole('tab', { name: 'Create' }).click();
    await page.locator('#dot-editor').fill('digraph Demo { start -> plan; plan -> test; test -> exit; }');
    await page.getByRole('button', { name: 'Validate' }).click();
    await page.getByRole('button', { name: 'Preview' }).click();
    await page.waitForSelector('#dot-preview svg', { timeout: 5000 });

    await page.getByRole('button', { name: 'Run' }).click();
    await page.waitForTimeout(600);

    await page.getByRole('tab', { name: 'Monitor' }).click();
    await page.waitForTimeout(400);
    await page.getByRole('tab', { name: 'Archived' }).click();
    await page.waitForTimeout(300);
    await page.getByRole('tab', { name: 'Docs' }).click();
    await page.waitForTimeout(300);

    await page.screenshot({ path: screenshotPath, fullPage: true });
    log(`screenshot=${screenshotPath}`);

    const allErrors = [
      ...pageErrors.map((m) => `pageerror: ${m}`),
      ...consoleErrors.map((m) => `console: ${m}`),
      ...responseErrors.map((m) => `http: ${m}`),
    ];
    if (allErrors.length > 0) {
      log('errors detected:');
      for (const err of allErrors) {
        log(`- ${err}`);
      }
      throw new Error('browser errors detected during dev workflow verification');
    }

    log('dev verification passed');
  } finally {
    await context.close();
    await browser.close();
  }
}

main().catch((error) => {
  log(`dev verification failed: ${error.stack || error.message}`);
  process.exit(1);
});
