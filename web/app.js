(() => {
  const state = {
    view: 'monitor',
    runs: [],
    selectedRunId: null,
    selectedRun: null,
    iterateSourceRun: null,
    lastDotError: '',
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
      refreshArchived();
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

  const refreshRuns = async () => {
    state.runs = await api('GET', '/api/v1/pipelines');
    renderRunList();
    if (state.selectedRunId) {
      await selectRun(state.selectedRunId);
    }
  };

  const renderRunList = () => {
    const list = el('run-list');
    list.innerHTML = '';
    for (const run of state.runs) {
      const item = document.createElement('li');
      item.className = `run-item ${run.id === state.selectedRunId ? 'active' : ''}`;
      item.innerHTML = `<strong>${run.displayName || run.id}</strong><br><span class="small">${runBadge(run.status)}</span><br><span class="small">${run.currentNodeId || 'n/a'}</span>`;
      item.addEventListener('click', () => selectRun(run.id));
      list.appendChild(item);
    }
  };

  const selectRun = async (runId) => {
    state.selectedRunId = runId;
    state.selectedRun = await api('GET', `/api/v1/pipelines/${encodeURIComponent(runId)}`);
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
    for (const stage of (run.stages || [])) {
      const li = document.createElement('li');
      li.className = 'stage';
      li.textContent = `${stage.index}. ${stage.name || stage.nodeId} -> ${stage.status}`;
      stages.appendChild(li);
    }

    el('cancel-run').disabled = run.status !== 'running';
    el('archive-run').disabled = ['running'].includes(run.status) || run.archived;
    el('unarchive-run').disabled = ['running'].includes(run.status) || !run.archived;
    el('delete-run').disabled = run.status === 'running';
    el('iterate-run').disabled = run.status === 'running';

    const log = el('live-log');
    log.textContent = (run.logs || []).join('\n');

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
        const resp = await fetch(`/api/v1/pipelines/${encodeURIComponent(run.id)}/artifacts/${item.path}`);
        const text = await resp.text();
        const preview = text.length > 5000 ? text.slice(0, 5000) + '\n...[truncated]' : text;
        el('artifact-preview').textContent = preview;
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
            flash(error.message);
          }
        });
      });
    }

    try {
      const raw = await fetch(`/api/v1/pipelines/${encodeURIComponent(run.id)}/events`).then((r) => r.text());
      const events = consumeSseFrames(raw);
      log.textContent = events.map((e) => `[${e.type}] ${JSON.stringify(e.payload || {})}`).join('\n');
    } catch {
      // no-op
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
        await api('POST', `/api/v1/pipelines/${encodeURIComponent(run.id)}/unarchive`);
        await refreshArchived();
        await refreshRuns();
      });
      item.querySelector('.open').addEventListener('click', async () => {
        setView('monitor');
        await refreshRuns();
        await selectRun(run.id);
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
    await refreshRuns();
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
      await refreshRuns();
      await selectRun(created.newId);
      state.iterateSourceRun = null;
      el('iterate-source').textContent = '';
    }
  };

  const attachActions = () => {
    document.querySelectorAll('.tab').forEach((tab) => tab.addEventListener('click', () => setView(tab.dataset.view)));
    el('refresh-runs').addEventListener('click', refreshRuns);
    el('refresh-archived').addEventListener('click', refreshArchived);

    el('cancel-run').addEventListener('click', async () => {
      if (!state.selectedRunId) return;
      await api('POST', `/api/v1/pipelines/${encodeURIComponent(state.selectedRunId)}/cancel`);
      await selectRun(state.selectedRunId);
    });

    el('archive-run').addEventListener('click', async () => {
      if (!state.selectedRunId) return;
      await api('POST', `/api/v1/pipelines/${encodeURIComponent(state.selectedRunId)}/archive`);
      await refreshRuns();
      await selectRun(state.selectedRunId);
    });

    el('unarchive-run').addEventListener('click', async () => {
      if (!state.selectedRunId) return;
      await api('POST', `/api/v1/pipelines/${encodeURIComponent(state.selectedRunId)}/unarchive`);
      await refreshRuns();
      await selectRun(state.selectedRunId);
    });

    el('delete-run').addEventListener('click', async () => {
      if (!state.selectedRunId) return;
      if (!window.confirm('Delete this run?')) return;
      await api('DELETE', `/api/v1/pipelines/${encodeURIComponent(state.selectedRunId)}`);
      state.selectedRunId = null;
      state.selectedRun = null;
      await refreshRuns();
      el('run-detail').classList.add('hidden');
      el('run-detail-empty').classList.remove('hidden');
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

    el('validate-dot').addEventListener('click', runValidate);
    el('preview-dot').addEventListener('click', runPreview);
    el('run-dot').addEventListener('click', runCreate);
    el('generate-dot').addEventListener('click', runGenerate);
    el('fix-dot').addEventListener('click', runFix);
    el('iterate-dot').addEventListener('click', runIterate);
  };

  const bootstrap = async () => {
    attachActions();
    await refreshRuns();
    if (window.location.hash.startsWith('#monitor/')) {
      const runId = window.location.hash.replace('#monitor/', '');
      await selectRun(runId);
    }
  };

  bootstrap().catch((error) => flash(error.message));
})();
