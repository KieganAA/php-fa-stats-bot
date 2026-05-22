<template>
    <div class="space-y-4">
        <h2 class="text-lg font-semibold">Stats</h2>

        <div class="space-y-2">
            <AliasPicker v-model="alias" :aliases="aliases" />
            <PeriodPicker v-model="period" />
            <button
                class="px-4 py-2 rounded-lg text-sm font-medium w-full bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] disabled:opacity-50"
                :disabled="!alias || loading"
                @click="fetchStats"
            >{{ loading ? 'Loading…' : 'Show metrics' }}</button>
        </div>

        <div v-if="error" class="text-sm text-red-500">{{ error }}</div>

        <div v-if="data" class="space-y-2">
            <div class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                {{ data.window.label }} · {{ data.landing.name }} (LP{{ data.position }})
            </div>
            <MetricsTable :columns="columnsFor(data)" />
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../api.js';
import { useAsync } from '../composables/useAsync.js';
import AliasPicker from '../components/AliasPicker.vue';
import PeriodPicker from '../components/PeriodPicker.vue';
import MetricsTable from '../components/MetricsTable.vue';

const aliases = ref([]);
const alias = ref('');
const period = ref('today');

const { data, error, loading, run } = useAsync(() => api.stats(alias.value, period.value));

async function fetchStats() {
    try { await run(); } catch { /* error surfaced via error.value */ }
}

function columnsFor(payload) {
    return [{ key: 'current', label: payload.alias || payload.landing.name, values: payload.metrics }];
}

onMounted(async () => {
    try {
        const me = await api.me();
        period.value = me.default_period || 'today';
        const r = await api.listAliases();
        aliases.value = r.aliases;
    } catch (e) {
        error.value = e.message;
    }
});
</script>
