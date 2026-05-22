<template>
    <div class="space-y-4">
        <h2 class="text-lg font-semibold">Stats</h2>

        <form @submit.prevent="fetchStats" class="space-y-2">
            <label class="block">
                <span class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">Примитив</span>
                <input
                    v-model="primitive"
                    placeholder="DK, BR, IT, US, …"
                    autocapitalize="characters"
                    autocomplete="off"
                    class="w-full mt-1 px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                />
            </label>

            <div>
                <span class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">Период</span>
                <div class="flex flex-wrap gap-1.5 mt-1">
                    <button
                        v-for="p in periods"
                        :key="p.value"
                        type="button"
                        class="px-3 py-1 text-xs rounded-full border transition-colors"
                        :class="period === p.value
                            ? 'bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] border-transparent'
                            : 'border-[var(--tg-theme-section-separator-color,#e5e7eb)] text-[var(--tg-theme-text-color,#000)]'"
                        @click="period = p.value"
                    >{{ p.label }}</button>
                </div>
            </div>

            <button
                type="submit"
                class="px-4 py-2 rounded-lg text-sm font-medium w-full bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] disabled:opacity-50"
                :disabled="!primitive.trim() || loading"
            >{{ loading ? 'Loading…' : 'Показать' }}</button>
        </form>

        <div v-if="error" class="text-sm text-red-500">{{ error }}</div>

        <div v-if="data" class="space-y-2">
            <div class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                {{ data.primitive.label }} · {{ data.window.label }}
            </div>
            <table class="w-full text-sm tabular-nums">
                <tbody>
                    <tr
                        v-for="m in metricsList(data)"
                        :key="m.key"
                        class="border-t border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                    >
                        <td class="py-1.5 pr-2">{{ m.label }}</td>
                        <td class="py-1.5 pr-2 text-right font-medium">{{ m.value }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../api.js';

const primitive = ref('');
const period = ref('today');
const data = ref(null);
const error = ref(null);
const loading = ref(false);

const periods = [
    { value: 'today', label: 'Today' },
    { value: 'yesterday', label: 'Yesterday' },
    { value: '24h', label: '24h' },
    { value: '7d', label: '7d' },
    { value: 'week', label: 'Week' },
    { value: 'month', label: 'Month' },
];

const LABELS = {
    clicks: 'clicks',
    lp_ctr: 'LP CTR',
    leads: 'leads',
    ftds_real: 'FTDs',
    real_cr: 'CR%',
    interest_rate: 'interest',
    scrolling: 'scroll',
};
const RATE_KEYS = new Set(['lp_ctr', 'real_cr', 'interest_rate', 'scrolling']);

function metricsList(payload) {
    const out = [];
    for (const key of Object.keys(LABELS)) {
        const v = payload.metrics?.[key];
        let value;
        if (v === null || v === undefined) value = '—';
        else if (RATE_KEYS.has(key)) value = Number(v).toFixed(2);
        else value = Number.isInteger(v) ? v.toLocaleString() : Number(v).toFixed(2);
        out.push({ key, label: LABELS[key], value });
    }
    return out;
}

async function fetchStats() {
    loading.value = true;
    error.value = null;
    try {
        data.value = await api.stats(primitive.value.trim(), period.value);
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    // Pull the user's default period as a starting choice.
    try {
        const me = await api.me();
        period.value = me.default_period || 'today';
    } catch {
        // /me failed (likely missing initData in dev) — fine, keep defaults.
    }
});
</script>
