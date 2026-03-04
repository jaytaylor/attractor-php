const assert = require('node:assert/strict');
const { chromium } = require('playwright');

function must(value, message) {
  assert.ok(value, message);
  return value;
}

async function waitForText(page, selector, expectedText, timeoutMs = 120000) {
  await page.waitForFunction(
    ({ sel, text }) => {
      const node = document.querySelector(sel);
      return node && node.textContent && node.textContent.includes(text);
    },
    { sel: selector, text: expectedText },
    { timeout: timeoutMs },
  );
}

async function validateDot(page) {
  await page.click('#btn-validate');
  await waitForText(page, '#validation-panel', '"valid": true');
}

async function previewDot(page) {
  await page.click('#btn-preview');
  await page.waitForSelector('#preview-panel svg', { timeout: 30000 });
  const svgHtml = await page.locator('#preview-panel').innerHTML();
  must(svgHtml.includes('graph0'), 'preview should contain graphviz svg content');
}

async function runGenerateFlow(page, provider, model) {
  await page.selectOption('#provider-select', provider);
  await page.fill('#model-input', model);
  await page.fill('#prompt-input', `Build release workflow for ${provider} with lint test deploy stages.`);
  await page.click('#btn-generate');
  await waitForText(page, '#validation-panel', 'Generated DOT stream completed.');

  const dot = await page.inputValue('#dot-input');
  must(dot.includes('digraph'), `${provider}: generated DOT should include digraph`);
  must(dot.includes('->'), `${provider}: generated DOT should include at least one edge`);

  await validateDot(page);
}

async function runFixFlow(page, provider, model) {
  await page.selectOption('#provider-select', provider);
  await page.fill('#model-input', model);
  await page.fill('#dot-input', '```dot\na->b\n```');
  await page.click('#btn-fix');
  await waitForText(page, '#validation-panel', 'Fix stream completed.');

  const dot = await page.inputValue('#dot-input');
  must(!dot.includes('```'), `${provider}: fixed DOT should not include markdown fences`);

  await validateDot(page);
}

async function runIterateFlow(page, provider, model) {
  await page.selectOption('#provider-select', provider);
  await page.fill('#model-input', model);
  await page.fill('#changes-input', 'Add a qa_gate node before done and connect it.');
  await page.click('#btn-iterate');
  await waitForText(page, '#validation-panel', 'Iterate stream completed.');

  const dot = await page.inputValue('#dot-input');
  must(dot.includes('digraph'), `${provider}: iterated DOT should include digraph`);
  must(dot.includes('done'), `${provider}: iterated DOT should include done node`);

  await validateDot(page);
}

async function runCreateRunFlow(page) {
  await page.check('#simulate-toggle');
  await page.check('#auto-approve-toggle');
  await page.click('#btn-run');

  await page.waitForSelector('#run-list li button', { timeout: 45000 });
  const runCount = await page.locator('#run-list li button').count();
  must(runCount > 0, 'monitor should list at least one run after Run action');
}

async function main() {
  const baseUrl = process.env.BASE_URL || 'http://127.0.0.1:18084';
  const providerConfigs = [
    { provider: 'openai', model: process.env.DOT_OPENAI_MODEL || 'gpt-5.3-chat-latest' },
    { provider: 'anthropic', model: process.env.DOT_ANTHROPIC_MODEL || 'claude-sonnet-4-6' },
    { provider: 'gemini', model: process.env.DOT_GEMINI_MODEL || 'gemini-2.5-flash' },
  ];

  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();

  try {
    await page.goto(baseUrl, { waitUntil: 'domcontentloaded', timeout: 45000 });

    await page.click('button[data-view="create"]');
    await page.waitForSelector('#prompt-input', { timeout: 10000 });

    for (const cfg of providerConfigs) {
      await runGenerateFlow(page, cfg.provider, cfg.model);
    }

    await runFixFlow(page, providerConfigs[0].provider, providerConfigs[0].model);
    await runIterateFlow(page, providerConfigs[0].provider, providerConfigs[0].model);
    await previewDot(page);
    await runCreateRunFlow(page);

    console.log('Playwright e2e passed');
  } finally {
    await browser.close();
  }
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
