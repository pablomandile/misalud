<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    FileText,
    Pill,
    Plus,
    Send,
    Trash2,
    Undo2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AdjuntoController from '@/actions/App/Http/Controllers/AdjuntoController';
import EnvioController from '@/actions/App/Http/Controllers/EnvioController';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
import RecetaMedicaController from '@/actions/App/Http/Controllers/RecetaMedicaController';
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
 * Las recetas de medicamentos de un paciente: el papel que firma el médico
 * para comprar en la farmacia. No es la bandeja de «Recetas» del menú (lo que
 * llega por mail).
 *
 * Contesta lo mismo que la bandeja: **¿qué receta puedo usar hoy?** Las
 * disponibles arriba, de la que vence primero a la última; la separación la
 * hace el servidor, porque "vencida" depende del día.
 *
 * ⚠️ `computed()` para lo que se deriva de props: guardar redirige a esta MISMA
 * URL e Inertia reutiliza el componente (la lección de tratamientos).
 */

type Documento = DocumentoVisible & {
    id: number;
    tamanio: number;
};

type RecetaMedica = {
    id: number;
    medicamento_id: number | null;
    medicamentoNombre: string | null;
    detalle: string | null;
    fecha: string;
    fechaVisible: string;
    vigencia_dias: number;
    usada: boolean;
    venceTexto: string;
    medico_id: number | null;
    medicoNombre: string | null;
    notas: string | null;
    adjuntos: Documento[];
};

type Opcion = { id: number; nombre: string };

const props = defineProps<{
    paciente: { id: number; nombre: string; puedeEditar: boolean };
    disponibles: RecetaMedica[];
    historial: RecetaMedica[];
    medicos: Opcion[];
    medicamentos: Opcion[];
    hoy: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pacientes', href: PacienteController.index() },
            { title: 'Recetas', href: '' },
        ],
    },
});

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

const sheetAlta = ref(false);
const recetaAEditar = ref<RecetaMedica | null>(null);
const recetaABorrar = ref<RecetaMedica | null>(null);
const recetaDeArchivos = ref<RecetaMedica | null>(null);
const documentoAbierto = ref<DocumentoVisible | null>(null);

const grupos = computed(() => [
    { titulo: 'Para usar', lista: props.disponibles },
    { titulo: 'Usadas y vencidas', lista: props.historial },
]);
const hayRecetas = computed(
    () => props.disponibles.length + props.historial.length > 0,
);

/** Cómo se nombra una receta: el medicamento del catálogo, o lo que se escribió. */
function titulo(receta: RecetaMedica): string {
    return receta.medicamentoNombre ?? receta.detalle ?? 'Receta';
}

function pesoLegible(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.round(bytes / 1024)} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
}
</script>

<template>
    <Head :title="`Recetas de ${paciente.nombre}`" />

    <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="`Recetas de ${paciente.nombre}`"
                description="Las recetas de medicamentos que te dio el médico"
            />
            <Button v-if="paciente.puedeEditar" @click="sheetAlta = true">
                <Plus />
                Agregar
            </Button>
        </div>

        <div
            v-if="!hayRecetas"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <Pill class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no cargaste ninguna receta.
            </p>
        </div>

        <template v-for="grupo in grupos" :key="grupo.titulo">
            <div v-if="grupo.lista.length > 0" class="space-y-3">
                <p class="text-sm text-muted-foreground">{{ grupo.titulo }}</p>

                <div class="grid gap-3">
                    <Card v-for="receta in grupo.lista" :key="receta.id">
                        <CardContent class="space-y-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 space-y-1">
                                    <p class="font-medium wrap-break-word">
                                        {{ titulo(receta) }}
                                    </p>
                                    <p
                                        v-if="
                                            receta.medicamentoNombre &&
                                            receta.detalle
                                        "
                                        class="text-sm wrap-break-word"
                                    >
                                        {{ receta.detalle }}
                                    </p>
                                    <!-- Con palabras, sin color de alarma (regla 1). -->
                                    <p class="text-sm text-muted-foreground">
                                        {{ receta.venceTexto }} · del
                                        {{ receta.fechaVisible }}
                                    </p>
                                    <p
                                        v-if="receta.medicoNombre"
                                        class="text-sm text-muted-foreground"
                                    >
                                        {{ receta.medicoNombre }}
                                    </p>
                                    <p
                                        v-if="receta.notas"
                                        class="text-sm whitespace-pre-line"
                                    >
                                        {{ receta.notas }}
                                    </p>
                                </div>

                                <div
                                    v-if="paciente.puedeEditar"
                                    class="flex shrink-0 gap-1"
                                >
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
                                        :aria-label="`Eliminar la receta de ${titulo(receta)}`"
                                        @click="recetaABorrar = receta"
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </div>
                            </div>

                            <!--
                                Marcar usada: lo que se toca en el mostrador.
                                Es su propia acción, no un campo de la edición.
                            -->
                            <Form
                                v-if="paciente.puedeEditar"
                                v-bind="
                                    RecetaMedicaController.uso.form({
                                        recetaMedica: receta.id,
                                    })
                                "
                                :options="{ preserveScroll: true }"
                                v-slot="{ processing }"
                            >
                                <input
                                    type="hidden"
                                    name="usada"
                                    :value="receta.usada ? '0' : '1'"
                                />
                                <Button
                                    type="submit"
                                    variant="outline"
                                    :disabled="processing"
                                >
                                    <Undo2 v-if="receta.usada" />
                                    <Check v-else />
                                    {{
                                        receta.usada
                                            ? 'Deshacer: no la usé'
                                            : 'Ya la usé'
                                    }}
                                </Button>
                            </Form>

                            <!-- El papel: la receta fotografiada o en PDF. -->
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
                                            @click="
                                                documentoAbierto = documento
                                            "
                                        >
                                            <FileText
                                                class="size-5 shrink-0 text-muted-foreground"
                                            />
                                            <span class="min-w-0">
                                                <span
                                                    class="block truncate text-sm"
                                                >
                                                    {{ documento.nombre }}
                                                </span>
                                                <span
                                                    class="block text-sm text-muted-foreground"
                                                >
                                                    {{
                                                        pesoLegible(
                                                            documento.tamanio,
                                                        )
                                                    }}
                                                </span>
                                            </span>
                                        </button>

                                        <!-- A la farmacia: mandarla pide lo mismo que verla. -->
                                        <Button
                                            as-child
                                            variant="ghost"
                                            size="icon-sm"
                                        >
                                            <Link
                                                :href="
                                                    EnvioController.create({
                                                        query: {
                                                            adjuntos: [
                                                                documento.id,
                                                            ],
                                                        },
                                                    })
                                                "
                                                :aria-label="`Enviar ${documento.nombre}`"
                                            >
                                                <Send class="size-4" />
                                            </Link>
                                        </Button>

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
                                            : 'Subir el papel'
                                    }}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </template>

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
            <SheetContent>
                <Form
                    v-bind="
                        recetaAEditar
                            ? RecetaMedicaController.update.form({
                                  recetaMedica: recetaAEditar.id,
                              })
                            : RecetaMedicaController.store.form({
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
                            recetaAEditar = null;
                        }
                    "
                >
                    <SheetHeader>
                        <SheetTitle>
                            {{
                                recetaAEditar
                                    ? 'Editar la receta'
                                    : 'Agregar una receta'
                            }}
                        </SheetTitle>
                        <SheetDescription v-if="!recetaAEditar">
                            Podés subir el papel ahora o más tarde.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div v-if="medicamentos.length > 0" class="grid gap-2">
                            <Label for="medicamento-receta">Medicamento</Label>
                            <select
                                id="medicamento-receta"
                                name="medicamento_id"
                                :value="recetaAEditar?.medicamento_id ?? ''"
                                :class="campoUnaLinea"
                            >
                                <option
                                    value=""
                                    class="bg-background text-foreground"
                                >
                                    No está en la lista
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
                            <InputError :message="errors.medicamento_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="detalle-receta">
                                Qué te recetaron
                            </Label>
                            <textarea
                                id="detalle-receta"
                                name="detalle"
                                rows="2"
                                placeholder="Por ejemplo: Enalapril 10 mg, 2 cajas"
                                :class="campoTexto"
                                >{{ recetaAEditar?.detalle }}</textarea>
                            <InputError :message="errors.detalle" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="fecha-receta">Fecha de la receta</Label>
                            <input
                                id="fecha-receta"
                                name="fecha"
                                type="date"
                                required
                                :max="hoy"
                                :value="recetaAEditar?.fecha ?? hoy"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.fecha" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="vigencia-receta"> Días que vale </Label>
                            <input
                                id="vigencia-receta"
                                name="vigencia_dias"
                                type="text"
                                inputmode="numeric"
                                required
                                aria-describedby="vigencia-receta-ayuda"
                                :value="recetaAEditar?.vigencia_dias ?? 30"
                                :class="campoUnaLinea"
                            />
                            <p
                                id="vigencia-receta-ayuda"
                                class="text-sm text-muted-foreground"
                            >
                                Casi siempre 30. La de un tratamiento prolongado
                                puede valer más.
                            </p>
                            <InputError :message="errors.vigencia_dias" />
                        </div>

                        <div v-if="medicos.length > 0" class="grid gap-2">
                            <Label for="medico-receta">Médico</Label>
                            <select
                                id="medico-receta"
                                name="medico_id"
                                :value="recetaAEditar?.medico_id ?? ''"
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

                        <!--
                            Solo en el alta: la edición va por PUT, y PHP no
                            parsea un cuerpo multipart de un PUT.
                        -->
                        <SubirArchivo
                            v-if="!recetaAEditar"
                            name="archivos[]"
                            multiple
                            etiqueta="Foto o PDF de la receta (opcional)"
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

        <!-- Subir el papel después -->
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
                        AdjuntoController.storeParaRecetaMedica.form({
                            recetaMedica: recetaDeArchivos.id,
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
                            {{ titulo(recetaDeArchivos) }}
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <input type="hidden" name="tipo" value="receta" />
                        <SubirArchivo
                            name="archivos[]"
                            multiple
                            etiqueta="Foto o PDF de la receta"
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
                        RecetaMedicaController.destroy.form({
                            recetaMedica: recetaABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                    @success="recetaABorrar = null"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar la receta de {{ titulo(recetaABorrar) }}?
                        </DialogTitle>
                        <DialogDescription>
                            Se borra con su papel.
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
