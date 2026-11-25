<script setup lang="ts">
import { Form, Link, router, usePage } from '@inertiajs/vue3';
import { ClipboardPen, RotateCcw, Save, Send, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CentroController from '@/actions/App/Http/Controllers/CentroController';
import FarmaciaController from '@/actions/App/Http/Controllers/FarmaciaController';
import MedicoController from '@/actions/App/Http/Controllers/MedicoController';
import PedidoMedicamentosController from '@/actions/App/Http/Controllers/PedidoMedicamentosController';
import InputError from '@/components/InputError.vue';
import VariablesDelMensaje from '@/components/VariablesDelMensaje.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { completar } from '@/lib/plantillaDePedido';
import type { VariablesDelPaciente } from '@/lib/plantillaDePedido';
import type { DestinatarioOpcion, MedicamentoDelPedido } from './tipos';

/*
 * La solapa «Mensajes»: pedirle por mail los medicamentos al médico, un centro
 * o una farmacia. Se suman de a uno con el select de arriba, y cada uno lleva su
 * propio mensaje, que se guarda como plantilla de ESE medicamento y vuelve
 * precargado. Los medicamentos sin plantilla propia usan el mensaje genérico de
 * la cuenta, que se edita arriba de todo. Los mensajes llevan variables
 * ({nombre}, {apellido}, {medicamento}) que se reemplazan en la vista previa y
 * al mandar: el campo muestra la plantilla tal cual se guarda.
 *
 * ⚠️ Todo con `v-model`: la lista y la vista previa tienen estado local, y un
 * `:value` en un hermano se pisaría (la regla de la Etapa 10). Al cambiar de
 * ficha la página no se remonta, así que el estado se reinicia con un `watch`.
 */

type Renglon = { id: number; mensaje: string };

const props = defineProps<{
    paciente: { id: number; nombre: string; variables: VariablesDelPaciente };
    medicamentos: MedicamentoDelPedido[];
    destinatarios: DestinatarioOpcion[];
    plantillaGenerica: string;
    plantillaOriginal: string;
}>();

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const renglones = ref<Renglon[]>([]);
const aSumar = ref('');
const destinatario = ref('');

watch(
    () => props.paciente.id,
    () => {
        renglones.value = [];
        aSumar.value = '';
    },
);

const medicamentoDe = (id: number) =>
    props.medicamentos.find((m) => m.id === id);

const enElPedido = (id: number) => renglones.value.some((r) => r.id === id);

const todosEnElPedido = computed(() =>
    props.medicamentos.every((m) => enElPedido(m.id)),
);

/*
 * Elegir en el select lo suma con su plantilla, y el elegido QUEDA a la vista en
 * el select (pedido del usuario: antes volvía a «Elegí un medicamento» y no se
 * sabía qué se había elegido). Los que ya están en el pedido siguen en la lista,
 * deshabilitados: así no se suman dos veces, y el select puede mostrar el último.
 */
watch(aSumar, (valor) => {
    const medicamento = medicamentoDe(Number(valor));

    if (medicamento && !enElPedido(medicamento.id)) {
        renglones.value.push({
            id: medicamento.id,
            mensaje: medicamento.plantilla,
        });
    }
});

/*
 * Sacar el que el select está mostrando lo vuelve a «Elegí…»: si no, elegirlo de
 * nuevo no cambiaría el valor del select y no se volvería a sumar.
 */
function sacar(id: number): void {
    renglones.value = renglones.value.filter((r) => r.id !== id);

    if (aSumar.value === String(id)) {
        aSumar.value = '';
    }
}

/*
 * «Guardar plantilla»: el mensaje queda como el de por defecto de ese
 * medicamento, sin mandar nada. Va por su propia ruta y no por el formulario del
 * pedido (un formulario no puede ir dentro de otro). `preserveState` deja la
 * lista armada como estaba; el error, si lo hay, va al lado del campo.
 */
const guardando = ref<number | null>(null);
const erroresPlantilla = ref<Record<number, string>>({});

function guardarPlantilla(renglon: Renglon): void {
    guardando.value = renglon.id;
    erroresPlantilla.value = { ...erroresPlantilla.value, [renglon.id]: '' };

    router.put(
        PedidoMedicamentosController.guardarPlantilla.url(renglon.id),
        { mensaje: renglon.mensaje },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errores) => {
                erroresPlantilla.value = {
                    ...erroresPlantilla.value,
                    [renglon.id]:
                        errores.mensaje ?? 'No se pudo guardar el mensaje.',
                };
            },
            onFinish: () => {
                guardando.value = null;
            },
        },
    );
}

/*
 * El mensaje genérico de la cuenta: el que arranca todo medicamento sin
 * plantilla propia, en cualquier ficha. Mismo camino que «Guardar plantilla»:
 * ruta propia, `preserveState` y el error al lado del campo. Lo que ya está en
 * la lista no cambia: el genérico vale para lo que se sume después.
 */
const generica = ref(props.plantillaGenerica);
const guardandoGenerica = ref(false);
const errorGenerica = ref('');

function guardarGenerica(): void {
    guardandoGenerica.value = true;
    errorGenerica.value = '';

    router.put(
        PedidoMedicamentosController.guardarPlantillaGenerica.url(),
        { mensaje: generica.value },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errores) => {
                errorGenerica.value =
                    errores.mensaje ?? 'No se pudo guardar el mensaje.';
            },
            onFinish: () => {
                guardandoGenerica.value = false;
            },
        },
    );
}

const vistaPrevia = (renglon: Renglon) =>
    completar(
        renglon.mensaje,
        medicamentoDe(renglon.id)?.nombre ?? '',
        props.paciente.variables,
    );

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
    aSumar.value = '';
    destinatario.value = '';
}
</script>

<template>
    <Form
        v-bind="PedidoMedicamentosController.store.form()"
        :options="{ preserveScroll: true }"
        v-slot="{ errors, processing }"
        class="space-y-6"
        @success="alMandar"
    >
        <input type="hidden" name="paciente_id" :value="paciente.id" />

        <!-- 0. El mensaje genérico: sin `name`, no viaja con el pedido -->
        <section class="space-y-3 rounded-md border p-3">
            <h2 class="font-medium">
                Mensaje para los medicamentos sin plantilla
            </h2>
            <div class="grid gap-2">
                <Label for="mensaje-generico">
                    Vale para todos los medicamentos de todas tus fichas que no
                    tengan su propio mensaje guardado
                </Label>
                <textarea
                    id="mensaje-generico"
                    v-model="generica"
                    rows="3"
                    :class="`${campoBase} min-h-20`"
                />
                <VariablesDelMensaje
                    v-model="generica"
                    para="mensaje-generico"
                />
                <p class="text-sm text-muted-foreground">
                    Las variables se reemplazan solas al mandar: {nombre} y
                    {apellido} del paciente, y {medicamento}.
                </p>
                <InputError :message="errorGenerica" />
                <div class="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="guardandoGenerica"
                        @click="guardarGenerica"
                    >
                        <Save />
                        {{
                            guardandoGenerica
                                ? 'Guardando…'
                                : 'Guardar mensaje genérico'
                        }}
                    </Button>
                    <Button
                        v-if="generica !== plantillaOriginal"
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="generica = plantillaOriginal"
                    >
                        <RotateCcw />
                        Volver al original
                    </Button>
                </div>
            </div>
        </section>

        <!-- 1. Qué, y qué le pedís de cada uno -->
        <section class="space-y-3">
            <h2 class="font-medium">¿Qué medicamentos?</h2>
            <div class="grid gap-2">
                <Label for="sumar-medicamento">Sumar un medicamento</Label>
                <select
                    id="sumar-medicamento"
                    v-model="aSumar"
                    :disabled="todosEnElPedido && aSumar === ''"
                    :class="`${campoBase} min-h-11`"
                >
                    <option
                        value=""
                        disabled
                        class="bg-background text-foreground"
                    >
                        Elegí un medicamento
                    </option>
                    <option
                        v-for="medicamento in medicamentos"
                        :key="medicamento.id"
                        :value="String(medicamento.id)"
                        :disabled="enElPedido(medicamento.id)"
                        class="bg-background text-foreground"
                    >
                        {{ medicamento.nombre }} · {{ medicamento.dosis
                        }}{{ medicamento.activo ? '' : ' (suspendido)'
                        }}{{
                            enElPedido(medicamento.id) &&
                            aSumar !== String(medicamento.id)
                                ? ' · ya está en el pedido'
                                : ''
                        }}
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
                        <VariablesDelMensaje
                            v-model="renglon.mensaje"
                            :para="`mensaje-pedido-${renglon.id}`"
                        />
                        <p class="text-sm text-muted-foreground">
                            {{
                                medicamentoDe(renglon.id)?.plantillaPropia
                                    ? 'Tiene su propio mensaje guardado.'
                                    : 'Usa el mensaje genérico.'
                            }}
                        </p>
                        <InputError
                            :message="
                                erroresPlantilla[renglon.id] ||
                                errors[`pedidos.${i}.mensaje`] ||
                                errors[`pedidos.${i}.tratamiento_id`]
                            "
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="justify-self-start"
                            :disabled="guardando === renglon.id"
                            @click="guardarPlantilla(renglon)"
                        >
                            <Save />
                            {{
                                guardando === renglon.id
                                    ? 'Guardando…'
                                    : 'Guardar plantilla'
                            }}
                        </Button>
                    </div>
                </li>
            </ul>
            <p
                v-if="renglones.length > 0"
                class="text-sm text-muted-foreground"
            >
                «Guardar plantilla» deja ese mensaje como el de siempre para ese
                medicamento. Al mandar el pedido también se guarda.
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
                    Ningún médico, centro ni farmacia tiene el correo cargado.
                    Agregalo en su ficha y aparece acá.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="MedicoController.index()">Médicos</Link>
                    </Button>
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="CentroController.index()">Centros</Link>
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
                        <p>{{ vistaPrevia(renglon) }}</p>
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
</template>
