const pyramidPage = document.querySelector('.pyramid-page');
const overviewEl = document.getElementById('pyramid-overview');
const editorEl = document.getElementById('path-editor');
const teamId = pyramidPage?.dataset.team;
const playerId = pyramidPage?.dataset.player;
let pollTimer;

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

function pathLabel(path) {
    return path && path.length ? path.join(' / ') : '—';
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

function drawPathLines(container, pyramid, answersByPlayer, players = []) {
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
        const color = colorForPlayer(pid);
        const isSelf = pid === playerId;
        return `<path d="${d}" fill="none" stroke="${color}" stroke-width="${isSelf ? 3 : 2}" stroke-linecap="round" stroke-linejoin="round" opacity="0.9" />`;
    }).join('');
    svg.innerHTML = lines;
}

function legendHtml(players = [], answersByPlayer = {}) {
    if (!players.length) return '';
    return `<div class="pyramid-legend">${players.map(p => {
        const color = colorForPlayer(p.player_id);
        const choiceLabel = pathLabel(answersByPlayer[p.player_id] || []);
        return `<div class="legend-item"><span class="legend-dot" style="background:${color}"></span><strong>${p.name}</strong><span class="muted">${choiceLabel}</span></div>`;
    }).join('')}</div>`;
}

function renderOverview(pyramid, answersByPlayer, lobby) {
    if (!overviewEl) return;
    if (!pyramid) {
        overviewEl.innerHTML = '<p class="muted">Keine aktive Runde.</p>';
        return;
    }
    const grid = buildPyramidGrid(pyramid);
    const players = lobby?.players || [];
    overviewEl.innerHTML = `<h2>Alle Karten</h2><p class="muted">Vollständige Pyramide mit allen Antwortwegen.</p><div class="pyramid-visual">
        <div class="pyramid-grid">${grid}</div>
        <svg class="pyramid-lines"></svg>
    </div>${legendHtml(players, answersByPlayer)}`;
    requestAnimationFrame(() => drawPathLines(overviewEl.querySelector('.pyramid-visual'), pyramid, answersByPlayer, players));
}

function renderEditor(pyramid, answersByPlayer, lobby) {
    if (!editorEl) return;
    if (!pyramid) {
        editorEl.innerHTML = '';
        return;
    }
    const players = lobby?.players || [];
    editorEl.innerHTML = `<h2>Pfad-Legende</h2><p class="muted">Farben entsprechen den Linien in der Pyramide.</p>${legendHtml(players, answersByPlayer)}`;
}

function fetchState() {
    if (!teamId) return;
    fetch(`api/state.php?team_id=${encodeURIComponent(teamId)}`)
        .then(r => r.json())
        .then(data => {
            const lobby = data.lobby || {};
            const state = lobby.game_state || {};
            if (state.phase !== 'ROUND_ACTIVE') {
                overviewEl.innerHTML = '<p class="muted">Keine aktive Runde. Bitte warte auf den nächsten Start.</p>';
                editorEl.innerHTML = '';
                return;
            }
            renderOverview(state.pyramid, state.answers_by_player || {}, lobby);
            renderEditor(state.pyramid, state.answers_by_player || {}, lobby);
        });
}

window.addEventListener('load', () => {
    if (!pyramidPage) return;
    fetchState();
    pollTimer = setInterval(fetchState, 4000);
});
