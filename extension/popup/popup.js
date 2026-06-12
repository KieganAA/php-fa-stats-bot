import { api, getConfig } from '../api.js';

const INTERVALS = [
    [60, '1ч'], [180, '3ч'], [360, '6ч'], [720, '12ч'], [1440, '24ч'],
];

const state = { campaigns: [] };

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
        await reload();
        show('main');
    } catch (e) {
        if (e.status === 401) {
            show('auth-error');
        } else {
            show('not-configured');
            document.getElementById('not-configured').insertAdjacentHTML(
                'beforeend', `<p class="error">${escapeHtml(e.message)}</p>`,
            );
        }
    }
}

function show(id) {
    for (const sel of ['not-configured', 'auth-error', 'main', 'loading']) {
        document.getElementById(sel)?.classList.toggle('hidden', sel !== id);
    }
}

// ===== campaigns =====
async function reload() {
    const data = await api.listCampaigns();
    state.campaigns = data.campaigns || [];
    document.getElementById('camps-count').textContent =
        state.campaigns.length ? `(${state.campaigns.length})` : '';
    renderCampaigns();
}

function renderCampaigns() {
    const box = document.getElementById('camps');
    if (!state.campaigns.length) {
        box.innerHTML = `<p class="empty">Подписок на кампании нет.<br>
            Введи human_id выше или жми 🔔 у кампании на AIO.</p>`;
        return;
    }

    box.innerHTML = state.campaigns.map((c) => {
        const bits = [];
        bits.push(`<span class="badge">${c.splits} сплит</span>`);
        bits.push(`<span class="badge">${c.mvts} MVT</span>`);
        if (c.paused) bits.push('<span class="badge warn">⏸ пауза</span>');
        if (c.orphans > 0) bits.push(`<span class="badge danger">⚠️ ${c.orphans} пропал</span>`);

        const intervalSel = INTERVALS.map(([v, l]) =>
            `<option value="${v}"${v === c.notify_interval_minutes ? ' selected' : ''}>${l}</option>`,
        ).join('');

        const children = (c.children || []).map((ch) => {
            const lands = (ch.landings || [])
                .map((l) => (l.human_id != null ? `#${l.human_id}` : (l.uuid || '').slice(0, 6)))
                .join(', ');
            const icon = ch.mode === 'mvt' ? '🧬' : '🔀';
            const orphan = ch.orphaned ? ' <span class="danger">⚠</span>' : '';
            return `<li>${icon} ${escapeHtml(stripPrefix(ch.name))}${orphan}
                <span class="land-ids">${escapeHtml(lands)}</span></li>`;
        }).join('');

        const next = c.next_push_at && !c.paused ? `next ~ ${formatRelative(c.next_push_at)}` : '';
        const synced = c.last_synced_at ? `синк ${formatRelative(c.last_synced_at)}` : '';

        return `
        <div class="camp ${c.paused ? 'is-paused' : ''}" data-id="${c.id}">
            <div class="camp-top">
                <div class="camp-id">
                    <span class="label">${escapeHtml(c.label)}</span>
                    <span class="cname">${escapeHtml(c.name || '')}</span>
                </div>
                <div class="camp-actions">
                    <button class="act pause" title="${c.paused ? 'Возобновить' : 'Пауза'}">${c.paused ? '▶️' : '⏸'}</button>
                    <button class="act resync" title="Пересобрать структуру (resync)">🔄</button>
                    <button class="act del" title="Удалить подписку">🗑</button>
                </div>
            </div>
            <div class="camp-badges">${bits.join('')}</div>
            <div class="camp-ctl">
                <label class="interval">⏱ каждые
                    <select class="interval-sel">${intervalSel}</select>
                </label>
                <span class="meta">${[next, synced].filter(Boolean).join(' · ')}</span>
            </div>
            ${children ? `
            <details class="camp-children">
                <summary>${(c.children || []).length} подписок внутри</summary>
                <ul>${children}</ul>
            </details>` : ''}
            <div class="camp-msg hidden"></div>
        </div>`;
    }).join('');

    box.querySelectorAll('.camp').forEach((node) => {
        const id = parseInt(node.dataset.id, 10);
        const c = state.campaigns.find((x) => x.id === id);
        node.querySelector('.pause')?.addEventListener('click', () => togglePause(c, node));
        node.querySelector('.resync')?.addEventListener('click', () => resync(c, node));
        node.querySelector('.del')?.addEventListener('click', () => remove(c));
        node.querySelector('.interval-sel')?.addEventListener('change', (e) =>
            setInterval(c, parseInt(e.target.value, 10), node));
    });
}

async function togglePause(c, node) {
    await guard(node, async () => {
        const res = await api.updateCampaign(c.id, { paused: !c.paused });
        Object.assign(c, res.campaign);
        renderCampaigns();
    });
}

async function setInterval(c, minutes, node) {
    await guard(node, async () => {
        const res = await api.updateCampaign(c.id, { notify_interval_minutes: minutes });
        Object.assign(c, res.campaign);
        renderCampaigns();
    });
}

async function resync(c, node) {
    await guard(node, async () => {
        const res = await api.resyncCampaign(c.id);
        Object.assign(c, res.campaign);
        const ch = res.changed || {};
        const parts = [];
        if (ch.created) parts.push(`+${ch.created}`);
        if (ch.reactivated) parts.push(`♻${ch.reactivated}`);
        if (ch.orphaned) parts.push(`⚠${ch.orphaned}`);
        renderCampaigns();
        flashCard(c.id, parts.length ? `Обновлено: ${parts.join(' ')}` : 'Без изменений', false);
    }, 'Обновляю…');
}

async function remove(c) {
    if (!confirm(`Удалить подписку на ${c.label}?\n${c.name || ''}`)) return;
    try {
        await api.deleteCampaign(c.id);
        await reload();
    } catch (e) {
        alert(e.message);
    }
}

// Disable a card's buttons while an action runs; show errors inline.
async function guard(node, fn, busyLabel) {
    const msg = node.querySelector('.camp-msg');
    node.querySelectorAll('button, select').forEach((el) => (el.disabled = true));
    if (busyLabel && msg) { msg.textContent = busyLabel; msg.className = 'camp-msg'; }
    try {
        await fn();
    } catch (e) {
        if (msg) { msg.textContent = e?.message || String(e); msg.className = 'camp-msg error'; }
    }
}

function flashCard(id, text, isError) {
    const node = document.querySelector(`.camp[data-id="${id}"] .camp-msg`);
    if (!node) return;
    node.textContent = text;
    node.className = `camp-msg ${isError ? 'error' : 'ok'}`;
    setTimeout(() => { node.textContent = ''; node.className = 'camp-msg hidden'; }, 3500);
}

// ===== subscribe by human_id =====
const $input = document.getElementById('camp-input');
const $subBtn = document.getElementById('camp-subscribe');
const $subMsg = document.getElementById('subscribe-msg');

$subBtn.addEventListener('click', subscribe);
$input.addEventListener('keydown', (e) => { if (e.key === 'Enter') subscribe(); });

async function subscribe() {
    const token = $input.value.trim();
    if (!token) return;
    $subBtn.disabled = true;
    $subBtn.textContent = '⏳';
    setMsg('', null);
    try {
        const res = await api.subscribeCampaign(token);
        const c = res.campaign || {};
        $input.value = '';
        setMsg(`✅ ${c.label || token}: ${c.splits} сплит, ${c.mvts} MVT.`, 'ok');
        await reload();
    } catch (e) {
        setMsg(e?.message || String(e), 'error');
    } finally {
        $subBtn.disabled = false;
        $subBtn.textContent = 'Подписать';
    }
}

function setMsg(text, kind) {
    if (!text) { $subMsg.className = 'msg hidden'; $subMsg.textContent = ''; return; }
    $subMsg.textContent = text;
    $subMsg.className = `msg ${kind || ''}`;
}

// ===== options links =====
['open-options', 'open-options-2', 'open-options-3'].forEach((id) => {
    document.getElementById(id)?.addEventListener('click', () => chrome.runtime.openOptionsPage());
});

// ===== utils =====
function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}

// "#116400 CA · шаг 1 сплит" → "шаг 1 сплит"
function stripPrefix(name) {
    const i = (name || '').indexOf('· ');
    return i !== -1 ? name.slice(i + 2).trim() : (name || '');
}

function formatRelative(iso) {
    try {
        const d = new Date(iso);
        const diffMs = d - new Date();
        const future = diffMs > 0;
        const mins = Math.round(Math.abs(diffMs) / 60000);
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
