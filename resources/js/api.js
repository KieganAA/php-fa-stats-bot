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

    // Numbers
    stats: (primitive, period) =>
        request('GET', '/stats', { query: { primitive, period } }),
    compare: (primitives, period) =>
        request('GET', '/compare', { query: { primitives: primitives.join(','), period } }),
    rankings: (kind, period, topN) =>
        request('GET', '/rankings', { query: { kind, period, top_n: topN } }),
    mvt: (primitive, period) =>
        request('GET', '/mvt', { query: { primitive, period } }),

    // Tracking groups
    listGroups: () => request('GET', '/groups'),
    createGroup: (body) => request('POST', '/groups', { body }),
    updateGroup: (id, body) => request('PATCH', `/groups/${id}`, { body }),
    deleteGroup: (id) => request('DELETE', `/groups/${id}`),
};
