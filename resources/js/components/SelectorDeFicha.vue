<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { RouteDefinition } from '@/wayfinder';

/*
 * Cambiar de ficha en las pantallas a las que el menú lleva directo con el
 * paciente principal (Credencial, Variables, Medicamentos). Botones y no un
 * desplegable, como el selector del panel: se ven todas las opciones de un
 * vistazo y se elige con un toque. Con una sola ficha no se dibuja.
 */
defineProps<{
    fichas: { id: number; nombre: string }[];
    actual: number | null;
    enlace: (id: number) => RouteDefinition<'get'> | string;
}>();
</script>

<template>
    <nav
        v-if="fichas.length > 1"
        aria-label="Elegir ficha"
        class="flex flex-wrap gap-2"
    >
        <Link
            v-for="ficha in fichas"
            :key="ficha.id"
            :href="enlace(ficha.id)"
            :aria-current="ficha.id === actual ? 'page' : undefined"
            class="inline-flex min-h-11 items-center rounded-md border px-4 text-sm font-medium transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
            :class="
                ficha.id === actual
                    ? 'border-primary bg-primary text-primary-foreground'
                    : 'bg-background hover:bg-accent'
            "
        >
            {{ ficha.nombre }}
        </Link>
    </nav>
</template>
