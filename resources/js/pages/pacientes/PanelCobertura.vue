<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { CreditCard, FileText, Pencil, Trash2, X } from '@lucide/vue';
import { ref } from 'vue';
import AdjuntoController from '@/actions/App/Http/Controllers/AdjuntoController';
import CoberturaController from '@/actions/App/Http/Controllers/CoberturaController';
import InputError from '@/components/InputError.vue';
import SubirArchivo from '@/components/SubirArchivo.vue';
import type { DocumentoVisible } from '@/components/VisorDocumento.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { olvidarCredencial } from '@/lib/cacheCredencial';
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
import type { CoberturaMedica, Paciente } from '@/pages/pacientes/Index.vue';

/*
 * Panel de cobertura médica: obra social, prepaga, mutual o PAMI.
 *
 * Vive en un componente propio y no adentro de pacientes/Index.vue -que ya
 * tiene el alta, la edición y los documentos del paciente- porque acá hay
 * TRES formularios distintos conviviendo (alta de cobertura, edición de una
 * puntual, subida de credencial), y cada paciente puede tener varias
 * coberturas a la vez.
 *
 * Es este componente el que abre y cierra el Sheet -no el padre-, con el
 * mismo patrón que usa VisorDocumento.vue para su Dialog: la pantalla que lo
 * monta solo decide QUÉ paciente mostrar (`:paciente="..."` o `null`).
 */

defineProps<{ paciente: Paciente | null }>();

const emit = defineEmits<{
    cerrar: [];
    /*
     * El visor de documentos es ÚNICO para toda la pantalla de pacientes
     * (ver el comentario de `documentoAbierto` en Index.vue): este panel no
     * monta el suyo, le avisa al padre qué abrir.
     */
    verDocumento: [documento: DocumentoVisible];
}>();

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm min-h-11';
const campoTexto =
    'flex w-full min-h-24 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

const agregando = ref(false);
const coberturaEditando = ref<number | null>(null);
const coberturaABorrar = ref<CoberturaMedica | null>(null);

function pesoLegible(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.round(bytes / 1024)} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
}

function alCerrarSheet(abierto: boolean): void {
    if (!abierto) {
        agregando.value = false;
        coberturaEditando.value = null;
        emit('cerrar');
    }
}

/**
 * Borrar la cobertura entera se lleva su credencial en cascada del lado del
 * servidor, pero eso no libera lo que el service worker haya guardado: hay
 * que avisarle por cada adjunto, uno por uno.
 */
function alBorrarCobertura(): void {
    coberturaABorrar.value?.adjuntos.forEach((documento) =>
        olvidarCredencial(documento.url),
    );
    coberturaABorrar.value = null;
}
</script>

<template>
    <Sheet :open="!!paciente" @update:open="alCerrarSheet">
        <SheetContent v-if="paciente">
            <SheetHeader>
                <SheetTitle>
                    Cobertura médica de {{ paciente.nombre }}
                </SheetTitle>
                <SheetDescription>
                    Obra social, prepaga o mutual. Podés cargar más de una.
                </SheetDescription>
            </SheetHeader>

            <div class="flex-1 space-y-4 overflow-y-auto px-4">
                <p
                    v-if="paciente.coberturas.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Todavía no cargaste ninguna cobertura.
                </p>

                <div
                    v-for="cobertura in paciente.coberturas"
                    :key="cobertura.id"
                    class="rounded-lg border p-3"
                >
                    <!-- Vista -->
                    <template v-if="coberturaEditando !== cobertura.id">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 space-y-1">
                                <p class="flex items-center gap-2 font-medium">
                                    <span class="truncate">{{
                                        cobertura.entidad
                                    }}</span>
                                    <span
                                        v-if="!cobertura.activa"
                                        class="shrink-0 rounded-full bg-muted px-2 py-0.5 text-sm text-muted-foreground"
                                    >
                                        Inactiva
                                    </span>
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    {{ cobertura.tipoEtiqueta
                                    }}<span v-if="cobertura.plan">
                                        · Plan {{ cobertura.plan }}</span
                                    >
                                </p>
                                <p
                                    v-if="cobertura.nro_afiliado"
                                    class="text-sm text-muted-foreground"
                                >
                                    Afiliado {{ cobertura.nro_afiliado }}
                                </p>
                                <p
                                    v-if="cobertura.telefono"
                                    class="text-sm text-muted-foreground"
                                >
                                    Tel. {{ cobertura.telefono }}
                                </p>
                                <p
                                    v-if="cobertura.telefono_urgencias"
                                    class="text-sm text-muted-foreground"
                                >
                                    Urgencias
                                    {{ cobertura.telefono_urgencias }}
                                </p>
                            </div>

                            <div
                                v-if="paciente.puedeEditar"
                                class="flex shrink-0 gap-1"
                            >
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="`Editar ${cobertura.entidad}`"
                                    @click="coberturaEditando = cobertura.id"
                                >
                                    <Pencil class="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    :aria-label="`Eliminar ${cobertura.entidad}`"
                                    @click="coberturaABorrar = cobertura"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </div>

                        <!-- Credencial: adjuntos tipo `credencial`, frente y dorso. -->
                        <div class="mt-3 space-y-2 border-t pt-3">
                            <ul
                                v-if="cobertura.adjuntos.length > 0"
                                class="grid gap-2"
                            >
                                <li
                                    v-for="documento in cobertura.adjuntos"
                                    :key="documento.id"
                                    class="flex items-center gap-2 rounded-md border p-2"
                                >
                                    <button
                                        type="button"
                                        class="flex min-h-11 min-w-0 flex-1 items-center gap-3 text-left"
                                        @click="emit('verDocumento', documento)"
                                    >
                                        <CreditCard
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
                                        @success="
                                            olvidarCredencial(documento.url)
                                        "
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

                            <Form
                                v-if="paciente.puedeEditar"
                                v-bind="
                                    AdjuntoController.storeParaCobertura.form({
                                        cobertura: cobertura.id,
                                    })
                                "
                                reset-on-success
                                :options="{ preserveScroll: true }"
                                v-slot="{ errors, processing }"
                            >
                                <!-- Siempre credencial: no hace falta elegir tipo acá. -->
                                <input
                                    type="hidden"
                                    name="tipo"
                                    value="credencial"
                                />
                                <SubirArchivo
                                    name="archivos[]"
                                    multiple
                                    etiqueta="Foto de la credencial (frente y dorso)"
                                    :error="
                                        errors['archivos.0'] ?? errors.archivos
                                    "
                                />
                                <Button
                                    type="submit"
                                    variant="secondary"
                                    size="sm"
                                    class="mt-2 w-full"
                                    :disabled="processing"
                                >
                                    <FileText />
                                    Subir credencial
                                </Button>
                            </Form>
                        </div>
                    </template>

                    <!-- Edición -->
                    <Form
                        v-else
                        v-bind="
                            CoberturaController.update.form({
                                cobertura: cobertura.id,
                            })
                        "
                        :options="{ preserveScroll: true }"
                        @success="coberturaEditando = null"
                        v-slot="{ errors, processing }"
                        class="space-y-3"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-medium">
                                Editar {{ cobertura.entidad }}
                            </p>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label="Cancelar edición"
                                @click="coberturaEditando = null"
                            >
                                <X class="size-4" />
                            </Button>
                        </div>

                        <div class="grid gap-2">
                            <Label :for="`tipo-editar-${cobertura.id}`"
                                >Tipo</Label
                            >
                            <select
                                :id="`tipo-editar-${cobertura.id}`"
                                name="tipo"
                                :value="cobertura.tipo"
                                :class="campoBase"
                            >
                                <option value="obra_social">Obra social</option>
                                <option value="prepaga">Prepaga</option>
                                <option value="mutual">Mutual</option>
                                <option value="pami">PAMI</option>
                            </select>
                            <InputError :message="errors.tipo" />
                        </div>

                        <div class="grid gap-2">
                            <Label :for="`entidad-editar-${cobertura.id}`">
                                Obra social o prepaga
                            </Label>
                            <input
                                :id="`entidad-editar-${cobertura.id}`"
                                name="entidad"
                                type="text"
                                required
                                :value="cobertura.entidad"
                                :class="campoBase"
                            />
                            <InputError :message="errors.entidad" />
                        </div>

                        <div class="grid gap-2">
                            <Label :for="`plan-editar-${cobertura.id}`"
                                >Plan</Label
                            >
                            <input
                                :id="`plan-editar-${cobertura.id}`"
                                name="plan"
                                type="text"
                                :value="cobertura.plan"
                                :class="campoBase"
                            />
                            <InputError :message="errors.plan" />
                        </div>

                        <div class="grid gap-2">
                            <Label :for="`nro_afiliado-editar-${cobertura.id}`">
                                Número de afiliado
                            </Label>
                            <input
                                :id="`nro_afiliado-editar-${cobertura.id}`"
                                name="nro_afiliado"
                                type="text"
                                :value="cobertura.nro_afiliado"
                                :class="campoBase"
                            />
                            <InputError :message="errors.nro_afiliado" />
                        </div>

                        <div class="grid gap-2">
                            <Label :for="`telefono-editar-${cobertura.id}`"
                                >Teléfono</Label
                            >
                            <input
                                :id="`telefono-editar-${cobertura.id}`"
                                name="telefono"
                                type="tel"
                                inputmode="tel"
                                :value="cobertura.telefono"
                                :class="campoBase"
                            />
                            <InputError :message="errors.telefono" />
                        </div>

                        <div class="grid gap-2">
                            <Label
                                :for="`telefono_urgencias-editar-${cobertura.id}`"
                            >
                                Teléfono de urgencias
                            </Label>
                            <input
                                :id="`telefono_urgencias-editar-${cobertura.id}`"
                                name="telefono_urgencias"
                                type="tel"
                                inputmode="tel"
                                :value="cobertura.telefono_urgencias"
                                :class="campoBase"
                            />
                            <InputError :message="errors.telefono_urgencias" />
                        </div>

                        <div class="grid gap-2">
                            <Label
                                :for="`vigencia_desde-editar-${cobertura.id}`"
                            >
                                Vigencia desde
                            </Label>
                            <input
                                :id="`vigencia_desde-editar-${cobertura.id}`"
                                name="vigencia_desde"
                                type="date"
                                :value="cobertura.vigencia_desde"
                                :class="campoBase"
                            />
                            <InputError :message="errors.vigencia_desde" />
                        </div>

                        <div class="grid gap-2">
                            <Label
                                :for="`vigencia_hasta-editar-${cobertura.id}`"
                            >
                                Vigencia hasta
                            </Label>
                            <input
                                :id="`vigencia_hasta-editar-${cobertura.id}`"
                                name="vigencia_hasta"
                                type="date"
                                :value="cobertura.vigencia_hasta"
                                :class="campoBase"
                            />
                            <InputError :message="errors.vigencia_hasta" />
                        </div>

                        <div class="grid gap-2">
                            <Label
                                :for="`activa-editar-${cobertura.id}`"
                                class="flex items-center space-x-3"
                            >
                                <Checkbox
                                    :id="`activa-editar-${cobertura.id}`"
                                    name="activa"
                                    :default-value="cobertura.activa"
                                />
                                <span>Cobertura activa</span>
                            </Label>
                            <InputError :message="errors.activa" />
                        </div>

                        <div class="grid gap-2">
                            <Label :for="`notas-editar-${cobertura.id}`"
                                >Notas</Label
                            >
                            <textarea
                                :id="`notas-editar-${cobertura.id}`"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                                >{{ cobertura.notas }}</textarea>
                            <InputError :message="errors.notas" />
                        </div>

                        <Button
                            type="submit"
                            class="w-full"
                            :disabled="processing"
                        >
                            Guardar
                        </Button>
                    </Form>
                </div>

                <!-- Agregar -->
                <Button
                    v-if="paciente.puedeEditar && !agregando"
                    variant="outline"
                    class="w-full"
                    @click="agregando = true"
                >
                    Agregar cobertura
                </Button>

                <Form
                    v-if="paciente.puedeEditar && agregando"
                    v-bind="
                        CoberturaController.store.form({
                            paciente: paciente.id,
                        })
                    "
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    @success="agregando = false"
                    v-slot="{ errors, processing }"
                    class="space-y-3 rounded-lg border p-3"
                >
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-sm font-medium">Nueva cobertura</p>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            aria-label="Cancelar"
                            @click="agregando = false"
                        >
                            <X class="size-4" />
                        </Button>
                    </div>

                    <div class="grid gap-2">
                        <Label for="tipo-crear">Tipo</Label>
                        <select id="tipo-crear" name="tipo" :class="campoBase">
                            <option value="obra_social">Obra social</option>
                            <option value="prepaga">Prepaga</option>
                            <option value="mutual">Mutual</option>
                            <option value="pami">PAMI</option>
                        </select>
                        <InputError :message="errors.tipo" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="entidad-crear">
                            Obra social o prepaga
                        </Label>
                        <input
                            id="entidad-crear"
                            name="entidad"
                            type="text"
                            required
                            :class="campoBase"
                        />
                        <InputError :message="errors.entidad" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="plan-crear">Plan</Label>
                        <input
                            id="plan-crear"
                            name="plan"
                            type="text"
                            :class="campoBase"
                        />
                        <InputError :message="errors.plan" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="nro_afiliado-crear">
                            Número de afiliado
                        </Label>
                        <input
                            id="nro_afiliado-crear"
                            name="nro_afiliado"
                            type="text"
                            :class="campoBase"
                        />
                        <InputError :message="errors.nro_afiliado" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="telefono-crear">Teléfono</Label>
                        <input
                            id="telefono-crear"
                            name="telefono"
                            type="tel"
                            inputmode="tel"
                            :class="campoBase"
                        />
                        <InputError :message="errors.telefono" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="telefono_urgencias-crear">
                            Teléfono de urgencias
                        </Label>
                        <input
                            id="telefono_urgencias-crear"
                            name="telefono_urgencias"
                            type="tel"
                            inputmode="tel"
                            :class="campoBase"
                        />
                        <InputError :message="errors.telefono_urgencias" />
                    </div>

                    <div class="grid gap-2">
                        <Label
                            for="activa-crear"
                            class="flex items-center space-x-3"
                        >
                            <Checkbox
                                id="activa-crear"
                                name="activa"
                                :default-value="true"
                            />
                            <span>Cobertura activa</span>
                        </Label>
                        <InputError :message="errors.activa" />
                    </div>

                    <Button type="submit" class="w-full" :disabled="processing">
                        Guardar
                    </Button>
                </Form>
            </div>

            <SheetFooter>
                <SheetClose as-child>
                    <Button type="button" variant="secondary">Cerrar</Button>
                </SheetClose>
            </SheetFooter>
        </SheetContent>
    </Sheet>

    <!-- Borrar -->
    <Dialog
        :open="!!coberturaABorrar"
        @update:open="
            (v: boolean) => {
                if (!v) coberturaABorrar = null;
            }
        "
    >
        <DialogContent v-if="coberturaABorrar">
            <Form
                v-bind="
                    CoberturaController.destroy.form({
                        cobertura: coberturaABorrar.id,
                    })
                "
                :options="{ preserveScroll: true }"
                @success="alBorrarCobertura"
                v-slot="{ processing }"
            >
                <DialogHeader class="space-y-3">
                    <DialogTitle>
                        ¿Eliminar {{ coberturaABorrar.entidad }}?
                    </DialogTitle>
                    <DialogDescription>
                        Se borra la cobertura y su credencial. Esto no se puede
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
</template>
