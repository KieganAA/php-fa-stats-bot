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

                <!-- Schedule: every-N-hours slider OR daily-at time -->
                <div class="mt-2 space-y-1.5">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex rounded-lg overflow-hidden border border-[var(--tg-theme-section-separator-color,#e5e7eb)] text-[11px]">
                            <button
                                class="px-2 py-1"
                                :class="scheduleOf(c) === 'interval' ? 'bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)]' : 'text-[var(--tg-theme-hint-color,#6b7280)]'"
                                @click="setScheduleType(c, 'interval')"
                            >⏱ интервал</button>
                            <button
                                class="px-2 py-1"
                                :class="scheduleOf(c) === 'daily' ? 'bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)]' : 'text-[var(--tg-theme-hint-color,#6b7280)]'"
                                @click="setScheduleType(c, 'daily')"
                            >📅 ежедневно</button>
                        </div>
                        <span class="text-[10px] text-[var(--tg-theme-hint-color,#6b7280)] text-right">
                            <span v-if="c.next_push_at && !c.paused">next ~ {{ formatRelative(c.next_push_at) }}</span>
                        </span>
                    </div>

                    <div v-if="scheduleOf(c) === 'interval'" class="flex items-center gap-2">
                        <input
                            type="range"
                            min="0"
                            :max="intervalOptions.length - 1"
                            step="1"
                            :value="intervalIndex(c)"
                            @input="dragLabel[c.id] = intervalOptions[Number($event.target.value)].label"
                            @change="setInterval(c, intervalOptions[Number($event.target.value)].value)"
                            class="flex-1 accent-[var(--tg-theme-button-color,#3b82f6)]"
                        />
                        <span class="text-xs font-medium w-16 text-right">каждые {{ dragLabel[c.id] ?? intervalLabel(c.notify_interval_minutes) }}</span>
                    </div>

                    <div v-else class="flex items-center gap-2">
                        <span class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">каждый день в</span>
                        <input
                            type="time"
                            :value="c.daily_at || '10:00'"
                            @change="setDailyAt(c, $event.target.value)"
                            class="px-2 py-1 rounded text-xs bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                        />
                        <span class="text-[10px] text-[var(--tg-theme-hint-color,#6b7280)]">(твоя TZ, ±15 мин)</span>
                    </div>
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

// Live label while the user drags the slider (committed on release).
const dragLabel = ref({});

function scheduleOf(c) {
    return c.schedule_type === 'daily' ? 'daily' : 'interval';
}

function intervalIndex(c) {
    const i = intervalOptions.findIndex((o) => o.value === c.notify_interval_minutes);
    return i === -1 ? 1 : i; // default to 3ч slot for non-preset values
}

function intervalLabel(minutes) {
    return intervalOptions.find((o) => o.value === minutes)?.label
        ?? (minutes % 60 === 0 ? `${minutes / 60}ч` : `${minutes}м`);
}

async function setScheduleType(c, type) {
    if (scheduleOf(c) === type) return;
    try {
        const body = type === 'daily'
            ? { schedule_type: 'daily', daily_at: c.daily_at || '10:00' }
            : { schedule_type: 'interval' };
        const r = await api.updateCampaign(c.id, body);
        Object.assign(c, r.campaign);
        hapticImpact('light');
    } catch (e) {
        showAlert(e.message);
    }
}

async function setDailyAt(c, value) {
    if (!value) return;
    try {
        const r = await api.updateCampaign(c.id, { schedule_type: 'daily', daily_at: value });
        Object.assign(c, r.campaign);
        hapticImpact('light');
    } catch (e) {
        showAlert(e.message);
    }
}

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
        const steps = Array.isArray(r.steps) && r.steps.length ? ` · ${r.steps.join('; ')}` : '';
        subMsg.value = `✅ ${c.label || t}: ${c.splits} сплит, ${c.mvts} MVT.${steps}`;
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
    } finally {
        delete dragLabel.value[c.id]; // fall back to the committed value
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
