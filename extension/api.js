// Thin client over /api/ext/* endpoints. Loaded by popup + options pages.
// Background.js uses its own copy (service workers can't share modules with
// regular pages in MV3 without bundler tricks; the surface is small enough
// to duplicate).
//
// Settings live in chrome.storage.sync so they roam across the user's Chrome
// install. Token + API URL are the only two things the user has to configure.

export async function getConfig() {
    return new Promise((resolve) => {
        chrome.storage.sync.get(
            { apiUrl: '', token: '', panelHidden: false },
            resolve,
        );
    });
}

export async function setConfig(patch) {
    return new Promise((resolve) => {
        chrome.storage.sync.set(patch, resolve);
    });
}

async function request(method, path, { query, body } = {}) {
    const { apiUrl, token } = await getConfig();
    if (!apiUrl) throw new Error('API URL не указан — открой настройки расширения');
    if (!token) throw new Error('Токен не указан — открой настройки расширения');

    const url = new URL(apiUrl.replace(/\/+$/, '') + '/api/ext' + path);
    if (query) {
        for (const [k, v] of Object.entries(query)) {
            if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
        }
    }

    const headers = {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
        // ngrok-free shows an HTML interstitial for browser requests; this
        // header is the documented bypass so our JSON gets through.
        'ngrok-skip-browser-warning': '1',
    };
    if (body !== undefined) headers['Content-Type'] = 'application/json';

    const res = await fetch(url, {
        method,
        headers,
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    // Read body once, try JSON, fall back to text. We need the text on the
    // failure path to give the user something useful — e.g. ngrok's HTML
    // splash or a Cloudflare error page should surface, not become `null`.
    const raw = await res.text();
    let data = null;
    if (raw !== '') {
        try { data = JSON.parse(raw); } catch { /* not JSON */ }
    }

    if (!res.ok) {
        const msg = data?.error || data?.message || (raw && raw.length < 300 ? raw : `HTTP ${res.status}`);
        const err = new Error(msg);
        err.status = res.status;
        throw err;
    }
    if (data === null) {
        // 200 OK but the body isn't JSON — most common cause is a tunnel /
        // proxy returning an HTML page. Surface a concrete message instead
        // of letting a downstream `.display_name` access blow up.
        throw new Error(
            'Сервер вернул не JSON. ' +
            'Проверь API URL и токен. Если используешь ngrok-free — иногда нужно один раз ' +
            'открыть API URL в браузере и нажать "Visit Site".',
        );
    }
    return data;
}

export const api = {
    me: () => request('GET', '/me'),
    listGroups: () => request('GET', '/groups'),
    createGroup: (body) => request('POST', '/groups', { body }),
    updateGroup: (id, body) => request('PATCH', `/groups/${id}`, { body }),
    deleteGroup: (id) => request('DELETE', `/groups/${id}`),
    listLandings: (q) => request('GET', '/landings', { query: { q } }),
    resolve: (tokens) => request('POST', '/resolve', { body: { tokens } }),

    // ===== Campaigns — the extension's primary surface =====
    // Hand the backend a campaign human_id (or uuid) and it derives the splits
    // + MVT subscriptions itself. Re-calling is an idempotent resync.
    subscribeCampaign: (campaign) => request('POST', '/campaign', { body: { campaign } }),
    listCampaigns: () => request('GET', '/campaigns'),
    updateCampaign: (id, body) => request('PATCH', `/campaigns/${id}`, { body }),
    resyncCampaign: (id) => request('POST', `/campaigns/${id}/resync`),
    pushCampaign: (id) => request('POST', `/campaigns/${id}/push`),
    deleteCampaign: (id) => request('DELETE', `/campaigns/${id}`),
};
