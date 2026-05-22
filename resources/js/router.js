import { createRouter, createWebHashHistory } from 'vue-router';

// Hash history keeps routing entirely client-side — no server config needed
// for /app/stats vs /app/aliases. Path-based routing would require either
// catch-all routes on Laravel or a separate sub-app per screen.
const routes = [
    { path: '/', redirect: '/stats' },
    { path: '/stats', component: () => import('./views/Stats.vue') },
    { path: '/compare', component: () => import('./views/Compare.vue') },
    { path: '/aliases', component: () => import('./views/Aliases.vue') },
    { path: '/bindings', component: () => import('./views/Bindings.vue') },
    { path: '/settings', component: () => import('./views/Settings.vue') },
];

export const router = createRouter({
    history: createWebHashHistory('/app'),
    routes,
});
