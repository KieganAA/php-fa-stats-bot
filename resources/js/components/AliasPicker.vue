<template>
    <select
        :value="modelValue"
        class="w-full px-3 py-2 rounded-lg text-sm bg-[var(--tg-theme-bg-color,#fff)] border border-[var(--tg-theme-section-separator-color,#e5e7eb)]"
        @change="$emit('update:modelValue', $event.target.value)"
    >
        <option value="">— select alias —</option>
        <option
            v-for="a in aliases"
            :key="a.id"
            :value="a.alias"
        >{{ a.alias }} → {{ a.landing_name || a.landing_uuid }} (LP{{ a.position }})</option>
    </select>
</template>

<script setup>
defineProps({
    aliases: { type: Array, required: true },
    modelValue: { type: String, default: '' },
});
defineEmits(['update:modelValue']);
</script>
