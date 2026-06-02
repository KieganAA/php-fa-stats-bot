// Injected into app.aio.tech pages. Three jobs:
//
//   1. Detect landings via <aio-data-lander-relation> and inject a tiny "＋"
//      button into each row. Click → landing is added to a local batch
//      ("подборка") shown in the floating panel.
//
//   2. Hook into AIO's own right-click menu (<ati-menu>): append a
//      "📌 В подборку bot-stats" item that does the same thing as the
//      "＋" button.
//
//   3. The floating panel itself: lists what's in the batch and lets the user
//      either create a new subscription from it (имя + частота + Создать) or
//      append the batch to an existing subscription (dropdown + Добавить).
//      Each "＋" button also wears a small amber dot when that landing is
//      already a member of any of the user's subscriptions — with a tooltip
//      listing the subscription names. The panel × is sticky: it stays
//      hidden across reloads until the user re-enables it from the options
//      page (chrome.storage.sync.panelHidden, mirrored to the checkbox).
//
// Direct add-to-bot via stash_landings (legacy path) is no longer used here —
// the panel now talks to /api/ext/* directly through a tiny `rpc()` helper
// that delegates to api.js in the background service worker.

const STATE = {
    picked: new Map(),               // id -> { id, label, hintText, country, name }
    contextRowId: null,              // last right-clicked landing's id (for menu hook)
    panelVisible: false,
    groupsById: new Map(),           // group_id -> group object (from listGroups)
    groupsByLandingId: new Map(),    // String(human_id) -> Set<group_id>
    groupsLoadedAt: 0,
    groupsLoading: false,
    groupsError: null,
    selectedAppendGroupId: null,     // sticky selection in the "add to existing" dropdown
};

// Mirrored from chrome.storage.sync.panelHidden. Updated reactively via
// storage.onChanged so the options-page checkbox can show/hide the panel
// without a page reload.
let PANEL_HIDDEN = false;

const SCAN_DEBOUNCE_MS = 400;
const GROUPS_REFRESH_MS = 60_000;
let scanTimer = null;

function start() {
    loadPanelHidden().then(() => {
        // Scan even if groups fail to load — picking still works without them.
        setTimeout(scan, 800);
        loadGroups().finally(() => { if (!PANEL_HIDDEN) renderPanel(); });
    });

    chrome.storage.onChanged.addListener((changes, area) => {
        if (area !== 'sync' || !('panelHidden' in changes)) return;
        PANEL_HIDDEN = !!changes.panelHidden.newValue;
        if (PANEL_HIDDEN) {
            document.getElementById('bot-stats-panel')?.remove();
            STATE.panelVisible = false;
        } else {
            renderPanel();
        }
    });

    const obs = new MutationObserver(() => {
        clearTimeout(scanTimer);
        scanTimer = setTimeout(scan, SCAN_DEBOUNCE_MS);
        injectIntoContextMenu();
    });
    obs.observe(document.body, { childList: true, subtree: true, characterData: true });

    // Capture which landing the user right-clicked, so when AIO's menu
    // appears we know which id to attach to our injected item.
    document.addEventListener('contextmenu', (e) => {
        const rel = e.target.closest?.('aio-data-lander-relation');
        if (rel) {
            STATE.contextRowId = extractIdFromRelation(rel);
            return;
        }
        // Some right-clicks land outside the data-relation cell but still on
        // the same <tr>. Use the row's stashed id in that case.
        const tr = e.target.closest?.('tr[data-bot-stats-id]');
        STATE.contextRowId = tr?.dataset?.botStatsId ?? null;
    }, true);
}

// ===== Background RPC =====
// Content scripts can't use ES module imports, so api.js lives in the
// background service worker. We call it through chrome.runtime.sendMessage.
function rpc(fn, ...args) {
    return new Promise((resolve, reject) => {
        chrome.runtime.sendMessage({ type: 'api_call', fn, args }, (res) => {
            if (chrome.runtime.lastError) {
                reject(new Error(chrome.runtime.lastError.message));
                return;
            }
            if (res?.error) reject(new Error(res.error));
            else resolve(res?.data);
        });
    });
}

async function loadPanelHidden() {
    return new Promise((resolve) => {
        chrome.storage.sync.get({ panelHidden: false }, (cfg) => {
            PANEL_HIDDEN = !!cfg.panelHidden;
            resolve();
        });
    });
}

// Pulls the user's current subscriptions + builds a reverse index so we can
// mark each landing row as "already in some subscription". Throttled — won't
// re-fetch more than once per minute unless `force` is set (e.g. after the
// user just created/appended).
async function loadGroups({ force = false } = {}) {
    if (STATE.groupsLoading) return;
    if (!force && STATE.groupsLoadedAt && Date.now() - STATE.groupsLoadedAt < GROUPS_REFRESH_MS) return;
    STATE.groupsLoading = true;
    STATE.groupsError = null;
    try {
        const data = await rpc('listGroups');
        const groups = data?.groups || [];
        STATE.groupsById = new Map();
        STATE.groupsByLandingId = new Map();
        for (const g of groups) {
            STATE.groupsById.set(g.id, g);
            for (const m of g.members || []) {
                if (m.human_id === null || m.human_id === undefined) continue;
                const key = String(m.human_id);
                if (!STATE.groupsByLandingId.has(key)) STATE.groupsByLandingId.set(key, new Set());
                STATE.groupsByLandingId.get(key).add(g.id);
            }
        }
        STATE.groupsLoadedAt = Date.now();
    } catch (e) {
        STATE.groupsError = e?.message || String(e);
        STATE.groupsLoadedAt = Date.now();
    } finally {
        STATE.groupsLoading = false;
    }
    // Refresh every row button so subscribed-state badges update.
    document.querySelectorAll('aio-data-lander-relation[data-bot-stats-id]').forEach(updateRelButton);
}

// ===== AIO context-menu integration =====
// AIO renders its right-click menu as <ati-menu> inside a CDK overlay
// container. We mimic the structure so our entry styles like a native one.
function injectIntoContextMenu() {
    const menus = document.querySelectorAll('ati-menu');
    for (const menu of menus) {
        const id = STATE.contextRowId;
        if (!id) continue;

        // Skip if we've already injected for this exact id. If the menu was
        // reused for a different row, drop the stale entry and re-inject.
        const existing = menu.querySelector('.bs-menu-entry');
        if (existing) {
            if (existing.dataset.botStatsId === id) continue;
            existing.remove();
        }

        const group = menu.querySelector('.menu-group');
        if (!group) continue;

        const sampleItem = group.querySelector('.menu-item');
        const sampleLabel = sampleItem?.querySelector('.menu-item__label');
        if (!sampleItem || !sampleLabel) continue;

        // Copy Angular content-encapsulation attributes so AIO's own CSS
        // matches our element. Without these, our div renders unstyled.
        const ngContentAttrs = [...sampleItem.attributes]
            .map((a) => a.name)
            .filter((n) => n.startsWith('_ngcontent') || n.startsWith('_nghost'));
        const ngLabelAttrs = [...sampleLabel.attributes]
            .map((a) => a.name)
            .filter((n) => n.startsWith('_ngcontent') || n.startsWith('_nghost'));

        const item = document.createElement('div');
        item.className = 'menu-item bs-menu-entry';
        item.setAttribute('role', 'menuitem');
        item.dataset.botStatsId = id;
        ngContentAttrs.forEach((n) => item.setAttribute(n, ''));

        const label = document.createElement('div');
        label.className = 'menu-item__label';
        ngLabelAttrs.forEach((n) => label.setAttribute(n, ''));
        label.textContent = STATE.picked.has(id)
            ? `📌 Уже в подборке (#${id})`
            : `📌 В подборку bot-stats (#${id})`;
        item.appendChild(label);

        item.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            // Look up the relation element for richer metadata + button refresh.
            const rel = document.querySelector(`aio-data-lander-relation[data-bot-stats-id="${id}"]`);
            const meta = rel ? extractMetaFromRelation(rel, id) : { id, label: `#${id}`, hintText: '' };
            if (!STATE.picked.has(id)) {
                STATE.picked.set(id, meta);
                if (rel) updateRelButton(rel);
                if (PANEL_HIDDEN) {
                    flashOk(`#${id} → в подборке. Включи плавающую панель в настройках, чтобы создать подписку.`);
                } else {
                    flashOk(`#${id} → в подборке.`);
                }
                renderPanel();
            } else {
                flashOk(`#${id} уже в подборке.`);
            }
            const overlay = menu.closest('.cdk-overlay-popover, .cdk-overlay-container > div');
            overlay?.remove();
        });

        group.appendChild(item);
        menu.dataset.botStatsMounted = '1';
    }
}

// ===== Landing detection + row-level "+" buttons =====
// <aio-data-lander-relation> is rendered ONLY for landings; the inner
// <span class="text-gray-500">NNNNNN</span> carries the human_id, .muted the
// country code, .font-bold the human name.
function scan() {
    let changed = false;
    const rels = document.querySelectorAll('aio-data-lander-relation');
    for (const rel of rels) {
        if (rel.dataset.botStatsMounted) {
            updateRelButton(rel);
            continue;
        }

        const id = extractIdFromRelation(rel);
        if (!id) continue;

        decorateRelation(rel, id);

        // Stash the id on the parent <tr> for the contextmenu fallback.
        const tr = rel.closest('tr[atipopovertrigger="contextmenu"], tr');
        if (tr) tr.dataset.botStatsId = id;
        changed = true;
    }
    if (changed && !PANEL_HIDDEN) renderPanel();
}

function extractIdFromRelation(rel) {
    // The id appears in <span class="text-gray-500">NNNNNN</span> — but the
    // same class is sometimes reused, so we accept only spans whose entire
    // trimmed text is a digit run.
    for (const span of rel.querySelectorAll('.text-gray-500')) {
        const t = span.textContent?.trim() ?? '';
        if (/^\d{3,8}$/.test(t)) return t;
    }
    return null;
}

function extractMetaFromRelation(rel, id) {
    const name = rel.querySelector('.font-bold')?.textContent?.trim().slice(0, 80) ?? '';
    const country = rel.querySelector('.muted')?.textContent?.trim() ?? '';
    const hintText = country && name ? `${country} · ${name}` : (name || country);
    return { id, label: `#${id}`, hintText: (hintText || '').slice(0, 60), country, name };
}

function decorateRelation(rel, id) {
    rel.dataset.botStatsMounted = '1';
    rel.dataset.botStatsId = id;

    // The data-relation div holds the existing flex row (id, flag, name).
    // We prepend our button so it sits flush at the row's left edge and
    // can't be pushed off by long names.
    const container = rel.querySelector('.data-relation') ?? rel;

    const btn = document.createElement('button');
    btn.className = 'bs-row-btn';

    btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        const wasPicked = STATE.picked.has(id);
        if (wasPicked) {
            STATE.picked.delete(id);
        } else {
            STATE.picked.set(id, extractMetaFromRelation(rel, id));
        }
        updateRelButton(rel);
        if (PANEL_HIDDEN && !wasPicked) {
            flashOk('В подборке. Включи плавающую панель в настройках, чтобы создать подписку.');
        }
        renderPanel();
    });
    // Stop the click from bubbling into the row and triggering AIO's own
    // navigation/selection.
    btn.addEventListener('mousedown', (e) => e.stopPropagation());

    applyButtonState(btn, id);
    container.insertBefore(btn, container.firstChild);
}

function updateRelButton(rel) {
    const id = rel.dataset.botStatsId;
    if (!id) return;
    const btn = rel.querySelector('.bs-row-btn');
    if (!btn) return;
    applyButtonState(btn, id);
}

// Two orthogonal states a button can be in: picked (locally selected) and
// subscribed (already in one or more of the user's existing subscriptions).
// Subscribed → small amber dot at the corner + tooltip listing names.
function applyButtonState(btn, id) {
    const isPicked = STATE.picked.has(id);
    const subscribedGids = STATE.groupsByLandingId.get(id);
    const isSubscribed = !!(subscribedGids && subscribedGids.size > 0);

    btn.textContent = isPicked ? '✓' : '＋';
    btn.classList.toggle('bs-row-btn-on', isPicked);
    btn.classList.toggle('bs-row-btn-subscribed', isSubscribed);

    if (isSubscribed) {
        const names = [...subscribedGids]
            .map((gid) => STATE.groupsById.get(gid)?.name)
            .filter(Boolean);
        const sub = `Уже в подписке: ${names.join(', ')}`;
        btn.title = isPicked ? `В подборке. ${sub}` : sub;
    } else {
        btn.title = isPicked ? 'В подборке (клик — убрать)' : 'В подборку bot-stats';
    }
}

// ===== Floating panel =====
function renderPanel() {
    if (PANEL_HIDDEN) return;

    let panel = document.getElementById('bot-stats-panel');
    if (!panel) {
        panel = document.createElement('div');
        panel.id = 'bot-stats-panel';
        document.body.appendChild(panel);
    }
    STATE.panelVisible = true;

    const picked = Array.from(STATE.picked.values());
    const pickedCount = picked.length;
    const groups = Array.from(STATE.groupsById.values());
    const uniqueLandingsInGroups = STATE.groupsByLandingId.size;

    const intervalOptions = [
        ['60', '1 час'],
        ['180', '3 часа'],
        ['360', '6 часов'],
        ['720', '12 часов'],
        ['1440', '24 часа'],
    ];

    panel.innerHTML = `
        <div class="bs-head" id="bs-head">
            <span>bot-stats${pickedCount ? ` <span class="bs-count">${pickedCount}</span>` : ''}</span>
            <span class="bs-actions">
                <button id="bs-toggle" title="Свернуть">_</button>
                <button id="bs-close" title="Скрыть (включить обратно в настройках)">×</button>
            </span>
        </div>
        <div class="bs-body" id="bs-body">
            ${pickedCount === 0 ? renderEmpty(groups.length, uniqueLandingsInGroups) : renderActive(picked, pickedCount, groups, intervalOptions)}
        </div>
    `;

    panel.querySelector('#bs-close')?.addEventListener('click', () => {
        panel.remove();
        STATE.panelVisible = false;
        PANEL_HIDDEN = true;
        chrome.storage.sync.set({ panelHidden: true });
        flashOk('Панель скрыта. Включить обратно — в настройках расширения.');
    });
    panel.querySelector('#bs-toggle')?.addEventListener('click', () => {
        panel.classList.toggle('bs-collapsed');
    });
    panel.querySelector('#bs-clear')?.addEventListener('click', () => {
        STATE.picked.clear();
        document.querySelectorAll('aio-data-lander-relation[data-bot-stats-id]').forEach(updateRelButton);
        renderPanel();
    });
    panel.querySelectorAll('.bs-drop').forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const id = e.currentTarget.dataset.drop;
            STATE.picked.delete(id);
            document.querySelectorAll(`aio-data-lander-relation[data-bot-stats-id="${id}"]`).forEach(updateRelButton);
            renderPanel();
        });
    });
    panel.querySelector('#bs-create')?.addEventListener('click', onCreateSubscription);
    panel.querySelector('#bs-group')?.addEventListener('change', (e) => {
        STATE.selectedAppendGroupId = e.target.value ? parseInt(e.target.value, 10) : null;
        const btn = panel.querySelector('#bs-add');
        if (btn) btn.disabled = !STATE.selectedAppendGroupId;
    });
    panel.querySelector('#bs-add')?.addEventListener('click', onAppendToSubscription);

    makeDraggable(panel);
}

function renderEmpty(groupsCount, landingsCount) {
    const statsLine = groupsCount
        ? `<small>${groupsCount} ${pluralize(groupsCount, ['подписка', 'подписки', 'подписок'])} · ${landingsCount} ${pluralize(landingsCount, ['ленд', 'ленда', 'лендов'])}</small>`
        : '';
    const errLine = STATE.groupsError
        ? `<small class="bs-err">Не могу получить подписки: ${escapeHtml(STATE.groupsError)}</small>`
        : '';
    return `
        <div class="bs-empty">
            Жми «＋» рядом с лендом, чтобы добавить в подборку.
            ${statsLine}
            ${errLine}
        </div>
    `;
}

function renderActive(picked, pickedCount, groups, intervalOptions) {
    const intervalSel = intervalOptions
        .map(([v, l]) => `<option value="${v}"${v === '180' ? ' selected' : ''}>${l}</option>`)
        .join('');

    const groupSel = groups
        .map((g) => {
            const selAttr = STATE.selectedAppendGroupId === g.id ? ' selected' : '';
            const memberCount = (g.members || []).length;
            return `<option value="${g.id}"${selAttr}>${escapeHtml(g.name)} (${memberCount})</option>`;
        })
        .join('');

    return `
        <div class="bs-section bs-picked-section">
            <div class="bs-section-head">
                <span>Подборка <span class="bs-count">${pickedCount}</span></span>
                <button id="bs-clear" class="bs-clear">очистить</button>
            </div>
            <ul class="bs-picked-list">
                ${picked.map((it) => `
                    <li class="bs-chip" data-id="${escapeAttr(it.id)}">
                        <span class="bs-label">${escapeHtml(it.label)}</span>
                        ${it.hintText ? `<span class="bs-hint">${escapeHtml(it.hintText)}</span>` : ''}
                        <button class="bs-drop" data-drop="${escapeAttr(it.id)}" title="Убрать">×</button>
                    </li>
                `).join('')}
            </ul>
        </div>
        <div class="bs-section">
            <div class="bs-section-head"><span>Создать подписку</span></div>
            <input id="bs-name" class="bs-input" placeholder="Имя (необязательно)" autocomplete="off" spellcheck="false">
            <div class="bs-form-row">
                <select id="bs-interval" class="bs-input bs-input-inline">${intervalSel}</select>
                <button id="bs-create" class="bs-primary">Создать (${pickedCount})</button>
            </div>
        </div>
        ${groups.length ? `
            <div class="bs-section">
                <div class="bs-section-head"><span>Добавить в подписку</span></div>
                <div class="bs-form-row">
                    <select id="bs-group" class="bs-input bs-input-inline">
                        <option value="">— выбери —</option>
                        ${groupSel}
                    </select>
                    <button id="bs-add" class="bs-secondary" ${STATE.selectedAppendGroupId ? '' : 'disabled'}>Добавить</button>
                </div>
            </div>
        ` : ''}
        <div class="bs-foot-msg" id="bs-foot-msg"></div>
    `;
}

async function onCreateSubscription() {
    const tokens = Array.from(STATE.picked.keys());
    if (!tokens.length) return;
    const panel = document.getElementById('bot-stats-panel');
    if (!panel) return;

    const btn = panel.querySelector('#bs-create');
    const nameField = panel.querySelector('#bs-name');
    const intervalField = panel.querySelector('#bs-interval');
    const fmsg = panel.querySelector('#bs-foot-msg');
    if (!btn || !nameField || !intervalField) return;

    const interval = parseInt(intervalField.value, 10);
    const name = nameField.value.trim();

    btn.disabled = true;
    btn.textContent = 'Сохраняю…';
    if (fmsg) { fmsg.textContent = ''; fmsg.className = 'bs-foot-msg'; }

    try {
        const data = await rpc('createGroup', {
            primitives: tokens,
            name: name || null,
            notify_interval_minutes: interval,
        });
        const gname = data?.group?.name || name || 'подписка';
        STATE.picked.clear();
        STATE.selectedAppendGroupId = null;
        await loadGroups({ force: true });
        renderPanel();
        flashOk(`Подписка «${gname}» создана.`);
    } catch (e) {
        if (fmsg) { fmsg.textContent = e?.message || String(e); fmsg.className = 'bs-foot-msg bs-err'; }
        btn.disabled = false;
        btn.textContent = `Создать (${tokens.length})`;
    }
}

async function onAppendToSubscription() {
    const gid = STATE.selectedAppendGroupId;
    if (!gid) return;
    const group = STATE.groupsById.get(gid);
    if (!group) return;
    const picked = Array.from(STATE.picked.keys());
    if (!picked.length) return;
    const panel = document.getElementById('bot-stats-panel');
    if (!panel) return;
    const btn = panel.querySelector('#bs-add');
    const fmsg = panel.querySelector('#bs-foot-msg');
    if (!btn) return;

    // Re-bind by name: existing members + picked, deduped. The binder is
    // idempotent for same (user, name) — it overwrites the member list,
    // keeping underlying tracked_landing rows.
    const existingIds = (group.members || [])
        .map((m) => (m.human_id !== null && m.human_id !== undefined ? String(m.human_id) : (m.uuid || null)))
        .filter(Boolean);
    const existingSet = new Set(existingIds);
    const merged = [...existingIds];
    let added = 0;
    for (const p of picked) {
        if (!existingSet.has(p)) {
            merged.push(p);
            existingSet.add(p);
            added++;
        }
    }
    if (added === 0) {
        if (fmsg) { fmsg.textContent = `Все ${picked.length} уже в «${group.name}».`; fmsg.className = 'bs-foot-msg'; }
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Сохраняю…';
    if (fmsg) { fmsg.textContent = ''; fmsg.className = 'bs-foot-msg'; }

    try {
        await rpc('createGroup', {
            primitives: merged,
            name: group.name,
            notify_interval_minutes: group.notify_interval_minutes,
        });
        STATE.picked.clear();
        STATE.selectedAppendGroupId = null;
        await loadGroups({ force: true });
        renderPanel();
        flashOk(`«${group.name}» +${added}.`);
    } catch (e) {
        if (fmsg) { fmsg.textContent = e?.message || String(e); fmsg.className = 'bs-foot-msg bs-err'; }
        btn.disabled = false;
        btn.textContent = 'Добавить';
    }
}

function makeDraggable(panel) {
    const head = panel.querySelector('#bs-head');
    if (!head || head.dataset.draggable === '1') return;
    head.dataset.draggable = '1';

    try {
        const saved = JSON.parse(localStorage.getItem('bs-panel-pos') || 'null');
        if (saved && Number.isFinite(saved.left) && Number.isFinite(saved.top)) {
            panel.style.left = saved.left + 'px';
            panel.style.top = saved.top + 'px';
            panel.style.right = 'auto';
            panel.style.bottom = 'auto';
        }
    } catch { /* ignore */ }

    let dragging = false, sx = 0, sy = 0, ox = 0, oy = 0;
    head.addEventListener('mousedown', (e) => {
        if (e.target.tagName === 'BUTTON') return;
        dragging = true;
        sx = e.clientX; sy = e.clientY;
        const rect = panel.getBoundingClientRect();
        ox = rect.left; oy = rect.top;
        e.preventDefault();
    });
    document.addEventListener('mousemove', (e) => {
        if (!dragging) return;
        const left = ox + (e.clientX - sx);
        const top = oy + (e.clientY - sy);
        panel.style.left = left + 'px';
        panel.style.top = top + 'px';
        panel.style.right = 'auto';
        panel.style.bottom = 'auto';
    });
    document.addEventListener('mouseup', () => {
        if (!dragging) return;
        dragging = false;
        const rect = panel.getBoundingClientRect();
        try {
            localStorage.setItem('bs-panel-pos', JSON.stringify({ left: rect.left, top: rect.top }));
        } catch { /* ignore */ }
    });
}

function flashOk(msg) {
    const ok = document.createElement('div');
    ok.className = 'bs-toast';
    ok.textContent = msg;
    document.body.appendChild(ok);
    setTimeout(() => ok.remove(), 4000);
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}
function escapeAttr(s) { return escapeHtml(s); }

// Russian plural for "1 ленд / 2 ленда / 5 лендов" — Slavic 1/few/many.
function pluralize(n, forms) {
    const m10 = n % 10;
    const m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return forms[0];
    if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return forms[1];
    return forms[2];
}

start();
