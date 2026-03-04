const app = document.getElementById('app');
let selectedRunId = null;
let latestDot = 'digraph Pipeline {\n  start -> done;\n  done [shape=Msquare];\n}';
const CUSTOM_MODEL_VALUE = '__custom__';
const providerDefaultModels = {
  openai: 'gpt-5-chat-latest',
  anthropic: 'claude-sonnet-4-6',
  gemini: 'gemini-2.5-flash',
};

function esc(s) {
  return String(s).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
}

async function api(path, opts = {}) {
  const res = await fetch(path, {
    headers: { 'Content-Type': 'application/json' },
    ...opts,
  });
  const text = await res.text();
  let json;
  try { json = text ? JSON.parse(text) : {}; } catch { json = { raw: text }; }
  if (!res.ok) {
    throw new Error(`${res.status} ${json.error || 'request failed'}`);
  }
  return json;
}

function setView(name) {
  const tpl = document.getElementById(`${name}-template`);
  app.innerHTML = '';
  app.appendChild(tpl.content.cloneNode(true));
  document.querySelectorAll('button[data-view]').forEach((btn) => {
    btn.classList.toggle('active', btn.dataset.view === name);
  });

  if (name === 'monitor') initMonitor();
  if (name === 'create') initCreate();
  if (name === 'archived') initArchived();
}

document.querySelectorAll('button[data-view]').forEach((btn) => {
  btn.addEventListener('click', () => setView(btn.dataset.view));
});

function renderEmptyHtml(message) {
  return `<div class="empty-state">${esc(message)}</div>`;
}

function makeSvgResponsive(svg, options = {}) {
  if (!svg) return;
  const {
    zoom = 1,
    mode = 'contain',
    container = svg.parentElement,
  } = options;

  svg.removeAttribute('width');
  svg.removeAttribute('height');
  const viewBox = (svg.getAttribute('viewBox') || '').trim().split(/\s+/).map(Number);

  if (mode === 'contain' && container && viewBox.length === 4 && viewBox.every(Number.isFinite)) {
    const [, , vbWidth, vbHeight] = viewBox;
    const containerWidth = Math.max(220, container.clientWidth - 20);
    const containerHeight = Math.max(160, container.clientHeight - 20);
    const fitScale = Math.max(0.02, Math.min(containerWidth / vbWidth, containerHeight / vbHeight));
    const scale = fitScale * zoom;
    svg.style.width = `${Math.max(120, vbWidth * scale)}px`;
    svg.style.height = `${Math.max(120, vbHeight * scale)}px`;
  } else {
    svg.style.width = `${Math.round(zoom * 100)}%`;
    svg.style.height = 'auto';
  }

  svg.style.display = 'block';
  svg.style.margin = '0 auto';
}

async function initMonitor() {
  const runList = document.getElementById('run-list');
  const refresh = document.getElementById('refresh-runs');
  const title = document.getElementById('run-title');
  const status = document.getElementById('run-status');
  const stages = document.getElementById('stage-list');
  const logs = document.getElementById('log-panel');
  const artifacts = document.getElementById('artifact-list');
  const graph = document.getElementById('graph-panel');
  const question = document.getElementById('question-panel');
  const actions = document.getElementById('run-actions');
  runList.innerHTML = '<li class="empty-state-item">Loading runs...</li>';
  graph.innerHTML = renderEmptyHtml('Loading graph...');
  logs.textContent = 'Loading logs...';
  question.textContent = 'Loading questions...';
  artifacts.innerHTML = '<li class="empty-state-item">Loading artifacts...</li>';

  async function loadRuns() {
    const data = await api('/api/v1/pipelines?includeArchived=true');
    runList.innerHTML = '';
    for (const run of data.items || []) {
      const li = document.createElement('li');
      li.innerHTML = `<button data-id="${esc(run.id)}">${esc(run.displayName)} (${esc(run.status)})${run.archived ? ' [archived]' : ''}</button>`;
      li.querySelector('button').addEventListener('click', () => {
        selectedRunId = run.id;
        renderRun(run.id);
      });
      runList.appendChild(li);
    }

    if (!data.items?.length) {
      selectedRunId = null;
      runList.innerHTML = '<li class="empty-state-item">No runs yet. Create one from the Create view.</li>';
      title.textContent = 'Run Details';
      status.textContent = '';
      stages.innerHTML = '<li class="empty-state-item">No stages yet.</li>';
      logs.textContent = 'No logs yet.';
      artifacts.innerHTML = '<li class="empty-state-item">No artifacts yet.</li>';
      graph.innerHTML = renderEmptyHtml('Graph preview appears after selecting a run.');
      question.textContent = 'No pending questions.';
      actions.innerHTML = '';
      return;
    }

    const runIds = new Set((data.items || []).map((item) => item.id));
    if (!selectedRunId || !runIds.has(selectedRunId)) {
      selectedRunId = data.items[0].id;
    }

    await renderRun(selectedRunId);
  }

  async function renderRun(id) {
    const run = await api(`/api/v1/pipelines/${encodeURIComponent(id)}`);
    title.textContent = `${run.displayName} (${run.id})`;
    status.className = `status-${run.status}`;
    status.textContent = `Status: ${run.status} | Current node: ${run.currentNodeId} | familyId: ${run.familyId}`;

    actions.innerHTML = '';
    const btns = [];
    if (run.status === 'running') {
      btns.push(['Cancel', async () => api(`/api/v1/pipelines/${encodeURIComponent(id)}/cancel`, { method: 'POST' })]);
    }
    if (run.status !== 'running') {
      btns.push([run.archived ? 'Unarchive' : 'Archive', async () => api(`/api/v1/pipelines/${encodeURIComponent(id)}/${run.archived ? 'unarchive' : 'archive'}`, { method: 'POST' })]);
      btns.push(['Delete', async () => api(`/api/v1/pipelines/${encodeURIComponent(id)}`, { method: 'DELETE' })]);
      btns.push(['Iterate', async () => {
        setView('create');
        const dotEl = document.getElementById('dot-input');
        const promptEl = document.getElementById('prompt-input');
        dotEl.value = run.dotSource || '';
        promptEl.value = run.originalPrompt || '';
      }]);
    }
    for (const [label, fn] of btns) {
      const b = document.createElement('button');
      b.textContent = label;
      b.addEventListener('click', async () => {
        try { await fn(); await loadRuns(); await renderRun(id); } catch (e) { alert(e.message); }
      });
      actions.appendChild(b);
    }

    stages.innerHTML = '';
    for (const st of run.stages || []) {
      const li = document.createElement('li');
      li.textContent = `${st.nodeId}: ${st.status}${st.error ? ` (${st.error})` : ''}`;
      stages.appendChild(li);
    }
    if (!run.stages?.length) {
      stages.innerHTML = '<li class="empty-state-item">No stage snapshots yet.</li>';
    }

    try {
      const svg = await fetch(`/api/v1/pipelines/${encodeURIComponent(id)}/graph`).then((r) => r.text());
      if (svg.includes('<svg')) {
        graph.innerHTML = svg;
        makeSvgResponsive(graph.querySelector('svg'), { container: graph, mode: 'contain', zoom: 1 });
      } else {
        graph.innerHTML = renderEmptyHtml('Graph unavailable for this run.');
      }
    } catch {
      graph.innerHTML = renderEmptyHtml('Graph unavailable');
    }

    try {
      const q = await api(`/api/v1/pipelines/${encodeURIComponent(id)}/questions`);
      if (q.items?.length) {
        const item = q.items[0];
        question.innerHTML = `<p>${esc(item.text)}</p>`;
        for (const opt of item.options || []) {
          const b = document.createElement('button');
          b.textContent = `${opt.key}: ${opt.label}`;
          b.addEventListener('click', async () => {
            await api(`/api/v1/pipelines/${encodeURIComponent(id)}/questions/${encodeURIComponent(item.id)}/answer`, {
              method: 'POST',
              body: JSON.stringify({ answer: opt.key }),
            });
            await renderRun(id);
          });
          question.appendChild(b);
        }
      } else {
        question.textContent = 'No pending questions.';
      }
    } catch {
      question.textContent = 'Question panel unavailable.';
    }

    try {
      const artifactData = await api(`/api/v1/pipelines/${encodeURIComponent(id)}/artifacts`);
      artifacts.innerHTML = '';
      for (const art of artifactData.items || []) {
        const li = document.createElement('li');
        const fileLink = `/api/v1/pipelines/${encodeURIComponent(id)}/artifacts/${art.path}`;
        li.innerHTML = `<a href="${encodeURI(fileLink)}" target="_blank">${esc(art.path)}</a> (${art.sizeBytes} bytes)`;
        artifacts.appendChild(li);
      }
      const zipLi = document.createElement('li');
      zipLi.innerHTML = `<a href="/api/v1/pipelines/${encodeURIComponent(id)}/artifacts.zip" target="_blank">Download artifacts.zip</a>`;
      artifacts.appendChild(zipLi);
      if (!artifactData.items?.length) {
        artifacts.innerHTML = '<li class="empty-state-item">No artifacts produced yet.</li>';
      }
    } catch {
      artifacts.textContent = 'Artifacts unavailable';
    }

    logs.textContent = '';
    const evRes = await fetch(`/api/v1/pipelines/${encodeURIComponent(id)}/events`);
    const txt = await evRes.text();
    logs.textContent = txt.trim() || 'No events streamed yet.';
  }

  refresh.addEventListener('click', () => loadRuns().catch((e) => alert(e.message)));
  await loadRuns();
}

async function streamDot(path, payload, outputEl) {
  const res = await fetch(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  if (!res.ok) {
    const text = await res.text();
    throw new Error(text);
  }
  const text = await res.text();
  let built = '';
  text.split('\n').forEach((line) => {
    if (!line.startsWith('data: ')) return;
    const frame = JSON.parse(line.slice(6));
    if (frame.delta) built += frame.delta;
    if (frame.done) built = frame.dotSource;
  });
  outputEl.value = built;
  latestDot = built;
}

async function initCreate() {
  const dot = document.getElementById('dot-input');
  const prompt = document.getElementById('prompt-input');
  const changes = document.getElementById('changes-input');
  const provider = document.getElementById('provider-select');
  const modelSelect = document.getElementById('model-select');
  const modelCustomInput = document.getElementById('model-custom-input');
  const modelCatalogMeta = document.getElementById('model-catalog-meta');
  const validation = document.getElementById('validation-panel');
  const preview = document.getElementById('preview-panel');
  const previewMeta = document.getElementById('preview-meta');
  const workflowStatus = document.getElementById('workflow-status');
  const btnGenerate = document.getElementById('btn-generate');
  const btnFix = document.getElementById('btn-fix');
  const btnIterate = document.getElementById('btn-iterate');
  const btnValidate = document.getElementById('btn-validate');
  const btnPreview = document.getElementById('btn-preview');
  const btnRun = document.getElementById('btn-run');
  const btnFitPreview = document.getElementById('btn-fit-preview');
  const btnZoomOut = document.getElementById('btn-zoom-out');
  const btnZoomIn = document.getElementById('btn-zoom-in');
  const btnResetPreview = document.getElementById('btn-reset-preview');

  const interactiveEls = [
    btnGenerate,
    btnFix,
    btnIterate,
    btnValidate,
    btnPreview,
    btnRun,
    btnFitPreview,
    btnZoomOut,
    btnZoomIn,
    btnResetPreview,
  ];
  let previewDirty = false;
  let validated = false;
  let busy = false;
  let previewZoom = 1;

  dot.value = latestDot;
  modelSelect.innerHTML = `<option value="${esc(providerDefaultModels[provider.value] || '')}">${esc(providerDefaultModels[provider.value] || '')}</option>`;
  modelCustomInput.classList.add('hidden');

  function selectedModelValue() {
    const selected = modelSelect.value;
    if (selected === CUSTOM_MODEL_VALUE) {
      return modelCustomInput.value.trim();
    }
    return selected.trim();
  }

  function setModelMeta(message, state = 'muted') {
    modelCatalogMeta.textContent = message;
    modelCatalogMeta.dataset.state = state;
  }

  function syncModelInputVisibility() {
    const custom = modelSelect.value === CUSTOM_MODEL_VALUE;
    modelCustomInput.classList.toggle('hidden', !custom);
    modelCustomInput.disabled = !custom;
  }

  function fallbackModels(providerId) {
    const fallback = providerDefaultModels[providerId] || '';
    return fallback ? [fallback] : [];
  }

  function renderModelOptions(models, selectedValue, customValue = '') {
    modelSelect.innerHTML = '';
    const unique = [];
    const seen = new Set();
    for (const m of models) {
      const value = String(m || '').trim();
      if (!value || seen.has(value)) continue;
      seen.add(value);
      unique.push(value);
    }

    unique.forEach((modelId) => {
      const opt = document.createElement('option');
      opt.value = modelId;
      opt.textContent = modelId;
      modelSelect.appendChild(opt);
    });

    const customOpt = document.createElement('option');
    customOpt.value = CUSTOM_MODEL_VALUE;
    customOpt.textContent = 'Custom...';
    modelSelect.appendChild(customOpt);

    if (selectedValue && unique.includes(selectedValue)) {
      modelSelect.value = selectedValue;
    } else if (selectedValue && !unique.includes(selectedValue)) {
      modelSelect.value = CUSTOM_MODEL_VALUE;
      modelCustomInput.value = selectedValue;
    } else if (unique.length) {
      modelSelect.value = unique[0];
    } else {
      modelSelect.value = CUSTOM_MODEL_VALUE;
      modelCustomInput.value = customValue;
    }

    if (modelSelect.value === CUSTOM_MODEL_VALUE && !modelCustomInput.value.trim()) {
      modelCustomInput.value = customValue;
    }
    syncModelInputVisibility();
  }

  async function loadModelsForProvider(providerId, preferredModel = '') {
    const previousSelection = preferredModel || providerDefaultModels[providerId] || '';
    const previousCustom = modelCustomInput.value.trim();
    modelSelect.innerHTML = '<option value="">Loading models...</option>';
    modelSelect.disabled = true;
    setModelMeta(`Loading ${providerId} model catalog...`, 'muted');

    try {
      const catalog = await api(`/api/v1/dot/models?provider=${encodeURIComponent(providerId)}`);
      const models = Array.isArray(catalog.models) ? catalog.models : [];
      const fallback = fallbackModels(providerId);
      const combined = models.length ? models : fallback;
      const defaultModel = String(catalog.defaultModel || providerDefaultModels[providerId] || '').trim();
      renderModelOptions(combined, previousSelection || defaultModel, previousCustom);
      const source = String(catalog.source || 'fallback');
      const count = combined.length;
      setModelMeta(`${count} models loaded (${source}).`, source === 'live' ? 'success' : 'muted');
    } catch (e) {
      const fallback = fallbackModels(providerId);
      renderModelOptions(fallback, previousSelection || fallback[0] || '', previousCustom);
      setModelMeta(`Model catalog unavailable (${e.message}). Using fallback list.`, 'error');
    } finally {
      modelSelect.disabled = false;
      syncModelInputVisibility();
    }
  }

  provider.addEventListener('change', () => {
    loadModelsForProvider(provider.value).catch((e) => {
      setModelMeta(`Failed to refresh models: ${e.message}`, 'error');
    });
  });

  modelSelect.addEventListener('change', () => {
    syncModelInputVisibility();
  });

  function dotOptionsPayload(basePayload) {
    const selectedModel = selectedModelValue();
    return {
      ...basePayload,
      provider: provider.value,
      model: selectedModel,
    };
  }

  function setValidationMessage(message, state = 'info', details = null) {
    validation.dataset.state = state;
    const content = details ? `${message}\n\n${JSON.stringify(details, null, 2)}` : message;
    validation.textContent = content;
  }

  function setPreviewMeta(message, state = 'info') {
    previewMeta.textContent = message;
    previewMeta.dataset.state = state;
    preview.dataset.state = state;
  }

  function applyPreviewZoom() {
    const svg = preview.querySelector('svg');
    if (!svg) return;
    makeSvgResponsive(svg, { container: preview, mode: 'contain', zoom: previewZoom });
  }

  function markPreviewDirty() {
    previewDirty = true;
    validated = false;
    btnRun.disabled = true;
    setPreviewMeta('Preview may be stale after DOT edits. Render to sync.', 'stale');
    updateWorkflowStatus();
  }

  function updateWorkflowStatus() {
    const steps = workflowStatus.querySelectorAll('[data-step]');
    const hasPreview = !!preview.querySelector('svg');
    const complete = {
      draft: dot.value.trim().length > 0,
      preview: hasPreview && !previewDirty,
      validate: validated,
      run: false,
    };
    const order = ['draft', 'preview', 'validate', 'run'];
    let current = 'run';
    for (const key of order) {
      if (!complete[key]) {
        current = key;
        break;
      }
    }
    steps.forEach((step) => {
      const key = step.dataset.step;
      step.classList.toggle('is-complete', !!complete[key]);
      step.classList.toggle('is-current', key === current);
      step.classList.toggle('is-pending', !complete[key] && key !== current);
    });
  }

  function setBusyState(isBusy, busyMessage = 'Working...') {
    busy = isBusy;
    interactiveEls.forEach((el) => {
      if (!el) return;
      if (el === btnRun) {
        el.disabled = isBusy || !validated;
      } else {
        el.disabled = isBusy;
      }
    });
    provider.disabled = isBusy;
    modelSelect.disabled = isBusy;
    modelCustomInput.disabled = isBusy || modelSelect.value !== CUSTOM_MODEL_VALUE;
    prompt.disabled = isBusy;
    changes.disabled = isBusy;
    dot.readOnly = isBusy;
    if (isBusy) {
      setValidationMessage(busyMessage, 'info');
    }
  }

  async function inBusyState(message, fn) {
    if (busy) return;
    setBusyState(true, message);
    try {
      await fn();
    } finally {
      setBusyState(false);
      updateWorkflowStatus();
    }
  }

  async function renderPreviewFromDot(successMessage = 'Preview updated.') {
    setPreviewMeta('Rendering preview...', 'loading');
    const res = await api('/api/v1/dot/render', { method: 'POST', body: JSON.stringify({ dotSource: dot.value }) });
    preview.innerHTML = res.svg;
    if (!preview.querySelector('svg')) {
      preview.innerHTML = renderEmptyHtml('No graph output returned.');
      previewDirty = true;
      setPreviewMeta('Preview render failed: no SVG output.', 'error');
      throw new Error('Preview render returned no SVG');
    }
    previewDirty = false;
    applyPreviewZoom();
    setPreviewMeta(successMessage, 'success');
    updateWorkflowStatus();
  }

  function onDotInput() {
    latestDot = dot.value;
    markPreviewDirty();
  }

  dot.addEventListener('input', onDotInput);

  btnGenerate.addEventListener('click', async () => {
    await inBusyState('Generating DOT...', async () => {
      try {
        await streamDot(
          '/api/v1/dot/generate/stream',
          dotOptionsPayload({ prompt: prompt.value || 'Build a pipeline' }),
          dot,
        );
        latestDot = dot.value;
        await renderPreviewFromDot('Generated DOT rendered in preview.');
        setValidationMessage('Generated DOT stream completed.', 'success');
      } catch (e) {
        setValidationMessage(e.message, 'error');
        setPreviewMeta('Generation failed. Check validation panel.', 'error');
      }
    });
  });

  btnFix.addEventListener('click', async () => {
    await inBusyState('Fixing DOT...', async () => {
      try {
        await streamDot(
          '/api/v1/dot/fix/stream',
          dotOptionsPayload({ dotSource: dot.value, error: 'fix it' }),
          dot,
        );
        latestDot = dot.value;
        await renderPreviewFromDot('Fixed DOT rendered in preview.');
        setValidationMessage('Fix stream completed.', 'success');
      } catch (e) {
        setValidationMessage(e.message, 'error');
        setPreviewMeta('Fix failed. Check validation panel.', 'error');
      }
    });
  });

  btnIterate.addEventListener('click', async () => {
    await inBusyState('Iterating DOT...', async () => {
      try {
        await streamDot(
          '/api/v1/dot/iterate/stream',
          dotOptionsPayload({ baseDot: dot.value, changes: changes.value || 'Add iteration node' }),
          dot,
        );
        latestDot = dot.value;
        await renderPreviewFromDot('Iterated DOT rendered in preview.');
        setValidationMessage('Iterate stream completed.', 'success');
      } catch (e) {
        setValidationMessage(e.message, 'error');
        setPreviewMeta('Iterate failed. Check validation panel.', 'error');
      }
    });
  });

  btnValidate.addEventListener('click', async () => {
    await inBusyState('Validating DOT...', async () => {
      try {
        const res = await api('/api/v1/dot/validate', { method: 'POST', body: JSON.stringify({ dotSource: dot.value }) });
        validated = !!res.valid;
        btnRun.disabled = !validated;
        setValidationMessage(validated ? 'Validation passed.' : 'Validation failed.', validated ? 'success' : 'error', res);
        if (!validated) setPreviewMeta('Validation failed. Fix DOT and re-render preview.', 'error');
      } catch (e) {
        validated = false;
        setValidationMessage(e.message, 'error');
        btnRun.disabled = true;
      }
    });
  });

  btnPreview.addEventListener('click', async () => {
    await inBusyState('Rendering preview...', async () => {
      try {
        await renderPreviewFromDot('Preview rendered from current DOT.');
        setValidationMessage('Preview render completed.', 'success');
      } catch (e) {
        preview.innerHTML = renderEmptyHtml(e.message);
        setValidationMessage(e.message, 'error');
      }
    });
  });

  btnFitPreview.addEventListener('click', () => {
    previewZoom = 1;
    applyPreviewZoom();
    setPreviewMeta('Fit to panel width.', 'info');
  });

  btnZoomIn.addEventListener('click', () => {
    previewZoom = Math.min(3, previewZoom + 0.25);
    applyPreviewZoom();
    setPreviewMeta(`Zoom ${Math.round(previewZoom * 100)}%.`, 'info');
  });

  btnZoomOut.addEventListener('click', () => {
    previewZoom = Math.max(0.5, previewZoom - 0.25);
    applyPreviewZoom();
    setPreviewMeta(`Zoom ${Math.round(previewZoom * 100)}%.`, 'info');
  });

  btnResetPreview.addEventListener('click', async () => {
    previewZoom = 1;
    await inBusyState('Resetting preview...', async () => {
      try {
        await renderPreviewFromDot('Preview reset and rendered.');
      } catch (e) {
        preview.innerHTML = renderEmptyHtml(e.message);
      }
    });
  });

  await loadModelsForProvider(provider.value).catch((e) => {
    setModelMeta(`Failed to load model catalog: ${e.message}`, 'error');
  });

  setValidationMessage('Ready. Generate or edit DOT, then Validate to enable Run.', 'info');
  setPreviewMeta('Rendering initial preview...', 'loading');
  updateWorkflowStatus();

  renderPreviewFromDot().catch((e) => {
    preview.innerHTML = renderEmptyHtml(e.message);
    setPreviewMeta('Initial preview failed. Use Render Preview after fixing DOT.', 'error');
    setValidationMessage(e.message, 'error');
  });

  btnRun.addEventListener('click', async () => {
    await inBusyState('Creating run...', async () => {
      try {
        const simulate = document.getElementById('simulate-toggle').checked;
        const autoApprove = document.getElementById('auto-approve-toggle').checked;
        const created = await api('/api/v1/pipelines', {
          method: 'POST',
          body: JSON.stringify({
            dotSource: dot.value,
            simulate,
            autoApprove,
            originalPrompt: prompt.value || '',
            fileName: 'pipeline.dot',
          }),
        });
        selectedRunId = created.id;
        setView('monitor');
      } catch (e) {
        setValidationMessage(e.message, 'error');
      }
    });
  });
}

async function initArchived() {
  const list = document.getElementById('archived-list');
  const help = document.getElementById('archived-help');
  list.innerHTML = '<li class="empty-state-item">Loading archived runs...</li>';
  const data = await api('/api/v1/pipelines?archived=only');
  list.innerHTML = '';
  if (!data.items?.length) {
    list.innerHTML = '<li class="empty-state-item">No archived runs yet.</li>';
    if (help) help.textContent = 'Archive a run from Monitor to see it here.';
    return;
  }
  for (const run of data.items || []) {
    const li = document.createElement('li');
    const openBtn = document.createElement('button');
    openBtn.textContent = `Open ${run.displayName}`;
    openBtn.addEventListener('click', () => {
      selectedRunId = run.id;
      setView('monitor');
    });

    const unarchiveBtn = document.createElement('button');
    unarchiveBtn.textContent = 'Unarchive';
    unarchiveBtn.addEventListener('click', async () => {
      await api(`/api/v1/pipelines/${encodeURIComponent(run.id)}/unarchive`, { method: 'POST' });
      initArchived().catch((e) => alert(e.message));
    });

    li.appendChild(openBtn);
    li.appendChild(unarchiveBtn);
    list.appendChild(li);
  }
}

setView('monitor');
