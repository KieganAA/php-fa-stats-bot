import { createRouter, createWebHashHistory } from 'vue-router';

const routes = [
    // Campaign subscriptions are the landing screen now.
    { path: '/', redirect: '/subs' },
    { path: '/subs', component: () => import('./views/Subs.vue') },
    { path: '/settings', component: () => import('./views/Settings.vue') },
    { path: '/help', component: () => import('./views/Help.vue') },
    // Hidden from the tab bar but still reachable by URL (legacy / future).
    { path: '/stats', component: () => import('./views/Stats.vue') },
    { path: '/top', component: () => import('./views/Top.vue') },
    { path: '/groups', redirect: '/subs' },
    { path: '/:catchAll(.*)', redirect: '/subs' },
];

export const router = createRouter({
    history: createWebHashHistory('/app'),
    routes,
});
