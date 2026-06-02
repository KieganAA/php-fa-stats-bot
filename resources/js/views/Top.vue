<template>
    <div class="space-y-3">
        <div class="flex items-baseline justify-between">
            <h2 class="text-lg font-semibold">Топы</h2>
            <button
                type="button"
                class="text-xs underline text-[var(--tg-theme-hint-color,#6b7280)]"
                @click="openSettings"
            >настроить →</button>
        </div>

        <!-- Kind tabs (geo / buyers / lp1 / lp2). -->
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

        <!-- Top-N picker — 15 fits a phone, but power users sometimes want deeper. -->
        <label class="flex items-center gap-2 text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
            <span>Top</span>
            <select
                v-model.number="topN"
                class="px-2 py-1 rounded-md text-xs bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
            >
                <option v-for="n in topOptions" :key="n" :value="n">{{ n }}</option>
            </select>
        </label>

        <button
            class="px-4 py-2 rounded-lg text-sm font-medium w-full bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] disabled:opacity-50"
            :disabled="loading"
            @click="run"
        >{{ loading ? 'Загружаю…' : 'Обновить' }}</button>

        <div v-if="error" class="text-sm text-red-500">{{ error }}</div>

        <!--
            Two render paths:
              - When the server returned structured rows + columns, show the
                sortable RankingsTable (sticky label col, click-to-sort).
              - Otherwise fall back to the Telegram-HTML the server emits as
                a safety net so the screen never silently goes blank.
        -->
        <div
            v-if="result && Array.isArray(result.rows) && Array.isArray(result.columns)"
            class="rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)] overflow-hidden"
        >
            <div class="px-3 py-2 bg-[var(--tg-theme-secondary-bg-color,#f3f4f6)]/60 border-b border-[var(--tg-theme-section-separator-color,#e5e7eb)]">
                <div class="text-sm font-semibold flex items-baseline gap-1.5">
                    <span>🏆 {{ result.title || 'отчёт' }}</span>
                    <span class="text-xs text-[var(--tg-theme-hint-color,#6b7280)] font-normal">
                        — {{ result.window?.label ?? '' }}
                    </span>
                </div>
                <div class="text-[10px] text-[var(--tg-theme-hint-color,#6b7280)] mt-0.5 flex flex-wrap gap-x-3">
                    <span v-if="result.window?.timezone">{{ result.window.timezone }}</span>
                    <span>{{ result.rows.length }} {{ pluralize(result.rows.length, ['строка', 'строки', 'строк']) }}</span>
                    <span>{{ result.columns.length }} {{ pluralize(result.columns.length, ['метрика', 'метрики', 'метрик']) }}</span>
                </div>
            </div>
            <div class="px-3 py-2">
                <RankingsTable
                    :header="result.header || 'name'"
                    :columns="result.columns"
                    :rows="result.rows"
                />
            </div>
        </div>
        <TelegramHtml
            v-else-if="result && result.html"
            :html="result.html"
        />
    </div>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';
import { api } from '../api.js';
import PeriodPicker from '../components/PeriodPicker.vue';
import RankingsTable from '../components/RankingsTable.vue';
import TelegramHtml from '../components/TelegramHtml.vue';

const kinds = [
    { value: 'geo', label: '🌍 Гео' },
    { value: 'buyers', label: '👤 Баеры' },
    { value: 'lp1', label: '📄 LP1' },
    { value: 'lp2', label: '📄 LP2' },
];

const topOptions = [10, 15, 25, 50];

const kind = ref('geo');
const period = ref('today');
const topN = ref(15);
const result = ref(null);
const error = ref(null);
const loading = ref(false);

async function run() {
    loading.value = true;
    error.value = null;
    try {
        result.value = await api.rankings(kind.value, period.value, topN.value);
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

// Plain hash navigation — no useRouter() in setup. Some Telegram WebViews
// seem to choke on it, and an undocumented blank-screen is the worst failure
// mode for this app. Hash works everywhere.
function openSettings() {
    window.location.hash = '#/settings?context=' + encodeURIComponent(kind.value);
}

function pluralize(n, forms) {
    const m10 = n % 10;
    const m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return forms[0];
    if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return forms[1];
    return forms[2];
}

// Auto-refresh whenever kind/period/topN changes.
watch([kind, period, topN], () => run());

onMounted(run);
</script>
