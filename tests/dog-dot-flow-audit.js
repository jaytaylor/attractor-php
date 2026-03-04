#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const root = process.cwd();
const baseUrl = process.env.ATTRACTOR_DEV_BASE_URL || 'http://127.0.0.1:8080';
const day = new Date().toISOString().slice(0, 10).replace(/-/g, '');
const outDir = path.join(root, `.scratch/ui-${day}-1`);
const logPath = path.join(outDir, 'dog-dot-flow-audit.log');
const summaryPath = path.join(outDir, 'summary.json');
const analysisPath = path.join(outDir, 'analysis.md');

fs.mkdirSync(outDir, { recursive: true });
fs.writeFileSync(logPath, '');

function log(line) {
  fs.appendFileSync(logPath, line + '\n');
  process.stdout.write(line + '\n');
}

async function waitForServer(url, timeoutMs = 12000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      const response = await fetch(url);
      if (response.ok) {
        return;
      }
    } catch {
      // retry
    }
    await new Promise((resolve) => setTimeout(resolve, 150));
  }
  throw new Error(`server did not start in time: ${url}`);
}

async function main() {
  const consoleErrors = [];
  const pageErrors = [];
  const responseErrors = [];
  const steps = [];

  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 1460, height: 960 } });
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
    if (url.endsWith('/favicon.ico') && status === 404) {
      return;
    }
    responseErrors.push(`${status} ${url}`);
  });

  async function capture(name, note, scrollSelector = null) {
    const filePath = path.join(outDir, `${name}.png`);
    if (scrollSelector) {
      await page.locator(scrollSelector).first().scrollIntoViewIfNeeded();
      await page.waitForTimeout(120);
    } else {
      await page.evaluate(() => window.scrollTo(0, 0));
    }
    await page.screenshot({ path: filePath, fullPage: false });
    steps.push({ name, note, filePath: path.relative(root, filePath) });
    log(`screenshot=${filePath}`);
  }

  try {
    await waitForServer(`${baseUrl}/`);
    await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });

    await page.getByRole('tab', { name: 'Create' }).click();
    await page.waitForTimeout(200);
    await capture('01-create-initial', 'Create tab before prompt submission.');

    await page.locator('#generate-prompt').fill('create a svg of a dog');
    await page.selectOption('#llm-provider', 'openai');
    await capture('02-dog-prompt-entered', 'Dog prompt entered and provider selected.');

    await page.getByRole('button', { name: 'Generate (stream)' }).click();
    await page.waitForTimeout(200);
    await capture('03-generation-in-progress', 'Generation requested and stream in progress.');

    await page.waitForFunction(() => {
      const node = document.getElementById('status-action');
      return node && /Generation complete/i.test(node.textContent || '');
    }, { timeout: 10000 });
    await capture('04-generation-complete', 'Generation completed and DOT drafted.');

    const dotSource = await page.locator('#dot-editor').inputValue();
    if (!/^\s*digraph\b/i.test(dotSource)) {
      throw new Error('generated DOT does not start with digraph');
    }
    if (dotSource.includes('<svg')) {
      throw new Error('generated DOT still contains raw svg content');
    }

    await page.getByRole('button', { name: 'Validate' }).click();
    await page.waitForFunction(() => {
      const node = document.getElementById('status-dot');
      return node && /Valid/i.test(node.textContent || '');
    }, { timeout: 10000 });
    await capture('05-dot-validated', 'DOT validated by backend.');

    await page.getByRole('button', { name: 'Preview' }).click();
    await page.waitForSelector('#dot-preview svg', { timeout: 10000 });
    await capture('06-preview-rendered', 'Graph preview rendered from generated DOT.', '#dot-preview');

    const previewText = await page.locator('#dot-preview').innerText();
    if (previewText.includes('Graph Preview Unavailable')) {
      throw new Error('preview still reports Graph Preview Unavailable');
    }

    await page.getByRole('button', { name: 'Run' }).click();
    await page.waitForTimeout(600);
    await capture('07-monitor-after-run', 'Run created and monitor view opened.', '#run-detail');

    await page.getByRole('tab', { name: 'Create' }).click();
    await page.waitForTimeout(200);
    await capture('08-backend-activity-timeline', 'Backend activity timeline after end-to-end dog flow.');

    const statusSnapshot = await page.evaluate(() => ({
      prompt: document.getElementById('status-prompt')?.textContent || '',
      dot: document.getElementById('status-dot')?.textContent || '',
      preview: document.getElementById('status-preview')?.textContent || '',
      action: document.getElementById('status-action')?.textContent || '',
      diagnostics: document.getElementById('dot-diagnostics')?.textContent || '',
      activity: Array.from(document.querySelectorAll('#backend-activity .activity-message')).map((n) => n.textContent || ''),
    }));

    if (!statusSnapshot.activity.some((line) => line.includes('/api/v1/dot/generate/stream'))) {
      throw new Error('backend activity timeline missing generate stream milestone');
    }
    if (!statusSnapshot.activity.some((line) => line.includes('/api/v1/dot/render'))) {
      throw new Error('backend activity timeline missing render milestone');
    }
    if (!statusSnapshot.activity.some((line) => line.includes('/api/v1/pipelines'))) {
      throw new Error('backend activity timeline missing pipeline creation milestone');
    }

    const allErrors = [
      ...pageErrors.map((msg) => `pageerror: ${msg}`),
      ...consoleErrors.map((msg) => `console: ${msg}`),
      ...responseErrors.map((msg) => `http: ${msg}`),
    ];

    fs.writeFileSync(summaryPath, JSON.stringify({
      baseUrl,
      steps,
      statusSnapshot,
      errors: allErrors,
    }, null, 2));

    fs.writeFileSync(analysisPath, [
      '# Dog Prompt DOT Flow Audit',
      '',
      `- Base URL: ${baseUrl}`,
      `- Screenshots: ${steps.length}`,
      `- Console/page/http errors: ${allErrors.length}`,
      '',
      '## Step Notes',
      ...steps.map((step) => `- ${step.name}: ${step.note} (${step.filePath})`),
      '',
      '## Status Snapshot',
      `- Prompt: ${statusSnapshot.prompt}`,
      `- DOT: ${statusSnapshot.dot}`,
      `- Preview: ${statusSnapshot.preview}`,
      `- Action: ${statusSnapshot.action}`,
      '',
    ].join('\n'));

    if (allErrors.length > 0) {
      throw new Error(`browser errors detected: ${allErrors.join(' | ')}`);
    }

    log('dog dot flow audit complete');
  } finally {
    await context.close();
    await browser.close();
  }
}

main().catch((error) => {
  log(`dog dot flow audit failed: ${error.stack || error.message}`);
  process.exit(1);
});
