<template>
    <div v-if="!rows.length" class="text-sm text-[var(--tg-theme-hint-color,#6b7280)] py-6 text-center">
        Нет данных за выбранный период.
    </div>
    <!--
        Outer wrapper enables horizontal scroll when the user picked many
        metrics. The `-mx-3 px-3` cancels the parent screen-level padding so the
        table can use the full viewport width (which is what the user expects
        with a wide column set on a phone).
    -->
    <div v-else class="-mx-3 px-3 overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="border-b border-[var(--tg-theme-section-separator-color,#e5e7eb)] align-baseline">
                    <th class="text-right py-1.5 pr-2 pl-0 text-[10px] uppercase tracking-wider font-medium text-[var(--tg-theme-hint-color,#6b7280)]">
                        #
                    </th>
                    <th
                        class="sticky-label-col text-left py-1.5 pr-3 pl-0 text-[10px] uppercase tracking-wider font-medium cursor-pointer select-none"
                        :class="headerClass('__label')"
                        @click="onSort('__label')"
                    >
                        <span class="inline-flex items-center gap-1">
                            <span>{{ header }}</span>
                            <span v-if="sortBy === '__label'" class="text-[9px] leading-none">
                                {{ sortDir === 'asc' ? '▲' : '▼' }}
                            </span>
                        </span>
                    </th>
                    <th
                        v-for="col in columns"
                        :key="col.name"
                        class="text-right py-1.5 px-2 text-[10px] uppercase tracking-wider font-medium cursor-pointer select-none whitespace-nowrap"
                        :class="headerClass(col.name)"
                        :title="col.name === col.label ? col.name : `${col.name} → ${col.label}`"
                        @click="onSort(col.name)"
                    >
                        <span class="inline-flex items-center gap-1">
                            <span>{{ col.label }}</span>
                            <span v-if="sortBy === col.name" class="text-[9px] leading-none">
                                {{ sortDir === 'asc' ? '▲' : '▼' }}
                            </span>
                        </span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="(row, i) in sortedRows"
                    :key="row.label + ':' + i"
                    class="border-b border-[var(--tg-theme-section-separator-color,#e5e7eb)] last:border-b-0 hover:bg-[var(--tg-theme-secondary-bg-color,#f3f4f6)]/40"
                >
                    <td class="py-1.5 pr-2 pl-0 text-right tabular-nums text-[11px] text-[var(--tg-theme-hint-color,#6b7280)]">
                        {{ i + 1 }}
                    </td>
                    <td class="sticky-label-col py-1.5 pr-3 pl-0 font-medium whitespace-nowrap">
                        {{ row.label }}
                    </td>
                    <td
                        v-for="col in columns"
                        :key="col.name"
                        class="py-1.5 px-2 text-right tabular-nums whitespace-nowrap"
                        :class="row.metrics?.[col.name]?.raw == null ? 'text-[var(--tg-theme-hint-color,#6b7280)]' : ''"
                    >
                        {{ row.metrics?.[col.name]?.formatted ?? '—' }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    // What to call the first (non-numeric) column — bot mirrors this:
    //   geo → "country", buyers → "buyer", lp1/lp2 → "landing".
    header: { type: String, default: 'name' },
    // [{ name, label, kind }]  — name is the AIO identifier (used for sorting),
    // label is what the user sees, kind ∈ count/ratio/percent/money.
    columns: { type: Array, default: () => [] },
    // [{ label, metrics: { [name]: { raw, formatted } } }]
    rows: { type: Array, default: () => [] },
});

// Sort spec. `__label` is a sentinel for the row-label column.
const sortBy = ref('Leads');
const sortDir = ref('desc'); // 'asc' | 'desc'

// When the column set changes (kind switch, period change) and the current
// sort column is no longer present, fall back to Leads (server default) or
// the first numeric column.
watch(
    () => props.columns,
    (cols) => {
        if (!cols?.length) return;
        if (cols.find((c) => c.name === sortBy.value)) return;
        const leads = cols.find((c) => c.name === 'Leads');
        sortBy.value = leads ? 'Leads' : cols[0].name;
        sortDir.value = 'desc';
    },
    { immediate: true, deep: true },
);

function onSort(key) {
    if (sortBy.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc';
        return;
    }
    sortBy.value = key;
    // First click defaults: label → ascending (A→Z reads naturally),
    // numeric → descending (top performer first).
    sortDir.value = key === '__label' ? 'asc' : 'desc';
}

const sortedRows = computed(() => {
    const out = [...(props.rows || [])];
    const key = sortBy.value;
    const dir = sortDir.value;
    out.sort((a, b) => {
        let av, bv;
        if (key === '__label') {
            av = (a.label ?? '').toLowerCase();
            bv = (b.label ?? '').toLowerCase();
            if (av === bv) return 0;
            return dir === 'asc' ? (av < bv ? -1 : 1) : (av < bv ? 1 : -1);
        }
        av = a.metrics?.[key]?.raw;
        bv = b.metrics?.[key]?.raw;
        // Push nulls to the bottom regardless of sort direction — they're
        // worse than any actual number for the purposes of "show me the top".
        if (av == null && bv == null) return 0;
        if (av == null) return 1;
        if (bv == null) return -1;
        return dir === 'asc' ? av - bv : bv - av;
    });
    return out;
});

function headerClass(key) {
    return sortBy.value === key
        ? 'text-[var(--tg-theme-text-color,#111)]'
        : 'text-[var(--tg-theme-hint-color,#6b7280)] hover:text-[var(--tg-theme-text-color,#111)]';
}
</script>

<style scoped>
/*
   Sticky label column keeps the country/buyer/landing visible when the user
   scrolls horizontally through many metric columns.
*/
.sticky-label-col {
    position: sticky;
    left: 0;
    background: var(--tg-theme-bg-color, #fff);
    z-index: 1;
}

th.sticky-label-col {
    background: var(--tg-theme-bg-color, #fff);
}

/* Tabular numerals so digit columns stay column-aligned. */
.tabular-nums {
    font-variant-numeric: tabular-nums;
}
</style>
