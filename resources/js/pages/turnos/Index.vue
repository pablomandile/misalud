<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BellRing,
    CalendarClock,
    Check,
    Plus,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
import RecordatorioController from '@/actions/App/Http/Controllers/RecordatorioController';
import TurnoController from '@/actions/App/Http/Controllers/TurnoController';
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
 * La agenda de un paciente.
 *
 * ## Lo que viene va primero, y al revés que todos los otros listados
 *
 * El resto de la app ordena de lo más reciente a lo más viejo, porque muestra
 * historia. Una agenda muestra futuro: lo inminente es lo que importa, y un
 * turno de mañana no puede quedar debajo de uno de diciembre. Por eso
 * `proximos` va de menor a mayor.
 *
 * **La separación la hace el servidor**, no un `computed()` acá: "qué viene"
 * depende de la hora, y el reloj del navegador puede estar corrido -con él se
 * correría la mitad de la agenda-.
 *
 * ## Los recordatorios se muestran pero no se crean
 *
 * Arriba de todo van los avisos abiertos. No hay ningún botón para agregar ni
 * para borrar uno: los genera un observer a partir del turno, y lo único que
 * se puede hacer es marcarlos hechos. Es la misma tabla vista desde la
 * pantalla, y sirve para comprobar de un vistazo que el observer trabajó.
 */

type Turno = {
    id: number;
    estado: string;
    estadoEtiqueta: string;
    estaVigente: boolean;
    fechaVisible: string;
    fechaLocal: string;
    fechaIso: string;
    medico_id: number | null;
    medicoNombre: string | null;
    centro_id: number | null;
    centroNombre: string | null;
    orden_estudio_id: number | null;
    ordenSolicitado: string | null;
    motivo: string | null;
    tieneRecordatorio: boolean;
};

type RecordatorioAbierto = {
    id: number;
    tipo: string;
    tipoEtiqueta: string;
    estado: string;
    estadoEtiqueta: string;
    fechaVisible: string;
    yaCorresponde: boolean;
};

defineProps<{
    paciente: { id: number; nombre: string; puedeEditar: boolean };
    proximos: Turno[];
    pasados: Turno[];
    recordatorios: RecordatorioAbierto[];
    medicos: Array<{ id: number; nombre: string }>;
    centros: Array<{ id: number; nombre: string }>;
    ordenesDisponibles: Array<{
        id: number;
        estudio_solicitado: string;
        fechaVisible: string;
    }>;
    ahoraLocal: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pacientes', href: PacienteController.index() },
            { title: 'Turnos', href: '' },
        ],
    },
});

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

const sheetAlta = ref(false);
const turnoAEditar = ref<Turno | null>(null);
const turnoABorrar = ref<Turno | null>(null);
</script>

<template>
    <Head :title="`Turnos de ${paciente.nombre}`" />

    <div class="space-y-6">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="`Turnos de ${paciente.nombre}`"
                description="Lo que viene, y los avisos que se desprenden"
            />
            <Button v-if="paciente.puedeEditar" @click="sheetAlta = true">
                <Plus />
                Agendar
            </Button>
        </div>

        <!--
            Los avisos abiertos, arriba de todo: es lo que contesta "qué
            tengo que hacer". Incluye los ya enviados, porque que el mail
            haya salido no significa que esté resuelto.
        -->
        <section v-if="recordatorios.length > 0" class="space-y-3">
            <h2 class="text-lg font-medium">Avisos</h2>

            <Card v-for="aviso in recordatorios" :key="aviso.id">
                <CardContent class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <BellRing
                            class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                        />
                        <div class="min-w-0 space-y-1">
                            <p class="font-medium">{{ aviso.tipoEtiqueta }}</p>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    aviso.yaCorresponde
                                        ? 'Desde el'
                                        : 'Avisa el'
                                }}
                                {{ aviso.fechaVisible }} ·
                                {{ aviso.estadoEtiqueta }}
                            </p>
                        </div>
                    </div>

                    <!--
                        Solo se puede marcar hecho: no hay alta ni baja de un
                        recordatorio en toda la app.
                    -->
                    <Form
                        v-if="paciente.puedeEditar"
                        v-bind="
                            RecordatorioController.update.form({
                                recordatorio: aviso.id,
                            })
                        "
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                        class="shrink-0"
                    >
                        <input type="hidden" name="completado" value="1" />
                        <Button
                            type="submit"
                            variant="ghost"
                            size="sm"
                            :disabled="processing"
                        >
                            <Check />
                            Ya está
                        </Button>
                    </Form>
                </CardContent>
            </Card>
        </section>

        <div
            v-if="proximos.length === 0 && pasados.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <CalendarClock class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no agendaste ningún turno.
            </p>
        </div>

        <template
            v-for="grupo in [
                { titulo: 'Lo que viene', lista: proximos },
                { titulo: 'Ya pasaron', lista: pasados },
            ]"
            :key="grupo.titulo"
        >
            <section v-if="grupo.lista.length > 0" class="space-y-3">
                <h2 class="text-lg font-medium">{{ grupo.titulo }}</h2>

                <div class="grid gap-3">
                    <Card v-for="turno in grupo.lista" :key="turno.id">
                        <CardContent
                            class="flex items-start justify-between gap-3"
                        >
                            <div class="min-w-0 space-y-1">
                                <p class="font-medium">
                                    {{ turno.fechaVisible }}
                                </p>
                                <p class="text-sm text-muted-foreground">
                                    {{ turno.estadoEtiqueta }}
                                    <span v-if="turno.tieneRecordatorio">
                                        · con aviso
                                    </span>
                                </p>
                                <p
                                    v-if="
                                        turno.medicoNombre || turno.centroNombre
                                    "
                                    class="text-sm text-muted-foreground"
                                >
                                    {{
                                        [turno.medicoNombre, turno.centroNombre]
                                            .filter(Boolean)
                                            .join(' · ')
                                    }}
                                </p>
                                <p v-if="turno.motivo" class="text-sm">
                                    {{ turno.motivo }}
                                </p>
                                <p
                                    v-if="turno.ordenSolicitado"
                                    class="text-sm text-muted-foreground"
                                >
                                    Por la orden: {{ turno.ordenSolicitado }}
                                </p>
                            </div>

                            <div
                                v-if="paciente.puedeEditar"
                                class="flex shrink-0 gap-1"
                            >
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="turnoAEditar = turno"
                                >
                                    Editar
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    :aria-label="`Eliminar el turno del ${turno.fechaVisible}`"
                                    @click="turnoABorrar = turno"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </section>
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
            :open="sheetAlta || !!turnoAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) {
                        sheetAlta = false;
                        turnoAEditar = null;
                    }
                }
            "
        >
            <SheetContent>
                <Form
                    :key="turnoAEditar?.id ?? 'nuevo'"
                    v-bind="
                        turnoAEditar
                            ? TurnoController.update.form({
                                  turno: turnoAEditar.id,
                              })
                            : TurnoController.store.form({
                                  paciente: paciente.id,
                              })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                    @success="
                        () => {
                            sheetAlta = false;
                            turnoAEditar = null;
                        }
                    "
                >
                    <SheetHeader>
                        <SheetTitle>
                            {{
                                turnoAEditar
                                    ? 'Editar el turno'
                                    : 'Agendar un turno'
                            }}
                        </SheetTitle>
                        <SheetDescription>
                            El aviso se genera solo, 24 horas antes.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="fecha-turno">Cuándo</Label>
                            <!--
                                Sin `max`: un turno FUTURO es el caso normal,
                                al revés que una medición. Y la precarga sale
                                del servidor (`ahoraLocal`), no de
                                `new Date()`.
                            -->
                            <input
                                id="fecha-turno"
                                name="fecha_hora"
                                type="datetime-local"
                                required
                                :value="
                                    turnoAEditar?.fechaLocal ??
                                    ahoraLocal ??
                                    undefined
                                "
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.fecha_hora" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="estado-turno">Estado</Label>
                            <select
                                id="estado-turno"
                                name="estado"
                                :value="turnoAEditar?.estado ?? 'programado'"
                                :class="campoUnaLinea"
                            >
                                <option value="programado">Programado</option>
                                <option value="asistido">Ya fui</option>
                                <option value="cancelado">Cancelado</option>
                            </select>
                            <InputError :message="errors.estado" />
                        </div>

                        <div v-if="medicos.length > 0" class="grid gap-2">
                            <Label for="medico-turno">Médico</Label>
                            <select
                                id="medico-turno"
                                name="medico_id"
                                :value="turnoAEditar?.medico_id ?? ''"
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

                        <div v-if="centros.length > 0" class="grid gap-2">
                            <Label for="centro-turno">Centro</Label>
                            <select
                                id="centro-turno"
                                name="centro_id"
                                :value="turnoAEditar?.centro_id ?? ''"
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

                        <div
                            v-if="ordenesDisponibles.length > 0"
                            class="grid gap-2"
                        >
                            <Label for="orden-turno">
                                ¿Es por una orden?
                            </Label>
                            <select
                                id="orden-turno"
                                name="orden_estudio_id"
                                :value="turnoAEditar?.orden_estudio_id ?? ''"
                                :class="campoUnaLinea"
                            >
                                <option value="">No</option>
                                <option
                                    v-for="orden in ordenesDisponibles"
                                    :key="orden.id"
                                    :value="orden.id"
                                >
                                    {{ orden.estudio_solicitado }} ({{
                                        orden.fechaVisible
                                    }})
                                </option>
                            </select>
                            <InputError :message="errors.orden_estudio_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="motivo-turno">Motivo</Label>
                            <textarea
                                id="motivo-turno"
                                name="motivo"
                                rows="3"
                                :class="campoTexto"
                                >{{ turnoAEditar?.motivo }}</textarea>
                            <InputError :message="errors.motivo" />
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
            :open="!!turnoABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) turnoABorrar = null;
                }
            "
        >
            <DialogContent v-if="turnoABorrar">
                <Form
                    v-bind="
                        TurnoController.destroy.form({
                            turno: turnoABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                    @success="turnoABorrar = null"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar el turno del
                            {{ turnoABorrar.fechaVisible }}?
                        </DialogTitle>
                        <DialogDescription>
                            Se borra con su aviso. Esto no se puede deshacer.
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
