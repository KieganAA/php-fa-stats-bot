<template>
    <div class="space-y-4">
        <h2 class="text-lg font-semibold">Compare</h2>

        <div class="space-y-2">
            <div class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                Pick 2+ aliases on the same LP position.
            </div>
            <div class="space-y-1 max-h-48 overflow-auto p-2 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]">
                <label
                    v-for="a in aliases"
                    :key="a.id"
                    class="flex items-center gap-2 text-sm py-0.5"
                >
                    <input
                        type="checkbox"
                        :value="a.alias"
                        v-model="selected"
                    />
                    <span>{{ a.alias }} → {{ a.landing_name || a.landing_uuid }} (LP{{ a.position }})</span>
                </label>
                <div v-if="!aliases.length" class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                    No aliases yet — go to Aliases tab to add some.
                </div>
            </div>
            <PeriodPicker v-model="period" />
            <button
                class="px-4 py-2 rounded-lg text-sm font-medium w-full bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] disabled:opacity-50"
                :disabled="selected.length < 2 || loading"
                @click="run"
            >{{ loading ? 'Loading…' : `Compare (${selected.length})` }}</button>
        </div>

        <div v-if="error" class="text-sm text-red-500">{{ error }}</div>

        <div v-if="data" class="space-y-2 overflow-x-auto">
            <div class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                {{ data.window.label }} · LP{{ data.position }}
            </div>
            <MetricsTable :columns="columns(data)" />
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../api.js';
import { useAsync } from '../composables/useAsync.js';
import PeriodPicker from '../components/PeriodPicker.vue';
import MetricsTable from '../components/MetricsTable.vue';

const aliases = ref([]);
const selected = ref([]);
const period = ref('today');

const { data, error, loading, run: runCompare } = useAsync(() =>
    api.compare(selected.value, period.value),
);

async function run() { try { await runCompare(); } catch { /* ignore */ } }

function columns(payload) {
    return payload.entries.map((e) => ({
        key: e.token,
        label: e.alias || e.landing.name,
        values: e.metrics,
    }));
}

onMounted(async () => {
    try {
        const r = await api.listAliases();
        aliases.value = r.aliases;
    } catch (e) {
        error.value = e.message;
    }
});
</script>
