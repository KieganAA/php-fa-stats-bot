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

    const headers = {
        Accept: 'application/json',
    };
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
        // empty body — fine
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
    me: () => request('GET', '/me'),
    updateMe: (body) => request('PATCH', '/me', { body }),

    listAliases: () => request('GET', '/aliases'),
    createAlias: (body) => request('POST', '/aliases', { body }),
    deleteAlias: (id) => request('DELETE', `/aliases/${id}`),

    stats: (alias, period) => request('GET', '/stats', { query: { alias, period } }),
    compare: (aliases, period) =>
        request('GET', '/compare', { query: { aliases: aliases.join(','), period } }),

    listBindings: () => request('GET', '/bindings'),
    createBinding: (body) => request('POST', '/bindings', { body }),
    updateBinding: (id, body) => request('PATCH', `/bindings/${id}`, { body }),
    deleteBinding: (id) => request('DELETE', `/bindings/${id}`),
    bindingLatest: (id) => request('GET', `/bindings/${id}/latest`),
};
