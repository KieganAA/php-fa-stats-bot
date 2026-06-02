<template>
    <div class="space-y-4">
        <h2 class="text-lg font-semibold">Подписки</h2>
        <p class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
            Бот шлёт сравнение или MVT-разбивку выбранных лендов каждые
            <i>N минут</i>. Один ленд → MVT-режим. Два и больше → compare с Δ%.
        </p>

        <!-- New subscription -->
        <div class="space-y-3 p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]">
            <div class="text-xs uppercase font-medium text-[var(--tg-theme-hint-color,#6b7280)]">
                Создать
            </div>

            <!-- Picked landings as chips -->
            <div v-if="form.landings.length" class="space-y-1">
                <div class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                    Лендинги ({{ form.landings.length }}):
                </div>
                <ul class="space-y-0.5">
                    <li
                        v-for="(l, i) in form.landings"
                        :key="l.uuid"
                        class="flex items-center gap-1 text-sm py-1 px-2 rounded bg-[var(--tg-theme-secondary-bg-color,#f3f4f6)]"
                    >
                        <span class="text-[10px] text-[var(--tg-theme-hint-color,#6b7280)] w-5">{{ i + 1 }}.</span>
                        <span class="flex-1 min-w-0 truncate">{{ l.label }}</span>
                        <button type="button" class="text-xs px-1 disabled:opacity-30" :disabled="i === 0" @click="reorder(i, -1)">↑</button>
                        <button type="button" class="text-xs px-1 disabled:opacity-30" :disabled="i === form.landings.length - 1" @click="reorder(i, +1)">↓</button>
                        <button type="button" class="text-xs px-1.5 text-red-500" @click="dropLanding(i)">×</button>
                    </li>
                </ul>
            </div>

            <!-- Search + suggestions -->
            <div class="relative">
                <input
                    v-model="search"
                    placeholder="Найти ленд: 33169 / NO / название…"
                    autocomplete="off"
                    spellcheck="false"
                    @focus="onSearchFocus"
                    @input="onSearchInput"
                    class="w-full px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                />
                <div
                    v-if="showSuggestions && suggestions.length"
                    class="absolute z-10 left-0 right-0 mt-1 max-h-72 overflow-auto rounded-lg shadow-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)] bg-[var(--tg-theme-bg-color,#fff)]"
                >
                    <button
                        v-for="s in suggestions"
                        :key="s.uuid"
                        type="button"
                        class="w-full text-left px-3 py-2 text-sm hover:bg-[var(--tg-theme-secondary-bg-color,#f3f4f6)] border-b border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                        :disabled="isPicked(s.uuid)"
                        :class="isPicked(s.uuid) ? 'opacity-40 cursor-not-allowed' : ''"
                        @click="addLanding(s)"
                    >
                        <div class="font-medium">{{ s.label }}</div>
                        <div class="text-[10px] text-[var(--tg-theme-hint-color,#6b7280)] truncate">
                            {{ s.name }}<span v-if="s.type"> · {{ s.type }}</span>
                        </div>
                    </button>
                </div>
                <div
                    v-else-if="showSuggestions && searchLoading"
                    class="absolute z-10 left-0 right-0 mt-1 px-3 py-2 rounded-lg bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)] text-xs text-[var(--tg-theme-hint-color,#6b7280)]"
                >Ищу…</div>
                <div
                    v-else-if="showSuggestions && search.trim() && !suggestions.length && !searchLoading"
                    class="absolute z-10 left-0 right-0 mt-1 px-3 py-2 rounded-lg bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)] text-xs text-[var(--tg-theme-hint-color,#6b7280)]"
                >Ничего не нашёл.</div>
            </div>

            <!-- Interval picker -->
            <label class="block">
                <span class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                    Частота уведомлений
                </span>
                <select
                    v-model.number="form.intervalMinutes"
                    class="w-full mt-1 px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                >
                    <option v-for="i in intervalOptions" :key="i.value" :value="i.value">{{ i.label }}</option>
                </select>
            </label>

            <input
                v-model="form.name"
                placeholder="Имя группы (необязательно — сгенерится)"
                class="w-full px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
            />

            <button
                type="button"
                class="w-full px-4 py-2 rounded-lg text-sm font-medium bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] disabled:opacity-50"
                :disabled="creating || form.landings.length < 1"
                @click="create"
            >{{ creating ? 'Сохраняю…' : createLabel }}</button>

            <div v-if="createError" class="text-xs text-red-500">{{ createError }}</div>
        </div>

        <!-- Existing subscriptions -->
        <div v-if="loadingList && !groups.length" class="text-sm">Загружаю…</div>
        <div v-else-if="!groups.length" class="text-sm text-[var(--tg-theme-hint-color,#6b7280)]">
            Подписок нет. Добавь выше.
        </div>
        <ul v-else class="space-y-3">
            <li
                v-for="g in groups"
                :key="g.id"
                class="p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <div class="font-medium text-sm flex items-center flex-wrap gap-1.5">
                            <code class="text-xs px-1.5 py-0.5 rounded bg-[var(--tg-theme-secondary-bg-color,#f3f4f6)]">{{ g.name }}</code>
                            <span class="text-xs px-1.5 py-0.5 rounded bg-[var(--tg-theme-secondary-bg-color,#f3f4f6)]">{{ g.mode }}</span>
                            <span class="text-xs px-1.5 py-0.5 rounded bg-[var(--tg-theme-secondary-bg-color,#f3f4f6)]">⏱ {{ formatInterval(g.notify_interval_minutes) }}</span>
                            <span v-if="g.paused" class="text-xs text-amber-500">⏸ paused</span>
                        </div>
                        <div class="text-[10px] text-[var(--tg-theme-hint-color,#6b7280)] mt-0.5 space-x-2">
                            <span v-if="g.last_notified_at">last push {{ formatRelative(g.last_notified_at) }}</span>
                            <span v-if="g.next_push_at && !g.paused">· next ~ {{ formatRelative(g.next_push_at) }}</span>
                        </div>
                    </div>
                    <div class="flex gap-1.5 shrink-0">
                        <button class="text-xs px-2 py-1 rounded text-[var(--tg-theme-hint-color,#6b7280)]" @click="togglePause(g)">{{ g.paused ? '▶' : '⏸' }}</button>
                        <button class="text-xs px-2 py-1 rounded text-[var(--tg-theme-hint-color,#6b7280)]" @click="editInterval(g)">⏱</button>
                        <button class="text-xs px-2 py-1 rounded text-red-500" @click="confirmDelete(g)">×</button>
                    </div>
                </div>

                <ul class="mt-2 space-y-0.5 text-xs">
                    <li
                        v-for="m in g.members"
                        :key="m.tracked_landing_id"
                        class="text-[var(--tg-theme-hint-color,#6b7280)]"
                    >
                        • {{ m.short_label || 'unknown landing' }}
                    </li>
                </ul>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { api } from '../api.js';
import { hapticImpact, showAlert, showConfirm } from '../telegram.js';

const groups = ref([]);
const loadingList = ref(false);
const creating = ref(false);
const createError = ref(null);

const form = reactive({
    landings: [],           // [{uuid, human_id, label, name, type, country}]
    name: '',
    intervalMinutes: 180,
});

const search = ref('');
const suggestions = ref([]);
const searchLoading = ref(false);
const showSuggestions = ref(false);
let searchAbort = null;
let searchTimer = null;

const intervalOptions = [
    { value: 60, label: '1 час' },
    { value: 180, label: '3 часа (по умолчанию)' },
    { value: 360, label: '6 часов' },
    { value: 720, label: '12 часов' },
    { value: 1440, label: '24 часа' },
];

const createLabel = computed(() => {
    const n = form.landings.length;
    if (n === 0) return 'Выбери хотя бы один ленд';
    if (n === 1) return 'Сохранить (MVT — разбивка вариантов)';
    return `Сохранить (compare ${n})`;
});

function isPicked(uuid) {
    return form.landings.some((l) => l.uuid === uuid);
}

function addLanding(s) {
    if (isPicked(s.uuid)) return;
    form.landings.push(s);
    search.value = '';
    suggestions.value = [];
    showSuggestions.value = false;
    hapticImpact('light');
}

function dropLanding(i) {
    form.landings.splice(i, 1);
    hapticImpact('light');
}

function reorder(i, delta) {
    const j = i + delta;
    if (j < 0 || j >= form.landings.length) return;
    const tmp = form.landings[i];
    form.landings[i] = form.landings[j];
    form.landings[j] = tmp;
}

async function fetchSuggestions(q) {
    if (searchAbort) {
        // Vue refs auto-unwrap; AbortController exposes abort().
        try { searchAbort.abort(); } catch {}
    }
    searchAbort = new AbortController();
    searchLoading.value = true;
    try {
        const r = await api.listLandings(q);
        suggestions.value = r.landings || [];
    } catch (e) {
        // Suggestions silently fail — picker still works with manual add via search.
        suggestions.value = [];
    } finally {
        searchLoading.value = false;
    }
}

function onSearchFocus() {
    showSuggestions.value = true;
    if (!suggestions.value.length && !searchLoading.value) {
        fetchSuggestions('');
    }
}

function onSearchInput() {
    showSuggestions.value = true;
    if (searchTimer) clearTimeout(searchTimer);
    // Debounce — don't hit the API on every keypress.
    searchTimer = setTimeout(() => fetchSuggestions(search.value.trim()), 200);
}

async function load() {
    loadingList.value = true;
    try {
        const r = await api.listGroups();
        groups.value = r.groups;
    } catch (e) {
        showAlert(e.message);
    } finally {
        loadingList.value = false;
    }
}

async function create() {
    creating.value = true;
    createError.value = null;
    try {
        await api.createGroup({
            primitives: form.landings.map((l) => l.human_id !== null && l.human_id !== undefined ? String(l.human_id) : l.uuid),
            name: form.name.trim() || null,
            notify_interval_minutes: form.intervalMinutes,
        });
        hapticImpact('light');
        form.landings = [];
        form.name = '';
        await load();
    } catch (e) {
        createError.value = e.message;
    } finally {
        creating.value = false;
    }
}

async function togglePause(g) {
    try {
        const r = await api.updateGroup(g.id, { paused: !g.paused });
        Object.assign(g, r.group);
        hapticImpact('light');
    } catch (e) {
        showAlert(e.message);
    }
}

async function editInterval(g) {
    const current = g.notify_interval_minutes;
    const choices = intervalOptions.map((o) => `${o.label} (${o.value})`).join('\n');
    const raw = window.prompt(`Минут между пушами для «${g.name}»:\n${choices}\n\nТекущее: ${current}`, String(current));
    if (raw === null || raw === '') return;
    const minutes = parseInt(raw, 10);
    if (Number.isNaN(minutes) || minutes < 15 || minutes > 10080) {
        await showAlert('Нужно число от 15 до 10080 (минут).');

        return;
    }
    try {
        const r = await api.updateGroup(g.id, { notify_interval_minutes: minutes });
        Object.assign(g, r.group);
        hapticImpact('light');
    } catch (e) {
        showAlert(e.message);
    }
}

async function confirmDelete(g) {
    if (!(await showConfirm(`Удалить подписку «${g.name}»?`))) return;
    try {
        await api.deleteGroup(g.id);
        hapticImpact('rigid');
        await load();
    } catch (e) {
        showAlert(e.message);
    }
}

function formatInterval(min) {
    if (!min) return '?';
    if (min % 60 === 0) {
        const h = min / 60;
        return h === 1 ? '1ч' : `${h}ч`;
    }
    return `${min}м`;
}

function formatRelative(iso) {
    try {
        const d = new Date(iso);
        const now = new Date();
        const diffMs = d - now;
        const abs = Math.abs(diffMs);
        const future = diffMs > 0;

        const minutes = Math.round(abs / 60000);
        if (minutes < 1) return future ? 'через мгновение' : 'только что';
        if (minutes < 60) return future ? `через ${minutes}м` : `${minutes}м назад`;
        const hours = Math.round(minutes / 60);
        if (hours < 24) return future ? `через ${hours}ч` : `${hours}ч назад`;
        return d.toLocaleDateString();
    } catch {
        return iso;
    }
}

// Close suggestions when clicking outside the input (basic blur handler).
function onDocClick(e) {
    if (!e.target.closest('input') && !e.target.closest('button')) {
        showSuggestions.value = false;
    }
}

onMounted(() => {
    load();
    document.addEventListener('click', onDocClick);
});

watch(showSuggestions, (v) => {
    if (!v) suggestions.value = [];
});
</script>
