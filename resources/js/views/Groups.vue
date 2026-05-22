<template>
    <div class="space-y-4">
        <h2 class="text-lg font-semibold">Tracking groups</h2>

        <form @submit.prevent="create" class="space-y-2 p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]">
            <div class="text-xs font-medium uppercase text-[var(--tg-theme-hint-color,#6b7280)]">
                Создать / перебиндить
            </div>
            <input
                v-model="form.primitives"
                placeholder="33169, 205215   (один ленд = MVT, два+ = compare)"
                autocomplete="off"
                spellcheck="false"
                class="w-full px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
            />
            <input
                v-model="form.name"
                placeholder="имя группы (необязательно — сгенерится)"
                class="w-full px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
            />
            <button
                type="submit"
                class="w-full px-4 py-2 rounded-lg text-sm font-medium bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] disabled:opacity-50"
                :disabled="creating || parsedTokens.length < 1"
            >
                {{ creating ? 'Binding…' : creatingLabel }}
            </button>
            <div v-if="createError" class="text-xs text-red-500">{{ createError }}</div>
        </form>

        <div v-if="loadingList && !groups.length" class="text-sm">Loading…</div>
        <div v-else-if="!groups.length" class="text-sm text-[var(--tg-theme-hint-color,#6b7280)]">
            Биндингов нет. Добавь группу выше.
        </div>
        <ul v-else class="space-y-3">
            <li
                v-for="g in groups"
                :key="g.id"
                class="p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="font-medium text-sm flex items-center gap-1.5">
                            <code class="text-xs px-1.5 py-0.5 rounded bg-[var(--tg-theme-secondary-bg-color,#f3f4f6)]">{{ g.name }}</code>
                            <span class="text-xs px-1.5 py-0.5 rounded bg-[var(--tg-theme-secondary-bg-color,#f3f4f6)]">{{ g.mode }}</span>
                            <span v-if="g.paused" class="text-xs text-amber-500">⏸ paused</span>
                        </div>
                        <div v-if="g.last_notified_at" class="text-[10px] text-[var(--tg-theme-hint-color,#6b7280)] mt-0.5">
                            last push {{ formatTime(g.last_notified_at) }}
                        </div>
                    </div>
                    <div class="flex gap-1.5">
                        <button
                            class="text-xs px-2 py-1 rounded text-[var(--tg-theme-hint-color,#6b7280)]"
                            @click="togglePause(g)"
                        >{{ g.paused ? '▶' : '⏸' }}</button>
                        <button
                            class="text-xs px-2 py-1 rounded text-red-500"
                            @click="confirmDelete(g)"
                        >×</button>
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
import { computed, onMounted, reactive, ref } from 'vue';
import { api } from '../api.js';
import { hapticImpact, showAlert, showConfirm } from '../telegram.js';

const groups = ref([]);
const loadingList = ref(false);
const creating = ref(false);
const createError = ref(null);
const form = reactive({ primitives: '', name: '' });

const parsedTokens = computed(() =>
    form.primitives.split(/[,\s]+/).map((s) => s.trim()).filter(Boolean),
);

const creatingLabel = computed(() => {
    const n = parsedTokens.value.length;
    if (n === 0) return 'Bind';
    if (n === 1) return 'Bind (MVT mode)';
    return `Bind ${n} (compare mode)`;
});

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
            primitives: parsedTokens.value,
            name: form.name.trim() || null,
        });
        hapticImpact('light');
        form.primitives = '';
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
        await api.updateGroup(g.id, { paused: !g.paused });
        g.paused = !g.paused;
        hapticImpact('light');
    } catch (e) {
        showAlert(e.message);
    }
}

async function confirmDelete(g) {
    if (!(await showConfirm(`Удалить группу "${g.name}"?`))) return;
    try {
        await api.deleteGroup(g.id);
        hapticImpact('rigid');
        await load();
    } catch (e) {
        showAlert(e.message);
    }
}

function formatTime(iso) {
    try {
        const d = new Date(iso);
        const now = new Date();
        const diffMs = now - d;
        const diffMin = Math.round(diffMs / 60000);
        if (diffMin < 1) return 'just now';
        if (diffMin < 60) return `${diffMin}m ago`;
        const diffH = Math.round(diffMin / 60);
        if (diffH < 24) return `${diffH}h ago`;
        return d.toLocaleDateString();
    } catch {
        return iso;
    }
}

onMounted(load);
</script>
