<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { RouteDefinition } from '@/wayfinder';

/*
 * Las solapas de un catálogo: «Míos» y una por cada ficha que le compartieron
 * a esta persona. En la de una ficha se ve lo que usa esa ficha (los médicos de
 * sus estudios y turnos, sus centros, sus medicamentos), sea de quien sea, en
 * solo lectura. Ver `CatalogoDeLaFicha`.
 *
 * Botones y no un desplegable, como el resto de los selectores de ficha. Sin
 * fichas compartidas no se dibuja.
 */
defineProps<{
    fichas: { id: number; nombre: string }[];
    ficha: { id: number; nombre: string } | null;
    enlace: (ficha: number | null) => RouteDefinition<'get'>;
}>();

const clase = (activa: boolean) =>
    activa
        ? 'border-primary bg-primary text-primary-foreground'
        : 'bg-background hover:bg-accent';
</script>

<template>
    <div v-if="fichas.length > 0" class="space-y-2">
        <nav aria-label="De quién" class="flex flex-wrap gap-2">
            <Link
                :href="enlace(null)"
                :aria-current="ficha === null ? 'page' : undefined"
                class="inline-flex min-h-11 items-center rounded-md border px-4 text-sm font-medium transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                :class="clase(ficha === null)"
            >
                Míos
            </Link>
            <Link
                v-for="opcion in fichas"
                :key="opcion.id"
                :href="enlace(opcion.id)"
                :aria-current="ficha?.id === opcion.id ? 'page' : undefined"
                class="inline-flex min-h-11 items-center rounded-md border px-4 text-sm font-medium transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                :class="clase(ficha?.id === opcion.id)"
            >
                De {{ opcion.nombre }}
            </Link>
        </nav>
        <p v-if="ficha" class="text-sm text-muted-foreground">
            Lo que figura en la ficha de {{ ficha.nombre }}. Se puede mirar;
            para cambiar un dato, copialo a tu lista.
        </p>
    </div>
</template>
