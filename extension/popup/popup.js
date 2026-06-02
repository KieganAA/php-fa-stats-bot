import { api, getConfig } from '../api.js';

// ===== state =====
const state = {
    picked: [],       // selected landings to bind
    groups: [],
    searchTimer: null,
};

// ===== boot =====
async function boot() {
    const { apiUrl, token } = await getConfig();
    if (!apiUrl || !token) {
        show('not-configured');
        return;
    }
    try {
        const me = await api.me();
        document.getElementById('who').innerHTML =
            `Залогинен как <b>${escapeHtml(me.display_name)}</b> · TZ ${escapeHtml(me.timezone)}`;
        await reloadGroups();
        // If the content script left "send these" message in storage — preload it.
        await consumePendingFromStorage();
        show('main');
    } catch (e) {
        if (e.status === 401) {
            show('auth-error');
        } else {
            show('not-configured');
            document.getElementById('not-configured').insertAdjacentHTML(
                'beforeend',
                `<p class="error">${escapeHtml(e.message)}</p>`,
            );
        }
    }
}

function show(id) {
    for (const sel of ['not-configured', 'auth-error', 'main', 'loading']) {
        document.getElementById(sel)?.classList.toggle('hidden', sel !== id);
    }
}

// ===== pending-from-content-script handoff =====
// content.js stuffs picked landings into chrome.storage.local under
// `pending_landings`. Popup drains it on open.
async function consumePendingFromStorage() {
    const data = await chrome.storage.local.get({ pending_landings: [] });
    if (!data.pending_landings.length) return;

    const tokens = data.pending_landings;
    await chrome.storage.local.remove('pending_landings');

    try {
        const res = await api.resolve(tokens);
        for (const r of res.resolved) {
            if (!state.picked.find((p) => p.uuid === r.uuid)) state.picked.push(r);
        }
        renderPicked();
        updateCreateButton();
        if (res.missing.length) {
            const msg = `Не нашёл в базе: ${res.missing.join(', ')}. Возможно, сначала запусти aio:sync:landings.`;
            const err = document.getElementById('create-error');
            err.textContent = msg;
            err.classList.remove('hidden');
        }
    } catch (e) {
        const err = document.getElementById('create-error');
        err.textContent = `Не удалось подтянуть найденные ленды: ${e.message}`;
        err.classList.remove('hidden');
    }
}

// ===== groups list =====
async function reloadGroups() {
    const data = await api.listGroups();
    state.groups = data.groups || [];
    document.getElementById('groups-count').textContent =
        state.groups.length ? `(${state.groups.length})` : '';
    renderGroups();
}

function renderGroups() {
    const box = document.getElementById('groups');
    if (!state.groups.length) {
        box.innerHTML = '<p style="color: var(--muted); font-size: 11px;">Подписок нет.</p>';
        return;
    }
    box.innerHTML = state.groups.map((g) => `
        <div class="group" data-id="${g.id}">
            <div class="header-row">
                <div>
                    <span class="name">${escapeHtml(g.name)}</span>
                    <span class="badge">${escapeHtml(g.mode || 'compare')}</span>
                    <span class="badge">⏱ ${formatInterval(g.notify_interval_minutes)}</span>
                    ${g.paused ? '<span class="badge" style="color: #d97706;">⏸</span>' : ''}
                </div>
                <div class="group-actions">
                    <button class="pause" title="${g.paused ? 'Возобновить' : 'Пауза'}">${g.paused ? '▶' : '⏸'}</button>
                    <button class="del" title="Удалить">×</button>
                </div>
            </div>
            <div class="meta-row">
                ${g.last_notified_at ? `last push ${formatRelative(g.last_notified_at)}` : 'ещё ни разу не пушил'}
                ${g.next_push_at && !g.paused ? ` · next ~ ${formatRelative(g.next_push_at)}` : ''}
            </div>
            <ul class="members">
                ${(g.members || []).map((m) => `<li>${escapeHtml(m.short_label || 'unknown')}</li>`).join('')}
            </ul>
        </div>
    `).join('');

    // wire actions
    box.querySelectorAll('.group').forEach((node) => {
        const id = parseInt(node.dataset.id, 10);
        const g = state.groups.find((x) => x.id === id);
        node.querySelector('.pause')?.addEventListener('click', () => togglePause(g));
        node.querySelector('.del')?.addEventListener('click', () => deleteGroup(g));
    });
}

async function togglePause(g) {
    try {
        const res = await api.updateGroup(g.id, { paused: !g.paused });
        Object.assign(g, res.group);
        renderGroups();
    } catch (e) {
        alert(e.message);
    }
}

async function deleteGroup(g) {
    if (!confirm(`Удалить подписку «${g.name}»?`)) return;
    try {
        await api.deleteGroup(g.id);
        await reloadGroups();
    } catch (e) {
        alert(e.message);
    }
}

// ===== landing search =====
const $search = document.getElementById('search');
const $suggestions = document.getElementById('suggestions');

$search.addEventListener('focus', () => fetchSuggestions(''));
$search.addEventListener('input', () => {
    clearTimeout(state.searchTimer);
    state.searchTimer = setTimeout(() => fetchSuggestions($search.value.trim()), 200);
});
document.addEventListener('click', (e) => {
    if (!e.target.closest('#search') && !e.target.closest('#suggestions')) {
        $suggestions.classList.add('hidden');
    }
});

async function fetchSuggestions(q) {
    try {
        const data = await api.listLandings(q);
        renderSuggestions(data.landings || []);
    } catch (e) {
        $suggestions.innerHTML = `<li class="error">${escapeHtml(e.message)}</li>`;
        $suggestions.classList.remove('hidden');
    }
}

function renderSuggestions(items) {
    if (!items.length) {
        $suggestions.classList.add('hidden');
        return;
    }
    $suggestions.innerHTML = items.map((l) => {
        const picked = state.picked.some((p) => p.uuid === l.uuid);
        return `
            <li data-uuid="${escapeHtml(l.uuid)}" class="${picked ? 'picked-state' : ''}">
                <span class="label">${escapeHtml(l.label)}</span>
                <span class="meta">${escapeHtml(l.name || '')}${l.type ? ' · ' + escapeHtml(l.type) : ''}</span>
            </li>
        `;
    }).join('');
    $suggestions.classList.remove('hidden');
    $suggestions.querySelectorAll('li').forEach((node) => {
        node.addEventListener('click', () => {
            const uuid = node.dataset.uuid;
            const l = items.find((x) => x.uuid === uuid);
            if (l) addPicked(l);
        });
    });
}

function addPicked(l) {
    if (state.picked.some((p) => p.uuid === l.uuid)) return;
    state.picked.push(l);
    $search.value = '';
    $suggestions.classList.add('hidden');
    renderPicked();
    updateCreateButton();
}

function dropPicked(idx) {
    state.picked.splice(idx, 1);
    renderPicked();
    updateCreateButton();
}

function movePicked(idx, delta) {
    const j = idx + delta;
    if (j < 0 || j >= state.picked.length) return;
    [state.picked[idx], state.picked[j]] = [state.picked[j], state.picked[idx]];
    renderPicked();
}

function renderPicked() {
    const box = document.getElementById('picked');
    if (!state.picked.length) {
        box.innerHTML = '';
        return;
    }
    box.innerHTML = state.picked.map((l, i) => `
        <div class="chip">
            <span class="idx">${i + 1}.</span>
            <span class="label">${escapeHtml(l.label)}</span>
            <button data-act="up" data-i="${i}" ${i === 0 ? 'disabled' : ''}>↑</button>
            <button data-act="down" data-i="${i}" ${i === state.picked.length - 1 ? 'disabled' : ''}>↓</button>
            <button class="drop" data-act="drop" data-i="${i}">×</button>
        </div>
    `).join('');
    box.querySelectorAll('button').forEach((b) => {
        b.addEventListener('click', () => {
            const i = parseInt(b.dataset.i, 10);
            const act = b.dataset.act;
            if (act === 'drop') dropPicked(i);
            else if (act === 'up') movePicked(i, -1);
            else if (act === 'down') movePicked(i, +1);
        });
    });
}

function updateCreateButton() {
    const btn = document.getElementById('create');
    const n = state.picked.length;
    btn.disabled = n === 0;
    if (n === 0) btn.textContent = 'Выбери хотя бы один ленд';
    else if (n === 1) btn.textContent = 'Создать (MVT-режим)';
    else btn.textContent = `Создать compare (${n})`;
}

// ===== create =====
document.getElementById('create').addEventListener('click', async () => {
    const btn = document.getElementById('create');
    const err = document.getElementById('create-error');
    err.classList.add('hidden');
    btn.disabled = true;
    btn.textContent = 'Сохраняю…';
    try {
        const primitives = state.picked.map((l) =>
            l.human_id !== null && l.human_id !== undefined ? String(l.human_id) : l.uuid,
        );
        const interval = parseInt(document.getElementById('interval').value, 10);
        const nameField = document.getElementById('group-name').value.trim();
        await api.createGroup({
            primitives,
            name: nameField || null,
            notify_interval_minutes: interval,
        });
        state.picked = [];
        document.getElementById('group-name').value = '';
        renderPicked();
        updateCreateButton();
        await reloadGroups();
    } catch (e) {
        err.textContent = e.message;
        err.classList.remove('hidden');
        btn.disabled = false;
        updateCreateButton();
    }
});

// ===== options links =====
['open-options', 'open-options-2', 'open-options-3'].forEach((id) => {
    document.getElementById(id)?.addEventListener('click', () => {
        chrome.runtime.openOptionsPage();
    });
});

// ===== utils =====
function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}

function formatInterval(min) {
    if (!min) return '?';
    if (min % 60 === 0) {
        const h = min / 60;
        return h === 1 ? '1ч' : `${h}ч`;
    }
    return `${min}м`;
}

function formatRelative(iso) {
    try {
        const d = new Date(iso);
        const diffMs = d - new Date();
        const future = diffMs > 0;
        const abs = Math.abs(diffMs);
        const mins = Math.round(abs / 60000);
        if (mins < 1) return future ? 'сейчас' : 'только что';
        if (mins < 60) return future ? `через ${mins}м` : `${mins}м назад`;
        const h = Math.round(mins / 60);
        if (h < 24) return future ? `через ${h}ч` : `${h}ч назад`;
        return d.toLocaleDateString();
    } catch {
        return iso;
    }
}

boot();
