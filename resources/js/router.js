import { createRouter, createWebHashHistory } from 'vue-router';

// Hash history keeps routing entirely client-side — no server config needed
// for /app/stats vs /app/settings. Phase K trims down to two screens; the
// rest of the surface (compare, bindings, alias-style favourites) returns
// in phase N once the new data model is settled.
const routes = [
    { path: '/', redirect: '/stats' },
    { path: '/stats', component: () => import('./views/Stats.vue') },
    { path: '/settings', component: () => import('./views/Settings.vue') },
    { path: '/:catchAll(.*)', redirect: '/stats' },
];

export const router = createRouter({
    history: createWebHashHistory('/app'),
    routes,
});
