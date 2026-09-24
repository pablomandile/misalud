<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ArrowLeft, FileText, FlaskConical, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import AdjuntoController from '@/actions/App/Http/Controllers/AdjuntoController';
import GraficoEvolucion from '@/components/GraficoEvolucion.vue';
import EstudioController from '@/actions/App/Http/Controllers/EstudioController';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
import ResultadoEstudioController from '@/actions/App/Http/Controllers/ResultadoEstudioController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SubirArchivo from '@/components/SubirArchivo.vue';
import type { DocumentoVisible } from '@/components/VisorDocumento.vue';
import VisorDocumento from '@/components/VisorDocumento.vue';
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
 * Los estudios de un paciente: lo que se hizo, con sus parámetros y su
 * informe.
 *
 * ⚠️ `computed()` y no un `const`: cargar un estudio o un resultado
 * redirige a esta MISMA URL e Inertia reutiliza la instancia del
 * componente -el bug que apareció en tratamientos, ver CLAUDE.md-.
 */

type Documento = DocumentoVisible & {
    id: number;
    tamanio: number;
};

type Resultado = {
    id: number;
    parametro: string;
    valor: string;
    unidad: string | null;
    rango_referencia: string | null;
};

type PuntoDeResultado = {
    fechaIso: string;
    fechaVisible: string;
    valor: number;
    valorSecundario: null;
};

type EvolucionDeParametro = {
    parametro: string;
    unidad: string;
    resumen: {
        cantidad: number;
        minimo: string;
        maximo: string;
        promedio: string;
    };
    puntos: PuntoDeResultado[];
};

type Estudio = {
    id: number;
    tipo: string;
    fecha: string;
    fechaVisible: string;
    medico_id: number | null;
    medicoNombre: string | null;
    centro_id: number | null;
    centroNombre: string | null;
    enfermedad_id: number | null;
    enfermedadNombre: string | null;
    notas: string | null;
    resultados: Resultado[];
    adjuntos: Documento[];
};

const props = defineProps<{
    paciente: { id: number; nombre: string; puedeEditar: boolean };
    estudios: Estudio[];
    medicos: Array<{ id: number; nombre: string }>;
    centros: Array<{ id: number; nombre: string }>;
    enfermedades: Array<{ id: number; nombre: string }>;
    ordenesDisponibles: Array<{
        id: number;
        estudio_solicitado: string;
        fechaVisible: string;
    }>;
    evolucion: EvolucionDeParametro[];
    hoy: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pacientes', href: PacienteController.index() },
            { title: 'Estudios', href: '' },
        ],
    },
});

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

const sheetAlta = ref(false);
const estudioAEditar = ref<Estudio | null>(null);
const estudioABorrar = ref<Estudio | null>(null);
const estudioDeArchivos = ref<Estudio | null>(null);
const estudioDeResultado = ref<Estudio | null>(null);
const resultadoAEditar = ref<{ estudio: Estudio; resultado: Resultado } | null>(
    null,
);
const resultadoABorrar = ref<{
    estudio: Estudio;
    resultado: Resultado;
} | null>(null);
const documentoAbierto = ref<DocumentoVisible | null>(null);

const ordenados = computed(() =>
    [...props.estudios].sort((a, b) => (a.fecha < b.fecha ? 1 : -1)),
);

function pesoLegible(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.round(bytes / 1024)} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
}

function resumenAccesibleDeParametro(serie: EvolucionDeParametro): string {
    return (
        `${serie.parametro}: ${serie.resumen.cantidad} resultados. ` +
        `Mínimo ${serie.resumen.minimo} ${serie.unidad}. ` +
        `Máximo ${serie.resumen.maximo} ${serie.unidad}. ` +
        `Promedio ${serie.resumen.promedio} ${serie.unidad}. ` +
        'El detalle está en cada estudio, más arriba.'
    );
}

function valorCompleto(r: Resultado): string {
    return r.unidad ? `${r.valor} ${r.unidad}` : r.valor;
}
</script>

<template>
    <Head :title="`Estudios de ${paciente.nombre}`" />

    <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="`Estudios de ${paciente.nombre}`"
                description="Lo que se hizo, con sus parámetros y su informe"
            />
            <Button v-if="paciente.puedeEditar" @click="sheetAlta = true">
                <Plus />
                Agregar
            </Button>
        </div>

        <div
            v-if="estudios.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <FlaskConical class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no cargaste ningún estudio.
            </p>
        </div>

        <div v-else class="grid gap-3">
            <Card v-for="estudio in ordenados" :key="estudio.id">
                <CardContent class="space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 space-y-1">
                            <p class="font-medium">{{ estudio.tipo }}</p>
                            <p class="text-sm text-muted-foreground">
                                {{ estudio.fechaVisible }}
                            </p>
                            <p
                                v-if="estudio.medicoNombre"
                                class="text-sm text-muted-foreground"
                            >
                                {{ estudio.medicoNombre }}
                            </p>
                            <p
                                v-if="estudio.centroNombre"
                                class="text-sm text-muted-foreground"
                            >
                                {{ estudio.centroNombre }}
                            </p>
                            <p
                                v-if="estudio.enfermedadNombre"
                                class="text-sm text-muted-foreground"
                            >
                                Por {{ estudio.enfermedadNombre }}
                            </p>
                            <p v-if="estudio.notas" class="text-sm">
                                {{ estudio.notas }}
                            </p>
                        </div>

                        <div
                            v-if="paciente.puedeEditar"
                            class="flex shrink-0 gap-1"
                        >
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="estudioAEditar = estudio"
                            >
                                Editar
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                :aria-label="`Eliminar el estudio de ${estudio.tipo}`"
                                @click="estudioABorrar = estudio"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                    </div>

                    <!-- Parámetros -->
                    <div class="space-y-2 border-t pt-3">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm text-muted-foreground">
                                Parámetros
                            </p>
                            <Button
                                v-if="paciente.puedeEditar"
                                variant="ghost"
                                size="sm"
                                @click="estudioDeResultado = estudio"
                            >
                                <Plus />
                                Agregar
                            </Button>
                        </div>

                        <p
                            v-if="estudio.resultados.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            Sin parámetros cargados.
                        </p>

                        <ul v-else class="divide-y">
                            <li
                                v-for="resultado in estudio.resultados"
                                :key="resultado.id"
                                class="flex items-start justify-between gap-3 py-2"
                            >
                                <div class="min-w-0">
                                    <p class="text-sm font-medium">
                                        {{ resultado.parametro }}
                                    </p>
                                    <p class="text-sm">
                                        {{ valorCompleto(resultado) }}
                                    </p>
                                    <p
                                        v-if="resultado.rango_referencia"
                                        class="text-sm text-muted-foreground"
                                    >
                                        Referencia:
                                        {{ resultado.rango_referencia }}
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
                                            resultadoAEditar = {
                                                estudio,
                                                resultado,
                                            }
                                        "
                                    >
                                        Editar
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                        :aria-label="`Eliminar el resultado de ${resultado.parametro}`"
                                        @click="
                                            resultadoABorrar = {
                                                estudio,
                                                resultado,
                                            }
                                        "
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <!-- El informe: PDF o imagen -->
                    <div class="space-y-2 border-t pt-3">
                        <ul
                            v-if="estudio.adjuntos.length > 0"
                            class="grid gap-2"
                        >
                            <li
                                v-for="documento in estudio.adjuntos"
                                :key="documento.id"
                                class="flex items-center gap-2 rounded-md border p-2"
                            >
                                <button
                                    type="button"
                                    class="flex min-h-11 min-w-0 flex-1 items-center gap-3 text-left"
                                    @click="documentoAbierto = documento"
                                >
                                    <FileText
                                        class="size-5 shrink-0 text-muted-foreground"
                                    />
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm">
                                            {{ documento.nombre }}
                                        </span>
                                        <span
                                            class="block text-sm text-muted-foreground"
                                        >
                                            {{ pesoLegible(documento.tamanio) }}
                                        </span>
                                    </span>
                                </button>

                                <Form
                                    v-if="paciente.puedeEditar"
                                    v-bind="
                                        AdjuntoController.destroy.form({
                                            adjunto: documento.id,
                                        })
                                    "
                                    :options="{ preserveScroll: true }"
                                    v-slot="{ processing }"
                                >
                                    <Button
                                        type="submit"
                                        variant="ghost"
                                        size="icon-sm"
                                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                        :disabled="processing"
                                        :aria-label="`Eliminar ${documento.nombre}`"
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </Form>
                            </li>
                        </ul>

                        <Button
                            v-if="paciente.puedeEditar"
                            variant="ghost"
                            size="sm"
                            @click="estudioDeArchivos = estudio"
                        >
                            <FileText />
                            {{
                                estudio.adjuntos.length > 0
                                    ? 'Agregar otro documento'
                                    : 'Subir el informe'
                            }}
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!--
            Evolución: el mismo parámetro a través de varios estudios, no
            solo dentro de uno -"Glucemia" en todos los análisis, no en uno
            solo-. Un resultado no numérico ("Positivo") no aparece acá; y un
            parámetro con un solo resultado tampoco -una línea de un punto no
            es una evolución-.
        -->
        <section v-if="evolucion.length > 0" class="space-y-3">
            <h2 class="text-lg font-medium">Evolución de tus parámetros</h2>

            <Card v-for="serie in evolucion" :key="serie.parametro">
                <CardContent class="space-y-2">
                    <p class="text-sm text-muted-foreground">
                        {{ serie.parametro }} ·
                        {{ serie.resumen.cantidad }} resultados · mínimo
                        {{ serie.resumen.minimo }} · máximo
                        {{ serie.resumen.maximo }} · promedio
                        {{ serie.resumen.promedio }} {{ serie.unidad }}
                    </p>

                    <!--
                        `zona-horaria="UTC"`, no la de la cuenta: cada punto
                        sale de `estudio.fecha`, una fecha de CALENDARIO que
                        Carbon guarda siempre a medianoche UTC. Mostrarla en
                        la zona del navegador correría el día -la misma
                        trampa de `hoy()` vs `hoyCalendario()` de CLAUDE.md,
                        del lado del cliente-.
                    -->
                    <GraficoEvolucion
                        :puntos="serie.puntos"
                        :etiqueta-principal="serie.parametro"
                        :etiqueta-secundaria="null"
                        :unidad="serie.unidad"
                        :decimales="2"
                        :min-normal="null"
                        :max-normal="null"
                        :min-normal-secundario="null"
                        :max-normal-secundario="null"
                        zona-horaria="UTC"
                        :resumen-accesible="resumenAccesibleDeParametro(serie)"
                    />
                </CardContent>
            </Card>
        </section>

        <div>
            <Button variant="outline" as-child>
                <a :href="PacienteController.index().url">
                    <ArrowLeft />
                    Volver a pacientes
                </a>
            </Button>
        </div>

        <!-- Alta y edición de estudio -->
        <Sheet
            :open="sheetAlta || !!estudioAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) {
                        sheetAlta = false;
                        estudioAEditar = null;
                    }
                }
            "
        >
            <SheetContent>
                <Form
                    v-bind="
                        estudioAEditar
                            ? EstudioController.update.form({
                                  estudio: estudioAEditar.id,
                              })
                            : EstudioController.store.form({
                                  paciente: paciente.id,
                              })
                    "
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                    @success="
                        () => {
                            sheetAlta = false;
                            estudioAEditar = null;
                        }
                    "
                >
                    <SheetHeader>
                        <SheetTitle>
                            {{
                                estudioAEditar
                                    ? 'Editar el estudio'
                                    : 'Agregar un estudio'
                            }}
                        </SheetTitle>
                        <SheetDescription v-if="!estudioAEditar">
                            El informe se sube después de guardarlo.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="tipo-estudio">Qué se hizo</Label>
                            <input
                                id="tipo-estudio"
                                name="tipo"
                                type="text"
                                required
                                placeholder="Análisis de sangre completo"
                                :value="estudioAEditar?.tipo"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.tipo" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="fecha-estudio">Fecha</Label>
                            <input
                                id="fecha-estudio"
                                name="fecha"
                                type="date"
                                required
                                :max="hoy ?? undefined"
                                :value="estudioAEditar?.fecha ?? hoy"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.fecha" />
                        </div>

                        <!--
                            Solo al crear: vincular la orden que este estudio
                            resuelve. Cierra el circuito orden -> estudio.
                        -->
                        <div
                            v-if="
                                !estudioAEditar && ordenesDisponibles.length > 0
                            "
                            class="grid gap-2"
                        >
                            <Label for="orden-estudio">
                                ¿Es de alguna orden pendiente?
                            </Label>
                            <select
                                id="orden-estudio"
                                name="orden_estudio_id"
                                :class="campoUnaLinea"
                            >
                                <option value="">No especificar</option>
                                <option
                                    v-for="orden in ordenesDisponibles"
                                    :key="orden.id"
                                    :value="orden.id"
                                >
                                    {{ orden.estudio_solicitado }} ({{
                                        orden.fechaVisible
                                    }})
                                </option>
                            </select>
                            <InputError :message="errors.orden_estudio_id" />
                        </div>

                        <div v-if="medicos.length > 0" class="grid gap-2">
                            <Label for="medico-estudio">Médico</Label>
                            <select
                                id="medico-estudio"
                                name="medico_id"
                                :value="estudioAEditar?.medico_id ?? ''"
                                :class="campoUnaLinea"
                            >
                                <option value="">Sin especificar</option>
                                <option
                                    v-for="medico in medicos"
                                    :key="medico.id"
                                    :value="medico.id"
                                >
                                    {{ medico.nombre }}
                                </option>
                            </select>
                            <InputError :message="errors.medico_id" />
                        </div>

                        <div v-if="centros.length > 0" class="grid gap-2">
                            <Label for="centro-estudio">Dónde</Label>
                            <select
                                id="centro-estudio"
                                name="centro_id"
                                :value="estudioAEditar?.centro_id ?? ''"
                                :class="campoUnaLinea"
                            >
                                <option value="">Sin especificar</option>
                                <option
                                    v-for="centro in centros"
                                    :key="centro.id"
                                    :value="centro.id"
                                >
                                    {{ centro.nombre }}
                                </option>
                            </select>
                            <InputError :message="errors.centro_id" />
                        </div>

                        <div v-if="enfermedades.length > 0" class="grid gap-2">
                            <Label for="enfermedad-estudio">
                                ¿Es por alguna enfermedad?
                            </Label>
                            <select
                                id="enfermedad-estudio"
                                name="enfermedad_id"
                                :value="estudioAEditar?.enfermedad_id ?? ''"
                                :class="campoUnaLinea"
                            >
                                <option value="">No especificar</option>
                                <option
                                    v-for="enfermedad in enfermedades"
                                    :key="enfermedad.id"
                                    :value="enfermedad.id"
                                >
                                    {{ enfermedad.nombre }}
                                </option>
                            </select>
                            <InputError :message="errors.enfermedad_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="notas-estudio">Notas</Label>
                            <textarea
                                id="notas-estudio"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                                >{{ estudioAEditar?.notas }}</textarea>
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

        <!-- Subir el informe -->
        <Sheet
            :open="!!estudioDeArchivos"
            @update:open="
                (v: boolean) => {
                    if (!v) estudioDeArchivos = null;
                }
            "
        >
            <SheetContent v-if="estudioDeArchivos">
                <Form
                    v-bind="
                        AdjuntoController.storeParaEstudio.form({
                            estudio: estudioDeArchivos.id,
                        })
                    "
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                    @success="estudioDeArchivos = null"
                >
                    <SheetHeader>
                        <SheetTitle>El informe del estudio</SheetTitle>
                        <SheetDescription>
                            {{ estudioDeArchivos.tipo }}
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="tipo-adjunto-estudio">
                                Qué es este documento
                            </Label>
                            <select
                                id="tipo-adjunto-estudio"
                                name="tipo"
                                :class="campoUnaLinea"
                            >
                                <option value="informe_estudio">
                                    El informe
                                </option>
                                <option value="imagen_estudio">
                                    La imagen (radiografía, ecografía…)
                                </option>
                            </select>
                            <InputError :message="errors.tipo" />
                        </div>

                        <SubirArchivo
                            name="archivos[]"
                            multiple
                            etiqueta="Foto o PDF del documento"
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

        <!-- Cargar un resultado -->
        <Sheet
            :open="!!estudioDeResultado || !!resultadoAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) {
                        estudioDeResultado = null;
                        resultadoAEditar = null;
                    }
                }
            "
        >
            <SheetContent v-if="estudioDeResultado || resultadoAEditar">
                <Form
                    v-bind="
                        resultadoAEditar
                            ? ResultadoEstudioController.update.form({
                                  resultado: resultadoAEditar.resultado.id,
                              })
                            : ResultadoEstudioController.store.form({
                                  estudio: estudioDeResultado!.id,
                              })
                    "
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                    @success="
                        () => {
                            estudioDeResultado = null;
                            resultadoAEditar = null;
                        }
                    "
                >
                    <SheetHeader>
                        <SheetTitle>
                            {{
                                resultadoAEditar
                                    ? 'Editar el parámetro'
                                    : 'Agregar un parámetro'
                            }}
                        </SheetTitle>
                        <SheetDescription>
                            {{
                                (
                                    resultadoAEditar?.estudio ??
                                    estudioDeResultado
                                )?.tipo
                            }}
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="parametro-resultado">Parámetro</Label>
                            <input
                                id="parametro-resultado"
                                name="parametro"
                                type="text"
                                required
                                placeholder="Glucemia"
                                :value="resultadoAEditar?.resultado.parametro"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.parametro" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="valor-resultado">Valor</Label>
                            <input
                                id="valor-resultado"
                                name="valor"
                                type="text"
                                inputmode="decimal"
                                required
                                placeholder="90"
                                :value="resultadoAEditar?.resultado.valor"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.valor" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="unidad-resultado">Unidad</Label>
                            <input
                                id="unidad-resultado"
                                name="unidad"
                                type="text"
                                placeholder="mg/dl"
                                :value="resultadoAEditar?.resultado.unidad"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.unidad" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="rango-resultado">
                                Rango de referencia
                            </Label>
                            <input
                                id="rango-resultado"
                                name="rango_referencia"
                                type="text"
                                placeholder="70 a 110"
                                :value="
                                    resultadoAEditar?.resultado.rango_referencia
                                "
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.rango_referencia" />
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

        <!-- Borrar estudio -->
        <Dialog
            :open="!!estudioABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) estudioABorrar = null;
                }
            "
        >
            <DialogContent v-if="estudioABorrar">
                <Form
                    v-bind="
                        EstudioController.destroy.form({
                            estudio: estudioABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                    @success="estudioABorrar = null"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar el estudio de {{ estudioABorrar.tipo }}?
                        </DialogTitle>
                        <DialogDescription>
                            Se borra con sus parámetros y su informe. Esto no se
                            puede deshacer.
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

        <!-- Borrar resultado -->
        <Dialog
            :open="!!resultadoABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) resultadoABorrar = null;
                }
            "
        >
            <DialogContent v-if="resultadoABorrar">
                <Form
                    v-bind="
                        ResultadoEstudioController.destroy.form({
                            resultado: resultadoABorrar.resultado.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                    @success="resultadoABorrar = null"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar el resultado de
                            {{ resultadoABorrar.resultado.parametro }}?
                        </DialogTitle>
                        <DialogDescription>
                            Esto no se puede deshacer.
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

        <!-- UNO SOLO para toda la pantalla, fuera de todo v-for. -->
        <VisorDocumento
            :documento="documentoAbierto"
            @cerrar="documentoAbierto = null"
        />
    </div>
</template>
