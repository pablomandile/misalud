<script setup lang="ts">
import { computed } from 'vue';
import { SidebarInset } from '@/components/ui/sidebar';
import type { AppVariant } from '@/types';

type Props = {
    variant?: AppVariant;
    class?: string;
};

const props = withDefaults(defineProps<Props>(), {
    variant: 'sidebar',
});
const className = computed(() => props.class);
</script>

<template>
    <!--
        La zona segura lateral va acá y no en cada página: este es el
        envoltorio de todo el contenido, así que cubre el encabezado y las
        pantallas de una sola vez.
    -->
    <SidebarInset
        v-if="props.variant === 'sidebar'"
        class="zona-segura-lateral"
        :class="className"
    >
        <slot />
    </SidebarInset>
    <main
        v-else
        class="zona-segura-lateral mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl"
        :class="className"
    >
        <slot />
    </main>
</template>
