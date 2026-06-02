<template>
    <div class="flex flex-wrap gap-1.5">
        <button
            v-for="p in periods"
            :key="p.value"
            type="button"
            class="px-3 py-1 text-xs rounded-full border transition-colors"
            :class="modelValue === p.value
                ? 'bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)] border-transparent'
                : 'border-[var(--tg-theme-section-separator-color,#e5e7eb)] text-[var(--tg-theme-text-color,#000)]'"
            @click="$emit('update:modelValue', p.value)"
        >{{ p.label }}</button>
    </div>
</template>

<script setup>
defineProps({ modelValue: { type: String, default: 'today' } });
defineEmits(['update:modelValue']);

const periods = [
    { value: 'today', label: 'Сегодня' },
    { value: 'yesterday', label: 'Вчера' },
    { value: '24h', label: '24ч' },
    { value: '7d', label: '7д' },
    { value: 'week', label: 'Неделя' },
    { value: 'month', label: 'Месяц' },
];
</script>
