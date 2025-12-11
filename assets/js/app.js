const pageEl = document.querySelector('.page');
const teamId = pageEl?.dataset.team;
const playerId = pageEl?.dataset.player;
const defaultDepth = Number(pageEl?.dataset.defaultDepth || 3);
const maxDepth = Number(pageEl?.dataset.maxDepth || 3);
const defaultTimer = Number(pageEl?.dataset.defaultTimer || 0);
const timerOptions = pageEl?.dataset.timers ? JSON.parse(pageEl.dataset.timers) : [0];
const poolMeta = pageEl?.dataset.pools ? JSON.parse(pageEl.dataset.pools) : [];
const poolLabelMap = Object.fromEntries(poolMeta.map(p => [p.key, p.label]));
let pollTimer;
let lastServerNow = Math.floor(Date.now() / 1000);
let currentPath = [];
let currentPyramidKey = '';

function apiPost(action, body = {}) {
    const form = new FormData();
    form.append('team_id', teamId);
    form.append('action', action);
    Object.entries(body).forEach(([k, v]) => {
        if (Array.isArray(v)) {
            v.forEach(item => form.append(k, item));
        } else {
            form.append(k, v);
        }
    });
    return fetch('api/action.php', { method: 'POST', body: form }).then(r => r.json());
}

function fetchState() {
    if (!teamId) return;
    fetch(`api/state.php?team_id=${encodeURIComponent(teamId)}`)
        .then(r => r.json())
        .then(data => {
            lastServerNow = data.now || Math.floor(Date.now() / 1000);
            updateUi(data.lobby || {});
        });
}

function nodeIndex(path) {
    let idx = 0;
    for (const step of path) {
        idx = step === 'L' ? idx * 2 + 1 : idx * 2 + 2;
    }
    return idx;
}

function renderPyramidForm(pyramid, activePlayerName, existingPath = []) {
    const container = document.getElementById('pyramid-form');
    if (!pyramid) { container.innerHTML = '<p>Keine Pyramide geladen.</p>'; return; }
    const depth = pyramid.depth;
    const nodes = pyramid.nodes;
    const pyramidKey = `${depth}:${nodes.map(n => `${n.question}|${n.optionA}|${n.optionB}`).join('|')}`;
    if (pyramidKey !== currentPyramidKey) {
        currentPyramidKey = pyramidKey;
        currentPath = [];
    }
    const submittedPath = existingPath.length ? [...existingPath] : [];
    let path = submittedPath.length ? [...submittedPath] : [...currentPath];

    function render() {
        let html = `<p class="pill info">Du antwortest gerade für ${activePlayerName || 'die aktive Person'}.</p>`;
        html += '<p class="muted">Tippe dich durch die Ebenen und denke an die Antwort der aktiven Person.</p>';
        for (let level = 0; level < depth; level++) {
            if (level > path.length) break;
            const idx = nodeIndex(path.slice(0, level));
            const card = nodes[idx];
            if (!card) continue;
            const choice = path[level];
            html += `<div class="card level"><div class="question">${card.question}</div>`;
            html += `<div class="options">`;
            html += `<button type="button" data-level="${level}" data-val="L" class="choice ${choice === 'L' ? 'active' : ''}" ${submittedPath.length ? 'disabled' : ''}>${card.optionA}</button>`;
            html += `<button type="button" data-level="${level}" data-val="R" class="choice ${choice === 'R' ? 'active' : ''}" ${submittedPath.length ? 'disabled' : ''}>${card.optionB}</button>`;
            html += `</div></div>`;
        }
        const pathLabel = path.length ? `Aktueller Pfad: ${path.join(' / ')}` : 'Noch keine Auswahl';
        container.innerHTML = html + `<div class="status muted">${pathLabel}</div><button id="submit-path" class="primary full">Antworten abschicken</button>`;
        if (!submittedPath.length) {
            container.querySelectorAll('.choice').forEach(btn => {
                btn.addEventListener('click', () => {
                    const lvl = Number(btn.dataset.level);
                    const val = btn.dataset.val;
                    path[lvl] = val;
                    path = path.slice(0, lvl + 1);
                    currentPath = [...path];
                    render();
                });
            });
            container.querySelector('#submit-path').addEventListener('click', () => {
                if (path.length !== depth) {
                    alert('Bitte jede Ebene beantworten.');
                    return;
                }
                currentPath = [...path];
                apiPost('submit_answers', { path: path.join('') }).then(fetchState);
            });
        } else {
            container.querySelector('#submit-path').setAttribute('disabled', 'disabled');
        }
    }
    render();
}

function renderReveal(lobby) {
    const wrap = document.getElementById('reveal-summary');
    const state = lobby.game_state;
    const depth = state.pyramid?.depth || 0;
    const truth = state.answers_by_player[state.active_player_id] || [];
    const nodes = state.pyramid?.nodes || [];
    let html = `<h2>Auflösung</h2>`;
    html += `<p>Aktive Person: ${displayName(lobby, state.active_player_id)}</p>`;
    html += `<p class="muted">Wahrheitspfad: ${truth.length ? truth.join(' / ') : 'keine Angabe'} (${truth.length}/${depth})</p>`;
    html += '<div class="levels">';
    for (let level = 0; level < depth; level++) {
        const idx = nodeIndex(truth.slice(0, level));
        const card = nodes[idx] || {};
        const truthChoice = truth[level];
        const truthLabel = truthChoice === 'L' ? card.optionA : truthChoice === 'R' ? card.optionB : 'Keine Angabe';
        html += `<div class="level-block"><div class="question">Frage ${level + 1}: ${card.question || ''}</div>`;
        html += `<div class="truth">Aktive Person: <strong>${truthLabel}</strong></div>`;
        html += '<div class="guesses">';
        lobby.players.filter(p => p.player_id !== state.active_player_id).forEach(p => {
            const path = state.answers_by_player[p.player_id] || [];
            const choice = path[level];
            const label = choice === 'L' ? card.optionA : choice === 'R' ? card.optionB : 'Offen';
            const same = choice === truthChoice;
            html += `<div class="pill guess ${same ? 'match' : 'miss'}">${p.name}: ${label || '—'}</div>`;
        });
        html += '</div></div>';
    }
    html += '</div>';
    html += '<div class="scores">';
    Object.entries(state.scores || {}).forEach(([pid, score]) => {
        html += `<div class="score-card"><div>${displayName(lobby, pid)}</div>`;
        const path = state.answers_by_player[pid] || [];
        html += `<div>Pfad: ${path.join(' / ') || '—'}</div>`;
        html += `<div>Übereinstimmungen: ${score.matches} / ${depth}</div>`;
        html += `<div>${score.leaf ? 'Endblatt getroffen' : 'Anderes Blatt'}</div>`;
        html += `<strong>Punkte: ${score.points}</strong></div>`;
    });
    html += '</div>';
    wrap.innerHTML = html;
}

function renderScoreboard(lobby) {
    const wrap = document.getElementById('scoreboard');
    if (!wrap) return;
    const totals = lobby.game_state?.totals || {};
    if (!Object.keys(totals).length) {
        wrap.textContent = 'Noch keine Punkte gesammelt.';
        wrap.classList.add('muted');
        return;
    }
    wrap.classList.remove('muted');
    const rows = Object.entries(totals)
        .map(([pid, points]) => ({ pid, points }))
        .sort((a, b) => b.points - a.points);
    wrap.innerHTML = rows.map(r => `<div class="score-row"><span>${displayName(lobby, r.pid)}</span><strong>${r.points} Punkte</strong></div>`).join('');
}

function displayName(lobby, playerId) {
    return lobby.players.find(p => p.player_id === playerId)?.name || 'Unbekannt';
}

function selectedPoolLabels(pools) {
    if (!pools || !pools.length) return 'Standard';
    return pools.map(k => poolLabelMap[k] || k).join(', ');
}

function updateUi(lobby) {
    if (!lobby || !lobby.game_state) return;
    const phase = lobby.game_state.phase;
    document.getElementById('lobby-view').classList.toggle('hidden', phase !== 'LOBBY_WAITING');
    document.getElementById('round-view').classList.toggle('hidden', phase !== 'ROUND_ACTIVE');
    document.getElementById('reveal-view').classList.toggle('hidden', phase !== 'ROUND_REVEAL');

    const playerList = document.getElementById('players');
    playerList.innerHTML = lobby.players.map(p => `<div class="pill ${p.is_host ? 'host' : ''}">${p.name}</div>`).join('');

    if (phase === 'ROUND_ACTIVE') {
        const meta = document.getElementById('round-meta');
        const timer = Number(lobby.game_state.timer_seconds || 0);
        const startedAt = Number(lobby.game_state.started_at || 0);
        const remaining = timer ? Math.max(0, timer - (lastServerNow - startedAt)) : null;
        const timerLabel = timer ? `${remaining}s verbleibend` : 'Kein Timer aktiv';
        const activeName = displayName(lobby, lobby.game_state.active_player_id);
        meta.innerHTML = `<h2>Runde ${lobby.game_state.round_index + 1}</h2><p>Aktiv: ${activeName}</p><p class="muted">Alle tippen gleichzeitig, niemand sieht die anderen bis zur Auflösung.</p><p class="muted">Pools: ${selectedPoolLabels(lobby.game_state.selected_pools)}</p><p class="pill timer">${timerLabel}</p>`;
        const existingPath = lobby.game_state.answers_by_player[playerId] || [];
        renderPyramidForm(lobby.game_state.pyramid, activeName, existingPath);
        const finishedIds = Object.keys(lobby.game_state.finished || {});
        const waiting = lobby.players.filter(p => !finishedIds.includes(p.player_id)).map(p => p.name);
        const finished = finishedIds.length;
        const statusText = `${finished} / ${lobby.players.length} fertig${waiting.length ? ' – wartet auf: ' + waiting.join(', ') : ''}`;
        document.getElementById('round-status').innerText = statusText;
    }
    if (phase === 'ROUND_REVEAL') {
        renderReveal(lobby);
    }
    renderScoreboard(lobby);
}

function wireButtons() {
    const getSelectedPools = (form) => {
        if (!form) return [];
        return Array.from(form.querySelectorAll('input[name="pools[]"]:checked')).map(el => el.value);
    };
    const start = document.getElementById('start-game');
    if (start) start.addEventListener('click', () => {
        const depth = Number(document.getElementById('depth-select')?.value || defaultDepth);
        const timer = Number(document.getElementById('timer-select')?.value || defaultTimer);
        const pools = getSelectedPools(document.getElementById('start-form'));
        apiPost('start_game', { depth, timer, 'pools[]': pools }).then(resp => {
            if (resp.error) alert(resp.error);
            fetchState();
        });
    });
    const next = document.getElementById('next-round');
    if (next) next.addEventListener('click', () => {
        const depth = Number(document.getElementById('next-depth')?.value || defaultDepth);
        const timer = Number(document.getElementById('next-timer')?.value || defaultTimer);
        const pools = getSelectedPools(document.getElementById('next-form'));
        apiPost('next_round', { depth, timer, 'pools[]': pools }).then(resp => {
            if (resp.error) alert(resp.error);
            fetchState();
        });
    });
    const force = document.getElementById('force-reveal');
    if (force) force.addEventListener('click', () => apiPost('force_reveal').then(fetchState));

    const addQuestion = document.getElementById('add-question');
    if (addQuestion) addQuestion.addEventListener('submit', (e) => {
        e.preventDefault();
        const data = new FormData(addQuestion);
        apiPost('add_question', Object.fromEntries(data.entries())).then(resp => {
            if (resp.error) alert(resp.error); else alert('Gespeichert');
        });
    });

}

window.addEventListener('load', () => {
    wireButtons();
    fetchState();
    pollTimer = setInterval(fetchState, 2000);
});
