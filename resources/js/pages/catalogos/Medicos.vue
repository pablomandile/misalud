<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Copy, Plus, Stethoscope, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import MedicoController from '@/actions/App/Http/Controllers/MedicoController';
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
 * El catálogo de médicos: el PRIMERO, y por lo tanto la plantilla de los
 * otros tres (centros, medicamentos, vacunas).
 *
 * Un catálogo es del USUARIO y no de un paciente: el mismo médico atiende a
 * toda la familia que uno administra. Por eso esta pantalla no tiene
 * selector de paciente por ningún lado.
 */

type Medico = {
    id: number;
    nombre: string;
    especialidad: string | null;
    telefono: string | null;
    email: string | null;
    notas: string | null;
    /*
     * Una semilla compartida no se edita: se copia al catálogo propio y
     * recién ahí se toca (regla 5 de CLAUDE.md). El servidor lo vuelve a
     * chequear igual — esto solo decide qué botones se dibujan.
     */
    esSemilla: boolean;
};

defineProps<{ registros: Medico[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Médicos', href: MedicoController.index() }],
    },
});

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

const sheetCrearAbierto = ref(false);
const medicoAEditar = ref<Medico | null>(null);
const medicoABorrar = ref<Medico | null>(null);
</script>

<template>
    <Head title="Médicos" />

    <div class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <Heading
                variant="small"
                title="Médicos"
                description="Tu agenda de médicos, la misma para toda la familia que administrés"
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
            <Stethoscope class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no agregaste ningún médico.
            </p>
        </div>

        <!-- Tarjetas apiladas y no una tabla: a 320px una tabla desborda. -->
        <div v-else class="grid gap-3 sm:grid-cols-2">
            <Card v-for="medico in registros" :key="medico.id">
                <CardContent class="flex items-start justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                        <p class="truncate font-medium">{{ medico.nombre }}</p>
                        <p
                            v-if="medico.especialidad"
                            class="text-sm text-muted-foreground"
                        >
                            {{ medico.especialidad }}
                        </p>
                        <p
                            v-if="medico.telefono"
                            class="text-sm text-muted-foreground"
                        >
                            Tel. {{ medico.telefono }}
                        </p>
                        <p
                            v-if="medico.esSemilla"
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
                            v-if="medico.esSemilla"
                            v-bind="
                                MedicoController.duplicar.form({
                                    medico: medico.id,
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
                                @click="medicoAEditar = medico"
                            >
                                Editar
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                :aria-label="`Eliminar ${medico.nombre}`"
                                @click="medicoABorrar = medico"
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
                    v-bind="MedicoController.store.form()"
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    @success="sheetCrearAbierto = false"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>Agregar un médico</SheetTitle>
                        <SheetDescription>
                            Queda en tu agenda, para cualquiera de tus
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
                            <Label for="especialidad-crear">Especialidad</Label>
                            <input
                                id="especialidad-crear"
                                name="especialidad"
                                type="text"
                                placeholder="Por ejemplo: Cardiología"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.especialidad" />
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
                            <Label for="email-crear">Email</Label>
                            <input
                                id="email-crear"
                                name="email"
                                type="email"
                                inputmode="email"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.email" />
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
            :open="!!medicoAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) medicoAEditar = null;
                }
            "
        >
            <SheetContent v-if="medicoAEditar">
                <Form
                    v-bind="
                        MedicoController.update.form({
                            medico: medicoAEditar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="medicoAEditar = null"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>
                            Editar a {{ medicoAEditar.nombre }}
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
                                :value="medicoAEditar.nombre"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.nombre" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="especialidad-editar">
                                Especialidad
                            </Label>
                            <input
                                id="especialidad-editar"
                                name="especialidad"
                                type="text"
                                :value="medicoAEditar.especialidad"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.especialidad" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="telefono-editar">Teléfono</Label>
                            <input
                                id="telefono-editar"
                                name="telefono"
                                type="tel"
                                inputmode="tel"
                                :value="medicoAEditar.telefono"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.telefono" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="email-editar">Email</Label>
                            <input
                                id="email-editar"
                                name="email"
                                type="email"
                                inputmode="email"
                                :value="medicoAEditar.email"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="notas-editar">Notas</Label>
                            <textarea
                                id="notas-editar"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                                >{{ medicoAEditar.notas }}</textarea>
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
            :open="!!medicoABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) medicoABorrar = null;
                }
            "
        >
            <DialogContent v-if="medicoABorrar">
                <Form
                    v-bind="
                        MedicoController.destroy.form({
                            medico: medicoABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="medicoABorrar = null"
                    v-slot="{ processing }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar a {{ medicoABorrar.nombre }}?
                        </DialogTitle>
                        <DialogDescription>
                            Se saca de tu agenda. Esto no se puede deshacer.
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
