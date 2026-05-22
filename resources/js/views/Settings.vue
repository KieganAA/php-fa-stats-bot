<template>
    <div class="space-y-4">
        <h2 class="text-lg font-semibold">Settings</h2>

        <div v-if="me" class="space-y-2 p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]">
            <div class="text-xs uppercase font-medium text-[var(--tg-theme-hint-color,#6b7280)]">Profile</div>
            <div class="text-sm">
                <div class="font-medium">{{ me.display_name }}</div>
                <div class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                    TG id: {{ me.telegram_user_id }} · internal #{{ me.id }}
                </div>
            </div>
        </div>

        <form v-if="me" @submit.prevent="save" class="space-y-3 p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]">
            <div class="text-xs uppercase font-medium text-[var(--tg-theme-hint-color,#6b7280)]">Preferences</div>

            <label class="block">
                <span class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">Timezone</span>
                <input
                    v-model="form.timezone"
                    placeholder="UTC"
                    class="w-full mt-1 px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                />
            </label>

            <label class="block">
                <span class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">Default period</span>
                <select
                    v-model="form.default_period"
                    class="w-full mt-1 px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                >
                    <option v-for="p in periods" :key="p" :value="p">{{ p }}</option>
                </select>
            </label>

            <label class="block">
                <span class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">Default position</span>
                <select
                    v-model.number="form.default_position"
                    class="w-full mt-1 px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                >
                    <option v-for="n in 5" :key="n" :value="n">LP{{ n }}</option>
                </select>
            </label>

            <button
                type="submit"
                class="w-full px-4 py-2 rounded-lg text-sm font-medium bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] disabled:opacity-50"
                :disabled="saving"
            >{{ saving ? 'Saving…' : (saved ? '✓ Saved' : 'Save') }}</button>
        </form>

        <div v-if="error" class="text-sm text-red-500">{{ error }}</div>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import { api } from '../api.js';
import { hapticImpact } from '../telegram.js';

const me = ref(null);
const form = reactive({ timezone: '', default_period: '', default_position: 1 });
const saving = ref(false);
const saved = ref(false);
const error = ref(null);

const periods = ['today', 'yesterday', '24h', '7d', 'week', 'month'];

async function load() {
    try {
        me.value = await api.me();
        form.timezone = me.value.timezone;
        form.default_period = me.value.default_period;
        form.default_position = me.value.default_position;
    } catch (e) {
        error.value = e.message;
    }
}

async function save() {
    saving.value = true;
    saved.value = false;
    error.value = null;
    try {
        me.value = await api.updateMe({ ...form });
        saved.value = true;
        hapticImpact('light');
    } catch (e) {
        error.value = e.message;
    } finally {
        saving.value = false;
    }
}

watch([() => form.timezone, () => form.default_period, () => form.default_position], () => {
    saved.value = false;
});

onMounted(load);
</script>
