<template>
    <div class="space-y-4">
        <h2 class="text-lg font-semibold">Bindings</h2>

        <form @submit.prevent="createBinding" class="space-y-2 p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]">
            <div class="text-xs font-medium uppercase text-[var(--tg-theme-hint-color,#6b7280)]">Bind a landing</div>
            <AliasPicker v-model="form.alias" :aliases="aliases" />
            <div class="flex items-center gap-4 text-sm">
                <label class="flex items-center gap-2">
                    <input type="checkbox" v-model="form.notify_3h" /> Notify 3h
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" v-model="form.notify_since_start" /> Notify start
                </label>
            </div>
            <button
                type="submit"
                class="w-full px-4 py-2 rounded-lg text-sm font-medium bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] disabled:opacity-50"
                :disabled="!form.alias || creating"
            >{{ creating ? 'Binding…' : 'Bind' }}</button>
            <div v-if="formError" class="text-xs text-red-500">{{ formError }}</div>
        </form>

        <div v-if="loadingList" class="text-sm">Loading…</div>
        <div v-else-if="!bindings.length" class="text-sm text-[var(--tg-theme-hint-color,#6b7280)]">
            No bindings yet — bind one above.
        </div>
        <ul v-else class="space-y-3">
            <li
                v-for="b in bindings"
                :key="b.id"
                class="p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="font-medium text-sm truncate">
                            {{ b.landing_name || b.landing_uuid }}
                            <span class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">LP{{ b.position }}</span>
                        </div>
                        <div v-if="b.paused" class="text-xs text-amber-500">paused</div>
                    </div>
                    <button class="text-xs px-2 py-1 rounded text-red-500" @click="confirmDelete(b)">Unbind</button>
                </div>

                <div class="flex items-center gap-4 mt-2 text-xs">
                    <label class="flex items-center gap-1.5">
                        <input
                            type="checkbox"
                            :checked="b.notify_3h"
                            @change="toggle(b, 'notify_3h', $event.target.checked)"
                        /> 3h
                    </label>
                    <label class="flex items-center gap-1.5">
                        <input
                            type="checkbox"
                            :checked="b.notify_since_start"
                            @change="toggle(b, 'notify_since_start', $event.target.checked)"
                        /> start
                    </label>
                    <button class="ml-auto text-xs underline" @click="showLatest(b)">Latest →</button>
                </div>

                <div
                    v-if="latestById[b.id]"
                    class="mt-3 pt-3 border-t border-[var(--tg-theme-section-separator-color,#e5e7eb)] text-xs space-y-1"
                >
                    <div class="text-[var(--tg-theme-hint-color,#6b7280)]">
                        {{ latestById[b.id].snapshot ? `${latestById[b.id].snapshot.window_start.slice(0,16).replace('T',' ')} – ${latestById[b.id].snapshot.window_end.slice(11,16)}` : 'No snapshot yet.' }}
                    </div>
                    <div
                        v-for="row in renderMetrics(latestById[b.id])"
                        :key="row.key"
                        class="flex justify-between"
                    >
                        <span>{{ row.label }}</span>
                        <span class="font-medium tabular-nums">{{ row.value }} <span class="text-[var(--tg-theme-hint-color,#6b7280)]">{{ row.delta }}</span></span>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { api } from '../api.js';
import { showConfirm, showAlert, hapticImpact } from '../telegram.js';
import AliasPicker from '../components/AliasPicker.vue';

const aliases = ref([]);
const bindings = ref([]);
const latestById = reactive({});
const loadingList = ref(false);
const creating = ref(false);
const formError = ref(null);
const form = reactive({ alias: '', notify_3h: true, notify_since_start: false });

async function load() {
    loadingList.value = true;
    try {
        const [a, b] = await Promise.all([api.listAliases(), api.listBindings()]);
        aliases.value = a.aliases;
        bindings.value = b.bindings;
    } catch (e) {
        showAlert(e.message);
    } finally {
        loadingList.value = false;
    }
}

async function createBinding() {
    creating.value = true;
    formError.value = null;
    try {
        await api.createBinding({
            alias: form.alias,
            notify_3h: form.notify_3h,
            notify_since_start: form.notify_since_start,
        });
        hapticImpact('light');
        form.alias = '';
        await load();
    } catch (e) {
        formError.value = e.message;
    } finally {
        creating.value = false;
    }
}

async function confirmDelete(b) {
    if (!(await showConfirm(`Unbind "${b.landing_name || b.landing_uuid}"?`))) return;
    try {
        await api.deleteBinding(b.id);
        hapticImpact('rigid');
        await load();
    } catch (e) {
        showAlert(e.message);
    }
}

async function toggle(b, field, value) {
    try {
        await api.updateBinding(b.id, { [field]: value });
        b[field] = value;
    } catch (e) {
        showAlert(e.message);
        await load();
    }
}

async function showLatest(b) {
    try {
        latestById[b.id] = await api.bindingLatest(b.id);
    } catch (e) {
        showAlert(e.message);
    }
}

const RATE_KEYS = new Set(['lp_ctr', 'real_cr', 'interest_rate', 'scrolling']);
const LABELS = {
    clicks: 'clicks',
    lp_ctr: 'LP CTR',
    leads: 'leads',
    ftds_real: 'FTDs',
    real_cr: 'CR%',
    interest_rate: 'interest',
    scrolling: 'scroll',
};

function renderMetrics(payload) {
    if (!payload?.snapshot) return [];
    const out = [];
    for (const key of Object.keys(LABELS)) {
        const v = payload.snapshot.metrics?.[key];
        if (v === undefined) continue;
        const d = payload.delta?.[key];
        let delta = '';
        if (d?.pct !== null && d?.pct !== undefined) {
            const pct = d.pct * 100;
            const sign = pct > 0 ? '+' : '';
            delta = `Δ ${sign}${pct.toFixed(1)}%`;
        }
        const formatted = v === null ? '—' : RATE_KEYS.has(key) ? Number(v).toFixed(2) : Number(v).toLocaleString();
        out.push({ key, label: LABELS[key], value: formatted, delta });
    }
    return out;
}

onMounted(load);
</script>
