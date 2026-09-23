<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core';
import { computed } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import { useAvisos } from '@/composables/useAvisos';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

// Un solo lugar en toda la app convierte el flash del servidor en un toast.
useAvisos();

/*
 * Abajo en el celular, donde está el pulgar; arriba a la derecha en escritorio,
 * donde no tapa el contenido.
 *
 * Se resuelve con una media query de verdad y no con clases `md:` sobre el
 * Toaster: sonner posiciona su lista con estilos en línea, así que una clase
 * de Tailwind tendría que ganarle con `!important` y quedar atada a la
 * estructura interna de la librería.
 */
const esEscritorio = useMediaQuery('(min-width: 768px)');
const posicion = computed(() =>
    esEscritorio.value ? ('top-right' as const) : ('bottom-center' as const),
);
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
        </AppContent>

        <!--
            `rich-colors` para que el verde y el rojo se distingan sin leer: es
            el primer dato que da un aviso.

            `close-button` porque los errores no se cierran solos (ver
            useAvisos) y hace falta una forma de sacarlos de encima.
        -->
        <Toaster
            :position="posicion"
            :mobile-offset="{
                bottom: 'calc(1rem + env(safe-area-inset-bottom))',
                left: 'calc(1rem + env(safe-area-inset-left))',
                right: 'calc(1rem + env(safe-area-inset-right))',
            }"
            rich-colors
            close-button
            container-aria-label="Avisos"
            :toast-options="{ closeButtonAriaLabel: 'Cerrar el aviso' }"
        />
    </AppShell>
</template>
