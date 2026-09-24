<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Activity, Copy, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import TipoMedicionController from '@/actions/App/Http/Controllers/TipoMedicionController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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
 * El catálogo de variables que se pueden medir: el quinto, copiado del
 * patrón (ver Medicos.vue).
 *
 * Es el único catálogo donde casi nadie va a crear nada: los siete tipos de
 * siempre vienen cargados como semillas. Esta pantalla existe para el caso
 * legítimo que ninguna lista fija cubre -"dosis de insulina"-, así que los
 * campos raros (segundo valor, rangos) van agrupados y explicados, no
 * mezclados con los dos que importan.
 */

type TipoMedicion = {
    id: number;
    nombre: string;
    unidad: string;
    unidad_secundaria: string | null;
    etiqueta_principal: string | null;
    etiqueta_secundaria: string | null;
    min_normal: number | null;
    max_normal: number | null;
    min_normal_secundario: number | null;
    max_normal_secundario: number | null;
    decimales: number;
    tieneValorSecundario: boolean;
    esSemilla: boolean;
};

defineProps<{ registros: TipoMedicion[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Variables', href: TipoMedicionController.index() },
        ],
    },
});

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;

const sheetCrearAbierto = ref(false);
const tipoAEditar = ref<TipoMedicion | null>(null);
const tipoABorrar = ref<TipoMedicion | null>(null);

/** Si el formulario abierto pide un segundo número. */
const conSegundoValor = ref(false);

function abrirAlta(): void {
    conSegundoValor.value = false;
    sheetCrearAbierto.value = true;
}

function abrirEdicion(tipo: TipoMedicion): void {
    conSegundoValor.value = tipo.tieneValorSecundario;
    tipoAEditar.value = tipo;
}

function rango(min: number | null, max: number | null): string | null {
    if (min === null && max === null) {
        return null;
    }
    if (min !== null && max !== null) {
        return `Referencia: ${min} a ${max}`;
    }
    return min !== null
        ? `Referencia: desde ${min}`
        : `Referencia: hasta ${max}`;
}
</script>

<template>
    <Head title="Variables" />

    <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                title="Variables"
                description="Qué se puede medir: peso, presión, glucemia y lo que agregues vos"
            />
            <Button @click="abrirAlta">
                <Plus />
                Agregar
            </Button>
        </div>

        <div
            v-if="registros.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <Activity class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no hay ninguna variable.
            </p>
        </div>

        <div v-else class="grid gap-3 sm:grid-cols-2">
            <Card v-for="tipo in registros" :key="tipo.id">
                <CardContent class="flex items-start justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                        <p class="truncate font-medium">
                            {{ tipo.nombre }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ tipo.unidad }}
                        </p>
                        <p
                            v-if="tipo.tieneValorSecundario"
                            class="text-sm text-muted-foreground"
                        >
                            Dos valores:
                            {{ tipo.etiqueta_principal ?? 'Valor' }} /
                            {{ tipo.etiqueta_secundaria }}
                        </p>
                        <p
                            v-if="rango(tipo.min_normal, tipo.max_normal)"
                            class="text-sm text-muted-foreground"
                        >
                            {{ rango(tipo.min_normal, tipo.max_normal) }}
                        </p>
                        <p
                            v-if="tipo.esSemilla"
                            class="text-sm text-muted-foreground"
                        >
                            De la lista compartida
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-wrap justify-end gap-1">
                        <Form
                            v-if="tipo.esSemilla"
                            v-bind="
                                TipoMedicionController.duplicar.form({
                                    tipo_medicion: tipo.id,
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
                                @click="abrirEdicion(tipo)"
                            >
                                Editar
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                :aria-label="`Eliminar ${tipo.nombre}`"
                                @click="tipoABorrar = tipo"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </template>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Crear -->
        <Sheet v-model:open="sheetCrearAbierto">
            <SheetContent>
                <Form
                    v-bind="TipoMedicionController.store.form()"
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    @success="sheetCrearAbierto = false"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>Agregar una variable</SheetTitle>
                        <SheetDescription>
                            Queda en tu lista, para cualquiera de tus pacientes.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="nombre-crear">Nombre</Label>
                            <input
                                id="nombre-crear"
                                name="nombre"
                                type="text"
                                required
                                placeholder="Por ejemplo: Peso"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.nombre" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="unidad-crear">Unidad</Label>
                            <input
                                id="unidad-crear"
                                name="unidad"
                                type="text"
                                required
                                placeholder="kg, mmHg, mg/dl…"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.unidad" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="decimales-crear">Decimales</Label>
                            <select
                                id="decimales-crear"
                                name="decimales"
                                :class="campoUnaLinea"
                            >
                                <option value="0">Ninguno (120)</option>
                                <option value="1" selected>Uno (72,5)</option>
                                <option value="2">Dos (36,75)</option>
                            </select>
                            <InputError :message="errors.decimales" />
                        </div>

                        <div class="grid gap-2 rounded-lg border p-3">
                            <Label
                                for="con-segundo-crear"
                                class="flex items-center gap-3"
                            >
                                <input
                                    id="con-segundo-crear"
                                    v-model="conSegundoValor"
                                    type="checkbox"
                                    class="size-5"
                                />
                                <span>Se anota con dos números</span>
                            </Label>
                            <p class="text-sm text-muted-foreground">
                                Como la presión: 120/80.
                            </p>

                            <template v-if="conSegundoValor">
                                <div class="grid gap-2">
                                    <Label for="etiqueta-principal-crear">
                                        Nombre del primer número
                                    </Label>
                                    <input
                                        id="etiqueta-principal-crear"
                                        name="etiqueta_principal"
                                        type="text"
                                        placeholder="Sistólica"
                                        :class="campoUnaLinea"
                                    />
                                    <InputError
                                        :message="errors.etiqueta_principal"
                                    />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="etiqueta-secundaria-crear">
                                        Nombre del segundo número
                                    </Label>
                                    <input
                                        id="etiqueta-secundaria-crear"
                                        name="etiqueta_secundaria"
                                        type="text"
                                        placeholder="Diastólica"
                                        :class="campoUnaLinea"
                                    />
                                    <InputError
                                        :message="errors.etiqueta_secundaria"
                                    />
                                </div>
                            </template>
                        </div>

                        <div class="grid gap-2 rounded-lg border p-3">
                            <p class="text-sm font-medium">
                                Valores de referencia (opcional)
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Se muestran al lado del valor, como en un
                                análisis. MiSalud no interpreta ni avisa nada:
                                eso es del médico.
                            </p>

                            <div class="grid grid-cols-2 gap-2">
                                <div class="grid gap-2">
                                    <Label for="min-normal-crear">Mínimo</Label>
                                    <input
                                        id="min-normal-crear"
                                        name="min_normal"
                                        type="text"
                                        inputmode="decimal"
                                        :class="campoUnaLinea"
                                    />
                                    <InputError :message="errors.min_normal" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="max-normal-crear">Máximo</Label>
                                    <input
                                        id="max-normal-crear"
                                        name="max_normal"
                                        type="text"
                                        inputmode="decimal"
                                        :class="campoUnaLinea"
                                    />
                                    <InputError :message="errors.max_normal" />
                                </div>
                            </div>
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
            :open="!!tipoAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) tipoAEditar = null;
                }
            "
        >
            <SheetContent v-if="tipoAEditar">
                <Form
                    v-bind="
                        TipoMedicionController.update.form({
                            tipo_medicion: tipoAEditar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="tipoAEditar = null"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>Editar {{ tipoAEditar.nombre }}</SheetTitle>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="nombre-editar">Nombre</Label>
                            <input
                                id="nombre-editar"
                                name="nombre"
                                type="text"
                                required
                                :value="tipoAEditar.nombre"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.nombre" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="unidad-editar">Unidad</Label>
                            <input
                                id="unidad-editar"
                                name="unidad"
                                type="text"
                                required
                                :value="tipoAEditar.unidad"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.unidad" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="decimales-editar">Decimales</Label>
                            <select
                                id="decimales-editar"
                                name="decimales"
                                :value="String(tipoAEditar.decimales)"
                                :class="campoUnaLinea"
                            >
                                <option value="0">Ninguno (120)</option>
                                <option value="1">Uno (72,5)</option>
                                <option value="2">Dos (36,75)</option>
                            </select>
                            <InputError :message="errors.decimales" />
                        </div>

                        <div class="grid gap-2 rounded-lg border p-3">
                            <Label
                                for="con-segundo-editar"
                                class="flex items-center gap-3"
                            >
                                <input
                                    id="con-segundo-editar"
                                    v-model="conSegundoValor"
                                    type="checkbox"
                                    class="size-5"
                                />
                                <span>Se anota con dos números</span>
                            </Label>

                            <template v-if="conSegundoValor">
                                <div class="grid gap-2">
                                    <Label for="etiqueta-principal-editar">
                                        Nombre del primer número
                                    </Label>
                                    <input
                                        id="etiqueta-principal-editar"
                                        name="etiqueta_principal"
                                        type="text"
                                        :value="tipoAEditar.etiqueta_principal"
                                        :class="campoUnaLinea"
                                    />
                                    <InputError
                                        :message="errors.etiqueta_principal"
                                    />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="etiqueta-secundaria-editar">
                                        Nombre del segundo número
                                    </Label>
                                    <input
                                        id="etiqueta-secundaria-editar"
                                        name="etiqueta_secundaria"
                                        type="text"
                                        :value="tipoAEditar.etiqueta_secundaria"
                                        :class="campoUnaLinea"
                                    />
                                    <InputError
                                        :message="errors.etiqueta_secundaria"
                                    />
                                </div>
                            </template>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div class="grid gap-2">
                                <Label for="min-normal-editar">
                                    Mínimo de referencia
                                </Label>
                                <input
                                    id="min-normal-editar"
                                    name="min_normal"
                                    type="text"
                                    inputmode="decimal"
                                    :value="tipoAEditar.min_normal"
                                    :class="campoUnaLinea"
                                />
                                <InputError :message="errors.min_normal" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="max-normal-editar">
                                    Máximo de referencia
                                </Label>
                                <input
                                    id="max-normal-editar"
                                    name="max_normal"
                                    type="text"
                                    inputmode="decimal"
                                    :value="tipoAEditar.max_normal"
                                    :class="campoUnaLinea"
                                />
                                <InputError :message="errors.max_normal" />
                            </div>
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
            :open="!!tipoABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) tipoABorrar = null;
                }
            "
        >
            <DialogContent v-if="tipoABorrar">
                <Form
                    v-bind="
                        TipoMedicionController.destroy.form({
                            tipo_medicion: tipoABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="tipoABorrar = null"
                    v-slot="{ processing }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar {{ tipoABorrar.nombre }}?
                        </DialogTitle>
                        <DialogDescription>
                            Se saca de tu lista. Si ya tiene mediciones cargadas
                            no se va a poder: en ese caso, editala.
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
