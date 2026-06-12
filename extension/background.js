// Service worker — proxies /api/ext/* calls on behalf of the content script
// and popup. Content scripts can't import ES modules, so the shared api.js
// client lives here and callers reach it via a tiny `api_call` RPC.
//
// MV3 service workers are short-lived: don't keep state in module globals,
// rely on storage / runtime messages.

import { api } from './api.js';

// Generic RPC: a caller asks us to invoke an api.js method by name and gets
// back either { data } or { error: "human-readable message" }.
chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
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
