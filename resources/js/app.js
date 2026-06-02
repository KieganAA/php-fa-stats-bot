import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router.js';
import './telegram.js';

const app = createApp(App);
app.use(router);

// Don't let a runtime error in one component turn the whole Mini App into a
// blank screen — render the message into the mount node so the user (and
// /me) at least sees what blew up. Common offenders: stale browser cache
// vs. new API shape, missing migrations, container DI miss after a deploy.
function renderFatal(message, source) {
    try {
        const el = document.getElementById('app');
        if (!el) return;
        el.innerHTML = `
            <div style="padding:16px;font-family:system-ui;color:#b91c1c;">
                <h2 style="margin:0 0 8px 0;font-weight:600;">😵 Mini App упал</h2>
                <div style="margin-bottom:8px;font-size:12px;color:#6b7280;">${source ?? 'fatal'}</div>
                <pre style="white-space:pre-wrap;font-size:12px;background:#fef2f2;padding:8px;border-radius:6px;">${String(message ?? '').slice(0, 1000)}</pre>
                <div style="margin-top:12px;font-size:12px;color:#6b7280;">
                    Перезайди в мини-апп (свайп вниз + close + open). Если повторится — пришли скриншот.
                </div>
            </div>
        `;
    } catch {
        // last-resort no-op — we already failed once.
    }
}

app.config.errorHandler = (err, _instance, info) => {
    console.error('[vue]', err, info);
    renderFatal(err?.stack || err?.message || err, `vue: ${info}`);
};

window.addEventListener('error', (e) => {
    console.error('[window error]', e.error || e.message);
    renderFatal(e.error?.stack || e.message, 'window');
});
window.addEventListener('unhandledrejection', (e) => {
    console.error('[unhandled rejection]', e.reason);
    renderFatal(e.reason?.stack || e.reason?.message || e.reason, 'promise');
});

app.mount('#app');
