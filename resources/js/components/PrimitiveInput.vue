<template>
    <label class="block">
        <span v-if="label" class="text-xs text-[var(--tg-theme-hint-color,#6b7280)]">{{ label }}</span>
        <input
            :value="modelValue"
            @input="$emit('update:modelValue', $event.target.value)"
            :placeholder="placeholder"
            autocomplete="off"
            spellcheck="false"
            class="w-full mt-1 px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
            @keyup.enter="$emit('submit')"
        />
    </label>
</template>

<script setup>
defineProps({
    modelValue: { type: String, default: '' },
    label: { type: String, default: '' },
    placeholder: { type: String, default: 'DK, BR, 33169, 205215…' },
});
defineEmits(['update:modelValue', 'submit']);
</script>
