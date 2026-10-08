<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { tonos } from '@/lib/tonos';
import type { Tono } from '@/lib/tonos';
import { cn } from '@/lib/utils';

/*
 * El ícono de una sección dentro de su pastilla de color. Es decoración: el
 * nombre de la sección siempre va escrito al lado, así que va con
 * `aria-hidden` y el lector de pantalla no lo anuncia dos veces.
 */
const props = withDefaults(
    defineProps<{
        icon: LucideIcon;
        tono: Tono;
        tamanio?: 'chico' | 'grande';
        class?: string;
    }>(),
    { tamanio: 'chico', class: undefined },
);
</script>

<template>
    <span
        aria-hidden="true"
        :class="
            cn(
                'inline-flex shrink-0 items-center justify-center',
                tamanio === 'grande'
                    ? 'size-11 rounded-xl'
                    : 'size-7 rounded-lg',
                tonos[props.tono].insignia,
                props.class,
            )
        "
    >
        <component
            :is="icon"
            :class="tamanio === 'grande' ? 'size-6' : 'size-4'"
        />
    </span>
</template>
