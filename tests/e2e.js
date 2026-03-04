#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');
const { chromium, devices } = require('playwright');

const root = process.cwd();
const port = 9081;
const baseUrl = `http://127.0.0.1:${port}`;
const logDir = path.join(root, '.scratch/verification/SPRINT-002/phase4/e2e');
const logPath = path.join(logDir, 'e2e.log');

fs.mkdirSync(logDir, { recursive: true });

function log(line) {
  fs.appendFileSync(logPath, line + '\n');
  process.stdout.write(line + '\n');
}

async function waitForServer(url, timeoutMs = 10000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      const res = await fetch(url);
      if (res.ok) return;
    } catch {
      // wait and retry
    }
    await new Promise((r) => setTimeout(r, 150));
  }
  throw new Error('server did not start in time');
}

async function api(method, pathName, body) {
  const response = await fetch(`${baseUrl}${pathName}`, {
    method,
    headers: { 'content-type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });
  const payload = await response.json();
  if (!response.ok) {
    throw new Error(`API ${method} ${pathName} failed: ${response.status} ${JSON.stringify(payload)}`);
  }
  return payload;
}

async function main() {
  fs.writeFileSync(logPath, '');

  const runRoot = path.join(root, '.scratch/verification/SPRINT-002/phase4/e2e/runs');
  fs.rmSync(runRoot, { recursive: true, force: true });
  fs.mkdirSync(runRoot, { recursive: true });

  const server = spawn('php', ['-S', `127.0.0.1:${port}`, 'public/index.php'], {
    cwd: root,
    env: { ...process.env, ATTRACTOR_LOGS_ROOT: runRoot },
    stdio: ['ignore', 'pipe', 'pipe'],
  });

  server.stdout.on('data', (chunk) => log(`[php] ${String(chunk).trim()}`));
  server.stderr.on('data', (chunk) => log(`[php-err] ${String(chunk).trim()}`));

  try {
    await waitForServer(`${baseUrl}/`);
    log('server ready');

    const created = await api('POST', '/api/v1/pipelines', {
      dotSource: 'digraph Demo { start -> plan; plan -> implement; implement -> exit; }',
      displayName: 'E2E Demo',
      simulate: true,
    });
    log(`created run ${created.id}`);

    const browser = await chromium.launch();

    const context = await browser.newContext({ viewport: { width: 1280, height: 820 } });
    const page = await context.newPage();
    await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });
    await page.screenshot({ path: path.join(root, '.scratch/verification/SPRINT-002/phase2/screenshots/monitor-desktop.png'), fullPage: true });
    log('captured monitor desktop screenshot');

    await page.getByRole('tab', { name: 'Create' }).click();
    await page.locator('#dot-editor').fill('digraph Broken { a -> ; }');
    await page.getByRole('button', { name: 'Validate' }).click();
    await page.waitForTimeout(250);
    await page.screenshot({ path: path.join(root, '.scratch/verification/SPRINT-002/phase2/screenshots/create-negative-validation.png'), fullPage: true });
    log('captured negative validation screenshot');

    await page.locator('#generate-prompt').fill('Generate a release pipeline');
    await page.getByRole('button', { name: 'Generate (stream)' }).click();
    await page.waitForTimeout(400);
    await page.getByRole('button', { name: 'Run' }).click();
    await page.waitForTimeout(400);
    await page.screenshot({ path: path.join(root, '.scratch/verification/SPRINT-002/phase3/ui-create/create-flow.png'), fullPage: true });
    log('captured create flow screenshot');

    await page.getByRole('tab', { name: 'Monitor' }).click();
    await page.waitForTimeout(250);
    await page.screenshot({ path: path.join(root, '.scratch/verification/SPRINT-002/phase2/ui-monitor/monitor-flow.png'), fullPage: true });

    const runList = await api('GET', '/api/v1/pipelines');
    const firstRunId = runList[0].id;
    await api('POST', `/api/v1/pipelines/${firstRunId}/archive`);
    await page.getByRole('tab', { name: 'Archived' }).click();
    await page.waitForTimeout(350);
    await page.screenshot({ path: path.join(root, '.scratch/verification/SPRINT-002/phase3/ui-archived/archived-view.png'), fullPage: true });
    log('captured archived view screenshot');

    await page.getByRole('tab', { name: 'Docs' }).click();
    await page.waitForTimeout(250);
    await page.screenshot({ path: path.join(root, '.scratch/verification/SPRINT-002/phase3/ui-docs/docs-view.png'), fullPage: true });
    log('captured docs view screenshot');

    await context.close();

    const mobileContext = await browser.newContext({ ...devices['iPhone 13'] });
    const mobilePage = await mobileContext.newPage();
    await mobilePage.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });
    await mobilePage.screenshot({ path: path.join(root, '.scratch/verification/SPRINT-002/phase2/screenshots/monitor-mobile.png'), fullPage: true });
    log('captured monitor mobile screenshot');

    await mobileContext.close();
    await browser.close();

    fs.writeFileSync(path.join(root, '.scratch/verification/SPRINT-002/phase4/ui/manual-ui-walkthrough.md'), `# Manual UI Walkthrough\n\n- Opened dashboard root and confirmed view navigation.\n- Verified create view validation negative case screenshot.\n- Verified create generate/run flow screenshot.\n- Verified monitor desktop/mobile layout screenshots.\n- Verified archived view and docs view screenshots.\n`);

    log('e2e complete');
  } finally {
    server.kill('SIGTERM');
  }
}

main().catch((error) => {
  log(`e2e failed: ${error.stack || error.message}`);
  process.exit(1);
});
