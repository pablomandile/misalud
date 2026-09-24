<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ArrowLeft, ClipboardList, FileText, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import AdjuntoController from '@/actions/App/Http/Controllers/AdjuntoController';
import OrdenEstudioController from '@/actions/App/Http/Controllers/OrdenEstudioController';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
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
 * Las órdenes de estudio de un paciente.
 *
 * La pantalla contesta una sola pregunta: **qué me falta hacerme**. Por eso
 * lo pendiente va arriba y separado, y por eso la orden lleva su archivo
 * -el papel fotografiado es lo que hay que mostrar en el mostrador del
 * laboratorio-.
 *
 * ⚠️ `computed()` y no un `const` calculado una vez: cargar una orden
 * redirige a esta MISMA URL e Inertia reutiliza la instancia del
 * componente, así que un `const` quedaría congelado con los props de la
 * primera carga y la orden recién creada no aparecería hasta un refresh
 * -el bug que apareció en tratamientos, ver CLAUDE.md-.
 */

type Documento = DocumentoVisible & {
    id: number;
    tamanio: number;
};

type Orden = {
    id: number;
    estudio_solicitado: string;
    fecha: string;
    fechaVisible: string;
    estado: string;
    estadoEtiqueta: string;
    estaPendiente: boolean;
    medico_id: number | null;
    medicoNombre: string | null;
    notas: string | null;
    adjuntos: Documento[];
};

const props = defineProps<{
    paciente: { id: number; nombre: string; puedeEditar: boolean };
    ordenes: Orden[];
    medicos: Array<{ id: number; nombre: string }>;
    hoy: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pacientes', href: PacienteController.index() },
            { title: 'Órdenes de estudio', href: '' },
        ],
    },
});

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

const sheetAlta = ref(false);
const ordenAEditar = ref<Orden | null>(null);
const ordenABorrar = ref<Orden | null>(null);
const ordenDeArchivos = ref<Orden | null>(null);
const documentoAbierto = ref<DocumentoVisible | null>(null);

const pendientes = computed(() => props.ordenes.filter((o) => o.estaPendiente));
const resto = computed(() => props.ordenes.filter((o) => !o.estaPendiente));

function pesoLegible(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.round(bytes / 1024)} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
}
</script>

<template>
    <Head :title="`Órdenes de estudio de ${paciente.nombre}`" />

    <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="`Órdenes de ${paciente.nombre}`"
                description="El papel que da el médico antes de un estudio"
            />
            <Button v-if="paciente.puedeEditar" @click="sheetAlta = true">
                <Plus />
                Agregar
            </Button>
        </div>

        <div
            v-if="ordenes.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <ClipboardList class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no cargaste ninguna orden.
            </p>
        </div>

        <template
            v-for="grupo in [
                { titulo: 'Pendientes de hacer', lista: pendientes },
                { titulo: 'Hechas y anuladas', lista: resto },
            ]"
            :key="grupo.titulo"
        >
            <div v-if="grupo.lista.length > 0" class="space-y-3">
                <p class="text-sm text-muted-foreground">{{ grupo.titulo }}</p>

                <div class="grid gap-3">
                    <Card v-for="orden in grupo.lista" :key="orden.id">
                        <CardContent class="space-y-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 space-y-1">
                                    <p class="font-medium">
                                        {{ orden.estudio_solicitado }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ orden.estadoEtiqueta }} · del
                                        {{ orden.fechaVisible }}
                                    </p>
                                    <p
                                        v-if="orden.medicoNombre"
                                        class="text-sm text-muted-foreground"
                                    >
                                        {{ orden.medicoNombre }}
                                    </p>
                                    <p v-if="orden.notas" class="text-sm">
                                        {{ orden.notas }}
                                    </p>
                                </div>

                                <div
                                    v-if="paciente.puedeEditar"
                                    class="flex shrink-0 gap-1"
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="ordenAEditar = orden"
                                    >
                                        Editar
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                        :aria-label="`Eliminar la orden de ${orden.estudio_solicitado}`"
                                        @click="ordenABorrar = orden"
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </div>
                            </div>

                            <!-- El papel: la orden fotografiada o en PDF. -->
                            <div class="space-y-2 border-t pt-3">
                                <ul
                                    v-if="orden.adjuntos.length > 0"
                                    class="grid gap-2"
                                >
                                    <li
                                        v-for="documento in orden.adjuntos"
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
                                    @click="ordenDeArchivos = orden"
                                >
                                    <FileText />
                                    {{
                                        orden.adjuntos.length > 0
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
            :open="sheetAlta || !!ordenAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) {
                        sheetAlta = false;
                        ordenAEditar = null;
                    }
                }
            "
        >
            <SheetContent>
                <Form
                    v-bind="
                        ordenAEditar
                            ? OrdenEstudioController.update.form({
                                  orden: ordenAEditar.id,
                              })
                            : OrdenEstudioController.store.form({
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
                            ordenAEditar = null;
                        }
                    "
                >
                    <SheetHeader>
                        <SheetTitle>
                            {{
                                ordenAEditar
                                    ? 'Editar la orden'
                                    : 'Agregar una orden'
                            }}
                        </SheetTitle>
                        <SheetDescription v-if="!ordenAEditar">
                            El papel se sube después de guardarla.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="estudio-orden">Qué pidió</Label>
                            <input
                                id="estudio-orden"
                                name="estudio_solicitado"
                                type="text"
                                required
                                placeholder="Análisis de sangre completo"
                                :value="ordenAEditar?.estudio_solicitado"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.estudio_solicitado" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="fecha-orden">Fecha de la orden</Label>
                            <input
                                id="fecha-orden"
                                name="fecha"
                                type="date"
                                required
                                :max="hoy ?? undefined"
                                :value="ordenAEditar?.fecha ?? hoy"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.fecha" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="estado-orden">Estado</Label>
                            <select
                                id="estado-orden"
                                name="estado"
                                :value="ordenAEditar?.estado ?? 'pendiente'"
                                :class="campoUnaLinea"
                            >
                                <option value="pendiente">
                                    Pendiente de hacer
                                </option>
                                <option value="hecha">Ya me la hice</option>
                                <option value="anulada">
                                    Anulada (ya no hace falta)
                                </option>
                            </select>
                            <InputError :message="errors.estado" />
                        </div>

                        <div v-if="medicos.length > 0" class="grid gap-2">
                            <Label for="medico-orden">Médico</Label>
                            <select
                                id="medico-orden"
                                name="medico_id"
                                :value="ordenAEditar?.medico_id ?? ''"
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
                            <Label for="notas-orden">Notas</Label>
                            <textarea
                                id="notas-orden"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                                >{{ ordenAEditar?.notas }}</textarea>
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

        <!-- Subir el papel -->
        <Sheet
            :open="!!ordenDeArchivos"
            @update:open="
                (v: boolean) => {
                    if (!v) ordenDeArchivos = null;
                }
            "
        >
            <SheetContent v-if="ordenDeArchivos">
                <Form
                    v-bind="
                        AdjuntoController.storeParaOrden.form({
                            orden: ordenDeArchivos.id,
                        })
                    "
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                    @success="ordenDeArchivos = null"
                >
                    <SheetHeader>
                        <SheetTitle>El papel de la orden</SheetTitle>
                        <SheetDescription>
                            {{ ordenDeArchivos.estudio_solicitado }}
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <!-- Siempre una orden: no hace falta elegir tipo acá. -->
                        <input
                            type="hidden"
                            name="tipo"
                            value="orden_estudio"
                        />
                        <SubirArchivo
                            name="archivos[]"
                            multiple
                            etiqueta="Foto o PDF de la orden"
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
            :open="!!ordenABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) ordenABorrar = null;
                }
            "
        >
            <DialogContent v-if="ordenABorrar">
                <Form
                    v-bind="
                        OrdenEstudioController.destroy.form({
                            orden: ordenABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                    @success="ordenABorrar = null"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar la orden de
                            {{ ordenABorrar.estudio_solicitado }}?
                        </DialogTitle>
                        <DialogDescription>
                            Se borra con su papel. Esto no se puede deshacer.
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
