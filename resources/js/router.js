import { createRouter, createWebHashHistory } from 'vue-router';

const routes = [
    { path: '/', redirect: '/stats' },
    { path: '/stats', component: () => import('./views/Stats.vue') },
    { path: '/top', component: () => import('./views/Top.vue') },
    { path: '/groups', component: () => import('./views/Groups.vue') },
    { path: '/settings', component: () => import('./views/Settings.vue') },
    { path: '/:catchAll(.*)', redirect: '/stats' },
];

export const router = createRouter({
    history: createWebHashHistory('/app'),
    routes,
});
