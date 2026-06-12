<template>
    <div class="min-h-screen flex flex-col">
        <nav class="sticky top-0 z-10 flex gap-1 overflow-x-auto whitespace-nowrap p-2 border-b border-[var(--tg-theme-section-separator-color,#e5e7eb)] bg-[var(--tg-theme-secondary-bg-color,#f3f4f6)]">
            <router-link
                v-for="t in tabs"
                :key="t.path"
                :to="t.path"
                v-slot="{ isActive }"
                custom
            >
                <a
                    :href="t.path"
                    @click.prevent="$router.push(t.path)"
                    class="px-3 py-1.5 rounded-full text-sm transition-colors"
                    :class="isActive
                        ? 'bg-[var(--tg-theme-button-color,#3b82f6)] text-[var(--tg-theme-button-text-color,#fff)]'
                        : 'text-[var(--tg-theme-text-color,#000)] hover:bg-[var(--tg-theme-bg-color,#fff)]'"
                >{{ t.label }}</a>
            </router-link>
        </nav>

        <main class="flex-1 p-3">
            <router-view />
        </main>
    </div>
</template>

<script setup>
// Campaign-first: the bot's job is campaign subscriptions now, so the nav
// leads with them. The old /stats and /top screens still exist as routes (for
// power users / future return) but are hidden from the tab bar.
const tabs = [
    { path: '/subs', label: '🔔 Подписки' },
    { path: '/settings', label: '⚙️ Настройки' },
    { path: '/help', label: '❓ Помощь' },
];
</script>
