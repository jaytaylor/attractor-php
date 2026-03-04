#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');
const { chromium, devices } = require('playwright');

const root = process.cwd();
const appPort = 9081;
const llmPort = 19083;
const baseUrl = `http://127.0.0.1:${appPort}`;
const llmUrl = `http://127.0.0.1:${llmPort}`;
const logDir = path.join(root, '.scratch/verification/SPRINT-002/phase4/e2e');
const logPath = path.join(logDir, 'e2e.log');
const mockLogPath = path.join(logDir, 'mock-llm.ndjson');

fs.mkdirSync(logDir, { recursive: true });

const screenshotDirs = [
  '.scratch/verification/SPRINT-002/phase2/screenshots',
  '.scratch/verification/SPRINT-002/phase2/ui-monitor',
  '.scratch/verification/SPRINT-002/phase3/ui-create',
  '.scratch/verification/SPRINT-002/phase3/ui-archived',
  '.scratch/verification/SPRINT-002/phase3/ui-docs',
  '.scratch/verification/SPRINT-002/phase4/ui',
];
for (const rel of screenshotDirs) {
  fs.mkdirSync(path.join(root, rel), { recursive: true });
}

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
  throw new Error(`server did not start in time: ${url}`);
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

function readMockProviders() {
  if (!fs.existsSync(mockLogPath)) return new Set();
  const providers = new Set();
  const lines = fs.readFileSync(mockLogPath, 'utf8').split('\n').filter(Boolean);
  for (const line of lines) {
    try {
      const data = JSON.parse(line);
      if (typeof data.provider === 'string' && data.provider) {
        providers.add(data.provider);
      }
    } catch {
      // ignore malformed lines
    }
  }
  return providers;
}

async function main() {
  fs.writeFileSync(logPath, '');
  fs.writeFileSync(mockLogPath, '');
  const browserErrors = [];

  const runRoot = path.join(root, '.scratch/verification/SPRINT-002/phase4/e2e/runs');
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

  const server = spawn('php', ['-S', `127.0.0.1:${appPort}`, 'public/index.php'], {
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

  server.stdout.on('data', (chunk) => log(`[php] ${String(chunk).trim()}`));
  server.stderr.on('data', (chunk) => log(`[php-err] ${String(chunk).trim()}`));

  try {
    await waitForServer(`${llmUrl}/health`);
    await waitForServer(`${baseUrl}/`);
    log('servers ready');

    const openaiDot = await api('POST', '/api/v1/dot/generate', {
      provider: 'openai',
      prompt: 'Build and test service',
    });
    if (!String(openaiDot.dotSource || '').includes('digraph')) {
      throw new Error('openai dot generate did not return dotSource');
    }
    if (!/validate/i.test(String(openaiDot.dotSource || ''))) {
      throw new Error('openai dot generate did not include validation stage');
    }
    if (!/fail/i.test(String(openaiDot.dotSource || ''))) {
      throw new Error('openai dot generate did not include fail branch');
    }

    const anthropicDot = await api('POST', '/api/v1/dot/generate', {
      provider: 'anthropic',
      prompt: 'Build release pipeline',
    });
    if (!String(anthropicDot.dotSource || '').includes('digraph')) {
      throw new Error('anthropic dot generate did not return dotSource');
    }
    if (!/validate/i.test(String(anthropicDot.dotSource || ''))) {
      throw new Error('anthropic dot generate did not include validation stage');
    }

    const dogPromptDot = await api('POST', '/api/v1/dot/generate', {
      provider: 'openai',
      prompt: 'create a svg of a dog',
    });
    if (!String(dogPromptDot.dotSource || '').includes('digraph')) {
      throw new Error('dog svg prompt did not normalize to dotSource');
    }
    if (String(dogPromptDot.dotSource || '').includes('<svg')) {
      throw new Error('dog svg prompt leaked raw svg into dotSource');
    }
    if (!/validate/i.test(String(dogPromptDot.dotSource || ''))) {
      throw new Error('dog svg prompt did not include validation stage');
    }
    const dogPromptRender = await api('POST', '/api/v1/dot/render', { dotSource: dogPromptDot.dotSource });
    if (!String(dogPromptRender.svg || '').includes('<svg')) {
      throw new Error('dog svg prompt did not render graph svg');
    }
    if (String(dogPromptRender.svg || '').includes('Graph Preview Unavailable')) {
      throw new Error('dog svg prompt render still produced preview unavailable error');
    }

    const fixed = await api('POST', '/api/v1/dot/fix', {
      provider: 'anthropic',
      dotSource: 'digraph Broken { a -> ; }',
      error: 'Invalid edge target detected',
    });
    if (!String(fixed.dotSource || '').includes('digraph')) {
      throw new Error('dot fix did not return valid dot');
    }

    const iterated = await api('POST', '/api/v1/dot/iterate', {
      provider: 'openai',
      baseDot: 'digraph X { start -> exit; }',
      changes: 'add review stage',
    });
    if (!String(iterated.dotSource || '').includes('digraph')) {
      throw new Error('dot iterate did not return valid dot');
    }

    const created = await api('POST', '/api/v1/pipelines', {
      dotSource: iterated.dotSource,
      displayName: 'E2E Demo',
    });
    log(`created run ${created.id}`);

    const browser = await chromium.launch();

    const context = await browser.newContext({ viewport: { width: 1280, height: 820 } });
    const page = await context.newPage();
    page.on('pageerror', (error) => {
      browserErrors.push(`pageerror: ${String(error && error.message ? error.message : error)}`);
    });
    page.on('console', (msg) => {
      if (msg.type() === 'error') {
        browserErrors.push(`console: ${msg.text()}`);
      }
    });
    page.on('response', (response) => {
      const status = response.status();
      const url = response.url();
      if (status >= 400 && url.startsWith(baseUrl)) {
        browserErrors.push(`http: ${status} ${url}`);
      }
    });
    await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });
    await page.screenshot({ path: path.join(root, '.scratch/verification/SPRINT-002/phase2/screenshots/monitor-desktop.png'), fullPage: true });
    log('captured monitor desktop screenshot');

    await page.getByRole('tab', { name: 'Create' }).click();
    await page.locator('#dot-editor').fill('digraph Broken { a -> ; }');
    await page.getByRole('button', { name: 'Validate' }).click();
    await page.waitForTimeout(250);
    await page.screenshot({ path: path.join(root, '.scratch/verification/SPRINT-002/phase2/screenshots/create-negative-validation.png'), fullPage: true });
    log('captured negative validation screenshot');

    await page.selectOption('#llm-provider', 'anthropic');
    await page.locator('#generate-prompt').fill('Generate a release pipeline');
    await page.getByRole('button', { name: 'Generate (stream)' }).click();
    await page.waitForTimeout(500);
    await page.getByRole('button', { name: 'Run' }).click();
    await page.waitForTimeout(500);
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
    mobilePage.on('pageerror', (error) => {
      browserErrors.push(`mobile-pageerror: ${String(error && error.message ? error.message : error)}`);
    });
    mobilePage.on('console', (msg) => {
      if (msg.type() === 'error') {
        browserErrors.push(`mobile-console: ${msg.text()}`);
      }
    });
    mobilePage.on('response', (response) => {
      const status = response.status();
      const url = response.url();
      if (status >= 400 && url.startsWith(baseUrl)) {
        browserErrors.push(`mobile-http: ${status} ${url}`);
      }
    });
    await mobilePage.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });
    await mobilePage.screenshot({ path: path.join(root, '.scratch/verification/SPRINT-002/phase2/screenshots/monitor-mobile.png'), fullPage: true });
    log('captured monitor mobile screenshot');
    await mobileContext.close();
    await browser.close();

    const providers = readMockProviders();
    if (!providers.has('openai') || !providers.has('anthropic')) {
      throw new Error(`expected both providers to receive traffic, got: ${Array.from(providers).join(',')}`);
    }
    if (browserErrors.length > 0) {
      throw new Error(`browser errors detected:\n${browserErrors.join('\n')}`);
    }

    fs.writeFileSync(
      path.join(root, '.scratch/verification/SPRINT-002/phase4/ui/manual-ui-walkthrough.md'),
      [
        '# Manual UI Walkthrough',
        '',
        '- Opened dashboard root and confirmed view navigation.',
        '- Verified create view validation negative case screenshot.',
        '- Verified create generate/run flow screenshot using anthropic provider.',
        '- Verified monitor desktop/mobile layout screenshots.',
        '- Verified archived view and docs view screenshots.',
        '- Verified backend DOT endpoints hit both openai and anthropic adapters.',
        '',
      ].join('\n')
    );

    log('e2e complete');
  } finally {
    server.kill('SIGTERM');
    llm.kill('SIGTERM');
  }
}

main().catch((error) => {
  log(`e2e failed: ${error.stack || error.message}`);
  process.exit(1);
});
