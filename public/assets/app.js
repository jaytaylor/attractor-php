const app = document.getElementById('app');
let selectedRunId = null;
let latestDot = 'digraph Pipeline {\n  start -> done;\n  done [shape=Msquare];\n}';
const providerDefaultModels = {
  openai: 'gpt-5.3-chat-latest',
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

  if (name === 'monitor') initMonitor();
  if (name === 'create') initCreate();
  if (name === 'archived') initArchived();
}

document.querySelectorAll('button[data-view]').forEach((btn) => {
  btn.addEventListener('click', () => setView(btn.dataset.view));
});

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
      title.textContent = 'Run Details';
      status.textContent = '';
      stages.innerHTML = '';
      logs.textContent = '';
      artifacts.innerHTML = '';
      graph.textContent = '';
      question.textContent = '';
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

    try {
      const svg = await fetch(`/api/v1/pipelines/${encodeURIComponent(id)}/graph`).then((r) => r.text());
      graph.innerHTML = svg;
    } catch {
      graph.textContent = 'Graph unavailable';
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
    } catch {
      artifacts.textContent = 'Artifacts unavailable';
    }

    logs.textContent = '';
    const evRes = await fetch(`/api/v1/pipelines/${encodeURIComponent(id)}/events`);
    const txt = await evRes.text();
    logs.textContent = txt;
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
  const model = document.getElementById('model-input');
  const validation = document.getElementById('validation-panel');
  const preview = document.getElementById('preview-panel');
  const btnRun = document.getElementById('btn-run');

  dot.value = latestDot;
  if (!model.value.trim()) model.value = providerDefaultModels[provider.value] || '';
  provider.addEventListener('change', () => {
    model.value = providerDefaultModels[provider.value] || '';
  });

  function dotOptionsPayload(basePayload) {
    return {
      ...basePayload,
      provider: provider.value,
      model: model.value.trim(),
    };
  }

  async function renderPreviewFromDot() {
    const res = await api('/api/v1/dot/render', { method: 'POST', body: JSON.stringify({ dotSource: dot.value }) });
    preview.innerHTML = res.svg;
  }

  document.getElementById('btn-generate').addEventListener('click', async () => {
    try {
      await streamDot(
        '/api/v1/dot/generate/stream',
        dotOptionsPayload({ prompt: prompt.value || 'Build a pipeline' }),
        dot,
      );
      await renderPreviewFromDot();
      validation.textContent = 'Generated DOT stream completed.';
      btnRun.disabled = true;
    } catch (e) {
      validation.textContent = e.message;
    }
  });

  document.getElementById('btn-fix').addEventListener('click', async () => {
    try {
      await streamDot(
        '/api/v1/dot/fix/stream',
        dotOptionsPayload({ dotSource: dot.value, error: 'fix it' }),
        dot,
      );
      await renderPreviewFromDot();
      validation.textContent = 'Fix stream completed.';
      btnRun.disabled = true;
    } catch (e) {
      validation.textContent = e.message;
    }
  });

  document.getElementById('btn-iterate').addEventListener('click', async () => {
    try {
      await streamDot(
        '/api/v1/dot/iterate/stream',
        dotOptionsPayload({ baseDot: dot.value, changes: changes.value || 'Add iteration node' }),
        dot,
      );
      await renderPreviewFromDot();
      validation.textContent = 'Iterate stream completed.';
      btnRun.disabled = true;
    } catch (e) {
      validation.textContent = e.message;
    }
  });

  document.getElementById('btn-validate').addEventListener('click', async () => {
    try {
      const res = await api('/api/v1/dot/validate', { method: 'POST', body: JSON.stringify({ dotSource: dot.value }) });
      validation.textContent = JSON.stringify(res, null, 2);
      btnRun.disabled = !res.valid;
    } catch (e) {
      validation.textContent = e.message;
      btnRun.disabled = true;
    }
  });

  document.getElementById('btn-preview').addEventListener('click', async () => {
    try {
      await renderPreviewFromDot();
    } catch (e) {
      preview.textContent = e.message;
    }
  });

  // Keep preview useful even before explicit interactions.
  renderPreviewFromDot().catch((e) => {
    preview.textContent = e.message;
  });

  btnRun.addEventListener('click', async () => {
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
      validation.textContent = e.message;
    }
  });
}

async function initArchived() {
  const list = document.getElementById('archived-list');
  const data = await api('/api/v1/pipelines?archived=only');
  list.innerHTML = '';
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
