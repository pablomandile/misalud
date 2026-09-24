<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import {
    ArrowLeft,
    HeartPulse,
    Plus,
    TriangleAlert,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AlergiaController from '@/actions/App/Http/Controllers/AlergiaController';
import EnfermedadController from '@/actions/App/Http/Controllers/EnfermedadController';
import RegistroEnfermedadController from '@/actions/App/Http/Controllers/RegistroEnfermedadController';
import GraficoEvolucion from '@/components/GraficoEvolucion.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
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
 * Enfermedades, su bitácora y las alergias de un paciente.
 *
 * Las alergias van ARRIBA aunque sean menos: es lo que alguien busca
 * apurado, y la pantalla la usa gente que necesita encontrarlo sin
 * desplazarse.
 *
 * La bitácora es texto y nada más. Los números medibles no van acá: van a
 * Mediciones y apuntan a la enfermedad, y por eso cada enfermedad muestra
 * su propia curva sin que el mismo dato viva en dos tablas.
 */

type Punto = {
    fechaIso: string;
    fechaVisible: string;
    valor: number;
    valorSecundario: number | null;
    valorVisible: string | null;
    valorSecundarioVisible: string | null;
};

type Serie = {
    tipoId: number;
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
    resumen: {
        cantidad: number;
        minimo: string | null;
        maximo: string | null;
        promedio: string | null;
    };
    mediciones: Punto[];
};

type Registro = {
    id: number;
    fecha: string;
    fechaVisible: string;
    nota: string;
};

type Enfermedad = {
    id: number;
    nombre: string;
    fecha_diagnostico: string | null;
    fechaVisible: string | null;
    estado: string;
    estadoEtiqueta: string;
    estaVigente: boolean;
    medico_id: number | null;
    medicoNombre: string | null;
    notas: string | null;
    registros: Registro[];
    series: Serie[];
};

type Alergia = {
    id: number;
    sustancia: string;
    reaccion: string | null;
    severidad: string;
    severidadEtiqueta: string;
    notas: string | null;
};

const props = defineProps<{
    paciente: { id: number; nombre: string; puedeEditar: boolean };
    enfermedades: Enfermedad[];
    alergias: Alergia[];
    medicos: Array<{ id: number; nombre: string }>;
    hoy: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pacientes', href: PacienteController.index() },
            { title: 'Enfermedades y alergias', href: '' },
        ],
    },
});

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

const sheetEnfermedad = ref(false);
const enfermedadAEditar = ref<Enfermedad | null>(null);
const enfermedadABorrar = ref<Enfermedad | null>(null);
const enfermedadDeBitacora = ref<Enfermedad | null>(null);

const sheetAlergia = ref(false);
const alergiaAEditar = ref<Alergia | null>(null);
const alergiaABorrar = ref<Alergia | null>(null);

const vigentes = computed(() =>
    props.enfermedades.filter((e) => e.estaVigente),
);
const pasadas = computed(() =>
    props.enfermedades.filter((e) => !e.estaVigente),
);

/** Lo que lee un lector de pantalla en lugar del canvas. */
function resumenAccesible(serie: Serie): string {
    return (
        `${serie.nombre}: ${serie.resumen.cantidad} mediciones. ` +
        `Mínimo ${serie.resumen.minimo} ${serie.unidad}. ` +
        `Máximo ${serie.resumen.maximo} ${serie.unidad}. ` +
        `Promedio ${serie.resumen.promedio} ${serie.unidad}. ` +
        'El detalle está en la pantalla de Mediciones.'
    );
}

function valorDe(punto: Punto, serie: Serie): string {
    return punto.valorSecundarioVisible !== null
        ? `${punto.valorVisible}/${punto.valorSecundarioVisible} ${serie.unidad}`
        : `${punto.valorVisible} ${serie.unidad}`;
}
</script>

<template>
    <Head :title="`Enfermedades y alergias de ${paciente.nombre}`" />

    <div class="space-y-8">
        <Heading
            variant="small"
            :title="`Enfermedades y alergias de ${paciente.nombre}`"
            description="Lo que tiene o tuvo, y a qué es alérgico"
        />

        <!--
            Alergias primero: son pocas y es lo que se busca apurado. La
            severidad se muestra como texto y no como un semáforo de colores;
            es lo que cargó la persona, no un juicio del sistema.
        -->
        <section class="space-y-3">
            <div class="flex items-center justify-between gap-4">
                <h2 class="flex items-center gap-2 text-lg font-medium">
                    <TriangleAlert class="size-5" />
                    Alergias
                </h2>
                <Button
                    v-if="paciente.puedeEditar"
                    variant="outline"
                    size="sm"
                    @click="sheetAlergia = true"
                >
                    <Plus />
                    Agregar
                </Button>
            </div>

            <p
                v-if="alergias.length === 0"
                class="text-sm text-muted-foreground"
            >
                No hay alergias cargadas.
            </p>

            <div v-else class="grid gap-3 sm:grid-cols-2">
                <Card v-for="alergia in alergias" :key="alergia.id">
                    <CardContent class="flex items-start justify-between gap-3">
                        <div class="min-w-0 space-y-1">
                            <p class="font-medium">{{ alergia.sustancia }}</p>
                            <p class="text-sm text-muted-foreground">
                                {{ alergia.severidadEtiqueta
                                }}<span v-if="alergia.reaccion">
                                    · {{ alergia.reaccion }}</span
                                >
                            </p>
                            <p v-if="alergia.notas" class="text-sm">
                                {{ alergia.notas }}
                            </p>
                        </div>

                        <div
                            v-if="paciente.puedeEditar"
                            class="flex shrink-0 gap-1"
                        >
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="alergiaAEditar = alergia"
                            >
                                Editar
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                :aria-label="`Eliminar la alergia a ${alergia.sustancia}`"
                                @click="alergiaABorrar = alergia"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </section>

        <section class="space-y-3">
            <div class="flex items-center justify-between gap-4">
                <h2 class="flex items-center gap-2 text-lg font-medium">
                    <HeartPulse class="size-5" />
                    Enfermedades
                </h2>
                <Button
                    v-if="paciente.puedeEditar"
                    variant="outline"
                    size="sm"
                    @click="sheetEnfermedad = true"
                >
                    <Plus />
                    Agregar
                </Button>
            </div>

            <p
                v-if="enfermedades.length === 0"
                class="text-sm text-muted-foreground"
            >
                No hay enfermedades cargadas.
            </p>

            <template
                v-for="grupo in [
                    { titulo: 'Actuales', lista: vigentes },
                    { titulo: 'Anteriores', lista: pasadas },
                ]"
                :key="grupo.titulo"
            >
                <div v-if="grupo.lista.length > 0" class="space-y-3">
                    <p class="text-sm text-muted-foreground">
                        {{ grupo.titulo }}
                    </p>

                    <Card
                        v-for="enfermedad in grupo.lista"
                        :key="enfermedad.id"
                    >
                        <CardContent class="space-y-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 space-y-1">
                                    <p class="font-medium">
                                        {{ enfermedad.nombre }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ enfermedad.estadoEtiqueta
                                        }}<span v-if="enfermedad.fechaVisible">
                                            · desde
                                            {{ enfermedad.fechaVisible }}</span
                                        >
                                    </p>
                                    <p
                                        v-if="enfermedad.medicoNombre"
                                        class="text-sm text-muted-foreground"
                                    >
                                        {{ enfermedad.medicoNombre }}
                                    </p>
                                    <p v-if="enfermedad.notas" class="text-sm">
                                        {{ enfermedad.notas }}
                                    </p>
                                </div>

                                <div
                                    v-if="paciente.puedeEditar"
                                    class="flex shrink-0 gap-1"
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="enfermedadAEditar = enfermedad"
                                    >
                                        Editar
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                        :aria-label="`Eliminar ${enfermedad.nombre}`"
                                        @click="enfermedadABorrar = enfermedad"
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </div>
                            </div>

                            <!--
                                La curva de lo que se sigue por esta
                                enfermedad. Los números viven en Mediciones y
                                apuntan acá: una sola tabla, una sola curva.
                            -->
                            <div
                                v-for="serie in enfermedad.series"
                                :key="serie.tipoId"
                                class="space-y-2 border-t pt-3"
                            >
                                <p class="text-sm text-muted-foreground">
                                    {{ serie.nombre }} ·
                                    {{ serie.resumen.cantidad }}
                                    {{
                                        serie.resumen.cantidad === 1
                                            ? 'medición'
                                            : 'mediciones'
                                    }}
                                    · promedio {{ serie.resumen.promedio }}
                                    {{ serie.unidad }}
                                </p>

                                <GraficoEvolucion
                                    v-if="serie.mediciones.length > 1"
                                    :puntos="serie.mediciones"
                                    :etiqueta-principal="
                                        serie.etiquetaPrincipal
                                    "
                                    :etiqueta-secundaria="
                                        serie.etiquetaSecundaria
                                    "
                                    :unidad="serie.unidad"
                                    :decimales="serie.decimales"
                                    :min-normal="serie.minNormal"
                                    :max-normal="serie.maxNormal"
                                    :min-normal-secundario="
                                        serie.minNormalSecundario
                                    "
                                    :max-normal-secundario="
                                        serie.maxNormalSecundario
                                    "
                                    :zona-horaria="null"
                                    :resumen-accesible="resumenAccesible(serie)"
                                />

                                <!--
                                    El gráfico nunca es la única fuente: al
                                    lado van los valores con su fecha.
                                -->
                                <ul class="text-sm text-muted-foreground">
                                    <li
                                        v-for="punto in serie.mediciones.slice(
                                            0,
                                            3,
                                        )"
                                        :key="punto.fechaIso"
                                    >
                                        {{ valorDe(punto, serie) }} ·
                                        {{ punto.fechaVisible }}
                                    </li>
                                </ul>
                            </div>

                            <!-- Bitácora -->
                            <div class="space-y-2 border-t pt-3">
                                <div
                                    class="flex items-center justify-between gap-2"
                                >
                                    <p class="text-sm text-muted-foreground">
                                        Bitácora
                                    </p>
                                    <Button
                                        v-if="paciente.puedeEditar"
                                        variant="ghost"
                                        size="sm"
                                        @click="
                                            enfermedadDeBitacora = enfermedad
                                        "
                                    >
                                        <Plus />
                                        Anotar
                                    </Button>
                                </div>

                                <p
                                    v-if="enfermedad.registros.length === 0"
                                    class="text-sm text-muted-foreground"
                                >
                                    Sin anotaciones.
                                </p>

                                <ul v-else class="divide-y">
                                    <li
                                        v-for="registro in enfermedad.registros"
                                        :key="registro.id"
                                        class="flex items-start justify-between gap-3 py-2"
                                    >
                                        <div class="min-w-0">
                                            <p class="text-sm">
                                                {{ registro.nota }}
                                            </p>
                                            <p
                                                class="text-sm text-muted-foreground"
                                            >
                                                {{ registro.fechaVisible }}
                                            </p>
                                        </div>

                                        <Form
                                            v-if="paciente.puedeEditar"
                                            v-bind="
                                                RegistroEnfermedadController.destroy.form(
                                                    { registro: registro.id },
                                                )
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
                                                :aria-label="`Eliminar la anotación del ${registro.fechaVisible}`"
                                            >
                                                <Trash2 class="size-4" />
                                            </Button>
                                        </Form>
                                    </li>
                                </ul>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </template>
        </section>

        <div>
            <Button variant="outline" as-child>
                <a :href="PacienteController.index().url">
                    <ArrowLeft />
                    Volver a pacientes
                </a>
            </Button>
        </div>

        <!-- Alta y edición de enfermedad -->
        <Sheet
            :open="sheetEnfermedad || !!enfermedadAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) {
                        sheetEnfermedad = false;
                        enfermedadAEditar = null;
                    }
                }
            "
        >
            <SheetContent>
                <Form
                    v-bind="
                        enfermedadAEditar
                            ? EnfermedadController.update.form({
                                  enfermedad: enfermedadAEditar.id,
                              })
                            : EnfermedadController.store.form({
                                  paciente: paciente.id,
                              })
                    "
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                    @success="
                        () => {
                            sheetEnfermedad = false;
                            enfermedadAEditar = null;
                        }
                    "
                >
                    <SheetHeader>
                        <SheetTitle>
                            {{
                                enfermedadAEditar
                                    ? `Editar ${enfermedadAEditar.nombre}`
                                    : 'Agregar una enfermedad'
                            }}
                        </SheetTitle>
                        <SheetDescription v-if="!enfermedadAEditar">
                            Queda en la ficha de {{ paciente.nombre }}.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="nombre-enfermedad">Nombre</Label>
                            <input
                                id="nombre-enfermedad"
                                name="nombre"
                                type="text"
                                required
                                :value="enfermedadAEditar?.nombre"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.nombre" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="estado-enfermedad">Estado</Label>
                            <select
                                id="estado-enfermedad"
                                name="estado"
                                :value="enfermedadAEditar?.estado ?? 'activa'"
                                :class="campoUnaLinea"
                            >
                                <option value="activa">Activa</option>
                                <option value="cronica">Crónica</option>
                                <option value="resuelta">Resuelta</option>
                            </select>
                            <InputError :message="errors.estado" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="fecha-enfermedad">
                                Fecha de diagnóstico
                            </Label>
                            <input
                                id="fecha-enfermedad"
                                name="fecha_diagnostico"
                                type="date"
                                :max="hoy ?? undefined"
                                :value="enfermedadAEditar?.fecha_diagnostico"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.fecha_diagnostico" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="medico-enfermedad">Médico</Label>
                            <select
                                id="medico-enfermedad"
                                name="medico_id"
                                :value="enfermedadAEditar?.medico_id ?? ''"
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

                        <div class="grid gap-2">
                            <Label for="notas-enfermedad">Notas</Label>
                            <textarea
                                id="notas-enfermedad"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                                >{{ enfermedadAEditar?.notas }}</textarea>
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

        <!-- Anotar en la bitácora -->
        <Sheet
            :open="!!enfermedadDeBitacora"
            @update:open="
                (v: boolean) => {
                    if (!v) enfermedadDeBitacora = null;
                }
            "
        >
            <SheetContent v-if="enfermedadDeBitacora">
                <Form
                    v-bind="
                        RegistroEnfermedadController.store.form({
                            enfermedad: enfermedadDeBitacora.id,
                        })
                    "
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                    @success="enfermedadDeBitacora = null"
                >
                    <SheetHeader>
                        <SheetTitle>
                            Anotar en {{ enfermedadDeBitacora.nombre }}
                        </SheetTitle>
                        <SheetDescription>
                            Qué pasó. Los valores medibles —peso, presión— van
                            en Mediciones, para que queden en la curva.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="fecha-registro">Cuándo</Label>
                            <input
                                id="fecha-registro"
                                name="fecha"
                                type="date"
                                required
                                :max="hoy ?? undefined"
                                :value="hoy ?? undefined"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.fecha" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="nota-registro">Anotación</Label>
                            <textarea
                                id="nota-registro"
                                name="nota"
                                rows="5"
                                required
                                :class="campoTexto"
                            ></textarea>
                            <InputError :message="errors.nota" />
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

        <!-- Alta y edición de alergia -->
        <Sheet
            :open="sheetAlergia || !!alergiaAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) {
                        sheetAlergia = false;
                        alergiaAEditar = null;
                    }
                }
            "
        >
            <SheetContent>
                <Form
                    v-bind="
                        alergiaAEditar
                            ? AlergiaController.update.form({
                                  alergia: alergiaAEditar.id,
                              })
                            : AlergiaController.store.form({
                                  paciente: paciente.id,
                              })
                    "
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                    @success="
                        () => {
                            sheetAlergia = false;
                            alergiaAEditar = null;
                        }
                    "
                >
                    <SheetHeader>
                        <SheetTitle>
                            {{
                                alergiaAEditar
                                    ? `Editar la alergia a ${alergiaAEditar.sustancia}`
                                    : 'Agregar una alergia'
                            }}
                        </SheetTitle>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="sustancia-alergia">Sustancia</Label>
                            <input
                                id="sustancia-alergia"
                                name="sustancia"
                                type="text"
                                required
                                placeholder="Penicilina, polen, maní…"
                                :value="alergiaAEditar?.sustancia"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.sustancia" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="severidad-alergia">Severidad</Label>
                            <select
                                id="severidad-alergia"
                                name="severidad"
                                :value="alergiaAEditar?.severidad ?? 'moderada'"
                                :class="campoUnaLinea"
                            >
                                <option value="leve">Leve</option>
                                <option value="moderada">Moderada</option>
                                <option value="grave">Grave</option>
                            </select>
                            <InputError :message="errors.severidad" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="reaccion-alergia">Reacción</Label>
                            <input
                                id="reaccion-alergia"
                                name="reaccion"
                                type="text"
                                placeholder="Urticaria, hinchazón…"
                                :value="alergiaAEditar?.reaccion"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.reaccion" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="notas-alergia">Notas</Label>
                            <textarea
                                id="notas-alergia"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                                >{{ alergiaAEditar?.notas }}</textarea>
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

        <!-- Borrar enfermedad -->
        <Dialog
            :open="!!enfermedadABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) enfermedadABorrar = null;
                }
            "
        >
            <DialogContent v-if="enfermedadABorrar">
                <Form
                    v-bind="
                        EnfermedadController.destroy.form({
                            enfermedad: enfermedadABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                    @success="enfermedadABorrar = null"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar {{ enfermedadABorrar.nombre }}?
                        </DialogTitle>
                        <DialogDescription>
                            Se borra con su bitácora. Las mediciones que la
                            siguen no se borran: quedan sin el vínculo.
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

        <!-- Borrar alergia -->
        <Dialog
            :open="!!alergiaABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) alergiaABorrar = null;
                }
            "
        >
            <DialogContent v-if="alergiaABorrar">
                <Form
                    v-bind="
                        AlergiaController.destroy.form({
                            alergia: alergiaABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                    @success="alergiaABorrar = null"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar la alergia a
                            {{ alergiaABorrar.sustancia }}?
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
    </div>
</template>
