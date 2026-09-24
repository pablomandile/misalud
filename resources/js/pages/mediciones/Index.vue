<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Activity, ArrowLeft, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import MedicionController from '@/actions/App/Http/Controllers/MedicionController';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';

/*
 * Seguimiento de variables de un paciente: peso, presión, glucemia.
 *
 * Dos cosas de esta pantalla no son decoración y conviene no tocarlas sin
 * leer el porqué:
 *
 * 1. Los valores van con `inputmode="decimal"` y SIN `type="number"`. El
 *    teclado decimal en español ofrece coma, y un `type="number"` con coma
 *    en un navegador con locale es-AR entrega un valor VACÍO al enviar
 *    -el campo se considera inválido y el `value` sale como ""-. Con
 *    `type="text"` + `inputmode="decimal"` llega el texto tal cual y lo
 *    normaliza el servidor (ver `NormalizaDecimales`).
 * 2. La fecha se precarga con `ahoraLocal`, que viene del SERVIDOR en la
 *    zona de la cuenta, no de `new Date()`.
 */

type Tipo = {
    id: number;
    nombre: string;
    unidad: string;
    unidadSecundaria: string;
    etiquetaPrincipal: string;
    etiquetaSecundaria: string | null;
    tieneValorSecundario: boolean;
    decimales: number;
    minNormal: number | null;
    maxNormal: number | null;
    minNormalSecundario: number | null;
    maxNormalSecundario: number | null;
};

type Medicion = {
    id: number;
    tipo_medicion_id: number;
    tipoNombre: string;
    unidad: string;
    unidadSecundaria: string;
    etiquetaPrincipal: string;
    etiquetaSecundaria: string | null;
    fechaVisible: string;
    fechaLocal: string;
    fechaIso: string;
    valor: number;
    valorVisible: string | null;
    valorSecundario: number | null;
    valorSecundarioVisible: string | null;
    notas: string | null;
};

type Paciente = {
    id: number;
    nombre: string;
    puedeEditar: boolean;
};

const props = defineProps<{
    paciente: Paciente;
    tipos: Tipo[];
    mediciones: Medicion[];
    ahoraLocal: string | null;
    zonaHoraria: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pacientes', href: PacienteController.index() },
            { title: 'Mediciones', href: '' },
        ],
    },
});

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

const sheetAbierto = ref(false);
const medicionAEditar = ref<Medicion | null>(null);
const medicionABorrar = ref<Medicion | null>(null);

/** El tipo elegido en el formulario, para saber si pedir el segundo número. */
const tipoElegidoId = ref<number | null>(props.tipos[0]?.id ?? null);

const tipoElegido = computed<Tipo | null>(
    () => props.tipos.find((t) => t.id === tipoElegidoId.value) ?? null,
);

/**
 * El rango de referencia, tal como lo trae el tipo.
 *
 * Es información, no un juicio: se muestra igual que en un análisis de
 * laboratorio. Ninguna parte de esta pantalla dice si un valor está "bien"
 * ni lo pinta de otro color (regla 1: el sistema registra, no aconseja).
 */
function referencia(
    min: number | null,
    max: number | null,
    unidad: string,
): string | null {
    if (min === null && max === null) {
        return null;
    }
    if (min !== null && max !== null) {
        return `Referencia: ${min} a ${max} ${unidad}`;
    }
    return min !== null
        ? `Referencia: desde ${min} ${unidad}`
        : `Referencia: hasta ${max} ${unidad}`;
}

function abrirAlta(): void {
    tipoElegidoId.value = props.tipos[0]?.id ?? null;
    sheetAbierto.value = true;
}

function abrirEdicion(medicion: Medicion): void {
    tipoElegidoId.value = medicion.tipo_medicion_id;
    medicionAEditar.value = medicion;
}

/** "120/80 mmHg" para dos valores; "72,5 kg" para uno. */
function valorCompleto(m: Medicion): string {
    if (m.valorSecundarioVisible !== null) {
        return `${m.valorVisible}/${m.valorSecundarioVisible} ${m.unidad}`;
    }
    return `${m.valorVisible} ${m.unidad}`;
}
</script>

<template>
    <Head :title="`Mediciones de ${paciente.nombre}`" />

    <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="`Mediciones de ${paciente.nombre}`"
                description="Peso, presión, glucemia y todo lo que quieras seguir en el tiempo"
            />
            <Button
                v-if="paciente.puedeEditar && tipos.length > 0"
                @click="abrirAlta"
            >
                <Plus />
                Cargar
            </Button>
        </div>

        <!--
            Sin tipos no hay nada que cargar, y el camino de salida tiene que
            estar a la vista: si no, la pantalla queda vacía sin explicar por qué.
        -->
        <div
            v-if="tipos.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <Activity class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no hay variables para medir. Creá una en
                <span class="font-medium">Variables</span> y volvé.
            </p>
        </div>

        <div
            v-else-if="mediciones.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <Activity class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no cargaste ninguna medición.
            </p>
        </div>

        <div v-else class="grid gap-3">
            <Card v-for="medicion in mediciones" :key="medicion.id">
                <CardContent class="flex items-start justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium">
                            {{ medicion.tipoNombre }}
                        </p>
                        <p class="text-lg">
                            {{ valorCompleto(medicion) }}
                        </p>
                        <p
                            v-if="medicion.etiquetaSecundaria"
                            class="text-sm text-muted-foreground"
                        >
                            {{ medicion.etiquetaPrincipal }} /
                            {{ medicion.etiquetaSecundaria }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ medicion.fechaVisible }}
                        </p>
                        <p v-if="medicion.notas" class="text-sm">
                            {{ medicion.notas }}
                        </p>
                    </div>

                    <div
                        v-if="paciente.puedeEditar"
                        class="flex shrink-0 gap-1"
                    >
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="abrirEdicion(medicion)"
                        >
                            Editar
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                            :aria-label="`Eliminar la medición de ${medicion.tipoNombre} del ${medicion.fechaVisible}`"
                            @click="medicionABorrar = medicion"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>

        <div>
            <Button variant="outline" as-child>
                <a :href="PacienteController.index().url">
                    <ArrowLeft />
                    Volver a pacientes
                </a>
            </Button>
        </div>

        <!-- Cargar -->
        <Sheet v-model:open="sheetAbierto">
            <SheetContent>
                <Form
                    v-bind="
                        MedicionController.store.form({
                            paciente: paciente.id,
                        })
                    "
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    @success="sheetAbierto = false"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>Cargar una medición</SheetTitle>
                        <SheetDescription>
                            Queda en la ficha de {{ paciente.nombre }}.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="tipo-crear">Qué medís</Label>
                            <select
                                id="tipo-crear"
                                name="tipo_medicion_id"
                                v-model="tipoElegidoId"
                                :class="campoUnaLinea"
                            >
                                <option
                                    v-for="tipo in tipos"
                                    :key="tipo.id"
                                    :value="tipo.id"
                                >
                                    {{ tipo.nombre }} ({{ tipo.unidad }})
                                </option>
                            </select>
                            <InputError :message="errors.tipo_medicion_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="fecha-crear">Cuándo</Label>
                            <input
                                id="fecha-crear"
                                name="fecha"
                                type="datetime-local"
                                required
                                :value="ahoraLocal"
                                :class="campoUnaLinea"
                            />
                            <p
                                v-if="zonaHoraria"
                                class="text-sm text-muted-foreground"
                            >
                                Hora de {{ zonaHoraria.split('/').pop() }}
                            </p>
                            <InputError :message="errors.fecha" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="valor-crear">
                                {{ tipoElegido?.etiquetaPrincipal ?? 'Valor' }}
                                <span v-if="tipoElegido">
                                    ({{ tipoElegido.unidad }})
                                </span>
                            </Label>
                            <input
                                id="valor-crear"
                                name="valor"
                                type="text"
                                inputmode="decimal"
                                required
                                :class="campoUnaLinea"
                            />
                            <p
                                v-if="
                                    tipoElegido &&
                                    referencia(
                                        tipoElegido.minNormal,
                                        tipoElegido.maxNormal,
                                        tipoElegido.unidad,
                                    )
                                "
                                class="text-sm text-muted-foreground"
                            >
                                {{
                                    referencia(
                                        tipoElegido.minNormal,
                                        tipoElegido.maxNormal,
                                        tipoElegido.unidad,
                                    )
                                }}
                            </p>
                            <InputError :message="errors.valor" />
                        </div>

                        <!--
                            El segundo número aparece SOLO si el tipo lo
                            declara. El servidor lo exige o lo prohíbe según
                            lo mismo, así que las dos mitades no se pueden
                            desincronizar sin que la validación lo diga.
                        -->
                        <div
                            v-if="tipoElegido?.tieneValorSecundario"
                            class="grid gap-2"
                        >
                            <Label for="valor-secundario-crear">
                                {{ tipoElegido.etiquetaSecundaria }}
                                ({{ tipoElegido.unidadSecundaria }})
                            </Label>
                            <input
                                id="valor-secundario-crear"
                                name="valor_secundario"
                                type="text"
                                inputmode="decimal"
                                required
                                :class="campoUnaLinea"
                            />
                            <p
                                v-if="
                                    referencia(
                                        tipoElegido.minNormalSecundario,
                                        tipoElegido.maxNormalSecundario,
                                        tipoElegido.unidadSecundaria,
                                    )
                                "
                                class="text-sm text-muted-foreground"
                            >
                                {{
                                    referencia(
                                        tipoElegido.minNormalSecundario,
                                        tipoElegido.maxNormalSecundario,
                                        tipoElegido.unidadSecundaria,
                                    )
                                }}
                            </p>
                            <InputError :message="errors.valor_secundario" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="notas-crear">Notas</Label>
                            <textarea
                                id="notas-crear"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                            ></textarea>
                            <InputError :message="errors.notas" />
                        </div>
                    </div>

                    <SheetFooter>
                        <Button type="submit" :disabled="processing">
                            Guardar
                        </Button>
                        <SheetClose as-child>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </SheetClose>
                    </SheetFooter>
                </Form>
            </SheetContent>
        </Sheet>

        <!-- Editar -->
        <Sheet
            :open="!!medicionAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) medicionAEditar = null;
                }
            "
        >
            <SheetContent v-if="medicionAEditar">
                <Form
                    v-bind="
                        MedicionController.update.form({
                            medicion: medicionAEditar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="medicionAEditar = null"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>Editar la medición</SheetTitle>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="tipo-editar">Qué medís</Label>
                            <select
                                id="tipo-editar"
                                name="tipo_medicion_id"
                                v-model="tipoElegidoId"
                                :class="campoUnaLinea"
                            >
                                <option
                                    v-for="tipo in tipos"
                                    :key="tipo.id"
                                    :value="tipo.id"
                                >
                                    {{ tipo.nombre }} ({{ tipo.unidad }})
                                </option>
                            </select>
                            <InputError :message="errors.tipo_medicion_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="fecha-editar">Cuándo</Label>
                            <input
                                id="fecha-editar"
                                name="fecha"
                                type="datetime-local"
                                required
                                :value="medicionAEditar.fechaLocal"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.fecha" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="valor-editar">
                                {{ tipoElegido?.etiquetaPrincipal ?? 'Valor' }}
                                <span v-if="tipoElegido">
                                    ({{ tipoElegido.unidad }})
                                </span>
                            </Label>
                            <input
                                id="valor-editar"
                                name="valor"
                                type="text"
                                inputmode="decimal"
                                required
                                :value="medicionAEditar.valorVisible"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.valor" />
                        </div>

                        <div
                            v-if="tipoElegido?.tieneValorSecundario"
                            class="grid gap-2"
                        >
                            <Label for="valor-secundario-editar">
                                {{ tipoElegido.etiquetaSecundaria }}
                                ({{ tipoElegido.unidadSecundaria }})
                            </Label>
                            <input
                                id="valor-secundario-editar"
                                name="valor_secundario"
                                type="text"
                                inputmode="decimal"
                                required
                                :value="medicionAEditar.valorSecundarioVisible"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.valor_secundario" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="notas-editar">Notas</Label>
                            <textarea
                                id="notas-editar"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                                >{{ medicionAEditar.notas }}</textarea>
                            <InputError :message="errors.notas" />
                        </div>
                    </div>

                    <SheetFooter>
                        <Button type="submit" :disabled="processing">
                            Guardar
                        </Button>
                        <SheetClose as-child>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </SheetClose>
                    </SheetFooter>
                </Form>
            </SheetContent>
        </Sheet>

        <!-- Borrar -->
        <Dialog
            :open="!!medicionABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) medicionABorrar = null;
                }
            "
        >
            <DialogContent v-if="medicionABorrar">
                <Form
                    v-bind="
                        MedicionController.destroy.form({
                            medicion: medicionABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="medicionABorrar = null"
                    v-slot="{ processing }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>¿Eliminar esta medición?</DialogTitle>
                        <DialogDescription>
                            {{ medicionABorrar.tipoNombre }} del
                            {{ medicionABorrar.fechaVisible }}. Esto no se puede
                            deshacer.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            variant="destructive"
                            :disabled="processing"
                        >
                            Eliminar
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
