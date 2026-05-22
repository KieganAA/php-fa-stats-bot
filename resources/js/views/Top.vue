<template>
    <div class="space-y-4">
        <h2 class="text-lg font-semibold">Top overview</h2>

        <div class="flex gap-1.5 flex-wrap">
            <button
                v-for="k in kinds"
                :key="k.value"
                type="button"
                class="px-3 py-1.5 text-sm rounded-full transition-colors"
                :class="kind === k.value
                    ? 'bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)]'
                    : 'bg-[var(--tg-theme-secondary-bg-color,#f3f4f6)] text-[var(--tg-theme-text-color,#000)]'"
                @click="kind = k.value"
            >{{ k.label }}</button>
        </div>

        <PeriodPicker v-model="period" />

        <button
            class="px-4 py-2 rounded-lg text-sm font-medium w-full bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] disabled:opacity-50"
            :disabled="loading"
            @click="run"
        >{{ loading ? 'Loading…' : 'Refresh' }}</button>

        <div v-if="error" class="text-sm text-red-500">{{ error }}</div>

        <TelegramHtml v-if="result" :html="result.html" />
    </div>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';
import { api } from '../api.js';
import PeriodPicker from '../components/PeriodPicker.vue';
import TelegramHtml from '../components/TelegramHtml.vue';

const kinds = [
    { value: 'geo', label: '🌍 Geo' },
    { value: 'buyers', label: '👤 Buyers' },
    { value: 'lp1', label: '📄 LP1' },
    { value: 'lp2', label: '📄 LP2' },
];

const kind = ref('geo');
const period = ref('today');
const result = ref(null);
const error = ref(null);
const loading = ref(false);

async function run() {
    loading.value = true;
    error.value = null;
    try {
        result.value = await api.rankings(kind.value, period.value, 15);
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

// Auto-refresh whenever kind/period changes — keeps the screen reactive.
watch([kind, period], () => run());

onMounted(run);
</script>
