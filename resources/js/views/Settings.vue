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

        <form v-if="me" @submit.prevent="saveAi" class="space-y-3 p-3 rounded-lg border border-[var(--tg-theme-section-separator-color,#e5e7eb)]">
            <div class="text-xs uppercase font-medium text-[var(--tg-theme-hint-color,#6b7280)]">
                Anthropic (для /ai)
            </div>
            <p class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                Личный ключ Claude — если задан, /ai ходит через него, а не через общий ключ бота.
                Получить ключ:
                <a href="https://console.anthropic.com/" class="underline">console.anthropic.com</a>.
            </p>

            <label class="block">
                <span class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                    API key
                    <span v-if="me.anthropic_key_hint" class="ml-1 text-emerald-500">сохранён ({{ me.anthropic_key_hint }})</span>
                </span>
                <input
                    v-model="aiForm.anthropic_api_key"
                    type="password"
                    autocomplete="off"
                    placeholder="sk-ant-…"
                    class="w-full mt-1 px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                />
                <span class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                    Пусто = использовать общий ключ бота. «Очистить» — кнопка ниже.
                </span>
            </label>

            <label class="block">
                <span class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">
                    Model <span class="text-[var(--tg-theme-hint-color,#6b7280)]">(пусто = {{ me.env_anthropic_model }})</span>
                </span>
                <input
                    v-model="aiForm.anthropic_model"
                    placeholder="claude-haiku-4-5-20251001"
                    class="w-full mt-1 px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
                />
            </label>

            <div class="flex gap-2">
                <button
                    type="submit"
                    class="flex-1 px-4 py-2 rounded-lg text-sm font-medium bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] disabled:opacity-50"
                    :disabled="savingAi"
                >{{ savingAi ? 'Saving…' : (savedAi ? '✓ Saved' : 'Save AI settings') }}</button>
                <button
                    v-if="me.anthropic_key_hint"
                    type="button"
                    class="px-3 py-2 rounded-lg text-sm text-red-500 border border-red-500/30"
                    :disabled="savingAi"
                    @click="clearKey"
                >Очистить</button>
            </div>
        </form>

        <div v-if="error" class="text-sm text-red-500">{{ error }}</div>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import { api } from '../api.js';
import { hapticImpact, showConfirm } from '../telegram.js';

const me = ref(null);
const form = reactive({ timezone: '', default_period: '', default_position: 1 });
const aiForm = reactive({ anthropic_api_key: '', anthropic_model: '' });
const saving = ref(false);
const saved = ref(false);
const savingAi = ref(false);
const savedAi = ref(false);
const error = ref(null);

const periods = ['today', 'yesterday', '24h', '7d', 'week', 'month'];

async function load() {
    try {
        me.value = await api.me();
        form.timezone = me.value.timezone;
        form.default_period = me.value.default_period;
        form.default_position = me.value.default_position;
        // Never repopulate the key field with a hint — leave it empty so users
        // can either type a new key or leave it untouched (no payload sent).
        aiForm.anthropic_api_key = '';
        aiForm.anthropic_model = me.value.anthropic_model || '';
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

async function saveAi() {
    savingAi.value = true;
    savedAi.value = false;
    error.value = null;
    try {
        const payload = { anthropic_model: aiForm.anthropic_model };
        // Only send the key if the user actually typed one — empty means "keep
        // existing", per the UX in the hint copy.
        if (aiForm.anthropic_api_key !== '') {
            payload.anthropic_api_key = aiForm.anthropic_api_key;
        }
        me.value = await api.updateMe(payload);
        aiForm.anthropic_api_key = '';
        aiForm.anthropic_model = me.value.anthropic_model || '';
        savedAi.value = true;
        hapticImpact('light');
    } catch (e) {
        error.value = e.message;
    } finally {
        savingAi.value = false;
    }
}

async function clearKey() {
    if (!(await showConfirm('Очистить личный ключ Anthropic? /ai опять пойдёт через общий ключ бота.'))) return;
    savingAi.value = true;
    try {
        me.value = await api.updateMe({ anthropic_api_key: '' });
        hapticImpact('rigid');
    } catch (e) {
        error.value = e.message;
    } finally {
        savingAi.value = false;
    }
}

watch([() => form.timezone, () => form.default_period, () => form.default_position], () => {
    saved.value = false;
});

onMounted(load);
</script>
