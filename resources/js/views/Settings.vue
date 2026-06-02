<template>
    <div class="space-y-4">
        <h2 class="text-lg font-semibold">Настройки</h2>

        <!-- Profile (read-only) -->
        <div v-if="me" class="space-y-2 p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]">
            <div class="text-xs uppercase font-medium text-[var(--tg-theme-hint-color,#6b7280)]">Профиль</div>
            <div class="text-sm">
                <div class="font-medium">{{ me.display_name }}</div>
                <div class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                    TG id: {{ me.telegram_user_id }} · внутр. #{{ me.id }}
                </div>
            </div>
        </div>

        <!-- Preferences -->
        <form v-if="me" @submit.prevent="save" class="space-y-3 p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]">
            <div class="text-xs uppercase font-medium text-[var(--tg-theme-hint-color,#6b7280)]">Общие</div>

            <label class="block">
                <span class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">Часовой пояс</span>
                <input
                    v-model="form.timezone"
                    placeholder="Europe/Moscow"
                    class="w-full mt-1 px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                />
            </label>

            <label class="block">
                <span class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">Период по умолчанию</span>
                <select
                    v-model="form.default_period"
                    class="w-full mt-1 px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                >
                    <option v-for="p in periodOptions" :key="p.value" :value="p.value">{{ p.label }}</option>
                </select>
            </label>

            <label class="block">
                <span class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">Позиция в воронке по умолчанию</span>
                <select
                    v-model.number="form.default_position"
                    class="w-full mt-1 px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                >
                    <option v-for="n in 5" :key="n" :value="n">LP{{ n }}</option>
                </select>
            </label>

            <button
                type="submit"
                class="w-full px-4 py-2 rounded-lg text-sm font-medium bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] disabled:opacity-50"
                :disabled="saving"
            >{{ saving ? 'Сохраняю…' : (saved ? '✓ Сохранено' : 'Сохранить') }}</button>
        </form>

        <!-- Landing display options -->
        <form v-if="me" @submit.prevent="saveLandingDisplay" class="space-y-2 p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]">
            <div class="text-xs uppercase font-medium text-[var(--tg-theme-hint-color,#6b7280)]">
                Отображение лендингов
            </div>
            <p class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                По умолчанию в таблицах показывается только <code>#id · страна</code>.
                Опционально добавляй тип и/или полное имя.
            </p>
            <label class="flex items-center gap-2 text-sm py-0.5">
                <input type="checkbox" v-model="landingForm.show_type" />
                <span>Показывать тип лендинга <span class="text-[var(--tg-theme-hint-color,#6b7280)]">(Celeb Preland, White 2.0…)</span></span>
            </label>
            <label class="flex items-center gap-2 text-sm py-0.5">
                <input type="checkbox" v-model="landingForm.show_name" />
                <span>Показывать полное имя лендинга</span>
            </label>
            <button
                type="submit"
                class="w-full px-4 py-2 rounded-lg text-sm font-medium bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] disabled:opacity-50"
                :disabled="savingLanding || !landingChanged"
            >{{ savingLanding ? 'Сохраняю…' : (landingSaved ? '✓ Сохранено' : 'Сохранить') }}</button>
        </form>

        <!-- Per-context metric presets -->
        <div
            v-if="me"
            ref="metricsSection"
            class="space-y-3 p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
        >
            <div class="text-xs uppercase font-medium text-[var(--tg-theme-hint-color,#6b7280)]">
                Метрики по контексту
            </div>
            <p class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                Под каждую команду — свой набор. Например <b>geo</b> 3 колонки на телефоне,
                а <b>stats</b> широкий со всеми семью.
            </p>

            <!-- Context tabs -->
            <div class="flex gap-1 overflow-x-auto p-0.5 -mx-0.5 rounded-lg bg-[var(--tg-theme-secondary-bg-color,#f3f4f6)]">
                <button
                    v-for="c in contexts"
                    :key="c.id"
                    type="button"
                    class="shrink-0 px-2.5 py-1 rounded-md text-xs transition-colors"
                    :class="activeContext === c.id
                        ? 'bg-[var(--tg-theme-bg-color,#fff)] font-medium shadow-sm'
                        : 'text-[var(--tg-theme-hint-color,#6b7280)]'"
                    @click="activeContext = c.id"
                >{{ c.label }}<span v-if="customized(c.id)" class="ml-0.5 text-[10px]">●</span></button>
            </div>

            <div class="flex items-baseline justify-between">
                <div class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                    {{ contextDescription }}
                </div>
                <button
                    v-if="customized(activeContext)"
                    type="button"
                    class="text-xs underline text-[var(--tg-theme-hint-color,#6b7280)]"
                    :disabled="savingMetrics"
                    @click="resetContext"
                >сбросить → дефолт</button>
            </div>

            <input
                v-model="metricSearch"
                placeholder="поиск по имени метрики…"
                class="w-full px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
            />

            <!-- Picked, draggable up/down. Per-name label override editable inline. -->
            <div v-if="picked.length" class="space-y-1">
                <div class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                    Выбрано ({{ picked.length }}):
                </div>
                <ul class="space-y-0.5">
                    <li
                        v-for="(name, i) in picked"
                        :key="name"
                        class="flex items-center gap-1 text-sm py-1 px-2 rounded bg-[var(--tg-theme-secondary-bg-color,#f3f4f6)]"
                    >
                        <span class="text-[10px] text-[var(--tg-theme-hint-color,#6b7280)] w-5">{{ i + 1 }}.</span>
                        <span class="flex-1 min-w-0">
                            <span class="block truncate text-[13px]">{{ name }}</span>
                            <span class="block text-[10px] text-[var(--tg-theme-hint-color,#6b7280)] truncate">
                                подпись: <i>{{ effectiveLabel(name) }}</i>
                            </span>
                        </span>
                        <span class="text-[10px] text-[var(--tg-theme-hint-color,#6b7280)] px-1.5 rounded">{{ kindOf(name) }}</span>
                        <button type="button" class="text-xs px-1 disabled:opacity-30" :disabled="i === 0" @click="move(i, -1)">↑</button>
                        <button type="button" class="text-xs px-1 disabled:opacity-30" :disabled="i === picked.length - 1" @click="move(i, +1)">↓</button>
                        <button type="button" class="text-xs px-1.5 text-red-500" @click="toggle(name)">×</button>
                    </li>
                </ul>
            </div>

            <!-- Available -->
            <div v-if="filteredAvailable.length" class="max-h-64 overflow-auto space-y-0.5 border border-[var(--tg-theme-section-separator-color,#e5e7eb)] rounded p-1">
                <button
                    v-for="m in filteredAvailable"
                    :key="m.name"
                    type="button"
                    class="w-full text-left text-sm py-1 px-2 rounded hover:bg-[var(--tg-theme-secondary-bg-color,#f3f4f6)]"
                    @click="toggle(m.name)"
                >
                    <span class="text-[var(--tg-theme-button-color,#3b82f6)]">+</span>
                    <span class="ml-2">{{ m.name }}</span>
                    <span class="text-[10px] text-[var(--tg-theme-hint-color,#6b7280)] ml-2">{{ m.kind }}</span>
                </button>
            </div>

            <button
                type="button"
                class="w-full px-4 py-2 rounded-lg text-sm font-medium bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] disabled:opacity-50"
                :disabled="savingMetrics || !contextChanged"
                @click="saveContext"
            >{{ savingMetrics ? 'Сохраняю…' : (metricsSaved ? '✓ Сохранено' : `Сохранить ${activeContextLabel} (${picked.length})`) }}</button>
        </div>

        <!-- Renamed metrics (global per-name overrides) -->
        <div v-if="me" class="space-y-2 p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]">
            <div class="flex items-baseline justify-between">
                <div class="text-xs uppercase font-medium text-[var(--tg-theme-hint-color,#6b7280)]">
                    Переименовать метрики
                </div>
                <button
                    type="button"
                    class="text-xs px-2 py-0.5 rounded border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                    @click="addLabelOverride"
                >+ добавить</button>
            </div>
            <p class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                Своя подпись метрики во всех отчётах. Пример: <code>Q Visits</code> → <i>Quals</i>.
            </p>

            <ul v-if="labelRows.length" class="space-y-1">
                <li
                    v-for="(row, i) in labelRows"
                    :key="i"
                    class="flex items-center gap-1 text-xs"
                >
                    <select
                        v-model="row.name"
                        class="flex-1 min-w-0 px-2 py-1 rounded bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                    >
                        <option value="">(выбери метрику)</option>
                        <option v-for="m in allMetrics" :key="m.name" :value="m.name">{{ m.name }}</option>
                    </select>
                    <span class="text-[var(--tg-theme-hint-color,#6b7280)]">→</span>
                    <input
                        v-model="row.label"
                        placeholder="подпись"
                        maxlength="32"
                        class="w-24 px-2 py-1 rounded bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                    />
                    <button
                        type="button"
                        class="text-xs px-1.5 text-red-500"
                        @click="labelRows.splice(i, 1)"
                    >×</button>
                </li>
            </ul>
            <p v-else class="text-xs text-[var(--tg-theme-hint-color,#6b7280)] italic">
                Нет переименований — везде используются стандартные подписи.
            </p>

            <button
                type="button"
                class="w-full px-4 py-2 rounded-lg text-sm font-medium bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] disabled:opacity-50"
                :disabled="savingLabels || !labelsChanged"
                @click="saveLabels"
            >{{ savingLabels ? 'Сохраняю…' : (labelsSaved ? '✓ Сохранено' : 'Сохранить подписи') }}</button>
        </div>

        <!-- AI keys (Phase I) -->
        <form v-if="me" @submit.prevent="saveAi" class="space-y-3 p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]">
            <div class="text-xs uppercase font-medium text-[var(--tg-theme-hint-color,#6b7280)]">
                Anthropic (для AI-ответов в чате)
            </div>
            <label class="block">
                <span class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                    API key
                    <span v-if="me.anthropic_key_hint" class="ml-1 text-emerald-500">сохранён ({{ me.anthropic_key_hint }})</span>
                </span>
                <input
                    v-model="aiForm.anthropic_api_key"
                    type="password"
                    autocomplete="off"
                    placeholder="sk-ant-…"
                    class="w-full mt-1 px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                />
            </label>
            <label class="block">
                <span class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                    Model <span class="text-[var(--tg-theme-hint-color,#6b7280)]">(пусто = {{ me.env_anthropic_model }})</span>
                </span>
                <input
                    v-model="aiForm.anthropic_model"
                    placeholder="claude-haiku-4-5-20251001"
                    class="w-full mt-1 px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                />
            </label>
            <div class="flex gap-2">
                <button
                    type="submit"
                    class="flex-1 px-4 py-2 rounded-lg text-sm font-medium bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] disabled:opacity-50"
                    :disabled="savingAi"
                >{{ savingAi ? 'Сохраняю…' : (savedAi ? '✓ Сохранено' : 'Сохранить AI') }}</button>
                <button
                    v-if="me.anthropic_key_hint"
                    type="button"
                    class="px-3 py-2 rounded-lg text-sm text-red-500 border border-red-500/30"
                    :disabled="savingAi"
                    @click="clearKey"
                >Очистить</button>
            </div>
        </form>

        <div v-if="error" class="text-sm text-red-500">{{ error }}</div>
    </div>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { api } from '../api.js';
import { hapticImpact, showConfirm } from '../telegram.js';

// Pull `?context=...` out of the hash query without depending on vue-router's
// useRoute() — that hook expects the router to be fully resolved at script
// eval time, and some Telegram WebViews seem to choke on it (resulting in a
// blank Mini App). Plain hash parsing always works.
function readContextFromHash() {
    const hash = window.location.hash || '';
    const i = hash.indexOf('?');
    if (i === -1) return null;
    const params = new URLSearchParams(hash.slice(i + 1));
    const ctx = params.get('context');
    return typeof ctx === 'string' && ctx !== '' ? ctx : null;
}

const me = ref(null);
const form = reactive({ timezone: '', default_period: '', default_position: 1 });
const aiForm = reactive({ anthropic_api_key: '', anthropic_model: '' });
const landingForm = reactive({ show_type: false, show_name: false });
const landingInitial = reactive({ show_type: false, show_name: false });
const saving = ref(false);
const saved = ref(false);
const savingAi = ref(false);
const savedAi = ref(false);
const savingLanding = ref(false);
const landingSaved = ref(false);
const error = ref(null);

const landingChanged = computed(() =>
    landingForm.show_type !== landingInitial.show_type ||
    landingForm.show_name !== landingInitial.show_name,
);

// Per-context picker state — picked is the current tab's editable copy;
// initial snapshots hold what the server returned so we can diff for "changed".
const allMetrics = ref([]);
// Honour ?context=geo (etc.) from deep-links — e.g. the "настроить метрики →"
// link on the Топы screen jumps straight to the matching tab. Falls back to
// 'stats' for unknown values so a bad query string can't break the screen.
const KNOWN_CONTEXTS = ['stats', 'compare', 'geo', 'buyers', 'lp1', 'lp2', 'mvt', 'tracking'];
const deepLinkContext = readContextFromHash();
const cameFromDeepLink = deepLinkContext !== null && KNOWN_CONTEXTS.includes(deepLinkContext);
const initialContext = cameFromDeepLink ? deepLinkContext : 'stats';
const activeContext = ref(initialContext);
const metricsSection = ref(null);
const picked = ref([]);
const pickedInitial = ref({}); // { context: list<name> }
const metricSearch = ref('');
const savingMetrics = ref(false);
const metricsSaved = ref(false);

// Per-name label overrides. labelRows is an editable {name,label} list (allows
// duplicates / empty slots while typing); save dedupes + drops empties.
const labelRows = ref([]);
const labelInitial = ref({}); // server snapshot
const savingLabels = ref(false);
const labelsSaved = ref(false);

// Period options for the "default period" dropdown. Value goes back as-is
// to the API (PeriodParser understands both en and ru forms).
const periodOptions = [
    { value: 'today', label: 'Сегодня' },
    { value: 'yesterday', label: 'Вчера' },
    { value: '24h', label: 'За 24 часа' },
    { value: '7d', label: 'За 7 дней' },
    { value: 'week', label: 'Эта неделя' },
    { value: 'month', label: 'Этот месяц' },
];

const contexts = [
    { id: 'stats', label: 'stats', desc: 'Одиночный примитив — /stats DK, бот в чате' },
    { id: 'compare', label: 'compare', desc: '/compare двух+ примитивов с Δ%' },
    { id: 'geo', label: 'geo', desc: '/geo — топ стран' },
    { id: 'buyers', label: 'buyers', desc: '/buyers — топ баеров' },
    { id: 'lp1', label: 'lp1', desc: '/lps1 — топ лендингов на LP1' },
    { id: 'lp2', label: 'lp2', desc: '/lps2 — топ лендингов на LP2' },
    { id: 'mvt', label: 'mvt', desc: '/mvt — разбивка по вариантам ленда' },
    { id: 'tracking', label: 'push', desc: '3h-пуш привязанных групп' },
];

const contextDescription = computed(() => contexts.find((c) => c.id === activeContext.value)?.desc ?? '');
const activeContextLabel = computed(() => contexts.find((c) => c.id === activeContext.value)?.label ?? activeContext.value);

const metricsByName = computed(() => {
    const out = {};
    for (const m of allMetrics.value) out[m.name] = m;
    return out;
});
const filteredAvailable = computed(() => {
    const q = metricSearch.value.trim().toLowerCase();
    const pickedSet = new Set(picked.value);
    return allMetrics.value.filter(
        (m) => !pickedSet.has(m.name) && (q === '' || m.name.toLowerCase().includes(q)),
    );
});
const contextChanged = computed(
    () => JSON.stringify(picked.value) !== JSON.stringify(pickedInitial.value[activeContext.value] ?? []),
);

// Map of name → current pending label (from labelRows). Used to preview
// what the row label will become once saved.
const pendingLabels = computed(() => {
    const out = {};
    for (const r of labelRows.value) {
        const n = (r.name || '').trim();
        const l = (r.label || '').trim();
        if (n !== '' && l !== '') out[n] = l;
    }
    return out;
});
const labelsChanged = computed(() => {
    return JSON.stringify(pendingLabels.value) !== JSON.stringify(labelInitial.value);
});

function kindOf(name) {
    return metricsByName.value[name]?.kind ?? '?';
}
function effectiveLabel(name) {
    // Live preview — pending edits win over saved overrides win over the built-in.
    return pendingLabels.value[name] ?? me.value?.metric_labels?.[name] ?? metricsByName.value[name]?.label ?? name;
}
function customized(context) {
    return !!me.value?.metric_presets?.[context]?.customized;
}

function toggle(name) {
    const idx = picked.value.indexOf(name);
    if (idx >= 0) picked.value.splice(idx, 1);
    else picked.value.push(name);
    hapticImpact('light');
}
function move(idx, delta) {
    const newIdx = idx + delta;
    if (newIdx < 0 || newIdx >= picked.value.length) return;
    const tmp = picked.value[idx];
    picked.value[idx] = picked.value[newIdx];
    picked.value[newIdx] = tmp;
}

function addLabelOverride() {
    labelRows.value.push({ name: '', label: '' });
}

async function load() {
    try {
        me.value = await api.me();
        // After me loads the per-context metrics section becomes visible.
        // Deep-link visitors land directly on that section so they don't have
        // to scroll past Profile / Preferences / Landing display first.
        if (cameFromDeepLink) {
            // Wait for the v-if="me" branch to render before scrolling.
            await nextTick();
            try {
                metricsSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } catch {
                // Older WebViews don't support smooth-scroll options — non-fatal.
            }
        }
        form.timezone = me.value.timezone;
        form.default_period = me.value.default_period;
        form.default_position = me.value.default_position;
        aiForm.anthropic_model = me.value.anthropic_model || '';
        aiForm.anthropic_api_key = '';

        const ld = me.value.settings?.landing_display ?? {};
        landingForm.show_type = !!ld.show_type;
        landingForm.show_name = !!ld.show_name;
        landingInitial.show_type = landingForm.show_type;
        landingInitial.show_name = landingForm.show_name;

        const mlist = await api.listMetrics();
        allMetrics.value = mlist.metrics;

        // Snapshot every context's name list — we'll swap into `picked` on tab switch.
        pickedInitial.value = {};
        for (const c of contexts) {
            pickedInitial.value[c.id] = me.value.metric_presets?.[c.id]?.names ?? [];
        }
        picked.value = [...(pickedInitial.value[activeContext.value] ?? [])];

        // Label overrides — initial copy + editable rows.
        labelInitial.value = { ...(me.value.metric_labels ?? {}) };
        labelRows.value = Object.entries(labelInitial.value).map(([name, label]) => ({ name, label }));
    } catch (e) {
        error.value = e.message;
    }
}

async function saveLandingDisplay() {
    savingLanding.value = true; landingSaved.value = false; error.value = null;
    try {
        const settings = { ...(me.value.settings || {}), landing_display: { ...landingForm } };
        me.value = await api.updateMe({ settings });
        landingInitial.show_type = landingForm.show_type;
        landingInitial.show_name = landingForm.show_name;
        landingSaved.value = true;
        hapticImpact('light');
    } catch (e) {
        error.value = e.message;
    } finally {
        savingLanding.value = false;
    }
}

async function save() {
    saving.value = true; saved.value = false; error.value = null;
    try {
        me.value = await api.updateMe({ ...form });
        saved.value = true; hapticImpact('light');
    } catch (e) { error.value = e.message; } finally { saving.value = false; }
}

async function saveAi() {
    savingAi.value = true; savedAi.value = false; error.value = null;
    try {
        const payload = { anthropic_model: aiForm.anthropic_model };
        if (aiForm.anthropic_api_key !== '') {
            payload.anthropic_api_key = aiForm.anthropic_api_key;
        }
        me.value = await api.updateMe(payload);
        aiForm.anthropic_api_key = '';
        aiForm.anthropic_model = me.value.anthropic_model || '';
        savedAi.value = true; hapticImpact('light');
    } catch (e) { error.value = e.message; } finally { savingAi.value = false; }
}

async function clearKey() {
    if (!(await showConfirm('Очистить личный ключ Anthropic?'))) return;
    savingAi.value = true;
    try {
        me.value = await api.updateMe({ anthropic_api_key: '' });
        hapticImpact('rigid');
    } catch (e) { error.value = e.message; } finally { savingAi.value = false; }
}

async function saveContext() {
    savingMetrics.value = true; metricsSaved.value = false; error.value = null;
    try {
        me.value = await api.setContextMetrics(activeContext.value, picked.value);
        pickedInitial.value[activeContext.value] = me.value.metric_presets?.[activeContext.value]?.names ?? [];
        picked.value = [...pickedInitial.value[activeContext.value]];
        metricsSaved.value = true;
        hapticImpact('light');
    } catch (e) { error.value = e.message; } finally { savingMetrics.value = false; }
}

async function resetContext() {
    if (!(await showConfirm(`Сбросить ${activeContext.value} к дефолтам?`))) return;
    savingMetrics.value = true; error.value = null;
    try {
        me.value = await api.setContextMetrics(activeContext.value, null);
        pickedInitial.value[activeContext.value] = me.value.metric_presets?.[activeContext.value]?.names ?? [];
        picked.value = [...pickedInitial.value[activeContext.value]];
        hapticImpact('rigid');
    } catch (e) { error.value = e.message; } finally { savingMetrics.value = false; }
}

async function saveLabels() {
    savingLabels.value = true; labelsSaved.value = false; error.value = null;
    try {
        me.value = await api.setMetricLabels(pendingLabels.value);
        labelInitial.value = { ...(me.value.metric_labels ?? {}) };
        labelRows.value = Object.entries(labelInitial.value).map(([name, label]) => ({ name, label }));
        labelsSaved.value = true;
        hapticImpact('light');
    } catch (e) { error.value = e.message; } finally { savingLabels.value = false; }
}

// Tab switch — load that context's saved picks into the editable copy.
watch(activeContext, (ctx) => {
    picked.value = [...(pickedInitial.value[ctx] ?? [])];
    metricsSaved.value = false;
    metricSearch.value = '';
});

watch([() => form.timezone, () => form.default_period, () => form.default_position], () => {
    saved.value = false;
});
watch(picked, () => { metricsSaved.value = false; }, { deep: true });
watch(labelRows, () => { labelsSaved.value = false; }, { deep: true });

onMounted(load);
</script>
