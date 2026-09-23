<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import SelectorTamanioTexto from '@/components/SelectorTamanioTexto.vue';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Pantalla',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Pantalla" />

    <h1 class="sr-only">Configuración de la pantalla</h1>

    <!--
        El tamaño de letra va PRIMERO, antes del modo claro/oscuro. No es orden
        alfabético ni de importancia técnica: es a lo que viene la mayoría de la
        gente que abre esta pantalla en esta app.
    -->
    <div class="space-y-10">
        <section class="space-y-4">
            <Heading
                variant="small"
                title="Tamaño de la letra"
                description="Elegí el que te resulte más cómodo de leer. Cambia en toda la app."
            />
            <SelectorTamanioTexto />
        </section>

        <section class="space-y-4">
            <Heading
                variant="small"
                title="Claro u oscuro"
                description="Cómo se ven los colores de la app"
            />
            <AppearanceTabs />
        </section>
    </div>
</template>
