const assert = require('node:assert/strict');
const { chromium } = require('playwright');

function must(value, message) {
  assert.ok(value, message);
  return value;
}

async function waitForText(page, selector, expectedText, timeoutMs = 300000) {
  await page.waitForFunction(
    ({ sel, text }) => {
      const node = document.querySelector(sel);
      return node && node.textContent && node.textContent.includes(text);
    },
    { sel: selector, text: expectedText },
    { timeout: timeoutMs },
  );
}

async function setModel(page, modelId) {
  await page.waitForFunction(() => {
    const select = document.querySelector('#model-select');
    if (!select) return false;
    if (!select.options || select.options.length === 0) return false;
    return !Array.from(select.options).some((opt) => (opt.textContent || '').includes('Loading models'));
  }, null, { timeout: 30000 });

  if (!modelId) {
    return;
  }

  const values = await page.$$eval('#model-select option', (opts) => opts.map((o) => o.value));
  if (values.includes(modelId)) {
    await page.selectOption('#model-select', modelId);
    return;
  }

  await page.selectOption('#model-select', '__custom__');
  await page.fill('#model-custom-input', modelId);
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
  await setModel(page, model);
  await page.fill('#prompt-input', 'Create a picture of a dog');
  await page.click('#btn-generate');
  await waitForText(page, '#validation-panel', 'Generated DOT stream completed.');

  const dot = await page.inputValue('#dot-input');
  must(dot.includes('digraph'), `${provider}: generated DOT should include digraph`);
  must(dot.includes('->'), `${provider}: generated DOT should include at least one edge`);

  await validateDot(page);
}

async function runFixFlow(page, provider, model) {
  await page.selectOption('#provider-select', provider);
  await setModel(page, model);
  await page.fill('#dot-input', '```dot\na->b\n```');
  await page.click('#btn-fix');
  await waitForText(page, '#validation-panel', 'Fix stream completed.');

  const dot = await page.inputValue('#dot-input');
  must(!dot.includes('```'), `${provider}: fixed DOT should not include markdown fences`);

  await validateDot(page);
}

async function runIterateFlow(page, provider, model) {
  await page.selectOption('#provider-select', provider);
  await setModel(page, model);
  await page.fill('#changes-input', 'Add a qa_gate node before done and connect it.');
  await page.click('#btn-iterate');
  await waitForText(page, '#validation-panel', 'Iterate stream completed.');

  const dot = await page.inputValue('#dot-input');
  must(dot.includes('digraph'), `${provider}: iterated DOT should include digraph`);
  must(dot.includes('done'), `${provider}: iterated DOT should include done node`);

  await validateDot(page);
}

async function runCreateRunFlow(page) {
  await page.click('#btn-run');

  await page.waitForSelector('#run-list li button', { timeout: 45000 });
  const runCount = await page.locator('#run-list li button').count();
  must(runCount > 0, 'monitor should list at least one run after Run action');

  await page.waitForFunction(() => {
    const status = document.querySelector('#run-status');
    if (!status || !status.textContent) return false;
    return status.textContent.includes('Status: completed')
      || status.textContent.includes('Status: failed')
      || status.textContent.includes('Status: cancelled');
  }, null, { timeout: 180000 });

  const statusText = await page.locator('#run-status').textContent();
  must((statusText || '').includes('Status: completed'), 'created run should complete');
}

async function main() {
  const baseUrl = process.env.BASE_URL || 'http://127.0.0.1:18084';
  const allProviderConfigs = [
    { provider: 'openai', model: process.env.DOT_OPENAI_MODEL || '' },
    { provider: 'anthropic', model: process.env.DOT_ANTHROPIC_MODEL || '' },
    { provider: 'gemini', model: process.env.DOT_GEMINI_MODEL || '' },
  ];
  const requestedProviders = (process.env.DOT_E2E_PROVIDERS || 'openai')
    .split(',')
    .map((value) => value.trim().toLowerCase())
    .filter(Boolean);
  const providerConfigs = allProviderConfigs.filter((cfg) => requestedProviders.includes(cfg.provider));
  const effectiveProviderConfigs = providerConfigs.length > 0 ? providerConfigs : [allProviderConfigs[0]];

  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();

  try {
    await page.goto(baseUrl, { waitUntil: 'domcontentloaded', timeout: 45000 });

    await page.click('button[data-view="create"]');
    await page.waitForSelector('#prompt-input', { timeout: 10000 });

    for (const cfg of effectiveProviderConfigs) {
      await runGenerateFlow(page, cfg.provider, cfg.model);
    }

    await runFixFlow(page, effectiveProviderConfigs[0].provider, effectiveProviderConfigs[0].model);
    await runIterateFlow(page, effectiveProviderConfigs[0].provider, effectiveProviderConfigs[0].model);
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
