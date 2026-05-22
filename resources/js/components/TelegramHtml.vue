<template>
    <!--
        Server already emits Telegram-HTML that matches what the bot sends to
        chat (stats/compare/rankings/mvt go through the same formatters). We
        render it as-is so the Mini App matches in-chat character for char.

        Safe to v-html because the markup is server-controlled and every
        user-string passes through htmlspecialchars in the PHP formatters.
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
.tg-html {
    line-height: 1.45;
}
/* Long monospaced rows in /lps* etc would overflow a phone viewport. Allow
   horizontal scroll on overflow instead of wrapping mid-row (which scrambles
   columns). Each <code> line is its own scrollable strip so the report still
   scrolls vertically as a single block but a wide row can be panned. */
.tg-html :deep(code) {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 0.82em;
    white-space: pre;
    display: block;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
/* Inline <code> (e.g. group names in /groups) stays inline. */
.tg-html :deep(p > code), .tg-html :deep(span > code) {
    display: inline;
    overflow: visible;
}
.tg-html :deep(b) {
    font-weight: 600;
}
.tg-html :deep(i) {
    opacity: 0.75;
}
</style>
