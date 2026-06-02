import { getConfig, setConfig, api } from '../api.js';

const $apiUrl = document.getElementById('api-url');
const $token = document.getElementById('token');
const $save = document.getElementById('save');
const $status = document.getElementById('status');
const $showPanel = document.getElementById('show-panel');

async function load() {
    const cfg = await getConfig();
    $apiUrl.value = cfg.apiUrl || '';
    $token.value = cfg.token || '';
    // panelHidden is the stored flag; the checkbox shows the inverse so the
    // label "Показывать ..." is intuitive.
    $showPanel.checked = !cfg.panelHidden;
}

// Toggle saves instantly — no need to click "Сохранить" for this one.
$showPanel.addEventListener('change', async () => {
    await setConfig({ panelHidden: !$showPanel.checked });
});

async function save() {
    const apiUrl = $apiUrl.value.trim();
    const token = $token.value.trim();
    if (!apiUrl || !token) {
        $status.textContent = 'Заполни оба поля.';
        $status.style.color = 'var(--danger)';
        return;
    }
    if (!apiUrl.startsWith('http://') && !apiUrl.startsWith('https://')) {
        $status.textContent = 'API URL должен начинаться с http:// или https://';
        $status.style.color = 'var(--danger)';
        return;
    }

    $save.disabled = true;
    $save.textContent = 'Сохраняю…';
    await setConfig({ apiUrl, token });

    // Verify the token works.
    try {
        const me = await api.me();
        const name = me?.display_name ?? 'user';
        const tz = me?.timezone ?? '?';
        $status.textContent = `✓ Подключено как ${name} (TZ ${tz}). Можно закрыть.`;
        $status.style.color = 'green';
    } catch (e) {
        $status.textContent = `❌ ${e.message}`;
        $status.style.color = 'var(--danger)';
    } finally {
        $save.disabled = false;
        $save.textContent = 'Сохранить';
    }
}

$save.addEventListener('click', save);
load();
