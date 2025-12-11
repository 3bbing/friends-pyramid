const pyramidPage = document.querySelector('.pyramid-page');
const overviewEl = document.getElementById('pyramid-overview');
const editorEl = document.getElementById('path-editor');
const teamId = pyramidPage?.dataset.team;
const playerId = pyramidPage?.dataset.player;
let pollTimer;

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

function renderOverview(pyramid, answersByPlayer) {
    if (!overviewEl) return;
    if (!pyramid) {
        overviewEl.innerHTML = '<p class="muted">Keine aktive Runde.</p>';
        return;
    }
    const nodes = pyramid.nodes || [];
    const activePath = answersByPlayer[playerId] || [];
    const html = nodes.map(node => {
        const path = indexToPath(node.id || 0);
        const pathLabel = path.length ? path.join(' / ') : 'Start';
        const truthPrefix = activePath.slice(0, path.length).join('');
        const isOnPath = truthPrefix === path.join('');
        return `<div class="card level ${isOnPath ? 'info' : ''}">
            <div class="muted">Pfad ${pathLabel}</div>
            <div class="question">${node.question || ''}</div>
            <div class="options">
                <span class="pill ${activePath[path.length] === 'L' && isOnPath ? 'match' : ''}">Links: ${node.optionA || ''}</span>
                <span class="pill ${activePath[path.length] === 'R' && isOnPath ? 'match' : ''}">Rechts: ${node.optionB || ''}</span>
            </div>
        </div>`;
    }).join('');
    overviewEl.innerHTML = `<h2>Alle Karten</h2><p class="muted">Alle Knoten der aktuellen Pyramide mit Pfad-Markern.</p>${html}`;
}

function renderEditor(pyramid, answersByPlayer) {
    if (!editorEl) return;
    if (!pyramid) {
        editorEl.innerHTML = '';
        return;
    }
    const depth = pyramid.depth || 0;
    let path = [...(answersByPlayer[playerId] || [])];

    function render() {
        let html = '<h2>Pfad bearbeiten</h2>';
        for (let level = 0; level < depth; level++) {
            const choice = path[level];
            html += `<div class="card level"><div class="question">Ebene ${level + 1}</div>`;
            html += '<div class="options">';
            html += `<button type="button" class="choice ${choice === 'L' ? 'active' : ''}" data-level="${level}" data-val="L">Links</button>`;
            html += `<button type="button" class="choice ${choice === 'R' ? 'active' : ''}" data-level="${level}" data-val="R">Rechts</button>`;
            html += '</div></div>';
        }
        const pathLabel = path.length ? path.join(' / ') : 'Keine Auswahl';
        html += `<div class="status muted">Aktueller Pfad: ${pathLabel}</div>`;
        html += '<button id="save-path" class="primary full">Antwort speichern</button>';
        editorEl.innerHTML = html;
        editorEl.querySelectorAll('.choice').forEach(btn => {
            btn.addEventListener('click', () => {
                const level = Number(btn.dataset.level);
                const val = btn.dataset.val;
                path[level] = val;
                path = path.slice(0, level + 1);
                render();
            });
        });
        editorEl.querySelector('#save-path').addEventListener('click', () => {
            if (path.length !== depth) {
                alert('Bitte jede Ebene beantworten.');
                return;
            }
            apiPost('submit_answers', { path: path.join('') }).then(() => fetchState());
        });
    }

    render();
}

function fetchState() {
    if (!teamId) return;
    fetch(`api/state.php?team_id=${encodeURIComponent(teamId)}`)
        .then(r => r.json())
        .then(data => {
            const state = data.lobby?.game_state || {};
            if (state.phase !== 'ROUND_ACTIVE') {
                overviewEl.innerHTML = '<p class="muted">Keine aktive Runde. Bitte warte auf den nächsten Start.</p>';
                editorEl.innerHTML = '';
                return;
            }
            renderOverview(state.pyramid, state.answers_by_player || {});
            renderEditor(state.pyramid, state.answers_by_player || {});
        });
}

window.addEventListener('load', () => {
    if (!pyramidPage) return;
    fetchState();
    pollTimer = setInterval(fetchState, 4000);
});
