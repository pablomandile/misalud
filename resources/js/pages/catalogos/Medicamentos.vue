<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Copy, FileText, Pill, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import AdjuntoController from '@/actions/App/Http/Controllers/AdjuntoController';
import MedicamentoController from '@/actions/App/Http/Controllers/MedicamentoController';
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
 * El catálogo de medicamentos, con su prospecto en PDF. Copiado de
 * Medicos.vue -es el patrón, ver ese archivo- y le suma una sola cosa: el
 * prospecto es un adjunto colgado del medicamento, así que necesita su
 * propio SubirArchivo y el VisorDocumento único de la pantalla (mismo
 * patrón que pacientes/Index.vue: un solo visor, fuera de cualquier
 * v-for, y la fila que se está mirando en un ref).
 */

type Prospecto = {
    id: number;
    nombre: string;
    mime: string;
    tamanio: number;
    url: string;
};

type Medicamento = {
    id: number;
    nombre_comercial: string;
    droga: string | null;
    para_que_sirve: string | null;
    notas: string | null;
    esSemilla: boolean;
    prospecto: Prospecto | null;
};

defineProps<{ registros: Medicamento[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Medicamentos', href: MedicamentoController.index() },
        ],
    },
});

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

function pesoLegible(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.round(bytes / 1024)} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
}

const sheetCrearAbierto = ref(false);
const medicamentoAEditar = ref<Medicamento | null>(null);
const medicamentoABorrar = ref<Medicamento | null>(null);
const documentoAbierto = ref<DocumentoVisible | null>(null);
</script>

<template>
    <Head title="Medicamentos" />

    <div class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <Heading
                variant="small"
                title="Medicamentos"
                description="Tu catálogo de medicamentos, con el prospecto a mano"
            />
            <Button @click="sheetCrearAbierto = true">
                <Plus />
                Agregar
            </Button>
        </div>

        <div
            v-if="registros.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <Pill class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no agregaste ningún medicamento.
            </p>
        </div>

        <div v-else class="grid gap-3 sm:grid-cols-2">
            <Card v-for="medicamento in registros" :key="medicamento.id">
                <CardContent class="space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 space-y-1">
                            <p class="truncate font-medium">
                                {{ medicamento.nombre_comercial }}
                            </p>
                            <p
                                v-if="medicamento.droga"
                                class="text-sm text-muted-foreground"
                            >
                                {{ medicamento.droga }}
                            </p>
                            <p
                                v-if="medicamento.para_que_sirve"
                                class="text-sm text-muted-foreground"
                            >
                                {{ medicamento.para_que_sirve }}
                            </p>
                            <p
                                v-if="medicamento.esSemilla"
                                class="text-sm text-muted-foreground"
                            >
                                De la lista compartida
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-wrap justify-end gap-1">
                            <Form
                                v-if="medicamento.esSemilla"
                                v-bind="
                                    MedicamentoController.duplicar.form({
                                        medicamento: medicamento.id,
                                    })
                                "
                                :options="{ preserveScroll: true }"
                                v-slot="{ processing }"
                            >
                                <Button
                                    type="submit"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="processing"
                                >
                                    <Copy />
                                    Copiar
                                </Button>
                            </Form>

                            <template v-else>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="medicamentoAEditar = medicamento"
                                >
                                    Editar
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    :aria-label="`Eliminar ${medicamento.nombre_comercial}`"
                                    @click="medicamentoABorrar = medicamento"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </template>
                        </div>
                    </div>

                    <!-- Prospecto -->
                    <div class="space-y-2 border-t pt-3">
                        <div
                            v-if="medicamento.prospecto"
                            class="flex items-center gap-2 rounded-md border p-2"
                        >
                            <button
                                type="button"
                                class="flex min-h-11 min-w-0 flex-1 items-center gap-3 text-left"
                                @click="
                                    documentoAbierto = medicamento.prospecto
                                "
                            >
                                <FileText
                                    class="size-5 shrink-0 text-muted-foreground"
                                />
                                <span class="min-w-0">
                                    <span class="block truncate text-sm">
                                        {{ medicamento.prospecto.nombre }}
                                    </span>
                                    <span
                                        class="block text-sm text-muted-foreground"
                                    >
                                        {{
                                            pesoLegible(
                                                medicamento.prospecto.tamanio,
                                            )
                                        }}
                                    </span>
                                </span>
                            </button>

                            <Form
                                v-if="!medicamento.esSemilla"
                                v-bind="
                                    AdjuntoController.destroy.form({
                                        adjunto: medicamento.prospecto.id,
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
                                    aria-label="Eliminar el prospecto"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </Form>
                        </div>

                        <Form
                            v-else-if="!medicamento.esSemilla"
                            v-bind="
                                AdjuntoController.storeParaMedicamento.form({
                                    medicamento: medicamento.id,
                                })
                            "
                            reset-on-success
                            :options="{ preserveScroll: true }"
                            v-slot="{ errors, processing }"
                        >
                            <input
                                type="hidden"
                                name="tipo"
                                value="prospecto"
                            />
                            <SubirArchivo
                                name="archivos[]"
                                etiqueta="Subir el prospecto"
                                :error="errors['archivos.0'] ?? errors.archivos"
                            />
                            <Button
                                type="submit"
                                variant="secondary"
                                size="sm"
                                class="mt-2 w-full"
                                :disabled="processing"
                            >
                                <FileText />
                                Subir prospecto
                            </Button>
                        </Form>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Crear -->
        <Sheet v-model:open="sheetCrearAbierto">
            <SheetContent>
                <Form
                    v-bind="MedicamentoController.store.form()"
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    @success="sheetCrearAbierto = false"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>Agregar un medicamento</SheetTitle>
                        <SheetDescription>
                            Queda en tu catálogo, para cualquiera de tus
                            pacientes. El prospecto se sube después de
                            guardarlo.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="nombre_comercial-crear">
                                Nombre comercial
                            </Label>
                            <input
                                id="nombre_comercial-crear"
                                name="nombre_comercial"
                                type="text"
                                required
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.nombre_comercial" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="droga-crear">Droga</Label>
                            <input
                                id="droga-crear"
                                name="droga"
                                type="text"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.droga" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="para_que_sirve-crear">
                                Para qué sirve
                            </Label>
                            <input
                                id="para_que_sirve-crear"
                                name="para_que_sirve"
                                type="text"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.para_que_sirve" />
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
            :open="!!medicamentoAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) medicamentoAEditar = null;
                }
            "
        >
            <SheetContent v-if="medicamentoAEditar">
                <Form
                    v-bind="
                        MedicamentoController.update.form({
                            medicamento: medicamentoAEditar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="medicamentoAEditar = null"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>
                            Editar {{ medicamentoAEditar.nombre_comercial }}
                        </SheetTitle>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="nombre_comercial-editar">
                                Nombre comercial
                            </Label>
                            <input
                                id="nombre_comercial-editar"
                                name="nombre_comercial"
                                type="text"
                                required
                                :value="medicamentoAEditar.nombre_comercial"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.nombre_comercial" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="droga-editar">Droga</Label>
                            <input
                                id="droga-editar"
                                name="droga"
                                type="text"
                                :value="medicamentoAEditar.droga"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.droga" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="para_que_sirve-editar">
                                Para qué sirve
                            </Label>
                            <input
                                id="para_que_sirve-editar"
                                name="para_que_sirve"
                                type="text"
                                :value="medicamentoAEditar.para_que_sirve"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.para_que_sirve" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="notas-editar">Notas</Label>
                            <textarea
                                id="notas-editar"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                                >{{ medicamentoAEditar.notas }}</textarea>
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
            :open="!!medicamentoABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) medicamentoABorrar = null;
                }
            "
        >
            <DialogContent v-if="medicamentoABorrar">
                <Form
                    v-bind="
                        MedicamentoController.destroy.form({
                            medicamento: medicamentoABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="medicamentoABorrar = null"
                    v-slot="{ processing }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar
                            {{ medicamentoABorrar.nombre_comercial }}?
                        </DialogTitle>
                        <DialogDescription>
                            Se saca de tu catálogo, con su prospecto. Esto no se
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

        <!--
            Único visor para toda la pantalla, fuera del v-for de tarjetas
            (ver CLAUDE.md, sección Visor de documentos).
        -->
        <VisorDocumento
            :documento="documentoAbierto"
            @cerrar="documentoAbierto = null"
        />
    </div>
</template>
