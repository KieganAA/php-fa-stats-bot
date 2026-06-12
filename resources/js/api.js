import { initData } from './telegram.js';

const BASE = '/api/v1';

async function request(method, path, { query, body } = {}) {
    const url = new URL(BASE + path, window.location.origin);
    if (query) {
        for (const [k, v] of Object.entries(query)) {
            if (v !== undefined && v !== null && v !== '') {
                url.searchParams.set(k, v);
            }
        }
    }

    const headers = { Accept: 'application/json' };
    if (initData) {
        headers.Authorization = `tma ${initData}`;
    }
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    const res = await fetch(url, {
        method,
        headers,
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    let data = null;
    try {
        data = await res.json();
    } catch {
        // empty body
    }

    if (!res.ok) {
        const message = data?.error || data?.message || `HTTP ${res.status}`;
        const err = new Error(message);
        err.status = res.status;
        err.data = data;
        throw err;
    }

    return data;
}

export const api = {
    // Profile / settings
    me: () => request('GET', '/me'),
    updateMe: (body) => request('PATCH', '/me', { body }),
    // Legacy single-key — sets the stats-context preset.
    setMetrics: (names) => request('PUT', '/me/metrics', { body: { metrics: names } }),
    // Per-context preset (stats, compare, geo, buyers, lp1, lp2, mvt, tracking).
    setContextMetrics: (context, names) =>
        request('PUT', `/me/metrics/${context}`, { body: { metrics: names } }),
    // Per-name display label overrides (apply across every context).
    setMetricLabels: (labels) => request('PUT', '/me/metric-labels', { body: { labels } }),
    listMetrics: (q) => request('GET', '/metrics', { query: { q } }),

    // Numbers
    stats: (primitive, period) =>
        request('GET', '/stats', { query: { primitive, period } }),
    compare: (primitives, period) =>
        request('GET', '/compare', { query: { primitives: primitives.join(','), period } }),
    rankings: (kind, period, topN) =>
        request('GET', '/rankings', { query: { kind, period, top_n: topN } }),
    mvt: (primitive, period) =>
        request('GET', '/mvt', { query: { primitive, period } }),

    // Campaign subscriptions — the Mini App's primary surface.
    listCampaigns: () => request('GET', '/campaigns'),
    subscribeCampaign: (campaign) => request('POST', '/campaigns', { body: { campaign } }),
    updateCampaign: (id, body) => request('PATCH', `/campaigns/${id}`, { body }),
    resyncCampaign: (id) => request('POST', `/campaigns/${id}/resync`),
    pushCampaign: (id) => request('POST', `/campaigns/${id}/push`),
    deleteCampaign: (id) => request('DELETE', `/campaigns/${id}`),

    // Legacy landing subscriptions (server calls them "groups"). Kept for the
    // hidden /subs-legacy surface; campaign-first UI no longer uses them.
    listGroups: () => request('GET', '/groups'),
    createGroup: (body) => request('POST', '/groups', { body }),
    updateGroup: (id, body) => request('PATCH', `/groups/${id}`, { body }),
    deleteGroup: (id) => request('DELETE', `/groups/${id}`),

    // Landing autocomplete for the picker. Empty `q` returns recent landings.
    listLandings: (q) => request('GET', '/landings', { query: { q } }),
};
