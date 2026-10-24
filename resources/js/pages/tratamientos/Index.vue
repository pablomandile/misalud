<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Camera, List, Pill, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import AdjuntoController from '@/actions/App/Http/Controllers/AdjuntoController';
import MedicamentoController from '@/actions/App/Http/Controllers/MedicamentoController';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
import TratamientoController from '@/actions/App/Http/Controllers/TratamientoController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SelectorDeFicha from '@/components/SelectorDeFicha.vue';
import SubirArchivo from '@/components/SubirArchivo.vue';
import type { DocumentoVisible } from '@/components/VisorDocumento.vue';
import VisorDocumento from '@/components/VisorDocumento.vue';
import DiasDeLaSemana from '@/pages/tratamientos/DiasDeLaSemana.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
 * Los medicamentos que toma un paciente (en el código, `tratamientos`): cuál,
 * con qué dosis, cada cuánto, y las fotos de la caja.
 *
 * El medicamento se elige de la lista o se escribe: si no está en la lista, el
 * servidor lo suma al catálogo propio. Nadie tiene que "copiar" nada antes.
 *
 * Activos primero, y separados de los que ya se dejaron de tomar: uno inactivo
 * no desaparece -sirve de historial-, pero no compite con lo vigente.
 */

type Foto = DocumentoVisible & { id: number };

type Tratamiento = {
    id: number;
    medicamento_id: number;
    medicamentoNombre: string;
    medico_id: number | null;
    medicoNombre: string | null;
    enfermedad_id: number | null;
    enfermedadNombre: string | null;
    dosis: string;
    frecuencia: string | null;
    hora: string | null;
    periodicidad: string | null;
    periodicidadEtiqueta: string | null;
    diasSemana: number[];
    inicio: string;
    inicioVisible: string;
    fin: string | null;
    finVisible: string | null;
    activo: boolean;
    notas: string | null;
    fotos: Foto[];
};

const props = defineProps<{
    paciente: { id: number; nombre: string; puedeEditar: boolean };
    fichas: { id: number; nombre: string }[];
    periodicidades: { valor: string; etiqueta: string }[];
    diasDeLaSemana: { valor: number; inicial: string; nombre: string }[];
    tratamientos: Tratamiento[];
    medicamentos: Array<{ id: number; nombre: string }>;
    medicos: Array<{ id: number; nombre: string }>;
    enfermedades: Array<{ id: number; nombre: string }>;
    hoy: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pacientes', href: PacienteController.index() },
            { title: 'Medicamentos', href: '' },
        ],
    },
});

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

const sheetAlta = ref(false);
const tratamientoAEditar = ref<Tratamiento | null>(null);
const tratamientoABorrar = ref<Tratamiento | null>(null);
const tratamientoDeFotos = ref<Tratamiento | null>(null);

/** UNO SOLO para toda la pantalla, y fuera de todo v-for. */
const fotoAbierta = ref<DocumentoVisible | null>(null);

const enlaceFicha = (id: number) =>
    TratamientoController.index({ paciente: id });

/*
 * `computed()` y NO un `const` calculado una vez: al cargar uno, Inertia
 * redirige a esta MISMA URL y reutiliza la instancia del componente, así que
 * un `const` quedaría congelado con la primera carga (encontrado en Chrome).
 */
const activos = computed(() => props.tratamientos.filter((t) => t.activo));
const inactivos = computed(() => props.tratamientos.filter((t) => !t.activo));

function detalleDeToma(t: Tratamiento): string {
    return [
        t.dosis,
        t.periodicidadEtiqueta,
        t.hora ? `a las ${t.hora}` : null,
        t.frecuencia,
    ]
        .filter((parte) => parte)
        .join(' · ');
}
</script>

<template>
    <Head :title="`Medicamentos de ${paciente.nombre}`" />

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="`Medicamentos de ${paciente.nombre}`"
                description="Lo que toma: la dosis, cada cuánto y una foto de la caja"
            />
            <Button v-if="paciente.puedeEditar" @click="sheetAlta = true">
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
            v-if="tratamientos.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <Pill class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no cargaste ningún medicamento. Tocá «Agregar» y elegilo
                de la lista.
            </p>
        </div>

        <template
            v-for="grupo in [
                { titulo: 'Los que toma', lista: activos },
                { titulo: 'Los que ya no toma', lista: inactivos },
            ]"
            :key="grupo.titulo"
        >
            <div v-if="grupo.lista.length > 0" class="space-y-3">
                <p class="text-sm text-muted-foreground">{{ grupo.titulo }}</p>

                <div class="grid gap-3">
                    <Card
                        v-for="tratamiento in grupo.lista"
                        :key="tratamiento.id"
                    >
                        <CardContent class="space-y-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 space-y-1">
                                    <p class="font-medium wrap-break-word">
                                        {{ tratamiento.medicamentoNombre }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ detalleDeToma(tratamiento) }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        Desde {{ tratamiento.inicioVisible
                                        }}<span v-if="tratamiento.finVisible">
                                            hasta
                                            {{ tratamiento.finVisible }}</span
                                        >
                                    </p>
                                    <p
                                        v-if="tratamiento.medicoNombre"
                                        class="text-sm text-muted-foreground"
                                    >
                                        {{ tratamiento.medicoNombre }}
                                    </p>
                                    <p
                                        v-if="tratamiento.enfermedadNombre"
                                        class="text-sm text-muted-foreground"
                                    >
                                        Por {{ tratamiento.enfermedadNombre }}
                                    </p>
                                    <p
                                        v-if="tratamiento.notas"
                                        class="text-sm whitespace-pre-line"
                                    >
                                        {{ tratamiento.notas }}
                                    </p>
                                </div>

                                <div
                                    v-if="paciente.puedeEditar"
                                    class="flex shrink-0 gap-1"
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="
                                            tratamientoAEditar = tratamiento
                                        "
                                    >
                                        Editar
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                        :aria-label="`Eliminar ${tratamiento.medicamentoNombre}`"
                                        @click="
                                            tratamientoABorrar = tratamiento
                                        "
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </div>
                            </div>

                            <!-- Las fotos de la caja: tocarlas las agranda. -->
                            <ul
                                v-if="tratamiento.fotos.length > 0"
                                class="flex flex-wrap gap-2"
                            >
                                <li
                                    v-for="foto in tratamiento.fotos"
                                    :key="foto.id"
                                    class="space-y-1"
                                >
                                    <button
                                        type="button"
                                        class="block size-24 overflow-hidden rounded-md border bg-muted/40 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                        :aria-label="`Ver ${foto.nombre}`"
                                        @click="fotoAbierta = foto"
                                    >
                                        <img
                                            v-if="
                                                foto.mime.startsWith('image/')
                                            "
                                            :src="foto.url"
                                            :alt="`Caja de ${tratamiento.medicamentoNombre}`"
                                            class="size-full object-contain"
                                        />
                                        <span
                                            v-else
                                            class="flex size-full items-center justify-center p-1 text-xs break-all"
                                        >
                                            {{ foto.nombre }}
                                        </span>
                                    </button>
                                    <Form
                                        v-if="paciente.puedeEditar"
                                        v-bind="
                                            AdjuntoController.destroy.form({
                                                adjunto: foto.id,
                                            })
                                        "
                                        :options="{ preserveScroll: true }"
                                        v-slot="{ processing }"
                                    >
                                        <Button
                                            type="submit"
                                            variant="ghost"
                                            size="sm"
                                            class="w-24 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                            :disabled="processing"
                                            :aria-label="`Quitar la foto ${foto.nombre}`"
                                            @click="fotoAbierta = null"
                                        >
                                            Quitar
                                        </Button>
                                    </Form>
                                </li>
                            </ul>

                            <Button
                                v-if="paciente.puedeEditar"
                                variant="outline"
                                size="sm"
                                @click="tratamientoDeFotos = tratamiento"
                            >
                                <Camera />
                                {{
                                    tratamiento.fotos.length > 0
                                        ? 'Agregar otra foto'
                                        : 'Foto de la caja'
                                }}
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </template>

        <div class="flex flex-wrap gap-2">
            <Button variant="outline" as-child>
                <Link :href="MedicamentoController.index()">
                    <List />
                    Lista de medicamentos
                </Link>
            </Button>
            <Button variant="outline" as-child>
                <a :href="PacienteController.index().url">
                    <ArrowLeft />
                    Volver a pacientes
                </a>
            </Button>
        </div>

        <!-- La lista para elegir: comunes + los propios. -->
        <datalist id="lista-medicamentos">
            <option
                v-for="medicamento in medicamentos"
                :key="medicamento.id"
                :value="medicamento.nombre"
            />
        </datalist>

        <!-- Alta -->
        <Sheet v-model:open="sheetAlta">
            <SheetContent>
                <Form
                    v-bind="
                        TratamientoController.store.form({
                            paciente: paciente.id,
                        })
                    "
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                    @success="sheetAlta = false"
                >
                    <SheetHeader>
                        <SheetTitle>Agregar un medicamento</SheetTitle>
                        <SheetDescription>
                            Queda en la ficha de {{ paciente.nombre }}.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="medicamento-crear">Medicamento</Label>
                            <input
                                id="medicamento-crear"
                                name="medicamento_nombre"
                                type="text"
                                list="lista-medicamentos"
                                required
                                autocomplete="off"
                                placeholder="Escribí y elegí de la lista"
                                :class="campoUnaLinea"
                            />
                            <p class="text-sm text-muted-foreground">
                                Si no está en la lista, escribilo y se agrega.
                            </p>
                            <InputError
                                :message="
                                    errors.medicamento_id ??
                                    errors.medicamento_nombre
                                "
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="dosis-crear">Dosis</Label>
                            <input
                                id="dosis-crear"
                                name="dosis"
                                type="text"
                                required
                                placeholder="1 comprimido de 500 mg"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.dosis" />
                        </div>

                        <!--
                            Los días aparecen solo con "Algunos días de la
                            semana", y lo decide el CSS (:has sobre la opción
                            elegida), sin estado en Vue: ver DiasDeLaSemana.
                        -->
                        <div class="group/toma grid gap-2">
                            <Label for="periodicidad-crear">Toma</Label>
                            <select
                                id="periodicidad-crear"
                                name="periodicidad"
                                :class="campoUnaLinea"
                            >
                                <option
                                    value=""
                                    class="bg-background text-foreground"
                                >
                                    Sin especificar
                                </option>
                                <option
                                    v-for="opcion in periodicidades"
                                    :key="opcion.valor"
                                    :value="opcion.valor"
                                    class="bg-background text-foreground"
                                >
                                    {{ opcion.etiqueta }}
                                </option>
                            </select>
                            <InputError :message="errors.periodicidad" />
                            <DiasDeLaSemana
                                class="hidden group-has-[option[value=personalizada]:checked]/toma:grid"
                                :dias="diasDeLaSemana"
                                :error="errors.dias_semana"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="frecuencia-crear">
                                Cada cuánto (opcional)
                            </Label>
                            <input
                                id="frecuencia-crear"
                                name="frecuencia"
                                type="text"
                                placeholder="Cada 8 horas"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.frecuencia" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="hora-crear">Horario (opcional)</Label>
                            <input
                                id="hora-crear"
                                name="hora"
                                type="time"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.hora" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="inicio-crear">Desde</Label>
                            <input
                                id="inicio-crear"
                                name="inicio"
                                type="date"
                                required
                                :value="hoy"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.inicio" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="fin-crear">Hasta (opcional)</Label>
                            <input
                                id="fin-crear"
                                name="fin"
                                type="date"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.fin" />
                        </div>

                        <div v-if="medicos.length > 0" class="grid gap-2">
                            <Label for="medico-crear">Médico</Label>
                            <select
                                id="medico-crear"
                                name="medico_id"
                                :class="campoUnaLinea"
                            >
                                <option
                                    value=""
                                    class="bg-background text-foreground"
                                >
                                    Sin especificar
                                </option>
                                <option
                                    v-for="medico in medicos"
                                    :key="medico.id"
                                    :value="medico.id"
                                    class="bg-background text-foreground"
                                >
                                    {{ medico.nombre }}
                                </option>
                            </select>
                            <InputError :message="errors.medico_id" />
                        </div>

                        <div v-if="enfermedades.length > 0" class="grid gap-2">
                            <Label for="enfermedad-crear">
                                ¿Es por alguna enfermedad?
                            </Label>
                            <select
                                id="enfermedad-crear"
                                name="enfermedad_id"
                                :class="campoUnaLinea"
                            >
                                <option
                                    value=""
                                    class="bg-background text-foreground"
                                >
                                    No especificar
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
                            <Label
                                for="activo-crear"
                                class="flex items-center space-x-3"
                            >
                                <Checkbox
                                    id="activo-crear"
                                    name="activo"
                                    :default-value="true"
                                />
                                <span>Lo sigue tomando</span>
                            </Label>
                            <InputError :message="errors.activo" />
                        </div>

                        <SubirArchivo
                            name="archivos[]"
                            multiple
                            etiqueta="Fotos de la caja (opcional)"
                            :error="errors['archivos.0'] ?? errors.archivos"
                        />

                        <div class="grid gap-2">
                            <Label for="notas-crear">Observaciones</Label>
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

        <!-- Editar (sin fotos: la edición va por PUT y ahí no viaja un archivo) -->
        <Sheet
            :open="!!tratamientoAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) tratamientoAEditar = null;
                }
            "
        >
            <SheetContent v-if="tratamientoAEditar">
                <Form
                    v-bind="
                        TratamientoController.update.form({
                            tratamiento: tratamientoAEditar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                    @success="tratamientoAEditar = null"
                >
                    <SheetHeader>
                        <SheetTitle>Editar el medicamento</SheetTitle>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="medicamento-editar">Medicamento</Label>
                            <input
                                id="medicamento-editar"
                                name="medicamento_nombre"
                                type="text"
                                list="lista-medicamentos"
                                required
                                autocomplete="off"
                                :value="tratamientoAEditar.medicamentoNombre"
                                :class="campoUnaLinea"
                            />
                            <InputError
                                :message="
                                    errors.medicamento_id ??
                                    errors.medicamento_nombre
                                "
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="dosis-editar">Dosis</Label>
                            <input
                                id="dosis-editar"
                                name="dosis"
                                type="text"
                                required
                                :value="tratamientoAEditar.dosis"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.dosis" />
                        </div>

                        <!--
                            Los días aparecen solo con "Algunos días de la
                            semana", y lo decide el CSS (:has sobre la opción
                            elegida), sin estado en Vue: ver DiasDeLaSemana.
                        -->
                        <div class="group/toma grid gap-2">
                            <Label for="periodicidad-editar">Toma</Label>
                            <select
                                id="periodicidad-editar"
                                name="periodicidad"
                                :value="tratamientoAEditar.periodicidad ?? ''"
                                :class="campoUnaLinea"
                            >
                                <option
                                    value=""
                                    class="bg-background text-foreground"
                                >
                                    Sin especificar
                                </option>
                                <option
                                    v-for="opcion in periodicidades"
                                    :key="opcion.valor"
                                    :value="opcion.valor"
                                    class="bg-background text-foreground"
                                >
                                    {{ opcion.etiqueta }}
                                </option>
                            </select>
                            <InputError :message="errors.periodicidad" />
                            <DiasDeLaSemana
                                class="hidden group-has-[option[value=personalizada]:checked]/toma:grid"
                                :dias="diasDeLaSemana"
                                :seleccionados="tratamientoAEditar.diasSemana"
                                :error="errors.dias_semana"
                            />
                        </div>

                        <div class="grid gap-2">
                            <Label for="frecuencia-editar">
                                Cada cuánto (opcional)
                            </Label>
                            <input
                                id="frecuencia-editar"
                                name="frecuencia"
                                type="text"
                                :value="tratamientoAEditar.frecuencia"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.frecuencia" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="hora-editar">Horario (opcional)</Label>
                            <input
                                id="hora-editar"
                                name="hora"
                                type="time"
                                :value="tratamientoAEditar.hora ?? ''"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.hora" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="inicio-editar">Desde</Label>
                            <input
                                id="inicio-editar"
                                name="inicio"
                                type="date"
                                required
                                :value="tratamientoAEditar.inicio"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.inicio" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="fin-editar">Hasta (opcional)</Label>
                            <input
                                id="fin-editar"
                                name="fin"
                                type="date"
                                :value="tratamientoAEditar.fin"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.fin" />
                        </div>

                        <div v-if="medicos.length > 0" class="grid gap-2">
                            <Label for="medico-editar">Médico</Label>
                            <select
                                id="medico-editar"
                                name="medico_id"
                                :value="tratamientoAEditar.medico_id ?? ''"
                                :class="campoUnaLinea"
                            >
                                <option
                                    value=""
                                    class="bg-background text-foreground"
                                >
                                    Sin especificar
                                </option>
                                <option
                                    v-for="medico in medicos"
                                    :key="medico.id"
                                    :value="medico.id"
                                    class="bg-background text-foreground"
                                >
                                    {{ medico.nombre }}
                                </option>
                            </select>
                            <InputError :message="errors.medico_id" />
                        </div>

                        <div v-if="enfermedades.length > 0" class="grid gap-2">
                            <Label for="enfermedad-editar">
                                ¿Es por alguna enfermedad?
                            </Label>
                            <select
                                id="enfermedad-editar"
                                name="enfermedad_id"
                                :value="tratamientoAEditar.enfermedad_id ?? ''"
                                :class="campoUnaLinea"
                            >
                                <option
                                    value=""
                                    class="bg-background text-foreground"
                                >
                                    No especificar
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
                            <Label
                                for="activo-editar"
                                class="flex items-center space-x-3"
                            >
                                <Checkbox
                                    id="activo-editar"
                                    name="activo"
                                    :default-value="tratamientoAEditar.activo"
                                />
                                <span>Lo sigue tomando</span>
                            </Label>
                            <InputError :message="errors.activo" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="notas-editar">Observaciones</Label>
                            <textarea
                                id="notas-editar"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                                >{{ tratamientoAEditar.notas }}</textarea>
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

        <!-- Fotos de la caja, después del alta -->
        <Sheet
            :open="!!tratamientoDeFotos"
            @update:open="
                (v: boolean) => {
                    if (!v) tratamientoDeFotos = null;
                }
            "
        >
            <SheetContent v-if="tratamientoDeFotos">
                <Form
                    v-bind="
                        AdjuntoController.storeParaTratamiento.form({
                            tratamiento: tratamientoDeFotos.id,
                        })
                    "
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                    @success="tratamientoDeFotos = null"
                >
                    <SheetHeader>
                        <SheetTitle>Fotos de la caja</SheetTitle>
                        <SheetDescription>
                            {{ tratamientoDeFotos.medicamentoNombre }}
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <input
                            type="hidden"
                            name="tipo"
                            value="caja_medicamento"
                        />
                        <SubirArchivo
                            name="archivos[]"
                            multiple
                            etiqueta="Elegir fotos"
                            :error="errors['archivos.0'] ?? errors.archivos"
                        />
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
            :open="!!tratamientoABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) tratamientoABorrar = null;
                }
            "
        >
            <DialogContent v-if="tratamientoABorrar">
                <Form
                    v-bind="
                        TratamientoController.destroy.form({
                            tratamiento: tratamientoABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                    @success="tratamientoABorrar = null"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar
                            {{ tratamientoABorrar.medicamentoNombre }}?
                        </DialogTitle>
                        <DialogDescription>
                            Si ya no lo toma, mejor destildá «Lo sigue tomando»
                            y queda en el historial. Eliminar es para lo que se
                            cargó mal.
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

        <VisorDocumento
            :documento="fotoAbierta"
            :puede-girar="paciente.puedeEditar"
            @cerrar="fotoAbierta = null"
        />
    </div>
</template>
