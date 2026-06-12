<template>
    <div class="space-y-4">
        <h2 class="text-lg font-semibold">🔔 Подписки на кампании</h2>
        <p class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
            Подпишись на кампанию — бот сам найдёт сплиты и MVT внутри и будет
            слать по ним пуши каждые <i>N часов</i>.
        </p>

        <!-- Subscribe by human_id -->
        <div class="space-y-2 p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]">
            <div class="text-xs uppercase font-medium text-[var(--tg-theme-hint-color,#6b7280)]">
                Подписаться
            </div>
            <div class="flex gap-2">
                <input
                    v-model="token"
                    inputmode="numeric"
                    placeholder="human_id кампании, напр. 036469"
                    autocomplete="off"
                    spellcheck="false"
                    @keydown.enter="subscribe"
                    class="flex-1 min-w-0 px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                />
                <button
                    type="button"
                    class="px-4 py-2 rounded-lg text-sm font-medium bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] disabled:opacity-50 whitespace-nowrap"
                    :disabled="subscribing || !token.trim()"
                    @click="subscribe"
                >{{ subscribing ? '⏳' : 'Подписать' }}</button>
            </div>
            <div v-if="subMsg" :class="subMsgKind === 'error' ? 'text-red-500' : 'text-green-600'" class="text-xs">
                {{ subMsg }}
            </div>
            <p class="text-[10px] text-[var(--tg-theme-hint-color,#6b7280)]">
                💡 Ещё проще — жми 🔔 у кампании прямо на странице AIO (через расширение).
            </p>
        </div>

        <!-- Campaign list -->
        <div v-if="loading && !campaigns.length" class="text-sm">Загружаю…</div>
        <div v-else-if="!campaigns.length" class="text-sm text-[var(--tg-theme-hint-color,#6b7280)]">
            Подписок на кампании пока нет.
        </div>
        <ul v-else class="space-y-3">
            <li
                v-for="c in campaigns"
                :key="c.id"
                class="p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                :class="c.paused ? 'opacity-70' : ''"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <code class="text-xs font-semibold">{{ c.label }}</code>
                        <div class="text-xs text-[var(--tg-theme-hint-color,#6b7280)] truncate">{{ c.name }}</div>
                    </div>
                    <div class="flex gap-1 shrink-0">
                        <button class="text-sm px-1.5 py-1 rounded disabled:opacity-40" title="Пушнуть отчёты сейчас (debug)" :disabled="busyId === c.id || c.paused" @click="pushNow(c)">📤</button>
                        <button class="text-sm px-1.5 py-1 rounded" :title="c.paused ? 'Возобновить' : 'Пауза'" @click="togglePause(c)">{{ c.paused ? '▶️' : '⏸' }}</button>
                        <button class="text-sm px-1.5 py-1 rounded disabled:opacity-40" title="Пересобрать (resync)" :disabled="busyId === c.id" @click="resync(c)">🔄</button>
                        <button class="text-sm px-1.5 py-1 rounded text-red-500" title="Удалить" @click="confirmDelete(c)">🗑</button>
                    </div>
                </div>

                <div class="flex flex-wrap gap-1.5 mt-2">
                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-[var(--tg-theme-secondary-bg-color,#f3f4f6)]">{{ c.splits }} сплит</span>
                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-[var(--tg-theme-secondary-bg-color,#f3f4f6)]">{{ c.mvts }} MVT</span>
                    <span v-if="c.paused" class="text-[10px] px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">⏸ пауза</span>
                    <span v-if="c.orphans > 0" class="text-[10px] px-2 py-0.5 rounded-full bg-red-100 text-red-600">⚠️ {{ c.orphans }} пропал</span>
                </div>

                <div class="flex items-center justify-between gap-2 mt-2">
                    <label class="text-xs text-[var(--tg-theme-hint-color,#6b7280)] flex items-center gap-1">
                        ⏱ каждые
                        <select
                            :value="c.notify_interval_minutes"
                            @change="setInterval(c, Number($event.target.value))"
                            class="px-1.5 py-1 rounded text-xs bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                        >
                            <option v-for="i in intervalOptions" :key="i.value" :value="i.value">{{ i.label }}</option>
                        </select>
                    </label>
                    <span class="text-[10px] text-[var(--tg-theme-hint-color,#6b7280)] text-right">
                        <span v-if="c.next_push_at && !c.paused">next ~ {{ formatRelative(c.next_push_at) }}</span>
                    </span>
                </div>

                <details v-if="c.children && c.children.length" class="mt-2">
                    <summary class="text-xs text-[var(--tg-theme-hint-color,#6b7280)] cursor-pointer select-none">
                        {{ c.children.length }} подписок внутри
                    </summary>
                    <ul class="mt-1 space-y-0.5 text-xs">
                        <li
                            v-for="ch in c.children"
                            :key="ch.id"
                            :class="ch.orphaned ? 'text-red-500' : 'text-[var(--tg-theme-hint-color,#6b7280)]'"
                        >
                            {{ ch.mode === 'mvt' ? '🧬' : '🔀' }} {{ stripPrefix(ch.name) }}
                            <span class="text-[10px]">{{ landingIds(ch) }}</span>
                            <span v-if="ch.orphaned">⚠</span>
                        </li>
                    </ul>
                </details>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../api.js';
import { hapticImpact, showAlert, showConfirm } from '../telegram.js';

const campaigns = ref([]);
const loading = ref(false);
const token = ref('');
const subscribing = ref(false);
const subMsg = ref('');
const subMsgKind = ref('');
const busyId = ref(null);

const intervalOptions = [
    { value: 60, label: '1ч' },
    { value: 180, label: '3ч' },
    { value: 360, label: '6ч' },
    { value: 720, label: '12ч' },
    { value: 1440, label: '24ч' },
];

async function load() {
    loading.value = true;
    try {
        const r = await api.listCampaigns();
        campaigns.value = r.campaigns || [];
    } catch (e) {
        showAlert(e.message);
    } finally {
        loading.value = false;
    }
}

async function subscribe() {
    const t = token.value.trim();
    if (!t || subscribing.value) return;
    subscribing.value = true;
    subMsg.value = '';
    try {
        const r = await api.subscribeCampaign(t);
        const c = r.campaign || {};
        token.value = '';
        subMsgKind.value = 'ok';
        subMsg.value = `✅ ${c.label || t}: ${c.splits} сплит, ${c.mvts} MVT.`;
        hapticImpact('light');
        await load();
    } catch (e) {
        subMsgKind.value = 'error';
        subMsg.value = e.message;
    } finally {
        subscribing.value = false;
    }
}

async function togglePause(c) {
    try {
        const r = await api.updateCampaign(c.id, { paused: !c.paused });
        Object.assign(c, r.campaign);
        hapticImpact('light');
    } catch (e) {
        showAlert(e.message);
    }
}

async function setInterval(c, minutes) {
    try {
        const r = await api.updateCampaign(c.id, { notify_interval_minutes: minutes });
        Object.assign(c, r.campaign);
        hapticImpact('light');
    } catch (e) {
        showAlert(e.message);
    }
}

async function pushNow(c) {
    busyId.value = c.id;
    try {
        const r = await api.pushCampaign(c.id);
        hapticImpact('light');
        await showAlert(`📤 ${c.label}: отправляю ${r.dispatched} отчёт(а) — смотри чат с ботом.`);
    } catch (e) {
        showAlert(e.message);
    } finally {
        busyId.value = null;
    }
}

async function resync(c) {
    busyId.value = c.id;
    try {
        const r = await api.resyncCampaign(c.id);
        Object.assign(c, r.campaign);
        const ch = r.changed || {};
        const parts = [];
        if (ch.created) parts.push(`+${ch.created}`);
        if (ch.reactivated) parts.push(`♻${ch.reactivated}`);
        if (ch.orphaned) parts.push(`⚠${ch.orphaned}`);
        hapticImpact('light');
        if (parts.length) await showAlert(`${c.label}: ${parts.join(' ')}`);
    } catch (e) {
        showAlert(e.message);
    } finally {
        busyId.value = null;
    }
}

async function confirmDelete(c) {
    if (!(await showConfirm(`Удалить подписку на ${c.label}?\n${c.name || ''}`))) return;
    try {
        await api.deleteCampaign(c.id);
        hapticImpact('rigid');
        await load();
    } catch (e) {
        showAlert(e.message);
    }
}

function stripPrefix(name) {
    const i = (name || '').indexOf('· ');
    return i !== -1 ? name.slice(i + 2).trim() : (name || '');
}

function landingIds(ch) {
    return (ch.landings || [])
        .map((l) => (l.human_id != null ? `#${l.human_id}` : (l.uuid || '').slice(0, 6)))
        .join(', ');
}

function formatRelative(iso) {
    try {
        const d = new Date(iso);
        const diffMs = d - new Date();
        const future = diffMs > 0;
        const mins = Math.round(Math.abs(diffMs) / 60000);
        if (mins < 1) return future ? 'сейчас' : 'только что';
        if (mins < 60) return future ? `через ${mins}м` : `${mins}м назад`;
        const h = Math.round(mins / 60);
        if (h < 24) return future ? `через ${h}ч` : `${h}ч назад`;
        return d.toLocaleDateString();
    } catch {
        return iso;
    }
}

onMounted(load);
</script>
