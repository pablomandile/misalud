<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ArrowLeft, Pill, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
import TratamientoController from '@/actions/App/Http/Controllers/TratamientoController';
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
 * Los tratamientos de un paciente: qué medicamento toma, con qué dosis.
 *
 * Activos primero, y separados de los que ya se dejaron de tomar. Un
 * tratamiento inactivo no desaparece -sirve de historial-, pero no puede
 * competir en pantalla con lo que sigue vigente.
 */

type Tratamiento = {
    id: number;
    medicamento_id: number;
    medicamentoNombre: string;
    medico_id: number | null;
    medicoNombre: string | null;
    enfermedad_id: number | null;
    enfermedadNombre: string | null;
    dosis: string;
    frecuencia: string;
    inicio: string;
    inicioVisible: string;
    fin: string | null;
    finVisible: string | null;
    activo: boolean;
    notas: string | null;
};

const props = defineProps<{
    paciente: { id: number; nombre: string; puedeEditar: boolean };
    tratamientos: Tratamiento[];
    medicamentos: Array<{ id: number; nombre: string }>;
    medicos: Array<{ id: number; nombre: string }>;
    enfermedades: Array<{ id: number; nombre: string }>;
    hoy: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pacientes', href: PacienteController.index() },
            { title: 'Tratamientos', href: '' },
        ],
    },
});

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

const sheetAlta = ref(false);
const tratamientoAEditar = ref<Tratamiento | null>(null);
const tratamientoABorrar = ref<Tratamiento | null>(null);

/*
 * `computed()` y NO un `const` calculado una vez: al cargar un tratamiento,
 * Inertia redirige a esta MISMA URL y reutiliza la instancia del
 * componente -no la remonta-, así que `props.tratamientos` cambia pero un
 * `const` calculado en el `setup()` quedaría congelado con el valor de la
 * primera carga. Con un `const`, el tratamiento recién creado no aparecía
 * hasta un refresh manual de la página: encontrado en Chrome real, ni un
 * test de Pest lo hubiera visto porque no hay reactividad de por medio.
 */
const activos = computed(() => props.tratamientos.filter((t) => t.activo));
const inactivos = computed(() => props.tratamientos.filter((t) => !t.activo));
</script>

<template>
    <Head :title="`Tratamientos de ${paciente.nombre}`" />

    <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="`Tratamientos de ${paciente.nombre}`"
                description="Qué medicamento toma, con qué dosis y por qué"
            />
            <Button
                v-if="paciente.puedeEditar && medicamentos.length > 0"
                @click="sheetAlta = true"
            >
                <Plus />
                Agregar
            </Button>
        </div>

        <div
            v-if="medicamentos.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <Pill class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no hay medicamentos en tu catálogo. Cargá uno en
                <span class="font-medium">Medicamentos</span> y volvé.
            </p>
        </div>

        <div
            v-else-if="tratamientos.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <Pill class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no cargaste ningún tratamiento.
            </p>
        </div>

        <template
            v-for="grupo in [
                { titulo: 'Activos', lista: activos },
                { titulo: 'Inactivos', lista: inactivos },
            ]"
            :key="grupo.titulo"
        >
            <div v-if="grupo.lista.length > 0" class="space-y-3">
                <p class="text-sm text-muted-foreground">{{ grupo.titulo }}</p>

                <div class="grid gap-3">
                    <Card
                        v-for="tratamiento in grupo.lista"
                        :key="tratamiento.id"
                    >
                        <CardContent
                            class="flex items-start justify-between gap-3"
                        >
                            <div class="min-w-0 space-y-1">
                                <p class="font-medium">
                                    {{ tratamiento.medicamentoNombre }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    {{ tratamiento.dosis }} ·
                                    {{ tratamiento.frecuencia }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    Desde {{ tratamiento.inicioVisible
                                    }}<span v-if="tratamiento.finVisible">
                                        hasta
                                        {{ tratamiento.finVisible }}</span
                                    >
                                </p>
                                <p
                                    v-if="tratamiento.medicoNombre"
                                    class="text-sm text-muted-foreground"
                                >
                                    {{ tratamiento.medicoNombre }}
                                </p>
                                <p
                                    v-if="tratamiento.enfermedadNombre"
                                    class="text-sm text-muted-foreground"
                                >
                                    Por {{ tratamiento.enfermedadNombre }}
                                </p>
                                <p v-if="tratamiento.notas" class="text-sm">
                                    {{ tratamiento.notas }}
                                </p>
                            </div>

                            <div
                                v-if="paciente.puedeEditar"
                                class="flex shrink-0 gap-1"
                            >
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="tratamientoAEditar = tratamiento"
                                >
                                    Editar
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    :aria-label="`Eliminar el tratamiento con ${tratamiento.medicamentoNombre}`"
                                    @click="tratamientoABorrar = tratamiento"
                                >
                                    <Trash2 class="size-4" />
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

        <!-- Alta -->
        <Sheet v-model:open="sheetAlta">
            <SheetContent>
                <Form
                    v-bind="
                        TratamientoController.store.form({
                            paciente: paciente.id,
                        })
                    "
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                    @success="sheetAlta = false"
                >
                    <SheetHeader>
                        <SheetTitle>Agregar un tratamiento</SheetTitle>
                        <SheetDescription>
                            Queda en la ficha de {{ paciente.nombre }}.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="medicamento-crear">Medicamento</Label>
                            <select
                                id="medicamento-crear"
                                name="medicamento_id"
                                required
                                :class="campoUnaLinea"
                            >
                                <option
                                    v-for="medicamento in medicamentos"
                                    :key="medicamento.id"
                                    :value="medicamento.id"
                                >
                                    {{ medicamento.nombre }}
                                </option>
                            </select>
                            <InputError :message="errors.medicamento_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="dosis-crear">Dosis</Label>
                            <input
                                id="dosis-crear"
                                name="dosis"
                                type="text"
                                required
                                placeholder="500mg"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.dosis" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="frecuencia-crear">Frecuencia</Label>
                            <input
                                id="frecuencia-crear"
                                name="frecuencia"
                                type="text"
                                required
                                placeholder="Cada 8 horas"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.frecuencia" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="inicio-crear">Desde</Label>
                            <input
                                id="inicio-crear"
                                name="inicio"
                                type="date"
                                required
                                :value="hoy"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.inicio" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="fin-crear"> Hasta (opcional) </Label>
                            <input
                                id="fin-crear"
                                name="fin"
                                type="date"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.fin" />
                        </div>

                        <div v-if="medicos.length > 0" class="grid gap-2">
                            <Label for="medico-crear">Médico</Label>
                            <select
                                id="medico-crear"
                                name="medico_id"
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

                        <div v-if="enfermedades.length > 0" class="grid gap-2">
                            <Label for="enfermedad-crear">
                                ¿Es por alguna enfermedad?
                            </Label>
                            <select
                                id="enfermedad-crear"
                                name="enfermedad_id"
                                :class="campoUnaLinea"
                            >
                                <option value="">No especificar</option>
                                <option
                                    v-for="enfermedad in enfermedades"
                                    :key="enfermedad.id"
                                    :value="enfermedad.id"
                                >
                                    {{ enfermedad.nombre }}
                                </option>
                            </select>
                            <InputError :message="errors.enfermedad_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label
                                for="activo-crear"
                                class="flex items-center space-x-3"
                            >
                                <Checkbox
                                    id="activo-crear"
                                    name="activo"
                                    :default-value="true"
                                />
                                <span>Lo sigue tomando</span>
                            </Label>
                            <InputError :message="errors.activo" />
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
            :open="!!tratamientoAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) tratamientoAEditar = null;
                }
            "
        >
            <SheetContent v-if="tratamientoAEditar">
                <Form
                    v-bind="
                        TratamientoController.update.form({
                            tratamiento: tratamientoAEditar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                    @success="tratamientoAEditar = null"
                >
                    <SheetHeader>
                        <SheetTitle>Editar el tratamiento</SheetTitle>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="medicamento-editar">Medicamento</Label>
                            <select
                                id="medicamento-editar"
                                name="medicamento_id"
                                required
                                :value="tratamientoAEditar.medicamento_id"
                                :class="campoUnaLinea"
                            >
                                <option
                                    v-for="medicamento in medicamentos"
                                    :key="medicamento.id"
                                    :value="medicamento.id"
                                >
                                    {{ medicamento.nombre }}
                                </option>
                            </select>
                            <InputError :message="errors.medicamento_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="dosis-editar">Dosis</Label>
                            <input
                                id="dosis-editar"
                                name="dosis"
                                type="text"
                                required
                                :value="tratamientoAEditar.dosis"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.dosis" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="frecuencia-editar">Frecuencia</Label>
                            <input
                                id="frecuencia-editar"
                                name="frecuencia"
                                type="text"
                                required
                                :value="tratamientoAEditar.frecuencia"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.frecuencia" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="inicio-editar">Desde</Label>
                            <input
                                id="inicio-editar"
                                name="inicio"
                                type="date"
                                required
                                :value="tratamientoAEditar.inicio"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.inicio" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="fin-editar">Hasta (opcional)</Label>
                            <input
                                id="fin-editar"
                                name="fin"
                                type="date"
                                :value="tratamientoAEditar.fin"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.fin" />
                        </div>

                        <div v-if="medicos.length > 0" class="grid gap-2">
                            <Label for="medico-editar">Médico</Label>
                            <select
                                id="medico-editar"
                                name="medico_id"
                                :value="tratamientoAEditar.medico_id ?? ''"
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

                        <div v-if="enfermedades.length > 0" class="grid gap-2">
                            <Label for="enfermedad-editar">
                                ¿Es por alguna enfermedad?
                            </Label>
                            <select
                                id="enfermedad-editar"
                                name="enfermedad_id"
                                :value="tratamientoAEditar.enfermedad_id ?? ''"
                                :class="campoUnaLinea"
                            >
                                <option value="">No especificar</option>
                                <option
                                    v-for="enfermedad in enfermedades"
                                    :key="enfermedad.id"
                                    :value="enfermedad.id"
                                >
                                    {{ enfermedad.nombre }}
                                </option>
                            </select>
                            <InputError :message="errors.enfermedad_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label
                                for="activo-editar"
                                class="flex items-center space-x-3"
                            >
                                <Checkbox
                                    id="activo-editar"
                                    name="activo"
                                    :default-value="tratamientoAEditar.activo"
                                />
                                <span>Lo sigue tomando</span>
                            </Label>
                            <InputError :message="errors.activo" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="notas-editar">Notas</Label>
                            <textarea
                                id="notas-editar"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                                >{{ tratamientoAEditar.notas }}</textarea>
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
            :open="!!tratamientoABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) tratamientoABorrar = null;
                }
            "
        >
            <DialogContent v-if="tratamientoABorrar">
                <Form
                    v-bind="
                        TratamientoController.destroy.form({
                            tratamiento: tratamientoABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                    @success="tratamientoABorrar = null"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar el tratamiento con
                            {{ tratamientoABorrar.medicamentoNombre }}?
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
