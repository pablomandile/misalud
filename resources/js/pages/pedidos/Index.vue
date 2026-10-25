<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { ClipboardPen, Pill, Send, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CentroController from '@/actions/App/Http/Controllers/CentroController';
import FarmaciaController from '@/actions/App/Http/Controllers/FarmaciaController';
import MedicoController from '@/actions/App/Http/Controllers/MedicoController';
import PedidoMedicamentosController from '@/actions/App/Http/Controllers/PedidoMedicamentosController';
import TratamientoController from '@/actions/App/Http/Controllers/TratamientoController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SelectorDeFicha from '@/components/SelectorDeFicha.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

/*
 * Pedirle por mail los medicamentos al médico, un centro o una farmacia. Se
 * suman de a uno con el select de arriba, y cada uno lleva su propio mensaje,
 * que se guarda como plantilla de ESE medicamento y vuelve precargado.
 *
 * ⚠️ Todo con `v-model`: la lista y la vista previa tienen estado local, y un
 * `:value` en un hermano se pisaría (la regla de la Etapa 10). Al cambiar de
 * ficha la página no se remonta, así que el estado se reinicia con un `watch`.
 */

type Medicamento = {
    id: number;
    nombre: string;
    dosis: string;
    activo: boolean;
    plantilla: string;
};

type Renglon = { id: number; mensaje: string };

type DestinatarioOpcion = {
    clave: string;
    nombre: string;
    email: string;
    etiqueta: string;
};

const props = defineProps<{
    paciente: { id: number; nombre: string; puedeEditar: boolean } | null;
    fichas: { id: number; nombre: string }[];
    medicamentos: Medicamento[];
    destinatarios: DestinatarioOpcion[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Pedido de medicamentos', href: '' }],
    },
});

const enlaceFicha = (id: number) =>
    PedidoMedicamentosController.index({ query: { paciente: id } });

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

const renglones = ref<Renglon[]>([]);
const aSumar = ref('');
const destinatario = ref('');

watch(
    () => props.paciente?.id,
    () => {
        renglones.value = [];
    },
);

const medicamentoDe = (id: number) =>
    props.medicamentos.find((m) => m.id === id);

const disponibles = computed(() =>
    props.medicamentos.filter(
        (m) => !renglones.value.some((r) => r.id === m.id),
    ),
);

// Elegir en el select lo suma con su plantilla, y el select vuelve a "Sumá…".
watch(aSumar, (valor) => {
    const medicamento = medicamentoDe(Number(valor));

    if (medicamento) {
        renglones.value.push({
            id: medicamento.id,
            mensaje: medicamento.plantilla,
        });
    }

    if (valor !== '') {
        aSumar.value = '';
    }
});

function sacar(id: number): void {
    renglones.value = renglones.value.filter((r) => r.id !== id);
}

const remitente = computed(
    () => (usePage().props.auth as { user?: { name?: string } })?.user?.name,
);

const elegido = computed(() =>
    props.destinatarios.find((d) => d.clave === destinatario.value),
);

const listo = computed(
    () =>
        renglones.value.length > 0 &&
        renglones.value.every((r) => r.mensaje.trim() !== '') &&
        !!elegido.value,
);

// Al mandar quedan las plantillas; lo que se elige es de cada pedido.
function alMandar(): void {
    renglones.value = [];
    destinatario.value = '';
}
</script>

<template>
    <Head title="Pedido de medicamentos" />

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="
                paciente
                    ? `Pedido de medicamentos de ${paciente.nombre}`
                    : 'Pedido de medicamentos'
            "
            description="Pedile por mail los medicamentos al médico, a un centro o a la farmacia"
        />

        <SelectorDeFicha
            v-if="paciente"
            :fichas="fichas"
            :actual="paciente.id"
            :enlace="enlaceFicha"
        />

        <p
            v-if="!paciente"
            class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            Todavía no tenés ninguna ficha.
        </p>

        <p
            v-else-if="!paciente.puedeEditar"
            class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            Solo quien puede cargar datos en esta ficha arma pedidos.
        </p>

        <div
            v-else-if="medicamentos.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <Pill class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Esta ficha todavía no tiene medicamentos cargados.
            </p>
            <Button variant="outline" class="mt-4" as-child>
                <Link
                    :href="
                        TratamientoController.index({ paciente: paciente.id })
                    "
                >
                    <Pill />
                    Ir a Medicamentos
                </Link>
            </Button>
        </div>

        <Form
            v-else
            v-bind="PedidoMedicamentosController.store.form()"
            :options="{ preserveScroll: true }"
            v-slot="{ errors, processing }"
            class="space-y-6"
            @success="alMandar"
        >
            <input type="hidden" name="paciente_id" :value="paciente.id" />

            <!-- 1. Qué, y qué le pedís de cada uno -->
            <section class="space-y-3">
                <h2 class="font-medium">¿Qué medicamentos?</h2>
                <div class="grid gap-2">
                    <Label for="sumar-medicamento">Sumar un medicamento</Label>
                    <select
                        id="sumar-medicamento"
                        v-model="aSumar"
                        :disabled="disponibles.length === 0"
                        :class="`${campoBase} min-h-11`"
                    >
                        <option
                            value=""
                            disabled
                            class="bg-background text-foreground"
                        >
                            {{
                                disponibles.length === 0
                                    ? 'Ya están todos en el pedido'
                                    : 'Elegí un medicamento'
                            }}
                        </option>
                        <option
                            v-for="medicamento in disponibles"
                            :key="medicamento.id"
                            :value="String(medicamento.id)"
                            class="bg-background text-foreground"
                        >
                            {{ medicamento.nombre }} · {{ medicamento.dosis
                            }}{{ medicamento.activo ? '' : ' (suspendido)' }}
                        </option>
                    </select>
                    <InputError :message="errors.pedidos" />
                </div>

                <ul v-if="renglones.length > 0" class="grid gap-3">
                    <li
                        v-for="(renglon, i) in renglones"
                        :key="renglon.id"
                        class="space-y-2 rounded-md border p-3"
                    >
                        <input
                            type="hidden"
                            :name="`pedidos[${i}][tratamiento_id]`"
                            :value="renglon.id"
                        />
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-medium break-words">
                                    {{ medicamentoDe(renglon.id)?.nombre }}
                                </p>
                                <p
                                    class="text-sm break-words text-muted-foreground"
                                >
                                    {{ medicamentoDe(renglon.id)?.dosis
                                    }}{{
                                        medicamentoDe(renglon.id)?.activo
                                            ? ''
                                            : ' · suspendido'
                                    }}
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="shrink-0"
                                @click="sacar(renglon.id)"
                            >
                                <X />
                                Sacar
                            </Button>
                        </div>
                        <div class="grid gap-2">
                            <Label :for="`mensaje-pedido-${renglon.id}`">
                                Mensaje para este medicamento
                            </Label>
                            <textarea
                                :id="`mensaje-pedido-${renglon.id}`"
                                v-model="renglon.mensaje"
                                :name="`pedidos[${i}][mensaje]`"
                                rows="3"
                                :class="`${campoBase} min-h-20`"
                            />
                            <InputError
                                :message="
                                    errors[`pedidos.${i}.mensaje`] ??
                                    errors[`pedidos.${i}.tratamiento_id`]
                                "
                            />
                        </div>
                    </li>
                </ul>
                <p
                    v-if="renglones.length > 0"
                    class="text-sm text-muted-foreground"
                >
                    El mensaje de cada medicamento se guarda para el próximo
                    pedido.
                </p>
            </section>

            <!-- 2. A quién -->
            <section class="space-y-3">
                <h2 class="font-medium">¿A quién?</h2>
                <div
                    v-if="destinatarios.length === 0"
                    class="space-y-3 rounded-md border border-dashed p-4 text-sm text-muted-foreground"
                >
                    <p>
                        Ningún médico, centro ni farmacia tiene el correo
                        cargado. Agregalo en su ficha y aparece acá.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="MedicoController.index()"
                                >Médicos</Link
                            >
                        </Button>
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="CentroController.index()"
                                >Centros</Link
                            >
                        </Button>
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="FarmaciaController.index()"
                                >Farmacias</Link
                            >
                        </Button>
                    </div>
                </div>
                <div v-else class="grid gap-2">
                    <Label for="destinatario-pedido">Mandárselo a</Label>
                    <select
                        id="destinatario-pedido"
                        v-model="destinatario"
                        name="destinatario"
                        :class="`${campoBase} min-h-11`"
                    >
                        <option
                            value=""
                            disabled
                            class="bg-background text-foreground"
                        >
                            Elegí a quién
                        </option>
                        <option
                            v-for="opcion in destinatarios"
                            :key="opcion.clave"
                            :value="opcion.clave"
                            class="bg-background text-foreground"
                        >
                            {{ opcion.etiqueta }} · {{ opcion.nombre }}
                        </option>
                    </select>
                    <p v-if="elegido" class="text-sm text-muted-foreground">
                        Va a {{ elegido.email }}
                    </p>
                    <InputError :message="errors.destinatario" />
                </div>
            </section>

            <!-- 3. Cómo llega -->
            <section class="space-y-3">
                <h2 class="flex items-center gap-2 font-medium">
                    <ClipboardPen class="size-4" />
                    Así llega
                </h2>
                <div
                    class="rounded-md border bg-muted/40 p-4 text-sm break-words whitespace-pre-line"
                >
                    <p class="text-muted-foreground">
                        Asunto: Pedido de medicamentos
                    </p>
                    <p class="mt-3">
                        {{ remitente }} te manda este pedido desde MiSalud.
                    </p>
                    <template v-if="renglones.length > 0">
                        <div
                            v-for="renglon in renglones"
                            :key="renglon.id"
                            class="mt-3"
                        >
                            <p>
                                - {{ medicamentoDe(renglon.id)?.nombre }} ·
                                {{ medicamentoDe(renglon.id)?.dosis }}
                            </p>
                            <p>{{ renglon.mensaje }}</p>
                        </div>
                    </template>
                    <p v-else class="mt-3 text-muted-foreground">
                        (elegí los medicamentos)
                    </p>
                </div>
            </section>

            <Button
                type="submit"
                class="w-full sm:w-auto"
                :disabled="!listo || processing"
            >
                <Send />
                {{
                    processing
                        ? 'Mandando…'
                        : elegido
                          ? `Mandar el pedido a ${elegido.nombre}`
                          : 'Mandar el pedido'
                }}
            </Button>
        </Form>
    </div>
</template>
