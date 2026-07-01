<template>
    <div class="space-y-2">
        <div class="flex flex-wrap gap-1.5">
            <button
                v-for="p in periods"
                :key="p.value"
                type="button"
                class="px-3 py-1 text-xs rounded-full border transition-colors"
                :class="modelValue === p.value ? activeClass : idleClass"
                @click="$emit('update:modelValue', p.value)"
            >{{ p.label }}</button>

            <!-- Custom calendar range. Toggling it reveals two native date
                 inputs, which Telegram renders as the phone's OS date wheel. -->
            <button
                type="button"
                class="px-3 py-1 text-xs rounded-full border transition-colors"
                :class="isRange ? activeClass : idleClass"
                @click="openCustom"
            >📅 Период</button>
        </div>

        <div v-if="isRange" class="flex items-center gap-2 text-xs">
            <label class="flex items-center gap-1">
                <span class="text-[var(--tg-theme-hint-color,#6b7280)]">с</span>
                <input
                    type="date"
                    :value="from"
                    :max="to || todayStr"
                    class="px-2 py-1 rounded-md bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)] text-[var(--tg-theme-text-color,#000)]"
                    @input="onFrom($event.target.value)"
                />
            </label>
            <label class="flex items-center gap-1">
                <span class="text-[var(--tg-theme-hint-color,#6b7280)]">по</span>
                <input
                    type="date"
                    :value="to"
                    :min="from"
                    :max="todayStr"
                    class="px-2 py-1 rounded-md bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)] text-[var(--tg-theme-text-color,#000)]"
                    @input="onTo($event.target.value)"
                />
            </label>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';

// v-model is a plain string so the parent screens stay unchanged: it is either
// a preset token ('today', '7d', …) or a custom range encoded as
// 'range:YYYY-MM-DD:YYYY-MM-DD'. api.js unpacks the range into from/to params.
const props = defineProps({ modelValue: { type: String, default: 'today' } });
const emit = defineEmits(['update:modelValue']);

const periods = [
    { value: 'today', label: 'Сегодня' },
    { value: 'yesterday', label: 'Вчера' },
    { value: '24h', label: '24ч' },
    { value: '7d', label: '7д' },
    { value: 'week', label: 'Неделя' },
    { value: 'month', label: 'Месяц' },
];

const activeClass =
    'bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] border-transparent';
const idleClass =
    'border-[var(--tg-theme-section-separator-color,#e5e7eb)] text-[var(--tg-theme-text-color,#000)]';

function isoDate(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}
const todayStr = isoDate(new Date());

const from = ref('');
const to = ref('');

const isRange = computed(() => (props.modelValue || '').startsWith('range:'));

// Mirror an externally-set range value back into the two inputs.
watch(
    () => props.modelValue,
    (v) => {
        if (typeof v === 'string' && v.startsWith('range:')) {
            const [, f, t] = v.split(':');
            if (f) from.value = f;
            if (t) to.value = t;
        }
    },
    { immediate: true },
);

function emitRange() {
    if (from.value && to.value) {
        emit('update:modelValue', `range:${from.value}:${to.value}`);
    }
}

// First tap on 📅 seeds an empty range with today so the inputs aren't blank;
// the user then scrolls the "с" date back to the start they want.
function openCustom() {
    if (!from.value) from.value = todayStr;
    if (!to.value) to.value = todayStr;
    emitRange();
}

function onFrom(v) {
    from.value = v;
    emitRange();
}

function onTo(v) {
    to.value = v;
    emitRange();
}
</script>
