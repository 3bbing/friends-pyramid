const pyramidPage = document.querySelector('.pyramid-page');
const overviewEl = document.getElementById('pyramid-overview');
const editorEl = document.getElementById('path-editor');
const roundPickerEl = document.getElementById('round-picker');
const teamId = pyramidPage?.dataset.team;
const playerId = pyramidPage?.dataset.player;
const urlParams = new URLSearchParams(window.location.search);
const requestedRoundIndex = urlParams.has('round') ? Number(urlParams.get('round')) : null;
let pollTimer;
let lobbyState = null;
let rounds = [];
let selectedRoundKey = null;
const draftPaths = {};

const colorPalette = ['#ef4444', '#22c55e', '#3b82f6', '#f97316', '#a855f7', '#14b8a6', '#f59e0b', '#e11d48'];

function colorForPlayer(id) {
    let hash = 0;
    for (let i = 0; i < id.length; i++) {
        hash = (hash << 5) - hash + id.charCodeAt(i);
        hash |= 0;
    }
    return colorPalette[Math.abs(hash) % colorPalette.length];
}

function apiPost(action, body = {}) {
    const form = new FormData();
    form.append('team_id', teamId);
    form.append('action', action);
    Object.entries(body).forEach(([k, v]) => form.append(k, v));
    return fetch('api/action.php', { method: 'POST', body: form }).then(r => r.json());
}

function indexToPath(idx) {
    const parts = [];
    while (idx > 0) {
        const parent = Math.floor((idx - 1) / 2);
        parts.push(idx === parent * 2 + 1 ? 'L' : 'R');
        idx = parent;
    }
    return parts.reverse();
}

function pathLabel(path, empty = '—') {
    return path && path.length ? path.join(' / ') : empty;
}

function displayName(lobby, playerId) {
    return (lobby?.players || []).find(p => p.player_id === playerId)?.name || 'Unbekannt';
}

function groupByLevel(pyramid) {
    const levels = [];
    const depth = pyramid.depth || 0;
    const nodes = pyramid.nodes || [];
    for (let level = 0; level < depth; level++) {
        const start = (2 ** level) - 1;
        const count = 2 ** level;
        levels.push(nodes.slice(start, start + count));
    }
    return levels;
}

function buildPyramidGrid(pyramid) {
    const levels = groupByLevel(pyramid);
    return levels.map((levelNodes, idx) => {
        const level = idx + 1;
        const cards = levelNodes.map(node => `<div class="pyramid-card" data-node-id="${node.id}">
                <div class="muted">Pfad ${pathLabel(indexToPath(node.id || 0))}</div>
                <div class="question">${node.question || ''}</div>
                <div class="option-row">
                    <div class="option left"><small>Links</small>${node.optionA || ''}</div>
                    <div class="option right"><small>Rechts</small>${node.optionB || ''}</div>
                </div>
            </div>`).join('');
        return `<div class="pyramid-row" data-level="${level}" style="--columns:${levelNodes.length}">${cards}</div>`;
    }).join('');
}

function anchorPoints(cardEl, containerRect) {
    const rect = cardEl.getBoundingClientRect();
    const left = { x: rect.left + rect.width * 0.25 - containerRect.left, y: rect.bottom - containerRect.top - rect.height * 0.15 };
    const right = { x: rect.left + rect.width * 0.75 - containerRect.left, y: rect.bottom - containerRect.top - rect.height * 0.15 };
    const top = { x: rect.left + rect.width / 2 - containerRect.left, y: rect.top - containerRect.top - 6 };
    return { left, right, top };
}

function drawPathLines(container, pyramid, answersByPlayer, players = [], activePlayerId = null) {
    const svg = container.querySelector('.pyramid-lines');
    const grid = container.querySelector('.pyramid-grid');
    if (!svg || !grid) return;
    const containerRect = grid.getBoundingClientRect();
    svg.setAttribute('width', containerRect.width);
    svg.setAttribute('height', containerRect.height);
    svg.setAttribute('viewBox', `0 0 ${containerRect.width} ${containerRect.height}`);
    const nodeEls = {};
    grid.querySelectorAll('.pyramid-card').forEach(card => {
        const id = Number(card.dataset.nodeId);
        nodeEls[id] = card;
    });
    const paths = Object.entries(answersByPlayer || {});
    const lines = paths.map(([pid, path]) => {
        if (!Array.isArray(path) || !path.length) return '';
        let idx = 0;
        let currentCard = nodeEls[idx];
        if (!currentCard) return '';
        const points = [];
        for (let i = 0; i < path.length; i++) {
            const choice = path[i];
            const anchors = anchorPoints(currentCard, containerRect);
            const from = choice === 'L' ? anchors.left : anchors.right;
            points.push(from);
            const childIdx = choice === 'L' ? idx * 2 + 1 : idx * 2 + 2;
            const childCard = nodeEls[childIdx];
            if (!childCard || i === path.length - 1) {
                points.push({ x: from.x, y: from.y + 14 });
                break;
            }
            const childAnchors = anchorPoints(childCard, containerRect);
            points.push(childAnchors.top);
            idx = childIdx;
            currentCard = childCard;
        }
        if (!points.length) return '';
        const d = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x},${p.y}`).join(' ');
        const isSelf = pid === playerId;
        const isActive = pid === activePlayerId;
        const color = isActive ? 'var(--accent)' : colorForPlayer(pid);
        const strokeWidth = isActive ? 4 : isSelf ? 3 : 2;
        const glow = isActive ? 'filter: drop-shadow(0 0 6px rgba(34, 211, 238, 0.75));' : '';
        return `<path d="${d}" fill="none" stroke="${color}" stroke-width="${strokeWidth}" stroke-linecap="round" stroke-linejoin="round" opacity="0.95" style="${glow}" />`;
    }).join('');
    svg.innerHTML = lines;
}

function legendHtml(players = [], answersByPlayer = {}, highlightPlayerId = null) {
    const visiblePlayers = players.filter(p => answersByPlayer[p.player_id]);
    if (!visiblePlayers.length) {
        return '<p class="muted">Noch keine sichtbaren Pfade.</p>';
    }
    return `<div class="pyramid-legend">${visiblePlayers.map(p => {
        const isTruth = p.player_id === highlightPlayerId;
        const color = isTruth ? 'var(--accent)' : colorForPlayer(p.player_id);
        const choiceLabel = pathLabel(answersByPlayer[p.player_id] || []);
        const tag = isTruth ? ' (aktiv)' : '';
        return `<div class="legend-item"><span class="legend-dot" style="background:${color}"></span><strong>${p.name}${tag}</strong><span class="muted">${choiceLabel}</span></div>`;
    }).join('')}</div>`;
}

function roundScoresHtml(round, lobby) {
    const scores = round.scores || {};
    const players = lobby?.players || [];
    if (!Object.keys(scores).length) {
        return '<p class="muted">Noch keine Punkte vergeben.</p>';
    }
    const activeCard = `<div class="score-card truth"><div class="label">Aktive Person</div><strong>${displayName(lobby, round.active_player_id)}</strong><span class="muted">Keine Punkte</span></div>`;
    const scoreCards = players
        .filter(p => p.player_id !== round.active_player_id)
        .map(p => {
            const score = scores[p.player_id] || { matches: 0, leaf: false, points: 0 };
            const detail = `${score.matches} Treffer${score.leaf ? ' · Blatt getroffen' : ''}`;
            return `<div class="score-card"><div class="label">${p.name}</div><strong>${score.points} Punkte</strong><span class="muted">${detail}</span></div>`;
        });
    return `<div class="round-scores">${[activeCard, ...scoreCards].join('')}</div>`;
}

function markOptionsForPath(gridEl, path) {
    if (!gridEl || !Array.isArray(path)) return;
    let idx = 0;
    for (const choice of path) {
        const card = gridEl.querySelector(`.pyramid-card[data-node-id="${idx}"]`);
        if (!card) break;
        const opt = card.querySelector(`.option.${choice === 'L' ? 'left' : 'right'}`);
        if (opt) opt.classList.add('chosen');
        idx = choice === 'L' ? idx * 2 + 1 : idx * 2 + 2;
    }
}

function visibleAnswers(round) {
    const merged = { ...(round.answers_by_player || {}) };
    const draft = draftPaths[round.key];
    if (!merged[playerId] && draft && round.phase === 'ROUND_ACTIVE') {
        merged[playerId] = draft;
    }
    if (round.phase === 'ROUND_REVEAL') {
        return merged;
    }
    const ownOnly = {};
    if (merged[playerId]) {
        ownOnly[playerId] = merged[playerId];
    }
    return ownOnly;
}

function roundStatusLabel(round) {
    if (round.phase === 'ROUND_ACTIVE') return 'Aktiv';
    if (round.phase === 'ROUND_REVEAL') return 'Auflösung';
    return 'Abgeschlossen';
}

function buildRounds(lobby) {
    const history = (lobby.round_history || []).map((round, idx) => ({
        ...round,
        key: `history-${idx}-${round.round_index ?? idx}-${round.started_at ?? idx}`,
        phase: round.phase || 'ROUND_REVEAL',
        answers_by_player: round.answers_by_player || {},
        round_index: round.round_index ?? idx,
    }));
    const current = lobby.game_state || {};
    if (current.pyramid) {
        const exists = history.some(r => (r.round_index === (current.round_index ?? 0)) && (r.started_at === current.started_at));
        if (!exists) {
            history.unshift({
                ...current,
                key: `current-${current.round_index ?? 0}-${current.started_at ?? 'live'}`,
                answers_by_player: current.answers_by_player || {},
                round_index: current.round_index ?? 0,
            });
        }
    }
    return history.sort((a, b) => (b.started_at || 0) - (a.started_at || 0));
}

function pickDefaultRound(list) {
    if (selectedRoundKey && list.some(r => r.key === selectedRoundKey)) {
        return selectedRoundKey;
    }
    if (Number.isInteger(requestedRoundIndex)) {
        const requested = list.find(r => Number(r.round_index) === requestedRoundIndex);
        if (requested) return requested.key;
    }
    const active = list.find(r => r.phase === 'ROUND_ACTIVE');
    if (active) return active.key;
    return list[0]?.key || null;
}

function renderRoundPicker(list) {
    if (!roundPickerEl) return;
    if (!list.length) {
        roundPickerEl.innerHTML = '<h2>Runden</h2><p class="muted">Noch keine Runde gestartet.</p>';
        return;
    }
    const buttons = list.map(r => {
        const activeName = displayName(lobbyState, r.active_player_id);
        const subtitle = [roundStatusLabel(r), activeName ? `Aktiv: ${activeName}` : null].filter(Boolean).join(' · ');
        return `<button class="round-btn ${r.key === selectedRoundKey ? 'active' : ''}" data-round="${r.key}">
            <strong>Runde ${Number(r.round_index ?? 0) + 1}</strong>
            <span>${subtitle}</span>
        </button>`;
    }).join('');
    roundPickerEl.innerHTML = `<h2>Runden</h2><p class="muted">Alle bisherigen Runden dieses Teams.</p><div class="round-list">${buttons}</div>`;
    roundPickerEl.querySelectorAll('.round-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            selectedRoundKey = btn.dataset.round;
            const sel = rounds.find(r => r.key === selectedRoundKey);
            renderRound(sel, lobbyState);
            renderRoundPicker(rounds);
        });
    });
}

function renderOverview(round, lobby) {
    if (!overviewEl) return;
    if (!round || !round.pyramid) {
        overviewEl.innerHTML = '<p class="muted">Keine Runde ausgewählt.</p>';
        return;
    }
    const pyramid = round.pyramid;
    const grid = buildPyramidGrid(pyramid);
    const answers = visibleAnswers(round);
    const players = lobby?.players || [];
    const activeName = displayName(lobby, round.active_player_id);
    const statusLine = round.phase === 'ROUND_ACTIVE'
        ? 'Nur dein eigener Pfad wird angezeigt. Alle anderen erscheinen nach Abschluss.'
        : 'Alle Pfade werden angezeigt.';
    overviewEl.innerHTML = `<h2>Pyramide Runde ${round.round_index + 1}</h2>
        <p class="muted">Aktive Person: <strong>${activeName}</strong></p>
        <p class="muted">${statusLine}</p>
        <div class="pyramid-visual">
            <div class="pyramid-grid">${grid}</div>
            <svg class="pyramid-lines"></svg>
        </div>
        ${legendHtml(players, answers, round.active_player_id)}
        <h3>Rundenergebnis</h3>
        ${roundScoresHtml(round, lobby)}`;
    requestAnimationFrame(() => {
        const visual = overviewEl.querySelector('.pyramid-visual');
        drawPathLines(visual, pyramid, answers, players, round.active_player_id);
        const gridEl = visual.querySelector('.pyramid-grid');
        const ownPath = answers[playerId] || draftPaths[round.key] || [];
        markOptionsForPath(gridEl, ownPath);
        if (round.phase === 'ROUND_REVEAL' && answers[round.active_player_id]) {
            markOptionsForPath(gridEl, answers[round.active_player_id]);
        }
        if (round.phase === 'ROUND_ACTIVE' && !round.answers_by_player?.[playerId]) {
            gridEl.addEventListener('click', (event) => {
                const option = event.target.closest('.option');
                if (!option) return;
                const card = option.closest('.pyramid-card');
                if (!card) return;
                const nodeId = Number(card.dataset.nodeId);
                const choice = option.classList.contains('right') ? 'R' : 'L';
                const path = [...indexToPath(nodeId), choice];
                const depth = pyramid.depth || path.length;
                draftPaths[round.key] = path.slice(0, depth);
                renderRound(round, lobby);
            });
        }
    });
}

function renderPathEditor(round, lobby) {
    if (!editorEl) return;
    if (!round || !round.pyramid) {
        editorEl.innerHTML = '';
        return;
    }
    const depth = round.pyramid?.depth || 0;
    const submittedPath = round.answers_by_player?.[playerId] || [];
    if (submittedPath.length && !draftPaths[round.key]) {
        draftPaths[round.key] = submittedPath;
    }
    const draft = draftPaths[round.key] || submittedPath || [];
    const players = lobby?.players || [];

    if (round.phase === 'ROUND_ACTIVE') {
        const ready = draft.length === depth;
        const submitted = submittedPath.length > 0;
        const button = submitted ? '' : `<button class="primary" id="submit-path" ${ready ? '' : 'disabled'}>Antworten abschicken</button>`;
        const hint = submitted ? '<p class="pill success">Pfad bereits abgegeben.</p>' : '<p class="muted">Klicke eine Antwort pro Ebene direkt in der Pyramide.</p>';
        editorEl.innerHTML = `<h2>Eigener Pfad</h2>
            ${hint}
            <p class="status">Pfad: ${pathLabel(draft)} (${draft.length}/${depth})</p>
            ${button}`;
        if (!submitted && ready) {
            editorEl.querySelector('#submit-path').addEventListener('click', () => {
                apiPost('submit_answers', { path: draft.join('') }).then(fetchState);
            });
        }
    } else {
        editorEl.innerHTML = `<h2>Pfad-Legende</h2><p class="muted">Pfad der aktiven Person wird hervorgehoben.</p>${legendHtml(players, round.answers_by_player || {}, round.active_player_id)}`;
    }
}

function renderRound(round, lobby) {
    renderOverview(round, lobby);
    renderPathEditor(round, lobby);
}

function fetchState() {
    if (!teamId) return;
    fetch(`api/state.php?team_id=${encodeURIComponent(teamId)}`)
        .then(r => r.json())
        .then(data => {
            lobbyState = data.lobby || {};
            rounds = buildRounds(lobbyState);
            selectedRoundKey = pickDefaultRound(rounds);
            rounds.forEach(r => {
                const existing = r.answers_by_player?.[playerId];
                if (existing) {
                    draftPaths[r.key] = existing;
                }
            });
            const selected = rounds.find(r => r.key === selectedRoundKey) || null;
            renderRoundPicker(rounds);
            renderRound(selected, lobbyState);
        });
}

window.addEventListener('load', () => {
    if (!pyramidPage) return;
    fetchState();
    pollTimer = setInterval(fetchState, 4000);
});
