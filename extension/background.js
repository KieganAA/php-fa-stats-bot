// Service worker — installs context menu, drains content-script messages,
// pre-loads picked landings into chrome.storage.local so the popup can
// pick them up on next open. Also proxies /api/ext/* calls on behalf of
// content.js (content scripts can't easily import ES modules, so the
// shared api.js client lives here instead).
//
// MV3 service workers are short-lived: don't keep state in module globals,
// rely on storage / runtime messages.

import { api } from './api.js';

const MENU_ID = 'bot-stats-send-selection';

chrome.runtime.onInstalled.addListener(() => {
    chrome.contextMenus.create({
        id: MENU_ID,
        title: 'Отправить выделенное в bot-stats',
        contexts: ['selection'],
    });
});

// Selection → parse ids → stash in chrome.storage.local → open popup so
// user sees them.
chrome.contextMenus.onClicked.addListener(async (info) => {
    if (info.menuItemId !== MENU_ID) return;
    const selection = (info.selectionText || '').trim();
    if (!selection) return;

    const tokens = extractTokens(selection);
    if (!tokens.length) {
        notify('Не нашёл ничего похожего на ID лендинга в выделении.');
        return;
    }

    await stashPending(tokens);
    notify(`Подгрузил ${tokens.length} — открой popup`, 'попап откроется при клике на иконку');
    // Can't open popup programmatically in MV3, but we can open the options
    // page if the user prefers, or just leave a notification.
});

// Messages from content scripts — they collect picked landings and hand off here.
chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    if (msg?.type === 'stash_landings' && Array.isArray(msg.tokens)) {
        stashPending(msg.tokens).then(() => sendResponse({ ok: true }));
        return true; // async
    }
    // Generic RPC: content.js asks us to call an api.js method by name and
    // returns either { data } or { error: "human-readable message" }.
    if (msg?.type === 'api_call' && typeof msg.fn === 'string') {
        handleApiCall(msg.fn, msg.args || []).then(
            (data) => sendResponse({ data }),
            (err) => sendResponse({ error: err?.message || String(err) }),
        );
        return true; // async
    }
});

async function handleApiCall(fn, args) {
    if (typeof api[fn] !== 'function') {
        throw new Error(`Unknown API fn: ${fn}`);
    }
    return await api[fn](...args);
}

async function stashPending(tokens) {
    const existing = await chrome.storage.local.get({ pending_landings: [] });
    const merged = Array.from(new Set([...existing.pending_landings, ...tokens]));
    await chrome.storage.local.set({ pending_landings: merged });
}

function extractTokens(text) {
    const out = new Set();
    // human_id — 3-7 digit run, surrounded by non-digit boundaries.
    for (const m of text.matchAll(/\b\d{3,7}\b/g)) out.add(m[0]);
    // UUID v4-ish.
    for (const m of text.matchAll(/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/gi)) {
        out.add(m[0].toLowerCase());
    }
    return Array.from(out);
}

function notify(title, message = '') {
    try {
        chrome.notifications.create({
            type: 'basic',
            iconUrl: chrome.runtime.getURL('icons/icon-48.png'),
            title,
            message,
        });
    } catch {
        // notifications permission may not be granted — silent fail.
    }
}
