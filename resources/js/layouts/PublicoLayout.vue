<script setup lang="ts">
import AvisoCookies from '@/components/AvisoCookies.vue';

/*
 * Para las páginas que se abren sin sesión y se dibujan solas (la portada y la
 * de cookies): no ponen nada alrededor, solo el aviso de cookies, que tiene
 * que verse en la primera visita.
 */
</script>

<template>
    <slot />
    <AvisoCookies />
</template>
