<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Copy, Plus, Syringe, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import VacunaController from '@/actions/App/Http/Controllers/VacunaController';
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
 * El catálogo de vacunas. Copiado de Medicos.vue -es el patrón, ver ese
 * archivo-, con menos campos: solo nombre y notas.
 */

type Vacuna = {
    id: number;
    nombre: string;
    notas: string | null;
    esSemilla: boolean;
};

defineProps<{ registros: Vacuna[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Vacunas', href: VacunaController.index() }],
    },
});

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

const sheetCrearAbierto = ref(false);
const vacunaAEditar = ref<Vacuna | null>(null);
const vacunaABorrar = ref<Vacuna | null>(null);
</script>

<template>
    <Head title="Vacunas" />

    <div class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <Heading
                variant="small"
                title="Vacunas"
                description="El catálogo de vacunas, para registrar las dosis aplicadas"
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
            <Syringe class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no agregaste ninguna vacuna.
            </p>
        </div>

        <div v-else class="grid gap-3 sm:grid-cols-2">
            <Card v-for="vacuna in registros" :key="vacuna.id">
                <CardContent class="flex items-start justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                        <p class="truncate font-medium">{{ vacuna.nombre }}</p>
                        <p
                            v-if="vacuna.esSemilla"
                            class="text-sm text-muted-foreground"
                        >
                            De la lista compartida
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-wrap justify-end gap-1">
                        <Form
                            v-if="vacuna.esSemilla"
                            v-bind="
                                VacunaController.duplicar.form({
                                    vacuna: vacuna.id,
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
                                @click="vacunaAEditar = vacuna"
                            >
                                Editar
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                :aria-label="`Eliminar ${vacuna.nombre}`"
                                @click="vacunaABorrar = vacuna"
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
                    v-bind="VacunaController.store.form()"
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    @success="sheetCrearAbierto = false"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>Agregar una vacuna</SheetTitle>
                        <SheetDescription>
                            Queda en tu catálogo, para cualquiera de tus
                            pacientes.
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
            :open="!!vacunaAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) vacunaAEditar = null;
                }
            "
        >
            <SheetContent v-if="vacunaAEditar">
                <Form
                    v-bind="
                        VacunaController.update.form({
                            vacuna: vacunaAEditar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="vacunaAEditar = null"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>
                            Editar {{ vacunaAEditar.nombre }}
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
                                :value="vacunaAEditar.nombre"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.nombre" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="notas-editar">Notas</Label>
                            <textarea
                                id="notas-editar"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                                >{{ vacunaAEditar.notas }}</textarea>
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
            :open="!!vacunaABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) vacunaABorrar = null;
                }
            "
        >
            <DialogContent v-if="vacunaABorrar">
                <Form
                    v-bind="
                        VacunaController.destroy.form({
                            vacuna: vacunaABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="vacunaABorrar = null"
                    v-slot="{ processing }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar {{ vacunaABorrar.nombre }}?
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
