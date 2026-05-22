<template>
    <div class="space-y-4">
        <h2 class="text-lg font-semibold">Settings</h2>

        <!-- Profile (read-only) -->
        <div v-if="me" class="space-y-2 p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]">
            <div class="text-xs uppercase font-medium text-[var(--tg-theme-hint-color,#6b7280)]">Profile</div>
            <div class="text-sm">
                <div class="font-medium">{{ me.display_name }}</div>
                <div class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                    TG id: {{ me.telegram_user_id }} · internal #{{ me.id }}
                </div>
            </div>
        </div>

        <!-- Preferences -->
        <form v-if="me" @submit.prevent="save" class="space-y-3 p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]">
            <div class="text-xs uppercase font-medium text-[var(--tg-theme-hint-color,#6b7280)]">Preferences</div>

            <label class="block">
                <span class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">Timezone</span>
                <input
                    v-model="form.timezone"
                    placeholder="UTC"
                    class="w-full mt-1 px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                />
            </label>

            <label class="block">
                <span class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">Default period</span>
                <select
                    v-model="form.default_period"
                    class="w-full mt-1 px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                >
                    <option v-for="p in periods" :key="p" :value="p">{{ p }}</option>
                </select>
            </label>

            <label class="block">
                <span class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">Default position</span>
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
            >{{ saving ? 'Saving…' : (saved ? '✓ Saved' : 'Save') }}</button>
        </form>

        <!-- Metric picker -->
        <div v-if="me" class="space-y-3 p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]">
            <div class="flex items-baseline justify-between">
                <div class="text-xs uppercase font-medium text-[var(--tg-theme-hint-color,#6b7280)]">
                    Метрики в отчётах
                </div>
                <button
                    v-if="me.metrics_customized"
                    type="button"
                    class="text-xs underline text-[var(--tg-theme-hint-color,#6b7280)]"
                    @click="resetMetrics"
                    :disabled="savingMetrics"
                >reset</button>
            </div>

            <p class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                Что показывать в /stats, /compare, /bind пушах. Если выбрано
                ничего — используются дефолты.
            </p>

            <input
                v-model="metricSearch"
                placeholder="поиск по имени метрики…"
                class="w-full px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
            />

            <!-- Selected: ordered, draggable up/down -->
            <div v-if="picked.length" class="space-y-1">
                <div class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">Выбрано ({{ picked.length }}):</div>
                <ul class="space-y-0.5">
                    <li
                        v-for="(name, i) in picked"
                        :key="name"
                        class="flex items-center gap-1 text-sm py-1 px-2 rounded bg-[var(--tg-theme-secondary-bg-color,#f3f4f6)]"
                    >
                        <span class="text-[10px] text-[var(--tg-theme-hint-color,#6b7280)] w-5">{{ i + 1 }}.</span>
                        <span class="flex-1 truncate">{{ name }}</span>
                        <span class="text-[10px] text-[var(--tg-theme-hint-color,#6b7280)] px-1.5 rounded">{{ kindOf(name) }}</span>
                        <button type="button" class="text-xs px-1 disabled:opacity-30" :disabled="i === 0" @click="move(i, -1)">↑</button>
                        <button type="button" class="text-xs px-1 disabled:opacity-30" :disabled="i === picked.length - 1" @click="move(i, +1)">↓</button>
                        <button type="button" class="text-xs px-1.5 text-red-500" @click="toggle(name)">×</button>
                    </li>
                </ul>
            </div>

            <!-- Available: filtered by search -->
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
                :disabled="savingMetrics || !metricsChanged"
                @click="saveMetrics"
            >{{ savingMetrics ? 'Saving…' : (metricsSaved ? '✓ Saved' : `Save metrics (${picked.length})`) }}</button>
        </div>

        <!-- AI keys (Phase I) -->
        <form v-if="me" @submit.prevent="saveAi" class="space-y-3 p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]">
            <div class="text-xs uppercase font-medium text-[var(--tg-theme-hint-color,#6b7280)]">
                Anthropic (для /ai)
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
                >{{ savingAi ? 'Saving…' : (savedAi ? '✓ Saved' : 'Save AI settings') }}</button>
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
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { api } from '../api.js';
import { hapticImpact, showConfirm } from '../telegram.js';

const me = ref(null);
const form = reactive({ timezone: '', default_period: '', default_position: 1 });
const aiForm = reactive({ anthropic_api_key: '', anthropic_model: '' });
const saving = ref(false);
const saved = ref(false);
const savingAi = ref(false);
const savedAi = ref(false);
const error = ref(null);

// Metric picker state
const allMetrics = ref([]);
const picked = ref([]);
const pickedInitial = ref([]);
const metricSearch = ref('');
const savingMetrics = ref(false);
const metricsSaved = ref(false);

const periods = ['today', 'yesterday', '24h', '7d', 'week', 'month'];

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
const metricsChanged = computed(
    () => JSON.stringify(picked.value) !== JSON.stringify(pickedInitial.value),
);

function kindOf(name) {
    return metricsByName.value[name]?.kind ?? '?';
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

async function load() {
    try {
        me.value = await api.me();
        form.timezone = me.value.timezone;
        form.default_period = me.value.default_period;
        form.default_position = me.value.default_position;
        aiForm.anthropic_model = me.value.anthropic_model || '';
        aiForm.anthropic_api_key = '';

        const m = await api.listMetrics();
        allMetrics.value = m.metrics;

        picked.value = me.value.metrics.map((x) => x.name);
        pickedInitial.value = [...picked.value];
    } catch (e) {
        error.value = e.message;
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

async function saveMetrics() {
    savingMetrics.value = true; metricsSaved.value = false; error.value = null;
    try {
        me.value = await api.setMetrics(picked.value);
        pickedInitial.value = [...picked.value];
        metricsSaved.value = true;
        hapticImpact('light');
    } catch (e) { error.value = e.message; } finally { savingMetrics.value = false; }
}

async function resetMetrics() {
    if (!(await showConfirm('Сбросить к дефолтам?'))) return;
    savingMetrics.value = true; error.value = null;
    try {
        me.value = await api.setMetrics(null);
        picked.value = me.value.metrics.map((x) => x.name);
        pickedInitial.value = [...picked.value];
        hapticImpact('rigid');
    } catch (e) { error.value = e.message; } finally { savingMetrics.value = false; }
}

watch([() => form.timezone, () => form.default_period, () => form.default_position], () => {
    saved.value = false;
});
watch(picked, () => { metricsSaved.value = false; }, { deep: true });

onMounted(load);
</script>
