<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    AudioLines,
    Mic,
    Pause,
    Play,
    Plus,
    Stethoscope,
    Trash2,
} from '@lucide/vue';
import { reactive, ref } from 'vue';
import AdjuntoController from '@/actions/App/Http/Controllers/AdjuntoController';
import ConsultaController from '@/actions/App/Http/Controllers/ConsultaController';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
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
import { formatoTiempo, useReproductor } from '@/composables/useReproductor';

/*
 * Las consultas de un paciente: la visita al médico, lo que se dijo, y su
 * grabación.
 *
 * ⚠️ **El formulario de la consulta va entero con `v-model`**, no con `:value`
 * como otros. El reproductor cambia de estado mientras suena (arranca, pausa,
 * termina), y cada cambio vuelve a dibujar esta pantalla: con `:value`, eso
 * pisa lo tipeado con lo que vino del servidor. Y el caso de uso es justamente
 * escuchar la grabación mientras se escriben las notas. Es la regla de la
 * Etapa 10: si un campo necesita estado local, todos los del formulario lo
 * necesitan —acá el "campo" con estado es el reproductor—.
 */

type Audio = {
    id: number;
    nombre: string;
    mime: string;
    tamanio: number;
    duracion: number | null;
    url: string;
};

type Consulta = {
    id: number;
    titulo: string;
    fechaVisible: string;
    fechaLocal: string;
    medico_id: number | null;
    medicoNombre: string | null;
    centro_id: number | null;
    centroNombre: string | null;
    enfermedad_id: number | null;
    enfermedadNombre: string | null;
    turno_id: number | null;
    motivo: string | null;
    notas: string | null;
    audios: Audio[];
};

type Opcion = { id: number; nombre: string };

const props = defineProps<{
    paciente: { id: number; nombre: string; puedeEditar: boolean };
    consultas: Consulta[];
    medicos: Opcion[];
    centros: Opcion[];
    enfermedades: Opcion[];
    turnos: Opcion[];
    ahoraLocal: string;
    maximoAudioMb: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pacientes', href: PacienteController.index() },
            { title: 'Consultas', href: '' },
        ],
    },
});

const reproductor = useReproductor();

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-32`;

/* ---------- Alta y edición ---------- */

const formularioAbierto = ref(false);
const consultaAEditar = ref<Consulta | null>(null);
const formulario = reactive({
    fecha_hora: '',
    medico_id: '' as number | '',
    centro_id: '' as number | '',
    enfermedad_id: '' as number | '',
    turno_id: '' as number | '',
    motivo: '',
    notas: '',
});

function abrirFormulario(consulta: Consulta | null): void {
    consultaAEditar.value = consulta;
    formulario.fecha_hora = consulta?.fechaLocal ?? props.ahoraLocal;
    formulario.medico_id = consulta?.medico_id ?? '';
    formulario.centro_id = consulta?.centro_id ?? '';
    formulario.enfermedad_id = consulta?.enfermedad_id ?? '';
    formulario.turno_id = consulta?.turno_id ?? '';
    formulario.motivo = consulta?.motivo ?? '';
    formulario.notas = consulta?.notas ?? '';
    formularioAbierto.value = true;
}

function cerrarFormulario(): void {
    formularioAbierto.value = false;
    consultaAEditar.value = null;
}

/* ---------- La grabación ---------- */

const consultaDeAudio = ref<Consulta | null>(null);
const archivoElegido = ref<File | null>(null);
const duracionMedida = ref<number | null>(null);

/*
 * La duración la mide el navegador antes de subir: en el hosting no hay con
 * qué medirla, y es un dato solo para mostrar. Si no la puede leer, la
 * grabación se sube igual, sin duración.
 */
function alElegirAudio(evento: Event): void {
    const archivo = (evento.target as HTMLInputElement).files?.[0] ?? null;
    archivoElegido.value = archivo;
    duracionMedida.value = null;

    if (archivo === null) {
        return;
    }

    const url = URL.createObjectURL(archivo);
    const sonda = new Audio();
    sonda.preload = 'metadata';
    sonda.addEventListener('loadedmetadata', () => {
        duracionMedida.value = Number.isFinite(sonda.duration)
            ? Math.max(1, Math.round(sonda.duration))
            : null;
        URL.revokeObjectURL(url);
    });
    sonda.addEventListener('error', () => URL.revokeObjectURL(url));
    sonda.src = url;
}

function cerrarAudio(): void {
    consultaDeAudio.value = null;
    archivoElegido.value = null;
    duracionMedida.value = null;
}

const pesaDemasiado = (archivo: File | null): boolean =>
    archivo !== null && archivo.size > props.maximoAudioMb * 1024 * 1024;

function megas(bytes: number): string {
    return `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
}

/* ---------- Borrar ---------- */

const consultaABorrar = ref<Consulta | null>(null);
const audioABorrar = ref<Audio | null>(null);

/** Si se borra lo que está sonando, el reproductor se cierra: si no, sonaría algo que ya no existe. */
function olvidarSiSuena(ids: number[]): void {
    const actual = reproductor.pista.value;

    if (actual !== null && ids.includes(actual.id)) {
        reproductor.cerrar();
    }
}

function escuchar(consulta: Consulta, audio: Audio): void {
    reproductor.reproducir({
        id: audio.id,
        url: audio.url,
        titulo: consulta.titulo,
    });
}

const suena = (audio: Audio): boolean =>
    reproductor.pista.value?.id === audio.id && reproductor.sonando.value;
</script>

<template>
    <Head :title="`Consultas de ${paciente.nombre}`" />

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="`Consultas de ${paciente.nombre}`"
                description="La visita al médico y lo que se dijo"
            />
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link
                        :href="
                            ConsultaController.grabaciones({
                                paciente: paciente.id,
                            })
                        "
                    >
                        <AudioLines />
                        Grabaciones
                    </Link>
                </Button>
                <Button
                    v-if="paciente.puedeEditar"
                    @click="abrirFormulario(null)"
                >
                    <Plus />
                    Agregar
                </Button>
            </div>
        </div>

        <div
            v-if="consultas.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <Stethoscope class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no cargaste ninguna consulta.
            </p>
        </div>

        <Card v-for="consulta in consultas" :key="consulta.id">
            <CardContent class="space-y-3">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium">{{ consulta.fechaVisible }}</p>
                        <p
                            v-if="
                                consulta.medicoNombre || consulta.centroNombre
                            "
                            class="text-sm text-muted-foreground"
                        >
                            {{
                                [consulta.medicoNombre, consulta.centroNombre]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </p>
                        <p v-if="consulta.motivo" class="text-sm">
                            {{ consulta.motivo }}
                        </p>
                        <p
                            v-if="consulta.enfermedadNombre"
                            class="text-sm text-muted-foreground"
                        >
                            Por {{ consulta.enfermedadNombre }}
                        </p>
                    </div>

                    <div
                        v-if="paciente.puedeEditar"
                        class="flex shrink-0 gap-1"
                    >
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="abrirFormulario(consulta)"
                        >
                            Editar
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                            :aria-label="`Eliminar la consulta del ${consulta.fechaVisible}`"
                            @click="consultaABorrar = consulta"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>

                <!-- Lo que dijo el médico: con sus saltos de línea. -->
                <p
                    v-if="consulta.notas"
                    class="rounded-md bg-muted/50 p-3 text-sm whitespace-pre-line"
                >
                    {{ consulta.notas }}
                </p>

                <div class="space-y-2 border-t pt-3">
                    <ul v-if="consulta.audios.length > 0" class="grid gap-2">
                        <li
                            v-for="audio in consulta.audios"
                            :key="audio.id"
                            class="flex items-center gap-2 rounded-md border p-2"
                        >
                            <Button
                                :variant="suena(audio) ? 'default' : 'outline'"
                                size="icon"
                                class="shrink-0"
                                :aria-label="
                                    suena(audio)
                                        ? 'Pausar la grabación'
                                        : `Escuchar la grabación de ${consulta.titulo}`
                                "
                                @click="escuchar(consulta, audio)"
                            >
                                <Pause v-if="suena(audio)" class="size-5" />
                                <Play v-else class="size-5" />
                            </Button>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm">
                                    {{ audio.nombre }}
                                </span>
                                <span
                                    class="block text-sm text-muted-foreground"
                                >
                                    {{
                                        audio.duracion
                                            ? formatoTiempo(audio.duracion)
                                            : 'Duración desconocida'
                                    }}
                                    · {{ megas(audio.tamanio) }}
                                </span>
                            </span>
                            <Button
                                v-if="paciente.puedeEditar"
                                variant="ghost"
                                size="icon-sm"
                                class="shrink-0 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                :aria-label="`Eliminar la grabación ${audio.nombre}`"
                                @click="audioABorrar = audio"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </li>
                    </ul>

                    <Button
                        v-if="paciente.puedeEditar"
                        variant="ghost"
                        size="sm"
                        @click="consultaDeAudio = consulta"
                    >
                        <Mic />
                        {{
                            consulta.audios.length > 0
                                ? 'Subir otra grabación'
                                : 'Subir la grabación'
                        }}
                    </Button>
                </div>
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

        <!-- Alta y edición -->
        <Sheet
            :open="formularioAbierto"
            @update:open="
                (v: boolean) => {
                    if (!v) cerrarFormulario();
                }
            "
        >
            <SheetContent>
                <Form
                    v-bind="
                        consultaAEditar
                            ? ConsultaController.update.form({
                                  consulta: consultaAEditar.id,
                              })
                            : ConsultaController.store.form({
                                  paciente: paciente.id,
                              })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                    @success="cerrarFormulario"
                >
                    <SheetHeader>
                        <SheetTitle>
                            {{
                                consultaAEditar
                                    ? 'Editar la consulta'
                                    : 'Agregar una consulta'
                            }}
                        </SheetTitle>
                        <SheetDescription v-if="!consultaAEditar">
                            La grabación se sube después de guardarla.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="fecha-consulta">Cuándo fue</Label>
                            <input
                                id="fecha-consulta"
                                v-model="formulario.fecha_hora"
                                name="fecha_hora"
                                type="datetime-local"
                                required
                                :max="ahoraLocal"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.fecha_hora" />
                        </div>

                        <div v-if="medicos.length > 0" class="grid gap-2">
                            <Label for="medico-consulta">Médico</Label>
                            <select
                                id="medico-consulta"
                                v-model="formulario.medico_id"
                                name="medico_id"
                                :class="campoUnaLinea"
                            >
                                <option value="">Sin especificar</option>
                                <option
                                    v-for="o in medicos"
                                    :key="o.id"
                                    :value="o.id"
                                >
                                    {{ o.nombre }}
                                </option>
                            </select>
                            <InputError :message="errors.medico_id" />
                        </div>

                        <div v-if="centros.length > 0" class="grid gap-2">
                            <Label for="centro-consulta">Dónde</Label>
                            <select
                                id="centro-consulta"
                                v-model="formulario.centro_id"
                                name="centro_id"
                                :class="campoUnaLinea"
                            >
                                <option value="">Sin especificar</option>
                                <option
                                    v-for="o in centros"
                                    :key="o.id"
                                    :value="o.id"
                                >
                                    {{ o.nombre }}
                                </option>
                            </select>
                            <InputError :message="errors.centro_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="motivo-consulta">Motivo</Label>
                            <input
                                id="motivo-consulta"
                                v-model="formulario.motivo"
                                name="motivo"
                                type="text"
                                placeholder="Control, dolor de rodilla…"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.motivo" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="notas-consulta"
                                >Qué dijo el médico</Label
                            >
                            <textarea
                                id="notas-consulta"
                                v-model="formulario.notas"
                                name="notas"
                                rows="6"
                                :class="campoTexto"
                            />
                            <InputError :message="errors.notas" />
                        </div>

                        <div v-if="enfermedades.length > 0" class="grid gap-2">
                            <Label for="enfermedad-consulta"
                                >Por qué enfermedad</Label
                            >
                            <select
                                id="enfermedad-consulta"
                                v-model="formulario.enfermedad_id"
                                name="enfermedad_id"
                                :class="campoUnaLinea"
                            >
                                <option value="">Ninguna en particular</option>
                                <option
                                    v-for="o in enfermedades"
                                    :key="o.id"
                                    :value="o.id"
                                >
                                    {{ o.nombre }}
                                </option>
                            </select>
                            <InputError :message="errors.enfermedad_id" />
                        </div>

                        <div v-if="turnos.length > 0" class="grid gap-2">
                            <Label for="turno-consulta">De qué turno</Label>
                            <select
                                id="turno-consulta"
                                v-model="formulario.turno_id"
                                name="turno_id"
                                :class="campoUnaLinea"
                            >
                                <option value="">Ninguno</option>
                                <option
                                    v-for="o in turnos"
                                    :key="o.id"
                                    :value="o.id"
                                >
                                    {{ o.nombre }}
                                </option>
                            </select>
                            <InputError :message="errors.turno_id" />
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

        <!-- Subir la grabación -->
        <Sheet
            :open="!!consultaDeAudio"
            @update:open="
                (v: boolean) => {
                    if (!v) cerrarAudio();
                }
            "
        >
            <SheetContent v-if="consultaDeAudio">
                <Form
                    v-bind="
                        AdjuntoController.storeAudioParaConsulta.form({
                            consulta: consultaDeAudio.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing, progress }"
                    class="flex h-full flex-col"
                    @success="cerrarAudio"
                >
                    <SheetHeader>
                        <SheetTitle>La grabación</SheetTitle>
                        <SheetDescription>
                            {{ consultaDeAudio.titulo }}
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <input
                            id="audio-consulta"
                            type="file"
                            name="audio"
                            accept=".m4a,.mp3,.aac,.wav,audio/mp4,audio/x-m4a,audio/mpeg,audio/aac,audio/wav"
                            class="sr-only"
                            @change="alElegirAudio"
                        />
                        <input
                            v-if="duracionMedida !== null"
                            type="hidden"
                            name="duracion_segundos"
                            :value="duracionMedida"
                        />

                        <!-- Un <label>: el toque abre el selector sin JavaScript de por medio. -->
                        <Button
                            as-child
                            variant="outline"
                            class="w-full justify-start"
                        >
                            <label for="audio-consulta" class="cursor-pointer">
                                <Mic />
                                Elegir la grabación
                            </label>
                        </Button>

                        <!-- El límite se dice ANTES de subir, no después de esperar tres minutos. -->
                        <p class="text-sm text-muted-foreground">
                            m4a, mp3, aac o wav, hasta {{ maximoAudioMb }} MB.
                            Una consulta de 40 minutos grabada en el celular
                            suele pesar entre 20 y 40 MB.
                        </p>

                        <div
                            v-if="archivoElegido"
                            class="rounded-md border p-3 text-sm"
                        >
                            <p class="truncate">{{ archivoElegido.name }}</p>
                            <p
                                :class="
                                    pesaDemasiado(archivoElegido)
                                        ? 'text-destructive'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ megas(archivoElegido.size) }}
                                <template v-if="duracionMedida">
                                    · {{ formatoTiempo(duracionMedida) }}
                                </template>
                                <template v-if="pesaDemasiado(archivoElegido)">
                                    · pesa más de {{ maximoAudioMb }} MB
                                </template>
                            </p>
                        </div>
                        <InputError
                            :message="errors.audio ?? errors.duracion_segundos"
                        />

                        <p
                            v-if="processing && progress"
                            class="text-sm"
                            role="status"
                        >
                            Subiendo…
                            {{ Math.round(progress.percentage ?? 0) }}%
                        </p>
                    </div>

                    <SheetFooter>
                        <Button
                            type="submit"
                            :disabled="
                                processing ||
                                !archivoElegido ||
                                pesaDemasiado(archivoElegido)
                            "
                        >
                            {{ processing ? 'Subiendo…' : 'Guardar' }}
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

        <!-- Borrar una consulta -->
        <Dialog
            :open="!!consultaABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) consultaABorrar = null;
                }
            "
        >
            <DialogContent v-if="consultaABorrar">
                <Form
                    v-bind="
                        ConsultaController.destroy.form({
                            consulta: consultaABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                    @success="
                        () => {
                            olvidarSiSuena(
                                consultaABorrar?.audios.map((a) => a.id) ?? [],
                            );
                            consultaABorrar = null;
                        }
                    "
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar la consulta del
                            {{ consultaABorrar.fechaVisible }}?
                        </DialogTitle>
                        <DialogDescription>
                            Se borra con sus grabaciones. Esto no se puede
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

        <!-- Borrar una grabación -->
        <Dialog
            :open="!!audioABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) audioABorrar = null;
                }
            "
        >
            <DialogContent v-if="audioABorrar">
                <Form
                    v-bind="
                        AdjuntoController.destroy.form({
                            adjunto: audioABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                    @success="
                        () => {
                            olvidarSiSuena(
                                audioABorrar ? [audioABorrar.id] : [],
                            );
                            audioABorrar = null;
                        }
                    "
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>¿Eliminar la grabación?</DialogTitle>
                        <DialogDescription>
                            {{ audioABorrar.nombre }}. Esto no se puede
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
    </div>
</template>
