<template>
    <!--
        Backend returns the exact same Telegram-HTML the bot sends to chat
        (stats/compare/rankings/mvt all go through formatters that already
        emit Telegram markup). Render it as-is so the Mini App matches the
        in-chat experience character for character — including <code> blocks
        and entity-encoded variant text.

        Safe because the markup is server-controlled: every user-facing value
        passes through htmlspecialchars() in the PHP formatters before being
        embedded.
    -->
    <div
        class="tg-html"
        v-html="html"
    />
</template>

<script setup>
defineProps({
    html: { type: String, required: true },
});
</script>

<style scoped>
.tg-html :deep(code) {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 0.85em;
    white-space: pre;
}
.tg-html :deep(b) {
    font-weight: 600;
}
.tg-html :deep(i) {
    opacity: 0.75;
}
</style>
