<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { ClipboardPen, Pill, Send } from '@lucide/vue';
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
 * Pedirle por mail los medicamentos al médico, un centro o una farmacia. El
 * mensaje se guarda como plantilla de la ficha y vuelve precargado.
 *
 * ⚠️ Todo con `v-model`: las casillas y la vista previa tienen estado local, y
 * un `:value` en un hermano se pisaría (la regla de la Etapa 10). Al cambiar de
 * ficha la página no se remonta, así que el estado se reinicia con un `watch`.
 */

type Medicamento = {
    id: number;
    nombre: string;
    dosis: string;
    activo: boolean;
};

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
    plantilla: string;
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

const elegidos = ref<number[]>([]);
const destinatario = ref('');
const mensaje = ref(props.plantilla);

watch(
    () => props.paciente?.id,
    () => {
        elegidos.value = [];
        mensaje.value = props.plantilla;
    },
);

const remitente = computed(
    () => (usePage().props.auth as { user?: { name?: string } })?.user?.name,
);

const elegido = computed(() =>
    props.destinatarios.find((d) => d.clave === destinatario.value),
);

const lista = computed(() =>
    props.medicamentos
        .filter((m) => elegidos.value.includes(m.id))
        .map((m) => `- ${m.nombre} · ${m.dosis}`),
);

const listo = computed(
    () =>
        elegidos.value.length > 0 &&
        !!elegido.value &&
        mensaje.value.trim() !== '',
);

// Al mandar queda la plantilla; lo que se elige es de cada pedido.
function alMandar(): void {
    elegidos.value = [];
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

            <!-- 1. Qué -->
            <section class="space-y-3">
                <h2 class="font-medium">¿Qué medicamentos?</h2>
                <ul class="grid gap-2">
                    <li
                        v-for="medicamento in medicamentos"
                        :key="medicamento.id"
                        class="rounded-md border p-2"
                    >
                        <label
                            class="flex min-h-11 min-w-0 cursor-pointer items-center gap-3"
                        >
                            <input
                                v-model="elegidos"
                                type="checkbox"
                                name="tratamientos[]"
                                :value="medicamento.id"
                                class="size-5 shrink-0"
                            />
                            <span class="min-w-0">
                                <span
                                    class="block text-sm font-medium break-words"
                                >
                                    {{ medicamento.nombre }}
                                </span>
                                <span
                                    class="block text-sm break-words text-muted-foreground"
                                >
                                    {{ medicamento.dosis
                                    }}{{
                                        medicamento.activo
                                            ? ''
                                            : ' · suspendido'
                                    }}
                                </span>
                            </span>
                        </label>
                    </li>
                </ul>
                <InputError
                    :message="errors.tratamientos ?? errors['tratamientos.0']"
                />
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

            <!-- 3. El mensaje -->
            <section class="space-y-3">
                <h2 class="font-medium">El mensaje</h2>
                <div class="grid gap-2">
                    <Label for="mensaje-pedido">Mensaje</Label>
                    <textarea
                        id="mensaje-pedido"
                        v-model="mensaje"
                        name="mensaje"
                        rows="4"
                        aria-describedby="mensaje-pedido-ayuda"
                        :class="`${campoBase} min-h-24`"
                    />
                    <p
                        id="mensaje-pedido-ayuda"
                        class="text-sm text-muted-foreground"
                    >
                        Se guarda para el próximo pedido de esta ficha. La lista
                        de medicamentos se agrega sola abajo.
                    </p>
                    <InputError :message="errors.mensaje" />
                </div>
            </section>

            <!-- 4. Cómo llega -->
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
                    <p class="mt-3">{{ mensaje }}</p>
                    <p class="mt-3">
                        {{
                            lista.length > 0
                                ? lista.join('\n')
                                : '(elegí los medicamentos)'
                        }}
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
