(() => {
  const state = {
    view: 'monitor',
    runs: [],
    selectedRunId: null,
    selectedRun: null,
    iterateSourceRun: null,
    lastDotError: '',
    runEventCursor: {},
    runEventLines: {},
    pollTimer: null,
    pollInFlight: false,
  };

  const el = (id) => document.getElementById(id);

  const flash = (message) => {
    const node = el('flash');
    node.textContent = message;
    node.classList.remove('hidden');
    setTimeout(() => node.classList.add('hidden'), 3200);
  };

  const setView = (view) => {
    state.view = view;
    document.querySelectorAll('.tab').forEach((tab) => {
      tab.classList.toggle('active', tab.dataset.view === view);
    });
    document.querySelectorAll('.view').forEach((node) => {
      node.classList.toggle('active', node.id === `view-${view}`);
    });

    if (view === 'archived') {
      void refreshArchived();
    }
    if (view === 'monitor') {
      void refreshRuns({ reloadSelected: true });
    }
  };

  const api = async (method, path, body) => {
    const response = await fetch(path, {
      method,
      headers: { 'content-type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined,
    });

    const type = response.headers.get('content-type') || '';
    const isJson = type.includes('application/json');
    const payload = isJson ? await response.json() : await response.text();

    if (!response.ok) {
      const message = isJson ? `${payload.code || 'ERR'}: ${payload.error || 'request failed'}` : `HTTP ${response.status}`;
      throw new Error(message);
    }
    return payload;
  };

  const consumeSseFrames = (raw) => {
    const events = [];
    const blocks = raw.split('\n\n');
    for (const block of blocks) {
      const line = block.trim();
      if (!line.startsWith('data:')) {
        continue;
      }
      const jsonText = line.replace(/^data:\s*/, '');
      try {
        events.push(JSON.parse(jsonText));
      } catch {
        // Ignore malformed frames.
      }
    }
    return events;
  };

  const postStream = async (path, body, onDelta) => {
    const response = await fetch(path, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(body),
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const raw = await response.text();
    const events = consumeSseFrames(raw);
    let done = null;
    for (const event of events) {
      if (event.delta) {
        onDelta(event.delta);
      }
      if (event.done) {
        done = event;
      }
      if (event.error) {
        throw new Error(event.error);
      }
    }
    if (!done) {
      throw new Error('stream completed without done frame');
    }
    return done.dotSource;
  };

  const runBadge = (status) => {
    const colors = {
      running: '#2a9d8f',
      completed: '#2a9d8f',
      failed: '#d62828',
      waiting_human: '#f4a261',
      cancelled: '#8d99ae',
    };
    const color = colors[status] || '#8d99ae';
    return `<span style="display:inline-block;width:10px;height:10px;border-radius:999px;background:${color};margin-right:6px;"></span>${status}`;
  };

  const eventLine = (event) => {
    const type = event.type || 'Event';
    const payload = JSON.stringify(event.payload || {});
    return `[${type}] ${payload}`;
  };

  const applyRunEvents = (runId, events, reset = false) => {
    if (reset || !Array.isArray(state.runEventLines[runId])) {
      state.runEventLines[runId] = [];
      state.runEventCursor[runId] = 0;
    }

    let cursor = state.runEventCursor[runId] || 0;
    for (const event of events) {
      const ts = Number(event.tsMs || 0);
      if (ts > cursor) {
        cursor = ts;
      }
      if (event.type === 'Snapshot') {
        continue;
      }
      state.runEventLines[runId].push(eventLine(event));
    }

    if (state.runEventLines[runId].length > 500) {
      state.runEventLines[runId] = state.runEventLines[runId].slice(-500);
    }
    state.runEventCursor[runId] = cursor;
  };

  const syncRunEvents = async (runId, reset = false) => {
    const sinceTs = reset ? 0 : (state.runEventCursor[runId] || 0);
    const raw = await fetch(`/api/v1/pipelines/${encodeURIComponent(runId)}/events?sinceTs=${sinceTs}`).then((r) => r.text());
    const events = consumeSseFrames(raw);
    applyRunEvents(runId, events, reset);
  };

  const refreshRuns = async ({ reloadSelected = true } = {}) => {
    state.runs = await api('GET', '/api/v1/pipelines');
    renderRunList();

    if (!reloadSelected || !state.selectedRunId) {
      return;
    }

    if (!state.runs.some((run) => run.id === state.selectedRunId)) {
      state.selectedRunId = null;
      state.selectedRun = null;
      el('run-detail').classList.add('hidden');
      el('run-detail-empty').classList.remove('hidden');
      return;
    }

    state.selectedRun = await api('GET', `/api/v1/pipelines/${encodeURIComponent(state.selectedRunId)}`);
    await syncRunEvents(state.selectedRunId, false);
    await renderRunDetail();
  };

  const renderRunList = () => {
    const list = el('run-list');
    list.innerHTML = '';
    for (const run of state.runs) {
      const item = document.createElement('li');
      item.className = `run-item ${run.id === state.selectedRunId ? 'active' : ''}`;
      item.innerHTML = `<strong>${run.displayName || run.id}</strong><br><span class="small">${runBadge(run.status)}</span><br><span class="small">${run.currentNodeId || 'n/a'}</span>`;
      item.addEventListener('click', () => {
        void selectRun(run.id);
      });
      list.appendChild(item);
    }
  };

  const selectRun = async (runId) => {
    state.selectedRunId = runId;
    state.selectedRun = await api('GET', `/api/v1/pipelines/${encodeURIComponent(runId)}`);
    await syncRunEvents(runId, true);
    renderRunList();
    await renderRunDetail();
  };

  const renderRunDetail = async () => {
    const run = state.selectedRun;
    if (!run) {
      return;
    }

    el('run-detail-empty').classList.add('hidden');
    el('run-detail').classList.remove('hidden');
    el('run-title').textContent = run.displayName || run.id;
    el('run-meta').innerHTML = `${runBadge(run.status)} | familyId=${run.familyId} | simulate=${run.simulate} | archived=${run.archived}`;

    const stages = el('stage-list');
    stages.innerHTML = '';
    for (const stage of run.stages || []) {
      const li = document.createElement('li');
      li.className = 'stage';
      li.textContent = `${stage.index}. ${stage.name || stage.nodeId} -> ${stage.status}`;
      stages.appendChild(li);
    }

    el('cancel-run').disabled = run.status !== 'running';
    el('archive-run').disabled = run.status === 'running' || run.archived;
    el('unarchive-run').disabled = run.status === 'running' || !run.archived;
    el('delete-run').disabled = run.status === 'running';
    el('iterate-run').disabled = run.status === 'running';

    const log = el('live-log');
    const lines = state.runEventLines[run.id] || [];
    log.textContent = lines.length > 0 ? lines.join('\n') : 'No events yet.';

    const graph = await fetch(`/api/v1/pipelines/${encodeURIComponent(run.id)}/graph`).then((r) => r.text());
    el('graph-svg').innerHTML = graph;

    const artifacts = await api('GET', `/api/v1/pipelines/${encodeURIComponent(run.id)}/artifacts`);
    const artifactList = el('artifact-list');
    artifactList.innerHTML = '';
    for (const item of artifacts) {
      const li = document.createElement('li');
      li.className = 'artifact';
      li.textContent = `${item.path} (${item.sizeBytes} bytes)`;
      li.addEventListener('click', async () => {
        try {
          const resp = await fetch(`/api/v1/pipelines/${encodeURIComponent(run.id)}/artifacts/${item.path}`);
          const text = await resp.text();
          const preview = text.length > 5000 ? `${text.slice(0, 5000)}\n...[truncated]` : text;
          el('artifact-preview').textContent = preview;
        } catch (error) {
          flash(error.message || 'artifact preview failed');
        }
      });
      artifactList.appendChild(li);
    }

    const questions = await api('GET', `/api/v1/pipelines/${encodeURIComponent(run.id)}/questions`);
    const qbox = el('question-box');
    if (questions.length === 0) {
      qbox.textContent = 'No pending questions.';
    } else {
      const q = questions[0];
      const buttons = (q.options || []).map((opt) => `<button class="btn answer-btn" data-q="${q.id}" data-answer="${opt.key}">${opt.label}</button>`).join(' ');
      qbox.innerHTML = `<div><strong>${q.text}</strong></div><div class="actions">${buttons}</div>`;
      qbox.querySelectorAll('.answer-btn').forEach((button) => {
        button.addEventListener('click', async () => {
          try {
            await api('POST', `/api/v1/pipelines/${encodeURIComponent(run.id)}/questions/${encodeURIComponent(button.dataset.q)}/answer`, { answer: button.dataset.answer });
            await selectRun(run.id);
            flash('Answer submitted');
          } catch (error) {
            flash(error.message || 'answer failed');
          }
        });
      });
    }
  };

  const refreshArchived = async () => {
    const runs = await api('GET', '/api/v1/pipelines?includeArchived=true');
    const archived = runs.filter((r) => r.archived);
    const list = el('archived-list');
    list.innerHTML = '';
    for (const run of archived) {
      const item = document.createElement('li');
      item.className = 'run-item';
      item.innerHTML = `<strong>${run.displayName || run.id}</strong><br><span class="small">${run.status}</span><br><button class="btn unarchive" data-id="${run.id}">Unarchive</button> <button class="btn open" data-id="${run.id}">Open</button>`;
      item.querySelector('.unarchive').addEventListener('click', async () => {
        try {
          await api('POST', `/api/v1/pipelines/${encodeURIComponent(run.id)}/unarchive`);
          await refreshArchived();
          await refreshRuns({ reloadSelected: true });
        } catch (error) {
          flash(error.message || 'unarchive failed');
        }
      });
      item.querySelector('.open').addEventListener('click', async () => {
        try {
          setView('monitor');
          await refreshRuns({ reloadSelected: false });
          await selectRun(run.id);
        } catch (error) {
          flash(error.message || 'open run failed');
        }
      });
      list.appendChild(item);
    }
  };

  const runValidate = async () => {
    const dotSource = el('dot-editor').value;
    try {
      const result = await api('POST', '/api/v1/dot/validate', { dotSource });
      if (result.valid) {
        el('dot-diagnostics').textContent = 'DOT is valid.';
        el('run-dot').disabled = false;
        state.lastDotError = '';
      } else {
        const msg = result.diagnostics.map((d) => d.message).join('\n');
        el('dot-diagnostics').textContent = msg;
        el('run-dot').disabled = true;
        state.lastDotError = msg;
      }
    } catch (error) {
      el('dot-diagnostics').textContent = error.message;
      el('run-dot').disabled = true;
      state.lastDotError = error.message;
    }
  };

  const runPreview = async () => {
    try {
      const result = await api('POST', '/api/v1/dot/render', { dotSource: el('dot-editor').value });
      el('dot-preview').innerHTML = result.svg;
    } catch (error) {
      state.lastDotError = error.message;
      el('dot-diagnostics').textContent = error.message;
      flash(error.message);
    }
  };

  const runCreate = async () => {
    await runValidate();
    if (el('run-dot').disabled) {
      flash('DOT must validate before run');
      return;
    }

    const payload = {
      dotSource: el('dot-editor').value,
      fileName: 'pipeline.dot',
      displayName: 'Dashboard Run',
      simulate: el('simulate').checked,
      autoApprove: el('auto-approve').checked,
      originalPrompt: el('generate-prompt').value.trim(),
    };

    const response = await api('POST', '/api/v1/pipelines', payload);
    flash(`Run started: ${response.id}`);
    setView('monitor');
    await refreshRuns({ reloadSelected: false });
    await selectRun(response.id);
  };

  const runGenerate = async () => {
    const prompt = el('generate-prompt').value.trim();
    if (!prompt) {
      flash('Prompt is required');
      return;
    }
    el('dot-editor').value = '';
    const doneDot = await postStream('/api/v1/dot/generate/stream', { prompt }, (delta) => {
      el('dot-editor').value += delta;
    });
    el('dot-editor').value = doneDot;
    await runValidate();
    await runPreview();
  };

  const runFix = async () => {
    const dotSource = el('dot-editor').value;
    const error = state.lastDotError || 'invalid DOT';
    el('dot-editor').value = '';
    const doneDot = await postStream('/api/v1/dot/fix/stream', { dotSource, error }, (delta) => {
      el('dot-editor').value += delta;
    });
    el('dot-editor').value = doneDot;
    await runValidate();
    await runPreview();
  };

  const runIterate = async () => {
    const changes = el('iterate-changes').value.trim();
    if (!changes) {
      flash('Iteration changes are required');
      return;
    }

    const baseDot = el('dot-editor').value;
    el('dot-editor').value = '';
    const doneDot = await postStream('/api/v1/dot/iterate/stream', { baseDot, changes }, (delta) => {
      el('dot-editor').value += delta;
    });
    el('dot-editor').value = doneDot;
    await runValidate();
    await runPreview();

    if (state.iterateSourceRun) {
      const created = await api('POST', `/api/v1/pipelines/${encodeURIComponent(state.iterateSourceRun)}/iterate`, {
        dotSource: doneDot,
        originalPrompt: changes,
      });
      flash(`Iterated run created: ${created.newId}`);
      setView('monitor');
      await refreshRuns({ reloadSelected: false });
      await selectRun(created.newId);
      state.iterateSourceRun = null;
      el('iterate-source').textContent = '';
    }
  };

  const pollTick = async () => {
    if (state.pollInFlight) {
      return;
    }
    if (state.view !== 'monitor' && state.view !== 'archived') {
      return;
    }

    state.pollInFlight = true;
    try {
      if (state.view === 'monitor') {
        await refreshRuns({ reloadSelected: true });
      } else if (state.view === 'archived') {
        await refreshArchived();
      }
    } catch (error) {
      flash(error.message || 'refresh failed');
    } finally {
      state.pollInFlight = false;
    }
  };

  const startPolling = () => {
    if (state.pollTimer !== null) {
      return;
    }
    state.pollTimer = window.setInterval(() => {
      void pollTick();
    }, 1200);
  };

  const attachActions = () => {
    document.querySelectorAll('.tab').forEach((tab) => tab.addEventListener('click', () => setView(tab.dataset.view)));
    el('refresh-runs').addEventListener('click', () => {
      void refreshRuns({ reloadSelected: true }).catch((error) => flash(error.message || 'refresh failed'));
    });
    el('refresh-archived').addEventListener('click', () => {
      void refreshArchived().catch((error) => flash(error.message || 'refresh failed'));
    });

    el('cancel-run').addEventListener('click', () => {
      if (!state.selectedRunId) return;
      void api('POST', `/api/v1/pipelines/${encodeURIComponent(state.selectedRunId)}/cancel`)
        .then(() => selectRun(state.selectedRunId))
        .catch((error) => flash(error.message || 'cancel failed'));
    });

    el('archive-run').addEventListener('click', () => {
      if (!state.selectedRunId) return;
      void api('POST', `/api/v1/pipelines/${encodeURIComponent(state.selectedRunId)}/archive`)
        .then(() => refreshRuns({ reloadSelected: true }))
        .catch((error) => flash(error.message || 'archive failed'));
    });

    el('unarchive-run').addEventListener('click', () => {
      if (!state.selectedRunId) return;
      void api('POST', `/api/v1/pipelines/${encodeURIComponent(state.selectedRunId)}/unarchive`)
        .then(() => refreshRuns({ reloadSelected: true }))
        .catch((error) => flash(error.message || 'unarchive failed'));
    });

    el('delete-run').addEventListener('click', () => {
      if (!state.selectedRunId) return;
      if (!window.confirm('Delete this run?')) return;
      void api('DELETE', `/api/v1/pipelines/${encodeURIComponent(state.selectedRunId)}`)
        .then(async () => {
          state.selectedRunId = null;
          state.selectedRun = null;
          await refreshRuns({ reloadSelected: false });
          el('run-detail').classList.add('hidden');
          el('run-detail-empty').classList.remove('hidden');
        })
        .catch((error) => flash(error.message || 'delete failed'));
    });

    el('iterate-run').addEventListener('click', () => {
      if (!state.selectedRun) return;
      state.iterateSourceRun = state.selectedRun.id;
      el('dot-editor').value = state.selectedRun.dotSource || '';
      el('iterate-source').textContent = `Iterating source run: ${state.selectedRun.id}`;
      setView('create');
    });

    el('download-dot').addEventListener('click', () => {
      if (!state.selectedRun) return;
      const blob = new Blob([state.selectedRun.dotSource || ''], { type: 'text/plain' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `${state.selectedRun.id}.dot`;
      a.click();
      URL.revokeObjectURL(url);
    });

    el('download-zip').addEventListener('click', () => {
      if (!state.selectedRun) return;
      window.open(`/api/v1/pipelines/${encodeURIComponent(state.selectedRun.id)}/artifacts.zip`, '_blank');
    });

    el('validate-dot').addEventListener('click', () => {
      void runValidate();
    });
    el('preview-dot').addEventListener('click', () => {
      void runPreview();
    });
    el('run-dot').addEventListener('click', () => {
      void runCreate().catch((error) => flash(error.message || 'run failed'));
    });
    el('generate-dot').addEventListener('click', () => {
      void runGenerate().catch((error) => flash(error.message || 'generate failed'));
    });
    el('fix-dot').addEventListener('click', () => {
      void runFix().catch((error) => flash(error.message || 'fix failed'));
    });
    el('iterate-dot').addEventListener('click', () => {
      void runIterate().catch((error) => flash(error.message || 'iterate failed'));
    });
  };

  const bootstrap = async () => {
    attachActions();
    await refreshRuns({ reloadSelected: false });
    if (window.location.hash.startsWith('#monitor/')) {
      const runId = window.location.hash.replace('#monitor/', '');
      await selectRun(runId);
    }
    startPolling();
  };

  bootstrap().catch((error) => flash(error.message));
})();
