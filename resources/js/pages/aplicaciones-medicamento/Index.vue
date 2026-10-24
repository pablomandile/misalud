<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, CalendarCheck, Pill, Plus, Trash2 } from '@lucide/vue';
import { reactive, ref } from 'vue';
import AplicacionMedicamentoController from '@/actions/App/Http/Controllers/AplicacionMedicamentoController';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
import TratamientoController from '@/actions/App/Http/Controllers/TratamientoController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SelectorDeFicha from '@/components/SelectorDeFicha.vue';
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
 * La sección «Tratamiento»: cada aplicación o toma de un medicamento que no es
 * diario, agrupada por enfermedad. Los grupos los arma el servidor, así que no
 * hay ningún `const` derivado de props que se quede congelado después de un
 * redirect a esta misma URL (la lección de la Etapa 8).
 *
 * ⚠️ El formulario va entero con `v-model`: elegir el medicamento precarga su
 * enfermedad, y ese estado local pisaría cualquier `:value` de los hermanos
 * (la regla de la Etapa 10).
 */

type Aplicacion = {
    id: number;
    tratamiento_id: number;
    medicamentoNombre: string;
    dosis: string;
    enfermedad_id: number | null;
    fecha: string;
    fechaVisible: string;
    notas: string | null;
};

type Grupo = {
    enfermedadId: number | null;
    nombre: string;
    aplicaciones: Aplicacion[];
};

type Medicamento = { id: number; nombre: string; enfermedadId: number | null };

const props = defineProps<{
    paciente: { id: number; nombre: string; puedeEditar: boolean };
    grupos: Grupo[];
    medicamentos: Medicamento[];
    enfermedades: { id: number; nombre: string }[];
    fichas: { id: number; nombre: string }[];
    hoy: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pacientes', href: PacienteController.index() },
            { title: 'Tratamiento', href: '' },
        ],
    },
});

const enlaceFicha = (id: number) =>
    AplicacionMedicamentoController.index({ paciente: id });

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

const formularioAbierto = ref(false);
const aplicacionAEditar = ref<Aplicacion | null>(null);
const aplicacionABorrar = ref<Aplicacion | null>(null);

const campos = reactive({
    tratamiento_id: '' as number | '',
    enfermedad_id: '' as number | '',
    fecha: '',
    notas: '',
});

function abrirAlta(base: Aplicacion | null): void {
    aplicacionAEditar.value = null;
    campos.tratamiento_id = base?.tratamiento_id ?? '';
    campos.enfermedad_id = base?.enfermedad_id ?? '';
    campos.fecha = props.hoy ?? '';
    campos.notas = '';
    formularioAbierto.value = true;
}

function abrirEdicion(aplicacion: Aplicacion): void {
    aplicacionAEditar.value = aplicacion;
    campos.tratamiento_id = aplicacion.tratamiento_id;
    campos.enfermedad_id = aplicacion.enfermedad_id ?? '';
    campos.fecha = aplicacion.fecha;
    campos.notas = aplicacion.notas ?? '';
    formularioAbierto.value = true;
}

// Elegir el medicamento trae la enfermedad que tiene cargada; se puede cambiar.
function alElegirMedicamento(): void {
    const elegido = props.medicamentos.find(
        (m) => m.id === campos.tratamiento_id,
    );
    campos.enfermedad_id = elegido?.enfermedadId ?? '';
}

function cerrarFormulario(): void {
    formularioAbierto.value = false;
    aplicacionAEditar.value = null;
}
</script>

<template>
    <Head :title="`Tratamiento de ${paciente.nombre}`" />

    <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="`Tratamiento de ${paciente.nombre}`"
                description="Cada aplicación o toma, por enfermedad"
            />
            <Button
                v-if="paciente.puedeEditar && medicamentos.length > 0"
                @click="abrirAlta(null)"
            >
                <Plus />
                Agregar
            </Button>
        </div>

        <SelectorDeFicha
            :fichas="fichas"
            :actual="paciente.id"
            :enlace="enlaceFicha"
        />

        <div
            v-if="medicamentos.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <Pill class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Acá se anotan los medicamentos que no son de todos los días (una
                vez por semana, por mes, por año…). Primero cargá uno en
                Medicamentos.
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

        <div
            v-else-if="grupos.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <CalendarCheck class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no anotaste ninguna aplicación.
            </p>
        </div>

        <Card v-for="grupo in grupos" :key="grupo.enfermedadId ?? 'sin'">
            <CardContent class="space-y-4">
                <div class="min-w-0 space-y-1">
                    <p class="font-medium">{{ grupo.nombre }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            grupo.aplicaciones.length === 1
                                ? '1 aplicación'
                                : `${grupo.aplicaciones.length} aplicaciones`
                        }}
                    </p>
                </div>

                <ul class="grid gap-3">
                    <li
                        v-for="aplicacion in grupo.aplicaciones"
                        :key="aplicacion.id"
                        class="space-y-2 border-t pt-3"
                    >
                        <div class="min-w-0 space-y-1">
                            <p class="text-sm font-medium">
                                {{ aplicacion.fechaVisible }} ·
                                {{ aplicacion.medicamentoNombre }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{ aplicacion.dosis }}
                            </p>
                            <p v-if="aplicacion.notas" class="text-sm">
                                {{ aplicacion.notas }}
                            </p>
                        </div>

                        <div
                            v-if="paciente.puedeEditar"
                            class="flex flex-wrap gap-1"
                        >
                            <Button
                                variant="outline"
                                size="sm"
                                @click="abrirAlta(aplicacion)"
                            >
                                <Plus />
                                Otra aplicación
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="abrirEdicion(aplicacion)"
                            >
                                Editar
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                :aria-label="`Eliminar la aplicación de ${aplicacion.medicamentoNombre} del ${aplicacion.fechaVisible}`"
                                @click="aplicacionABorrar = aplicacion"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <div class="flex flex-wrap gap-2">
            <Button variant="outline" as-child>
                <Link
                    :href="
                        TratamientoController.index({ paciente: paciente.id })
                    "
                >
                    <Pill />
                    Medicamentos
                </Link>
            </Button>
            <Button variant="outline" as-child>
                <a :href="PacienteController.index().url">
                    <ArrowLeft />
                    Volver a pacientes
                </a>
            </Button>
        </div>

        <!-- Anotar y editar -->
        <Sheet
            :open="formularioAbierto"
            @update:open="
                (v: boolean) => {
                    if (!v) cerrarFormulario();
                }
            "
        >
            <SheetContent>
                <Form
                    v-bind="
                        aplicacionAEditar
                            ? AplicacionMedicamentoController.update.form({
                                  aplicacionMedicamento: aplicacionAEditar.id,
                              })
                            : AplicacionMedicamentoController.store.form({
                                  paciente: paciente.id,
                              })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                    @success="cerrarFormulario"
                >
                    <SheetHeader>
                        <SheetTitle>
                            {{
                                aplicacionAEditar
                                    ? 'Editar la aplicación'
                                    : 'Anotar una aplicación'
                            }}
                        </SheetTitle>
                        <SheetDescription>
                            Cuándo, qué medicamento y por qué enfermedad.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="medicamento-aplicacion">
                                Medicamento
                            </Label>
                            <select
                                id="medicamento-aplicacion"
                                v-model="campos.tratamiento_id"
                                name="tratamiento_id"
                                required
                                :class="campoUnaLinea"
                                @change="alElegirMedicamento"
                            >
                                <option
                                    value=""
                                    disabled
                                    class="bg-background text-foreground"
                                >
                                    Elegí el medicamento
                                </option>
                                <option
                                    v-for="medicamento in medicamentos"
                                    :key="medicamento.id"
                                    :value="medicamento.id"
                                    class="bg-background text-foreground"
                                >
                                    {{ medicamento.nombre }}
                                </option>
                            </select>
                            <InputError :message="errors.tratamiento_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="enfermedad-aplicacion">
                                Por qué enfermedad
                            </Label>
                            <select
                                id="enfermedad-aplicacion"
                                v-model="campos.enfermedad_id"
                                name="enfermedad_id"
                                :class="campoUnaLinea"
                            >
                                <option
                                    value=""
                                    class="bg-background text-foreground"
                                >
                                    Sin especificar
                                </option>
                                <option
                                    v-for="enfermedad in enfermedades"
                                    :key="enfermedad.id"
                                    :value="enfermedad.id"
                                    class="bg-background text-foreground"
                                >
                                    {{ enfermedad.nombre }}
                                </option>
                            </select>
                            <InputError :message="errors.enfermedad_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="fecha-aplicacion">
                                Fecha en que se aplicó
                            </Label>
                            <input
                                id="fecha-aplicacion"
                                v-model="campos.fecha"
                                name="fecha"
                                type="date"
                                required
                                :max="hoy ?? undefined"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.fecha" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="notas-aplicacion">Observaciones</Label>
                            <textarea
                                id="notas-aplicacion"
                                v-model="campos.notas"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                            />
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
            :open="!!aplicacionABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) aplicacionABorrar = null;
                }
            "
        >
            <DialogContent v-if="aplicacionABorrar">
                <Form
                    v-bind="
                        AplicacionMedicamentoController.destroy.form({
                            aplicacionMedicamento: aplicacionABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                    @success="aplicacionABorrar = null"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar la aplicación de
                            {{ aplicacionABorrar.medicamentoNombre }} del
                            {{ aplicacionABorrar.fechaVisible }}?
                        </DialogTitle>
                        <DialogDescription>
                            Se saca del historial.
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
