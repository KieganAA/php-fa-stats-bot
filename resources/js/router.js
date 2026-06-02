import { createRouter, createWebHashHistory } from 'vue-router';

const routes = [
    { path: '/', redirect: '/stats' },
    { path: '/stats', component: () => import('./views/Stats.vue') },
    { path: '/top', component: () => import('./views/Top.vue') },
    { path: '/subs', component: () => import('./views/Subs.vue') },
    // Legacy alias — old keyboard shortcuts still land on the new screen.
    { path: '/groups', redirect: '/subs' },
    { path: '/settings', component: () => import('./views/Settings.vue') },
    { path: '/help', component: () => import('./views/Help.vue') },
    { path: '/:catchAll(.*)', redirect: '/stats' },
];

export const router = createRouter({
    history: createWebHashHistory('/app'),
    routes,
});
