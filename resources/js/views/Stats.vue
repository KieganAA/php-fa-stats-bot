<template>
    <div class="space-y-4">
        <!-- Mode segmented control -->
        <div class="flex gap-1 p-0.5 rounded-lg bg-[var(--tg-theme-secondary-bg-color,#f3f4f6)]">
            <button
                v-for="m in modes"
                :key="m.value"
                type="button"
                class="flex-1 px-3 py-1.5 rounded-md text-sm transition-colors"
                :class="mode === m.value
                    ? 'bg-[var(--tg-theme-bg-color,#fff)] font-medium shadow-sm'
                    : 'text-[var(--tg-theme-hint-color,#6b7280)]'"
                @click="mode = m.value"
            >{{ m.label }}</button>
        </div>

        <!-- Single — one primitive -->
        <form v-if="mode === 'single'" @submit.prevent="run" class="space-y-2">
            <PrimitiveInput
                v-model="primitiveSingle"
                label="Примитив"
                placeholder="DK, BR, 33169, 205215…"
                @submit="run"
            />
            <PeriodPicker v-model="period" />
            <button
                type="submit"
                class="px-4 py-2 rounded-lg text-sm font-medium w-full bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] disabled:opacity-50"
                :disabled="!primitiveSingle.trim() || loading"
            >{{ loading ? 'Loading…' : 'Show' }}</button>
        </form>

        <!-- Compare — 2+ primitives -->
        <form v-else-if="mode === 'compare'" @submit.prevent="run" class="space-y-2">
            <PrimitiveInput
                v-model="primitivesCompare"
                label="Примитивы (через запятую)"
                placeholder="33169, 205215   или   DK, BR, IT"
                @submit="run"
            />
            <PeriodPicker v-model="period" />
            <button
                type="submit"
                class="px-4 py-2 rounded-lg text-sm font-medium w-full bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] disabled:opacity-50"
                :disabled="compareTokens.length < 2 || loading"
            >{{ loading ? 'Loading…' : `Compare (${compareTokens.length})` }}</button>
        </form>

        <!-- MVT — single landing variant breakdown -->
        <form v-else @submit.prevent="run" class="space-y-2">
            <PrimitiveInput
                v-model="primitiveMvt"
                label="Лендинг (human_id или uuid)"
                placeholder="33169 или a64f13e6-…"
                @submit="run"
            />
            <PeriodPicker v-model="period" />
            <button
                type="submit"
                class="px-4 py-2 rounded-lg text-sm font-medium w-full bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] disabled:opacity-50"
                :disabled="!primitiveMvt.trim() || loading"
            >{{ loading ? 'Loading…' : 'MVT breakdown' }}</button>
        </form>

        <div v-if="error" class="text-sm text-red-500">{{ error }}</div>

        <!-- Single uses native rendering of metrics for cleaner mobile look -->
        <div v-if="mode === 'single' && singleResult" class="space-y-2">
            <div class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                {{ singleResult.primitive.label }} · {{ singleResult.window.label }}
            </div>
            <table class="w-full text-sm tabular-nums">
                <tbody>
                    <tr
                        v-for="m in singleMetricsList"
                        :key="m.key"
                        class="border-t border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                    >
                        <td class="py-1.5 pr-2">{{ m.label }}</td>
                        <td class="py-1.5 pr-2 text-right font-medium">{{ m.value }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Compare / MVT — render the Telegram-HTML the server emitted; it
             already has the table layout we want -->
        <TelegramHtml v-if="mode === 'compare' && htmlResult" :html="htmlResult" />
        <TelegramHtml v-if="mode === 'mvt' && htmlResult" :html="htmlResult" />
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { api } from '../api.js';
import PrimitiveInput from '../components/PrimitiveInput.vue';
import PeriodPicker from '../components/PeriodPicker.vue';
import TelegramHtml from '../components/TelegramHtml.vue';

const modes = [
    { value: 'single', label: 'One' },
    { value: 'compare', label: 'Compare' },
    { value: 'mvt', label: 'MVT' },
];

const mode = ref('single');
const period = ref('today');
const primitiveSingle = ref('');
const primitivesCompare = ref('');
const primitiveMvt = ref('');

const singleResult = ref(null);
const htmlResult = ref(null);
const loading = ref(false);
const error = ref(null);

const compareTokens = computed(() =>
    primitivesCompare.value
        .split(/[,\s]+/)
        .map((s) => s.trim())
        .filter(Boolean),
);

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

const singleMetricsList = computed(() => {
    if (!singleResult.value) return [];
    const out = [];
    for (const key of Object.keys(LABELS)) {
        const v = singleResult.value.metrics?.[key];
        let value;
        if (v === null || v === undefined) value = '—';
        else if (RATE_KEYS.has(key)) value = Number(v).toFixed(2);
        else value = Number.isInteger(v) ? v.toLocaleString() : Number(v).toFixed(2);
        out.push({ key, label: LABELS[key], value });
    }
    return out;
});

async function run() {
    loading.value = true;
    error.value = null;
    singleResult.value = null;
    htmlResult.value = null;

    try {
        if (mode.value === 'single') {
            singleResult.value = await api.stats(primitiveSingle.value.trim(), period.value);
        } else if (mode.value === 'compare') {
            const resp = await api.compare(compareTokens.value, period.value);
            htmlResult.value = resp.html;
        } else {
            const resp = await api.mvt(primitiveMvt.value.trim(), period.value);
            htmlResult.value = resp.html;
        }
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    try {
        const me = await api.me();
        period.value = me.default_period || 'today';
    } catch {
        // dev sandbox without initData — keep defaults
    }
});
</script>
