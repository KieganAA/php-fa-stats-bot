// Injected into app.aio.tech pages. Campaign-first: on the campaigns list it
// finds each <aio-data-campaign-relation> and injects a "🔔" button. One click
// subscribes the whole campaign — the backend looks inside, finds the splits +
// MVT landings and wires up the notifications. We only ever send the campaign's
// human_id; the backend resolves it to the uuid.
//
// Campaigns the user is already subscribed to are marked (green ✓) using the
// human_ids we get back from /api/ext/campaigns, so the same id is recognisable
// on both sides. Clicking an already-subscribed campaign re-syncs it.
//
// (Landing "＋" buttons + the floating batch panel were removed in the campaign
// pivot — they live in git history if landing subscriptions come back.)

const STATE = {
    subscribed: new Map(),   // String(human_id) -> { id, splits, mvts, name }
    loadedAt: 0,
    loading: false,
};

const SCAN_DEBOUNCE_MS = 400;
const SUBS_REFRESH_MS = 60_000;
let scanTimer = null;

function start() {
    loadSubscribed().finally(() => setTimeout(scan, 600));

    const obs = new MutationObserver(() => {
        clearTimeout(scanTimer);
        scanTimer = setTimeout(scan, SCAN_DEBOUNCE_MS);
    });
    obs.observe(document.body, { childList: true, subtree: true, characterData: true });
}

// ===== Background RPC =====
// Content scripts can't import ES modules, so api.js lives in the background
// service worker; we call it through chrome.runtime.sendMessage.
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

// Pull the user's campaign subscriptions and index them by human_id so we can
// mark already-subscribed rows. Throttled to once a minute unless forced.
async function loadSubscribed({ force = false } = {}) {
    if (STATE.loading) return;
    if (!force && STATE.loadedAt && Date.now() - STATE.loadedAt < SUBS_REFRESH_MS) return;
    STATE.loading = true;
    try {
        const data = await rpc('listCampaigns');
        const list = data?.campaigns || [];
        STATE.subscribed = new Map();
        for (const c of list) {
            if (c.human_id === null || c.human_id === undefined) continue;
            STATE.subscribed.set(String(c.human_id), {
                id: c.id,
                splits: c.splits ?? 0,
                mvts: c.mvts ?? 0,
                paused: !!c.paused,
                name: c.name || '',
            });
        }
        STATE.loadedAt = Date.now();
    } catch (e) {
        // Not authed yet / API unreachable — buttons still work, just unmarked.
        STATE.loadedAt = Date.now();
    } finally {
        STATE.loading = false;
    }
    // Refresh any buttons already on the page so marks appear.
    document.querySelectorAll('aio-data-campaign-relation[data-bs-camp-id]').forEach((rel) => {
        const btn = rel.querySelector('.bs-camp-btn');
        if (btn) applyCampButtonState(btn, rel.dataset.bsCampId);
    });
}

// ===== Campaign detection + row-level "subscribe" buttons =====
// Each campaign cell is an <aio-data-campaign-relation>:
//   <span class="text-gray-500">NNNNNN</span>   ← human_id
//   <ati-country-flag class="… fi-xx">          ← country
//   <div class="data-campaign-relation__name">  ← name
function scan() {
    for (const rel of document.querySelectorAll('aio-data-campaign-relation')) {
        const humanId = extractHumanId(rel);
        if (!humanId) continue;

        if (rel.dataset.bsCampMounted) {
            // AIO is an Angular SPA — it RECYCLES row nodes on search / filter /
            // virtual-scroll, swapping the campaign inside an existing
            // <aio-data-campaign-relation> without re-creating it. Our button
            // would then keep the previous campaign's id. So whenever the id in
            // the node no longer matches what we stored, re-bind the button.
            if (rel.dataset.bsCampId !== humanId) {
                rel.dataset.bsCampId = humanId;
                const btn = rel.querySelector('.bs-camp-btn');
                if (btn) { btn.dataset.busy = '0'; applyCampButtonState(btn, humanId); }
            }
            continue;
        }
        decorateCampaign(rel, humanId);
    }
}

function nameOf(rel) {
    return rel.querySelector('.data-campaign-relation__name')?.textContent?.trim().slice(0, 80) ?? '';
}

function extractHumanId(rel) {
    // human_id sits in <span class="text-gray-500">NNNNNN</span>; the class is
    // sometimes reused, so accept only spans whose whole trimmed text is digits.
    for (const span of rel.querySelectorAll('.text-gray-500')) {
        const t = span.textContent?.trim() ?? '';
        if (/^\d{3,8}$/.test(t)) return t;
    }
    return null;
}

function decorateCampaign(rel, humanId) {
    rel.dataset.bsCampMounted = '1';
    rel.dataset.bsCampId = humanId;

    const btn = document.createElement('button');
    btn.className = 'bs-camp-btn';
    btn.type = 'button';

    // Keep the click from triggering AIO's own row navigation/selection.
    btn.addEventListener('mousedown', (e) => e.stopPropagation());
    btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        // Read id + name from the DOM AT CLICK TIME — the node may have been
        // recycled for a different campaign since we attached this handler.
        const id = extractHumanId(rel);
        if (!id) return;
        onSubscribeCampaign(rel, id, nameOf(rel), btn);
    });

    applyCampButtonState(btn, humanId);
    rel.insertBefore(btn, rel.firstChild);
}

// Reflect subscribed / not-subscribed in the button face + tooltip.
function applyCampButtonState(btn, humanId) {
    if (btn.dataset.busy === '1') return;
    const sub = STATE.subscribed.get(String(humanId));
    btn.classList.remove('bs-camp-btn-err');
    if (sub) {
        btn.textContent = '✓';
        btn.classList.add('bs-camp-btn-done');
        const bits = [];
        if (sub.splits) bits.push(`${sub.splits} сплит`);
        if (sub.mvts) bits.push(`${sub.mvts} MVT`);
        const what = bits.length ? bits.join(' · ') : 'нет сплитов/MVT';
        btn.title = `Подписан (${what})${sub.paused ? ' ⏸' : ''} — клик пересоберёт структуру`;
    } else {
        btn.textContent = '🔔';
        btn.classList.remove('bs-camp-btn-done');
        btn.title = `Подписать кампанию #${humanId} — бот сам найдёт сплиты и MVT`;
    }
}

async function onSubscribeCampaign(rel, humanId, name, btn) {
    if (btn.dataset.busy === '1') return;
    btn.dataset.busy = '1';
    btn.textContent = '⏳';
    btn.classList.remove('bs-camp-btn-done', 'bs-camp-btn-err');

    try {
        const data = await rpc('subscribeCampaign', humanId);
        const c = data?.campaign || {};
        const splits = c.splits ?? 0;
        const mvts = c.mvts ?? 0;
        const hours = Math.round((c.notify_interval_minutes ?? 180) / 60);
        const label = c.label || `#${humanId}`;

        STATE.subscribed.set(String(humanId), {
            id: c.id, splits, mvts, paused: !!c.paused, name: c.name || name,
        });

        btn.dataset.busy = '0';
        applyCampButtonState(btn, humanId);

        if (splits === 0 && mvts === 0) {
            flashOk(`${label}: ни сплитов, ни MVT — пушить нечего.`);
        } else {
            flashOk(`✅ ${label} — ${splits} сплит, ${mvts} MVT. Пуш каждые ${hours}ч.`);
        }
    } catch (e) {
        btn.dataset.busy = '0';
        btn.textContent = '⚠️';
        btn.classList.add('bs-camp-btn-err');
        btn.title = e?.message || String(e);
        flashOk(`⚠️ ${name || ('#' + humanId)}: ${e?.message || e}`);
    }
}

// ===== Toast =====
function flashOk(msg) {
    const ok = document.createElement('div');
    ok.className = 'bs-toast';
    ok.textContent = msg;
    document.body.appendChild(ok);
    setTimeout(() => ok.remove(), 4000);
}

start();
