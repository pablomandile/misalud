<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Building2, Copy, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import CentroController from '@/actions/App/Http/Controllers/CentroController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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
 * El catálogo de centros: copiado de médicos (ver esa pantalla), con dos
 * cosas de más — el tipo de centro y el checklist de médicos que atienden
 * ahí, que arma `centro_medico`.
 *
 * Un catálogo es del USUARIO y no de un paciente: por eso tampoco acá hay
 * selector de paciente.
 */

type MedicoDisponible = { id: number; nombre: string };

type Centro = {
    id: number;
    nombre: string;
    tipo: string;
    tipoEtiqueta: string;
    direccion: string | null;
    telefono: string | null;
    notas: string | null;
    esSemilla: boolean;
    medicos: MedicoDisponible[];
};

defineProps<{
    registros: Centro[];
    medicosDisponibles: MedicoDisponible[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Centros', href: CentroController.index() }],
    },
});

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

const sheetCrearAbierto = ref(false);
const centroAEditar = ref<Centro | null>(null);
const centroABorrar = ref<Centro | null>(null);

function estaVinculado(centro: Centro, medicoId: number): boolean {
    return centro.medicos.some((m) => m.id === medicoId);
}
</script>

<template>
    <Head title="Centros" />

    <div class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <Heading
                variant="small"
                title="Centros"
                description="Consultorios, clínicas y laboratorios donde atienden tus médicos"
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
            <Building2 class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no agregaste ningún centro.
            </p>
        </div>

        <!-- Tarjetas apiladas y no una tabla: a 320px una tabla desborda. -->
        <div v-else class="grid gap-3 sm:grid-cols-2">
            <Card v-for="centro in registros" :key="centro.id">
                <CardContent class="flex items-start justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                        <p class="truncate font-medium">{{ centro.nombre }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{ centro.tipoEtiqueta }}
                        </p>
                        <p
                            v-if="centro.direccion"
                            class="text-sm text-muted-foreground"
                        >
                            {{ centro.direccion }}
                        </p>
                        <p
                            v-if="centro.telefono"
                            class="text-sm text-muted-foreground"
                        >
                            Tel. {{ centro.telefono }}
                        </p>
                        <p
                            v-if="centro.medicos.length > 0"
                            class="text-sm text-muted-foreground"
                        >
                            {{ centro.medicos.map((m) => m.nombre).join(', ') }}
                        </p>
                        <p
                            v-if="centro.esSemilla"
                            class="text-sm text-muted-foreground"
                        >
                            De la lista compartida
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-wrap justify-end gap-1">
                        <!--
                            Una semilla no se edita: la única acción posible es
                            copiarla al catálogo propio.
                        -->
                        <Form
                            v-if="centro.esSemilla"
                            v-bind="
                                CentroController.duplicar.form({
                                    centro: centro.id,
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
                                @click="centroAEditar = centro"
                            >
                                Editar
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                :aria-label="`Eliminar ${centro.nombre}`"
                                @click="centroABorrar = centro"
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
                    v-bind="CentroController.store.form()"
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    @success="sheetCrearAbierto = false"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>Agregar un centro</SheetTitle>
                        <SheetDescription>
                            Consultorio, clínica o laboratorio, para vincular
                            con tus médicos.
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
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.nombre" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="tipo-crear">Tipo</Label>
                            <select
                                id="tipo-crear"
                                name="tipo"
                                :class="campoUnaLinea"
                            >
                                <option value="consultorio">Consultorio</option>
                                <option value="clinica">Clínica</option>
                                <option value="hospital">Hospital</option>
                                <option value="sanatorio">Sanatorio</option>
                                <option value="laboratorio">Laboratorio</option>
                                <option value="centro_diagnostico">
                                    Centro de diagnóstico
                                </option>
                            </select>
                            <InputError :message="errors.tipo" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="direccion-crear">Dirección</Label>
                            <input
                                id="direccion-crear"
                                name="direccion"
                                type="text"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.direccion" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="telefono-crear">Teléfono</Label>
                            <input
                                id="telefono-crear"
                                name="telefono"
                                type="tel"
                                inputmode="tel"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.telefono" />
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

                        <div
                            v-if="medicosDisponibles.length > 0"
                            class="grid gap-2"
                        >
                            <Label>Médicos que atienden acá</Label>
                            <div class="grid gap-1 rounded-md border p-2">
                                <Label
                                    v-for="medico in medicosDisponibles"
                                    :key="medico.id"
                                    :for="`medico-crear-${medico.id}`"
                                    class="flex min-h-11 items-center space-x-3 rounded-md px-1"
                                >
                                    <Checkbox
                                        :id="`medico-crear-${medico.id}`"
                                        name="medicos[]"
                                        :value="medico.id"
                                    />
                                    <span>{{ medico.nombre }}</span>
                                </Label>
                            </div>
                            <InputError :message="errors.medicos" />
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
            :open="!!centroAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) centroAEditar = null;
                }
            "
        >
            <SheetContent v-if="centroAEditar">
                <Form
                    v-bind="
                        CentroController.update.form({
                            centro: centroAEditar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="centroAEditar = null"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>
                            Editar a {{ centroAEditar.nombre }}
                        </SheetTitle>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="nombre-editar">Nombre</Label>
                            <input
                                id="nombre-editar"
                                name="nombre"
                                type="text"
                                required
                                :value="centroAEditar.nombre"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.nombre" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="tipo-editar">Tipo</Label>
                            <select
                                id="tipo-editar"
                                name="tipo"
                                :value="centroAEditar.tipo"
                                :class="campoUnaLinea"
                            >
                                <option value="consultorio">Consultorio</option>
                                <option value="clinica">Clínica</option>
                                <option value="hospital">Hospital</option>
                                <option value="sanatorio">Sanatorio</option>
                                <option value="laboratorio">Laboratorio</option>
                                <option value="centro_diagnostico">
                                    Centro de diagnóstico
                                </option>
                            </select>
                            <InputError :message="errors.tipo" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="direccion-editar">Dirección</Label>
                            <input
                                id="direccion-editar"
                                name="direccion"
                                type="text"
                                :value="centroAEditar.direccion"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.direccion" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="telefono-editar">Teléfono</Label>
                            <input
                                id="telefono-editar"
                                name="telefono"
                                type="tel"
                                inputmode="tel"
                                :value="centroAEditar.telefono"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.telefono" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="notas-editar">Notas</Label>
                            <textarea
                                id="notas-editar"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                                >{{ centroAEditar.notas }}</textarea>
                            <InputError :message="errors.notas" />
                        </div>

                        <div
                            v-if="medicosDisponibles.length > 0"
                            class="grid gap-2"
                        >
                            <Label>Médicos que atienden acá</Label>
                            <div class="grid gap-1 rounded-md border p-2">
                                <Label
                                    v-for="medico in medicosDisponibles"
                                    :key="medico.id"
                                    :for="`medico-editar-${medico.id}`"
                                    class="flex min-h-11 items-center space-x-3 rounded-md px-1"
                                >
                                    <Checkbox
                                        :id="`medico-editar-${medico.id}`"
                                        name="medicos[]"
                                        :value="medico.id"
                                        :default-value="
                                            estaVinculado(
                                                centroAEditar,
                                                medico.id,
                                            )
                                        "
                                    />
                                    <span>{{ medico.nombre }}</span>
                                </Label>
                            </div>
                            <InputError :message="errors.medicos" />
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
            :open="!!centroABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) centroABorrar = null;
                }
            "
        >
            <DialogContent v-if="centroABorrar">
                <Form
                    v-bind="
                        CentroController.destroy.form({
                            centro: centroABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="centroABorrar = null"
                    v-slot="{ processing }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar a {{ centroABorrar.nombre }}?
                        </DialogTitle>
                        <DialogDescription>
                            Se saca de tu catálogo. Esto no se puede deshacer.
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
