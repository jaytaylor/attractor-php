#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');
const { chromium } = require('playwright');

const root = process.cwd();
const appPort = 9086;
const llmPort = 19086;
const baseUrl = `http://127.0.0.1:${appPort}`;
const llmUrl = `http://127.0.0.1:${llmPort}`;
const outDir = path.join(root, '.scratch/verification/SPRINT-002/phase4/prompt-dot-ux');
const screenshotDir = path.join(outDir, 'screenshots');
const logPath = path.join(outDir, 'audit.log');
const mockLogPath = path.join(outDir, 'mock-llm.ndjson');

fs.mkdirSync(screenshotDir, { recursive: true });
fs.writeFileSync(logPath, '');
fs.writeFileSync(mockLogPath, '');

function log(line) {
  fs.appendFileSync(logPath, line + '\n');
  process.stdout.write(line + '\n');
}

async function waitForServer(url, timeoutMs = 10000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      const res = await fetch(url);
      if (res.ok) {
        return;
      }
    } catch {
      // retry
    }
    await new Promise((r) => setTimeout(r, 150));
  }
  throw new Error(`server did not start in time: ${url}`);
}

async function api(method, pathname, body) {
  const response = await fetch(`${baseUrl}${pathname}`, {
    method,
    headers: { 'content-type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });
  const payload = await response.json();
  if (!response.ok) {
    throw new Error(`${method} ${pathname} failed: ${response.status} ${JSON.stringify(payload)}`);
  }
  return payload;
}

async function screenshot(page, name) {
  const filePath = path.join(screenshotDir, name);
  await page.screenshot({ path: filePath, fullPage: true });
  log(`screenshot=${filePath}`);
}

async function main() {
  const runRoot = path.join(outDir, 'runs');
  fs.rmSync(runRoot, { recursive: true, force: true });
  fs.mkdirSync(runRoot, { recursive: true });

  const llmRouter = path.join(root, 'tests/fixtures/llm_mock_router.php');
  const llm = spawn('php', ['-S', `127.0.0.1:${llmPort}`, llmRouter], {
    cwd: root,
    env: { ...process.env, ATTRACTOR_MOCK_LLM_LOG: mockLogPath },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  llm.stdout.on('data', (chunk) => log(`[llm] ${String(chunk).trim()}`));
  llm.stderr.on('data', (chunk) => log(`[llm-err] ${String(chunk).trim()}`));

  const app = spawn('php', ['-S', `127.0.0.1:${appPort}`, 'public/index.php'], {
    cwd: root,
    env: {
      ...process.env,
      ATTRACTOR_LOGS_ROOT: runRoot,
      ATTRACTOR_DOT_PROVIDER: 'openai',
      OPENAI_API_KEY: 'test-openai-key',
      OPENAI_BASE_URL: `${llmUrl}/openai/v1`,
      ATTRACTOR_OPENAI_MODEL: 'test-openai-model',
      ANTHROPIC_API_KEY: 'test-anthropic-key',
      ANTHROPIC_BASE_URL: `${llmUrl}/anthropic/v1`,
      ATTRACTOR_ANTHROPIC_MODEL: 'test-anthropic-model',
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  app.stdout.on('data', (chunk) => log(`[app] ${String(chunk).trim()}`));
  app.stderr.on('data', (chunk) => log(`[app-err] ${String(chunk).trim()}`));

  try {
    await waitForServer(`${llmUrl}/health`);
    await waitForServer(`${baseUrl}/`);
    log('servers ready');

    const browser = await chromium.launch();
    const context = await browser.newContext({ viewport: { width: 1440, height: 960 } });
    const page = await context.newPage();

    const consoleErrors = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        consoleErrors.push(msg.text());
      }
    });
    page.on('pageerror', (error) => {
      consoleErrors.push(String(error && error.message ? error.message : error));
    });

    await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });
    await page.getByRole('tab', { name: 'Create' }).click();
    await page.waitForTimeout(200);
    await screenshot(page, '01-create-initial.png');

    await page.locator('#generate-prompt').fill('Design a CI pipeline with lint, unit tests, and deploy');
    await page.selectOption('#llm-provider', 'openai');
    await screenshot(page, '02-prompt-ready.png');

    await page.getByRole('button', { name: 'Generate (stream)' }).click();
    await page.waitForTimeout(350);
    await screenshot(page, '03-after-generate.png');

    await page.getByRole('button', { name: 'Validate' }).click();
    await page.getByRole('button', { name: 'Preview' }).click();
    await page.waitForSelector('#dot-preview svg', { timeout: 5000 });
    await screenshot(page, '04-preview-valid.png');

    await page.locator('#dot-editor').fill('digraph Broken { a -> ; }');
    await page.getByRole('button', { name: 'Validate' }).click();
    await page.waitForTimeout(200);
    await screenshot(page, '05-invalid-dot.png');

    await page.selectOption('#llm-provider', 'anthropic');
    await page.getByRole('button', { name: 'Fix DOT' }).click();
    await page.waitForTimeout(350);
    await screenshot(page, '06-after-fix.png');

    await page.locator('#iterate-changes').fill('Add a human review gate before exit');
    await page.getByRole('button', { name: 'Iterate (stream)' }).click();
    await page.waitForTimeout(350);
    await screenshot(page, '07-after-iterate.png');

    await page.getByRole('button', { name: 'Run' }).click();
    await page.waitForTimeout(500);
    await screenshot(page, '08-monitor-after-run.png');

    const runs = await api('GET', '/api/v1/pipelines');
    if (runs.length > 0) {
      await page.getByRole('tab', { name: 'Monitor' }).click();
      await page.waitForTimeout(300);
      await page.locator('.run-item').first().click();
      await page.waitForTimeout(300);
      await screenshot(page, '09-monitor-run-detail.png');
    }

    await context.close();
    await browser.close();

    fs.writeFileSync(
      path.join(outDir, 'summary.json'),
      JSON.stringify(
        {
          baseUrl,
          screenshots: fs.readdirSync(screenshotDir).sort(),
          consoleErrors,
        },
        null,
        2
      )
    );

    if (consoleErrors.length > 0) {
      throw new Error(`console/page errors detected: ${consoleErrors.join(' | ')}`);
    }
    log('prompt-dot ux audit complete');
  } finally {
    app.kill('SIGTERM');
    llm.kill('SIGTERM');
  }
}

main().catch((error) => {
  log(`prompt-dot ux audit failed: ${error.stack || error.message}`);
  process.exit(1);
});
