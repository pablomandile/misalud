<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowLeftRight,
    Eye,
    FileText,
    Plus,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
import AdjuntoController from '@/actions/App/Http/Controllers/AdjuntoController';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
import PrescripcionOcularController from '@/actions/App/Http/Controllers/PrescripcionOcularController';
import type { ValoresDeOjo } from '@/components/DiagramaOjo.vue';
import DiagramaOjo from '@/components/DiagramaOjo.vue';
import GraficoEvolucion from '@/components/GraficoEvolucion.vue';
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
 * Salud ocular: las recetas de anteojos de un paciente.
 *
 * ## OD va a la izquierda
 *
 * Es la convención óptica y tiene un motivo físico: mirando a la persona, su
 * ojo derecho queda a tu izquierda. Acá se cumple por el **orden del DOM**
 * -`od` primero-, así que vale igual cuando los dos ojos quedan uno arriba
 * del otro en el celular. Y cada uno lleva su rótulo en texto completo, que
 * es lo que de verdad lo desambigua (ver `DiagramaOjo.vue`).
 *
 * ## Una receta trae siempre sus dos ojos
 *
 * No hay "agregar un ojo": el formulario carga los dos juntos y el
 * controlador los guarda en una transacción. Un ojo sin datos dice "sin
 * datos", que es una respuesta —a diferencia de una fila que falta, que no
 * se sabe si es un ojo sano o una carga a medias—.
 *
 * ## Transponer es solo una forma distinta de MOSTRAR
 *
 * "Transponer" no guarda nada ni pega al servidor: alterna un `Set` local
 * de ids que le pasa `transpuesto` a `DiagramaOjo`, que hace la cuenta con
 * los valores que ya tiene en la mano (ver el comentario largo ahí). Por
 * eso se pierde al recargar la página, y está bien que sea así -es una
 * lectura distinta del mismo papel, no una preferencia que alguien
 * necesite que persista-.
 */

type Documento = DocumentoVisible & {
    id: number;
    tamanio: number;
};

type Prescripcion = {
    id: number;
    tipo: string;
    tipoEtiqueta: string;
    fecha: string;
    fechaVisible: string;
    medico_id: number | null;
    medicoNombre: string | null;
    centro_id: number | null;
    centroNombre: string | null;
    dp_total: string | null;
    notas: string | null;
    ojos: { od: ValoresDeOjo; oi: ValoresDeOjo };
    adjuntos: Documento[];
};

type PuntoDeEsfera = {
    fechaIso: string;
    fechaVisible: string;
    valor: number;
    valorSecundario: null;
};

type EvolucionDeOjo = {
    ojo: 'od' | 'oi';
    etiqueta: string;
    puntos: PuntoDeEsfera[];
    resumen: {
        cantidad: number;
        minimo: string;
        maximo: string;
        promedio: string;
    };
};

defineProps<{
    paciente: { id: number; nombre: string; puedeEditar: boolean };
    prescripciones: Prescripcion[];
    tipos: Array<{ valor: string; etiqueta: string }>;
    medicos: Array<{ id: number; nombre: string }>;
    centros: Array<{ id: number; nombre: string }>;
    evolucion: EvolucionDeOjo[];
    hoy: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pacientes', href: PacienteController.index() },
            { title: 'Salud ocular', href: '' },
        ],
    },
});

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

const sheetAlta = ref(false);
const recetaAEditar = ref<Prescripcion | null>(null);
const recetaABorrar = ref<Prescripcion | null>(null);
const recetaDeArchivos = ref<Prescripcion | null>(null);
const documentoAbierto = ref<DocumentoVisible | null>(null);

function pesoLegible(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.round(bytes / 1024)} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
}

// Qué recetas se están mostrando transpuestas. Ver el comentario de arriba:
// es un `Set` de ids, no una propiedad de cada receta -no hay nada que
// guardar-.
const transpuestas = ref<Set<number>>(new Set());

function alternarTransposicion(id: number): void {
    const nuevo = new Set(transpuestas.value);

    if (nuevo.has(id)) {
        nuevo.delete(id);
    } else {
        nuevo.add(id);
    }

    transpuestas.value = nuevo;
}

/*
 * Si ninguno de los dos ojos tiene cilindro, no hay nada que transponer -la
 * esfera sola no tiene "otra forma"- y el botón sería un control que no
 * hace nada. `cilindro` llega como string ("0", "-0.50", null): un `Number`
 * antes de comparar, porque `Boolean("0")` es `true` en JS.
 */
function tieneAlgoQueTransponer(receta: Prescripcion): boolean {
    return (
        Number(receta.ojos.od.cilindro ?? 0) !== 0 ||
        Number(receta.ojos.oi.cilindro ?? 0) !== 0
    );
}

function resumenAccesibleDeOjo(serie: EvolucionDeOjo): string {
    return (
        `Esfera del ${serie.etiqueta}: ${serie.resumen.cantidad} recetas. ` +
        `Mínimo ${serie.resumen.minimo} D. ` +
        `Máximo ${serie.resumen.maximo} D. ` +
        `Promedio ${serie.resumen.promedio} D. ` +
        'El detalle está en cada receta, más arriba.'
    );
}
</script>

<template>
    <Head :title="`Salud ocular de ${paciente.nombre}`" />

    <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="`Salud ocular de ${paciente.nombre}`"
                description="Las recetas de anteojos, con la graduación de cada ojo"
            />
            <Button v-if="paciente.puedeEditar" @click="sheetAlta = true">
                <Plus />
                Agregar
            </Button>
        </div>

        <div
            v-if="prescripciones.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <Eye class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no cargaste ninguna receta de anteojos.
            </p>
        </div>

        <div class="grid gap-4">
            <Card v-for="receta in prescripciones" :key="receta.id">
                <CardContent class="space-y-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 space-y-1">
                            <p class="font-medium">{{ receta.tipoEtiqueta }}</p>
                            <p class="text-sm text-muted-foreground">
                                Del {{ receta.fechaVisible }}
                            </p>
                            <p
                                v-if="
                                    receta.medicoNombre || receta.centroNombre
                                "
                                class="text-sm text-muted-foreground"
                            >
                                {{
                                    [receta.medicoNombre, receta.centroNombre]
                                        .filter(Boolean)
                                        .join(' · ')
                                }}
                            </p>
                            <p
                                v-if="receta.dp_total"
                                class="text-sm text-muted-foreground"
                            >
                                Distancia pupilar total:
                                {{ receta.dp_total }} mm
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-wrap justify-end gap-1">
                            <Button
                                v-if="tieneAlgoQueTransponer(receta)"
                                variant="ghost"
                                size="sm"
                                @click="alternarTransposicion(receta.id)"
                            >
                                <ArrowLeftRight />
                                {{
                                    transpuestas.has(receta.id)
                                        ? 'Volver al papel'
                                        : 'Transponer'
                                }}
                            </Button>
                            <template v-if="paciente.puedeEditar">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="recetaAEditar = receta"
                                >
                                    Editar
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    :aria-label="`Eliminar la receta del ${receta.fechaVisible}`"
                                    @click="recetaABorrar = receta"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </template>
                        </div>
                    </div>

                    <!--
                        OD primero en el DOM: apilados en el celular queda
                        arriba, y en escritorio a la izquierda. Las dos cosas
                        son la misma convención.

                        `transpuesto` solo cambia lo que se MUESTRA acá -el
                        listado-, nunca el formulario de alta o edición, que
                        siempre carga y guarda tal cual figura en el papel.
                    -->
                    <div class="grid gap-4 md:grid-cols-2">
                        <DiagramaOjo
                            ojo="od"
                            modo="lectura"
                            :valores="receta.ojos.od"
                            :transpuesto="transpuestas.has(receta.id)"
                        />
                        <DiagramaOjo
                            ojo="oi"
                            modo="lectura"
                            :valores="receta.ojos.oi"
                            :transpuesto="transpuestas.has(receta.id)"
                        />
                    </div>

                    <p v-if="receta.notas" class="text-sm">
                        {{ receta.notas }}
                    </p>

                    <!-- El papel: se sube igual aunque los valores estén cargados. -->
                    <div class="space-y-2 border-t pt-3">
                        <ul
                            v-if="receta.adjuntos.length > 0"
                            class="grid gap-2"
                        >
                            <li
                                v-for="documento in receta.adjuntos"
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
                            @click="recetaDeArchivos = receta"
                        >
                            <FileText />
                            {{
                                receta.adjuntos.length > 0
                                    ? 'Agregar otra foto'
                                    : 'Subir la receta'
                            }}
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!--
            Solo con dos o más recetas por ojo: una línea de un punto no es
            una evolución. Es `esfera` sola, no un "poder equivalente"
            -sumar esfera y cilindro sería una cuenta que el papel no trae
            escrita, y el sistema registra, no interpreta (regla 1)-.
        -->
        <section v-if="evolucion.length > 0" class="space-y-3">
            <h2 class="text-lg font-medium">Evolución de la esfera</h2>

            <Card v-for="serie in evolucion" :key="serie.ojo">
                <CardContent class="space-y-2">
                    <p class="text-sm text-muted-foreground">
                        {{ serie.etiqueta }} ·
                        {{ serie.resumen.cantidad }} recetas · mínimo
                        {{ serie.resumen.minimo }} · máximo
                        {{ serie.resumen.maximo }} · promedio
                        {{ serie.resumen.promedio }} D
                    </p>

                    <!--
                        `zona-horaria="UTC"`, string literal y no un binding:
                        `prescripcion.fecha` es una fecha de CALENDARIO que
                        Carbon guarda a medianoche UTC. Mostrarla en la zona
                        del navegador corre el día -la misma trampa de
                        `hoy()` vs `hoyCalendario()`, del lado del cliente
                        (ver Etapa 9.4 en CLAUDE.md)-.
                    -->
                    <GraficoEvolucion
                        :puntos="serie.puntos"
                        :etiqueta-principal="serie.etiqueta"
                        :etiqueta-secundaria="null"
                        unidad="D"
                        :decimales="2"
                        :min-normal="null"
                        :max-normal="null"
                        :min-normal-secundario="null"
                        :max-normal-secundario="null"
                        zona-horaria="UTC"
                        :resumen-accesible="resumenAccesibleDeOjo(serie)"
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

        <!-- Alta y edición -->
        <Sheet
            :open="sheetAlta || !!recetaAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) {
                        sheetAlta = false;
                        recetaAEditar = null;
                    }
                }
            "
        >
            <SheetContent class="sm:max-w-xl">
                <!--
                    `:key` para que al pasar de una receta a otra el
                    componente se remonte: `DiagramaOjo` guarda el eje que se
                    va escribiendo en un `ref` propio, y sin remontar
                    arrastraría el de la receta anterior.
                -->
                <Form
                    :key="recetaAEditar?.id ?? 'nueva'"
                    v-bind="
                        recetaAEditar
                            ? PrescripcionOcularController.update.form({
                                  prescripcion: recetaAEditar.id,
                              })
                            : PrescripcionOcularController.store.form({
                                  paciente: paciente.id,
                              })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                    @success="
                        () => {
                            sheetAlta = false;
                            recetaAEditar = null;
                        }
                    "
                >
                    <SheetHeader>
                        <SheetTitle>
                            {{
                                recetaAEditar
                                    ? 'Editar la receta'
                                    : 'Agregar una receta de anteojos'
                            }}
                        </SheetTitle>
                        <SheetDescription>
                            Copiá los valores tal como figuran en el papel.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="tipo-receta">Para qué es</Label>
                            <select
                                id="tipo-receta"
                                name="tipo"
                                :value="recetaAEditar?.tipo ?? 'lejos'"
                                :class="campoUnaLinea"
                            >
                                <option
                                    v-for="opcion in tipos"
                                    :key="opcion.valor"
                                    :value="opcion.valor"
                                >
                                    {{ opcion.etiqueta }}
                                </option>
                            </select>
                            <InputError :message="errors.tipo" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="fecha-receta">Fecha de la receta</Label>
                            <input
                                id="fecha-receta"
                                name="fecha"
                                type="date"
                                required
                                :max="hoy ?? undefined"
                                :value="recetaAEditar?.fecha ?? hoy"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.fecha" />
                        </div>

                        <div v-if="medicos.length > 0" class="grid gap-2">
                            <Label for="medico-receta">Médico</Label>
                            <select
                                id="medico-receta"
                                name="medico_id"
                                :value="recetaAEditar?.medico_id ?? ''"
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
                            <Label for="centro-receta">Óptica o centro</Label>
                            <select
                                id="centro-receta"
                                name="centro_id"
                                :value="recetaAEditar?.centro_id ?? ''"
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

                        <div class="grid gap-2">
                            <Label for="dp-receta">
                                Distancia pupilar total
                                <span class="font-normal text-muted-foreground">
                                    (en mm)
                                </span>
                            </Label>
                            <input
                                id="dp-receta"
                                name="dp_total"
                                type="text"
                                inputmode="decimal"
                                autocomplete="off"
                                :value="recetaAEditar?.dp_total ?? ''"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.dp_total" />
                        </div>

                        <DiagramaOjo
                            ojo="od"
                            :valores="recetaAEditar?.ojos.od ?? null"
                            :errores="errors"
                        />
                        <DiagramaOjo
                            ojo="oi"
                            :valores="recetaAEditar?.ojos.oi ?? null"
                            :errores="errors"
                        />

                        <div class="grid gap-2">
                            <Label for="notas-receta">Notas</Label>
                            <textarea
                                id="notas-receta"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                                >{{ recetaAEditar?.notas }}</textarea>
                            <InputError :message="errors.notas" />
                        </div>
                    </div>

                    <SheetFooter>
                        <Button type="submit" :disabled="processing"
                            >Guardar</Button
                        >
                        <SheetClose as-child>
                            <Button type="button" variant="secondary"
                                >Cancelar</Button
                            >
                        </SheetClose>
                    </SheetFooter>
                </Form>
            </SheetContent>
        </Sheet>

        <!-- Subir el papel -->
        <Sheet
            :open="!!recetaDeArchivos"
            @update:open="
                (v: boolean) => {
                    if (!v) recetaDeArchivos = null;
                }
            "
        >
            <SheetContent v-if="recetaDeArchivos">
                <Form
                    v-bind="
                        AdjuntoController.storeParaPrescripcionOcular.form({
                            prescripcion: recetaDeArchivos.id,
                        })
                    "
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                    @success="recetaDeArchivos = null"
                >
                    <SheetHeader>
                        <SheetTitle>El papel de la receta</SheetTitle>
                        <SheetDescription>
                            {{ recetaDeArchivos.tipoEtiqueta }}, del
                            {{ recetaDeArchivos.fechaVisible }}
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <input
                            type="hidden"
                            name="tipo"
                            value="prescripcion_ocular"
                        />
                        <SubirArchivo
                            name="archivos[]"
                            multiple
                            etiqueta="Foto o PDF de la receta"
                            :error="errors['archivos.0'] ?? errors.archivos"
                        />
                    </div>

                    <SheetFooter>
                        <Button type="submit" :disabled="processing"
                            >Guardar</Button
                        >
                        <SheetClose as-child>
                            <Button type="button" variant="secondary"
                                >Cancelar</Button
                            >
                        </SheetClose>
                    </SheetFooter>
                </Form>
            </SheetContent>
        </Sheet>

        <!-- Borrar -->
        <Dialog
            :open="!!recetaABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) recetaABorrar = null;
                }
            "
        >
            <DialogContent v-if="recetaABorrar">
                <Form
                    v-bind="
                        PrescripcionOcularController.destroy.form({
                            prescripcion: recetaABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                    @success="recetaABorrar = null"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar la receta del
                            {{ recetaABorrar.fechaVisible }}?
                        </DialogTitle>
                        <DialogDescription>
                            Se borra con la graduación de los dos ojos y con su
                            papel. Esto no se puede deshacer.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="secondary"
                                >Cancelar</Button
                            >
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
