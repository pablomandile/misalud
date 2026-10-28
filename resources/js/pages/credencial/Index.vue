<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CreditCard, FileText } from '@lucide/vue';
import { ref } from 'vue';
import CredencialController from '@/actions/App/Http/Controllers/CredencialController';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
import Heading from '@/components/Heading.vue';
import SelectorDeFicha from '@/components/SelectorDeFicha.vue';
import type { DocumentoVisible } from '@/components/VisorDocumento.vue';
import VisorDocumento from '@/components/VisorDocumento.vue';
import { Card, CardContent } from '@/components/ui/card';

/*
 * La credencial digital del paciente principal, a un toque desde el menú. Es
 * lo que se muestra en un mostrador con alguien esperando: los números van
 * grandes y la imagen, tocándola, a pantalla completa.
 */

type Credencial = DocumentoVisible & { id: number };

defineProps<{
    paciente: { id: number; nombre: string } | null;
    fichas: { id: number; nombre: string }[];
    coberturas: {
        id: number;
        entidad: string;
        tipo: string;
        plan: string | null;
        nroAfiliado: string | null;
        telefonoUrgencias: string | null;
        credenciales: Credencial[];
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Credencial', href: CredencialController.index() },
        ],
    },
});

/** UNO SOLO para toda la pantalla, y fuera de todo v-for. */
const abierta = ref<DocumentoVisible | null>(null);

const enlace = (id: number) =>
    CredencialController.index({ query: { paciente: id } });
</script>

<template>
    <Head title="Credencial" />

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="
                paciente ? `Credencial de ${paciente.nombre}` : 'Credencial'
            "
            description="Tocá la imagen para verla a pantalla completa. Si ya la abriste una vez con señal, se ve también sin conexión."
        />

        <SelectorDeFicha
            :fichas="fichas"
            :actual="paciente?.id ?? null"
            :enlace="enlace"
        />

        <div
            v-if="!paciente"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <p class="text-muted-foreground">Sin datos.</p>
            <Link
                :href="PacienteController.index()"
                class="mt-2 inline-flex min-h-11 items-center underline underline-offset-4"
            >
                Cargar una ficha
            </Link>
        </div>

        <div
            v-else-if="coberturas.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <CreditCard class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-muted-foreground">
                Sin datos: no hay ninguna cobertura activa.
            </p>
            <Link
                :href="PacienteController.index()"
                class="mt-2 inline-flex min-h-11 items-center underline underline-offset-4"
            >
                Cargarla en la ficha
            </Link>
        </div>

        <ul v-else class="grid gap-4 lg:grid-cols-2">
            <li v-for="cobertura in coberturas" :key="cobertura.id">
                <Card class="h-full">
                    <CardContent class="space-y-4">
                        <div class="space-y-1">
                            <p class="text-xl font-semibold wrap-break-word">
                                {{ cobertura.entidad }}
                            </p>
                            <p class="text-muted-foreground">
                                {{ cobertura.tipo
                                }}<span v-if="cobertura.plan">
                                    · Plan {{ cobertura.plan }}</span
                                >
                            </p>
                            <p v-if="cobertura.nroAfiliado" class="text-lg">
                                Afiliado
                                <span class="font-semibold tabular-nums">{{
                                    cobertura.nroAfiliado
                                }}</span>
                            </p>
                            <p v-if="cobertura.telefonoUrgencias">
                                Urgencias
                                <a
                                    :href="`tel:${cobertura.telefonoUrgencias.replace(/[^0-9+]/g, '')}`"
                                    class="inline-flex min-h-11 items-center font-medium underline underline-offset-4"
                                    >{{ cobertura.telefonoUrgencias }}</a
                                >
                            </p>
                        </div>

                        <p
                            v-if="cobertura.credenciales.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            Sin credencial digital: se sube desde la ficha, en
                            Cobertura.
                        </p>

                        <div
                            v-else
                            class="grid grid-cols-1 gap-3 sm:grid-cols-2"
                        >
                            <button
                                v-for="credencial in cobertura.credenciales"
                                :key="credencial.id"
                                type="button"
                                class="overflow-hidden rounded-lg border bg-muted/40 text-left focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                :aria-label="`Ver la credencial de ${cobertura.entidad} a pantalla completa`"
                                @click="abierta = credencial"
                            >
                                <img
                                    v-if="credencial.mime.startsWith('image/')"
                                    :src="credencial.url"
                                    :alt="`Credencial de ${cobertura.entidad}`"
                                    class="aspect-[1.6] w-full object-contain"
                                />
                                <span
                                    v-else
                                    class="flex min-h-24 items-center gap-2 p-4"
                                >
                                    <FileText
                                        class="size-6 text-muted-foreground"
                                    />
                                    Credencial en PDF
                                </span>
                            </button>
                        </div>
                    </CardContent>
                </Card>
            </li>
        </ul>

        <VisorDocumento :documento="abierta" @cerrar="abierta = null" />
    </div>
</template>
