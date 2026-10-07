<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, AudioLines, Pause, Play } from '@lucide/vue';
import ConsultaController from '@/actions/App/Http/Controllers/ConsultaController';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { formatoTiempo, useReproductor } from '@/composables/useReproductor';

/*
 * "Grabaciones": una vista sobre los audios de las consultas de la ficha, la
 * más reciente arriba. No es una tabla propia (ver
 * `ConsultaController::grabaciones()`), y se escuchan con el mismo reproductor
 * de toda la app.
 */

type Grabacion = {
    id: number;
    nombre: string;
    duracion: number | null;
    url: string;
    consultaId: number;
    consultaVisible: string;
};

defineProps<{
    paciente: { id: number; nombre: string; puedeEditar: boolean };
    grabaciones: Grabacion[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pacientes', href: PacienteController.index() },
            { title: 'Grabaciones', href: '' },
        ],
    },
});

const reproductor = useReproductor();

const suena = (g: Grabacion): boolean =>
    reproductor.pista.value?.id === g.id && reproductor.sonando.value;

function escuchar(g: Grabacion): void {
    reproductor.reproducir({ id: g.id, url: g.url, titulo: g.consultaVisible });
}
</script>

<template>
    <Head :title="`Grabaciones de ${paciente.nombre}`" />

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="`Grabaciones de ${paciente.nombre}`"
            description="Lo que se grabó en cada consulta"
        />

        <div
            v-if="grabaciones.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <AudioLines class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no hay grabaciones. Se suben desde cada consulta.
            </p>
        </div>

        <ul v-else class="grid gap-2">
            <li
                v-for="g in grabaciones"
                :key="g.id"
                class="flex items-center gap-3 rounded-md border p-3"
            >
                <Button
                    :variant="suena(g) ? 'default' : 'outline'"
                    size="icon"
                    class="shrink-0"
                    :aria-label="
                        suena(g)
                            ? 'Pausar la grabación'
                            : `Escuchar la grabación de ${g.consultaVisible}`
                    "
                    @click="escuchar(g)"
                >
                    <Pause v-if="suena(g)" class="size-5" />
                    <Play v-else class="size-5" />
                </Button>
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium">
                        {{ g.consultaVisible }}
                    </span>
                    <span class="block truncate text-sm text-muted-foreground">
                        {{ g.duracion ? formatoTiempo(g.duracion) : '' }}
                        {{ g.duracion ? '·' : '' }} {{ g.nombre }}
                    </span>
                </span>
            </li>
        </ul>

        <div class="flex flex-wrap gap-2">
            <Button variant="outline" as-child>
                <Link
                    :href="ConsultaController.index({ paciente: paciente.id })"
                >
                    <ArrowLeft />
                    Volver a las consultas
                </Link>
            </Button>
        </div>
    </div>
</template>
