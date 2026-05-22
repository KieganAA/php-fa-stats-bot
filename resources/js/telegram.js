// Thin shim over window.Telegram.WebApp. When running outside Telegram
// (e.g. `npm run dev` in a browser) we expose a no-op stub so the app
// renders rather than throwing — useful for design iteration.

const tg = window.Telegram?.WebApp;

if (tg) {
    tg.ready();
    tg.expand();
}

export const initData = tg?.initData ?? '';
export const themeParams = tg?.themeParams ?? {};
export const colorScheme = tg?.colorScheme ?? 'light';
export const platform = tg?.platform ?? 'unknown';

export function hapticImpact(style = 'light') {
    tg?.HapticFeedback?.impactOccurred(style);
}

export function showAlert(message) {
    if (tg?.showAlert) {
        tg.showAlert(message);
    } else {
        window.alert(message);
    }
}

export function showConfirm(message) {
    return new Promise((resolve) => {
        if (tg?.showConfirm) {
            tg.showConfirm(message, resolve);
        } else {
            resolve(window.confirm(message));
        }
    });
}

export function closeApp() {
    tg?.close();
}

// Apply theme params as CSS custom properties on :root. The blade template
// already binds bg/text colors, but everything else (buttons, hints, etc.)
// reads from these vars.
if (typeof document !== 'undefined') {
    const root = document.documentElement;
    for (const [k, v] of Object.entries(themeParams)) {
        root.style.setProperty(`--tg-theme-${k.replace(/_/g, '-')}`, v);
    }
    root.classList.add(`scheme-${colorScheme}`);
}
