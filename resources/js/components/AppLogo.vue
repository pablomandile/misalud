<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import LogoConNombre from '@/components/LogoConNombre.vue';
import { useSidebar } from '@/components/ui/sidebar';

/*
 * El logo de la cabecera del menú, según dónde se dibuja:
 *
 * - En el celular (el menú hamburguesa) va el ícono solo.
 * - En escritorio, el logo con el nombre.
 * - Con la barra lateral contraída a íconos, el ícono solo: el nombre no entra
 *   en los 48 px que le quedan.
 */
const { isMobile } = useSidebar();
</script>

<template>
    <AppLogoIcon v-if="isMobile" class="size-11 rounded-lg" />
    <template v-else>
        <AppLogoIcon
            class="hidden size-8 rounded-md group-data-[collapsible=icon]:block"
        />
        <LogoConNombre class="h-10 group-data-[collapsible=icon]:hidden" />
    </template>
</template>
