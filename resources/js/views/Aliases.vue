<template>
    <div class="space-y-4">
        <h2 class="text-lg font-semibold">Aliases</h2>

        <form @submit.prevent="createAlias" class="space-y-2 p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]">
            <div class="text-xs font-medium uppercase text-[var(--tg-theme-hint-color,#6b7280)]">Add alias</div>
            <input
                v-model="form.alias"
                placeholder="alias (e.g. dk-blue)"
                class="w-full px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                required
            />
            <input
                v-model="form.token"
                placeholder="human_id, uuid, or existing alias"
                class="w-full px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                required
            />
            <div class="flex gap-2">
                <select
                    v-model="form.position"
                    class="px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                >
                    <option v-for="n in 5" :key="n" :value="n">LP{{ n }}</option>
                </select>
                <input
                    v-model="form.notes"
                    placeholder="notes (optional)"
                    class="flex-1 px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                />
            </div>
            <button
                type="submit"
                class="w-full px-4 py-2 rounded-lg text-sm font-medium bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] disabled:opacity-50"
                :disabled="creating"
            >{{ creating ? 'Adding…' : 'Add' }}</button>
            <div v-if="formError" class="text-xs text-red-500">{{ formError }}</div>
        </form>

        <div v-if="loadingList" class="text-sm">Loading…</div>
        <div v-else-if="!aliases.length" class="text-sm text-[var(--tg-theme-hint-color,#6b7280)]">
            No aliases yet.
        </div>
        <ul v-else class="divide-y divide-[var(--tg-theme-section-separator-color,#e5e7eb)]">
            <li
                v-for="a in aliases"
                :key="a.id"
                class="py-2 flex items-center justify-between gap-2"
            >
                <div class="min-w-0">
                    <div class="font-medium text-sm">{{ a.alias }}</div>
                    <div class="text-xs text-[var(--tg-theme-hint-color,#6b7280)] truncate">
                        {{ a.landing_name || a.landing_uuid }} (LP{{ a.position }})
                    </div>
                </div>
                <button
                    class="text-xs px-2 py-1 rounded text-red-500"
                    @click="confirmDelete(a)"
                >Delete</button>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { api } from '../api.js';
import { showConfirm, showAlert, hapticImpact } from '../telegram.js';

const aliases = ref([]);
const loadingList = ref(false);
const creating = ref(false);
const formError = ref(null);
const form = reactive({ alias: '', token: '', position: 1, notes: '' });

async function load() {
    loadingList.value = true;
    try {
        const r = await api.listAliases();
        aliases.value = r.aliases;
    } catch (e) {
        showAlert(e.message);
    } finally {
        loadingList.value = false;
    }
}

async function createAlias() {
    creating.value = true;
    formError.value = null;
    try {
        await api.createAlias({
            alias: form.alias.trim(),
            token: form.token.trim(),
            position: Number(form.position),
            notes: form.notes.trim() || null,
        });
        hapticImpact('light');
        form.alias = '';
        form.token = '';
        form.notes = '';
        await load();
    } catch (e) {
        formError.value = e.message;
    } finally {
        creating.value = false;
    }
}

async function confirmDelete(a) {
    if (!(await showConfirm(`Delete alias "${a.alias}"?`))) return;
    try {
        await api.deleteAlias(a.id);
        hapticImpact('rigid');
        await load();
    } catch (e) {
        showAlert(e.message);
    }
}

onMounted(load);
</script>
