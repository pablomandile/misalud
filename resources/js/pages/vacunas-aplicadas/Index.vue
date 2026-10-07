<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileText, Plus, Send, Syringe, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import AdjuntoController from '@/actions/App/Http/Controllers/AdjuntoController';
import AplicacionVacunaController from '@/actions/App/Http/Controllers/AplicacionVacunaController';
import EnvioController from '@/actions/App/Http/Controllers/EnvioController';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
import VacunaController from '@/actions/App/Http/Controllers/VacunaController';
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
 * El carnet de vacunación de un paciente, agrupado por vacuna: la pregunta
 * que se le hace a un carnet es "¿cuántas dosis de esta tengo y cuándo toca
 * la próxima?".
 *
 * Los grupos los arma el servidor (no hay nada que filtrar acá), así que no
 * hay ningún `const` que se pueda quedar congelado después de un redirect a
 * esta misma URL —la lección de la Etapa 8—. Los sheets guardan una copia del
 * registro, pero se cierran al guardar: nunca muestran props viejas.
 */

type Documento = DocumentoVisible & {
    id: number;
    tamanio: number;
};

type Dosis = {
    id: number;
    vacuna_id: number;
    centro_id: number | null;
    centroNombre: string | null;
    fecha: string;
    fechaVisible: string;
    proxima_dosis: string | null;
    dosis: string | null;
    lote: string | null;
    notas: string | null;
    adjuntos: Documento[];
};

type GrupoVacuna = {
    vacunaId: number;
    nombre: string;
    proximaDosis: string | null;
    proximaDosisVisible: string | null;
    dosis: Dosis[];
};

type Opcion = { id: number; nombre: string };

defineProps<{
    paciente: { id: number; nombre: string; puedeEditar: boolean };
    vacunas: GrupoVacuna[];
    catalogoVacunas: Opcion[];
    centros: Opcion[];
    hoy: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pacientes', href: PacienteController.index() },
            { title: 'Vacunas', href: '' },
        ],
    },
});

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

/*
 * El alta guarda QUÉ vacuna viene elegida: "Anotar otra dosis" desde un grupo
 * abre el formulario con esa vacuna puesta, que es el caso más común (la
 * segunda dosis de algo que ya está en el carnet).
 */
const altaAbierta = ref(false);
const vacunaPreelegida = ref<number | null>(null);
const dosisAEditar = ref<Dosis | null>(null);
const dosisABorrar = ref<{ dosis: Dosis; vacuna: string } | null>(null);
const dosisDeArchivos = ref<{ dosis: Dosis; vacuna: string } | null>(null);
const documentoAbierto = ref<DocumentoVisible | null>(null);

function abrirAlta(vacunaId: number | null): void {
    vacunaPreelegida.value = vacunaId;
    altaAbierta.value = true;
}

function cerrarFormulario(): void {
    altaAbierta.value = false;
    dosisAEditar.value = null;
}

function pesoLegible(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.round(bytes / 1024)} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
}
</script>

<template>
    <Head :title="`Vacunas de ${paciente.nombre}`" />

    <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="`Vacunas de ${paciente.nombre}`"
                description="Las dosis aplicadas y cuándo toca la próxima"
            />
            <Button
                v-if="paciente.puedeEditar && catalogoVacunas.length > 0"
                @click="abrirAlta(null)"
            >
                <Plus />
                Anotar
            </Button>
        </div>

        <!--
            Sin vacunas en el catálogo no hay qué elegir: se dice, con el camino
            para resolverlo, en vez de abrir un formulario con el desplegable vacío.
        -->
        <p
            v-if="paciente.puedeEditar && catalogoVacunas.length === 0"
            class="text-sm text-muted-foreground"
        >
            Para anotar una dosis, primero agregá la vacuna en
            <Link :href="VacunaController.index()" class="underline"
                >Vacunas</Link
            >.
        </p>

        <div
            v-if="vacunas.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <Syringe class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no anotaste ninguna dosis.
            </p>
        </div>

        <Card v-for="grupo in vacunas" :key="grupo.vacunaId">
            <CardContent class="space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium">{{ grupo.nombre }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{
                                grupo.dosis.length === 1
                                    ? '1 dosis'
                                    : `${grupo.dosis.length} dosis`
                            }}<template v-if="grupo.proximaDosisVisible">
                                · próxima el
                                {{ grupo.proximaDosisVisible }}</template
                            >
                        </p>
                    </div>
                    <Button
                        v-if="paciente.puedeEditar"
                        variant="outline"
                        size="sm"
                        class="shrink-0"
                        @click="abrirAlta(grupo.vacunaId)"
                    >
                        <Plus />
                        Otra dosis
                    </Button>
                </div>

                <ul class="grid gap-3">
                    <li
                        v-for="dosis in grupo.dosis"
                        :key="dosis.id"
                        class="space-y-2 border-t pt-3"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 space-y-1">
                                <p class="text-sm font-medium">
                                    {{ dosis.fechaVisible
                                    }}<template v-if="dosis.dosis">
                                        · {{ dosis.dosis }}</template
                                    >
                                </p>
                                <p
                                    v-if="dosis.centroNombre || dosis.lote"
                                    class="text-sm text-muted-foreground"
                                >
                                    {{
                                        [
                                            dosis.centroNombre,
                                            dosis.lote
                                                ? `lote ${dosis.lote}`
                                                : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')
                                    }}
                                </p>
                                <p v-if="dosis.notas" class="text-sm">
                                    {{ dosis.notas }}
                                </p>
                            </div>

                            <div
                                v-if="paciente.puedeEditar"
                                class="flex shrink-0 gap-1"
                            >
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="dosisAEditar = dosis"
                                >
                                    Editar
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    :aria-label="`Eliminar la dosis de ${grupo.nombre} del ${dosis.fechaVisible}`"
                                    @click="
                                        dosisABorrar = {
                                            dosis,
                                            vacuna: grupo.nombre,
                                        }
                                    "
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </div>

                        <!-- El comprobante, o la foto del carnet de papel. -->
                        <ul v-if="dosis.adjuntos.length > 0" class="grid gap-2">
                            <li
                                v-for="documento in dosis.adjuntos"
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

                                <!-- Mandarlo pide lo mismo que verlo (ver CLAUDE.md, Etapa 14). -->
                                <Button as-child variant="ghost" size="icon-sm">
                                    <Link
                                        :href="
                                            EnvioController.create({
                                                query: {
                                                    adjuntos: [documento.id],
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
                            @click="
                                dosisDeArchivos = {
                                    dosis,
                                    vacuna: grupo.nombre,
                                }
                            "
                        >
                            <FileText />
                            {{
                                dosis.adjuntos.length > 0
                                    ? 'Agregar otra foto'
                                    : 'Subir el comprobante'
                            }}
                        </Button>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <div>
            <Button variant="outline" as-child>
                <a :href="PacienteController.index().url">
                    <ArrowLeft />
                    Volver a pacientes
                </a>
            </Button>
        </div>

        <!-- Anotar y editar -->
        <Sheet
            :open="altaAbierta || !!dosisAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) cerrarFormulario();
                }
            "
        >
            <SheetContent>
                <Form
                    v-bind="
                        dosisAEditar
                            ? AplicacionVacunaController.update.form({
                                  aplicacion: dosisAEditar.id,
                              })
                            : AplicacionVacunaController.store.form({
                                  paciente: paciente.id,
                              })
                    "
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                    @success="cerrarFormulario"
                >
                    <SheetHeader>
                        <SheetTitle>
                            {{
                                dosisAEditar
                                    ? 'Editar la dosis'
                                    : 'Anotar una dosis'
                            }}
                        </SheetTitle>
                        <SheetDescription v-if="!dosisAEditar">
                            El comprobante se sube después de guardarla.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="vacuna-dosis">Vacuna</Label>
                            <select
                                id="vacuna-dosis"
                                name="vacuna_id"
                                required
                                :value="
                                    dosisAEditar?.vacuna_id ??
                                    vacunaPreelegida ??
                                    ''
                                "
                                :class="campoUnaLinea"
                            >
                                <option value="" disabled>Elegí una</option>
                                <option
                                    v-for="vacuna in catalogoVacunas"
                                    :key="vacuna.id"
                                    :value="vacuna.id"
                                >
                                    {{ vacuna.nombre }}
                                </option>
                            </select>
                            <InputError :message="errors.vacuna_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="fecha-dosis"
                                >Fecha en que se aplicó</Label
                            >
                            <input
                                id="fecha-dosis"
                                name="fecha"
                                type="date"
                                required
                                :max="hoy ?? undefined"
                                :value="dosisAEditar?.fecha ?? hoy"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.fecha" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="numero-dosis">Qué dosis fue</Label>
                            <input
                                id="numero-dosis"
                                name="dosis"
                                type="text"
                                placeholder="1ª dosis, refuerzo, anual…"
                                :value="dosisAEditar?.dosis"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.dosis" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="proxima-dosis">Próxima dosis</Label>
                            <input
                                id="proxima-dosis"
                                name="proxima_dosis"
                                type="date"
                                aria-describedby="proxima-dosis-ayuda"
                                :value="dosisAEditar?.proxima_dosis"
                                :class="campoUnaLinea"
                            />
                            <p
                                id="proxima-dosis-ayuda"
                                class="text-sm text-muted-foreground"
                            >
                                Si la anotás, te avisamos el día anterior.
                            </p>
                            <InputError :message="errors.proxima_dosis" />
                        </div>

                        <div v-if="centros.length > 0" class="grid gap-2">
                            <Label for="centro-dosis">Dónde</Label>
                            <select
                                id="centro-dosis"
                                name="centro_id"
                                :value="dosisAEditar?.centro_id ?? ''"
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
                            <Label for="lote-dosis">Lote</Label>
                            <input
                                id="lote-dosis"
                                name="lote"
                                type="text"
                                :value="dosisAEditar?.lote"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.lote" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="notas-dosis">Notas</Label>
                            <textarea
                                id="notas-dosis"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                                >{{ dosisAEditar?.notas }}</textarea>
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

        <!-- Subir el comprobante -->
        <Sheet
            :open="!!dosisDeArchivos"
            @update:open="
                (v: boolean) => {
                    if (!v) dosisDeArchivos = null;
                }
            "
        >
            <SheetContent v-if="dosisDeArchivos">
                <Form
                    v-bind="
                        AdjuntoController.storeParaAplicacionVacuna.form({
                            aplicacion: dosisDeArchivos.dosis.id,
                        })
                    "
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                    @success="dosisDeArchivos = null"
                >
                    <SheetHeader>
                        <SheetTitle>El comprobante</SheetTitle>
                        <SheetDescription>
                            {{ dosisDeArchivos.vacuna }} · del
                            {{ dosisDeArchivos.dosis.fechaVisible }}
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <input type="hidden" name="tipo" value="vacuna" />
                        <SubirArchivo
                            name="archivos[]"
                            multiple
                            etiqueta="Foto o PDF del comprobante"
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
            :open="!!dosisABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) dosisABorrar = null;
                }
            "
        >
            <DialogContent v-if="dosisABorrar">
                <Form
                    v-bind="
                        AplicacionVacunaController.destroy.form({
                            aplicacion: dosisABorrar.dosis.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                    @success="dosisABorrar = null"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar la dosis de {{ dosisABorrar.vacuna }} del
                            {{ dosisABorrar.dosis.fechaVisible }}?
                        </DialogTitle>
                        <DialogDescription>
                            Se borra con su comprobante.
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
