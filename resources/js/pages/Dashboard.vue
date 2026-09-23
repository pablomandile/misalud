<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CreditCard } from '@lucide/vue';
import { ref } from 'vue';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
import Heading from '@/components/Heading.vue';
import type { DocumentoVisible } from '@/components/VisorDocumento.vue';
import VisorDocumento from '@/components/VisorDocumento.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { dashboard } from '@/routes';

/*
 * Panel principal.
 *
 * Por ahora el único contenido es el acceso rápido a la credencial (paso
 * 4.2 del plan): mostrarla en un mostrador es uno de los usos más
 * frecuentes de la app, y no puede estar a más de un toque. El resto del
 * dashboard -recetas, tratamientos, turnos, órdenes, últimas mediciones-
 * llega en la Etapa 15, cuando esos módulos existan.
 */

type Credencial = DocumentoVisible & {
    id: number;
    entidad: string;
};

defineProps<{
    pacienteActivo: { id: number; nombre: string } | null;
    credenciales: Credencial[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Panel', href: dashboard() }],
    },
});

/*
 * Mismo patrón que pacientes/Index.vue: UN SOLO documento abierto para
 * toda la pantalla, y un solo <VisorDocumento> al final del template.
 */
const documentoAbierto = ref<DocumentoVisible | null>(null);
</script>

<template>
    <Head title="Panel" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Panel"
            :description="
                pacienteActivo
                    ? `Mostrando ${pacienteActivo.nombre}`
                    : undefined
            "
        />

        <!-- Sin paciente: no hay a quién mostrarle nada. Regla 2: sin datos
             se dice "sin datos", no se infiere nada. -->
        <div
            v-if="!pacienteActivo"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <p class="text-sm text-muted-foreground">
                Todavía no hay ningún paciente cargado.
            </p>
            <Button as-child class="mt-4">
                <Link :href="PacienteController.index()">Ir a Pacientes</Link>
            </Button>
        </div>

        <template v-else>
            <Card>
                <CardContent class="space-y-3">
                    <div class="flex items-center gap-2">
                        <CreditCard class="size-5 text-muted-foreground" />
                        <h2 class="font-medium">Credencial</h2>
                    </div>

                    <p
                        v-if="credenciales.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Sin datos. Todavía no cargaste ninguna credencial en
                        Cobertura médica.
                    </p>

                    <div v-else class="grid gap-2 sm:grid-cols-2">
                        <button
                            v-for="credencial in credenciales"
                            :key="credencial.id"
                            type="button"
                            class="flex min-h-11 items-center gap-3 rounded-md border p-3 text-left hover:bg-accent"
                            @click="documentoAbierto = credencial"
                        >
                            <CreditCard
                                class="size-5 shrink-0 text-muted-foreground"
                            />
                            <span class="min-w-0">
                                <span class="block truncate font-medium">
                                    {{ credencial.entidad }}
                                </span>
                                <span
                                    class="block truncate text-sm text-muted-foreground"
                                >
                                    {{ credencial.nombre }}
                                </span>
                            </span>
                        </button>
                    </div>
                </CardContent>
            </Card>
        </template>

        <!-- UNO SOLO para toda la pantalla, fuera de todo v-for. -->
        <VisorDocumento
            :documento="documentoAbierto"
            @cerrar="documentoAbierto = null"
        />
    </div>
</template>
