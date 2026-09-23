<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { FileText, Plus, Trash2, UserRound } from '@lucide/vue';
import { ref } from 'vue';
import AdjuntoController from '@/actions/App/Http/Controllers/AdjuntoController';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
import Heading from '@/components/Heading.vue';
import SubirArchivo from '@/components/SubirArchivo.vue';
import type { DocumentoVisible } from '@/components/VisorDocumento.vue';
import VisorDocumento from '@/components/VisorDocumento.vue';
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

type DocumentoDePaciente = DocumentoVisible & {
    id: number;
    tamanio: number;
    tipo: string;
};

type Paciente = {
    id: number;
    nombre: string;
    fecha_nacimiento: string | null;
    edad: number | null;
    sexo: string | null;
    grupo_sanguineo: string | null;
    notas: string | null;
    puedeEditar: boolean;
    esPropietario: boolean;
    adjuntos: DocumentoDePaciente[];
};

defineProps<{ pacientes: Paciente[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Pacientes', href: PacienteController.index() }],
    },
});

/*
 * <select> y <textarea> no tienen primitiva en ui/, así que comparten esta
 * cadena, calcada del <Input> de ui/input.
 *
 * El alto va en una constante aparte y NO adentro de `campoBase`: antes se
 * derivaba con `campoBase.replace('h-9', 'h-auto')` para el textarea, y ese
 * replace se vuelve silenciosamente inofensivo en cuanto alguien toca el alto
 * -no falla, no avisa, simplemente deja de hacer nada-.
 *
 * `min-h-11` son los 44 px de área táctil mínima del proyecto (ver
 * ui/button/index.ts), en rem para que escalen con el tamaño de letra.
 */
const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

const sheetCrearAbierto = ref(false);
const pacienteAEditar = ref<Paciente | null>(null);
const pacienteABorrar = ref<Paciente | null>(null);
const pacienteDeDocumentos = ref<Paciente | null>(null);

/*
 * UN SOLO documento abierto para toda la pantalla, y un solo <VisorDocumento>
 * al final del template -fuera de cualquier v-for-. Uno por archivo
 * multiplicaría los overlays y los focus traps de reka-ui por la cantidad de
 * documentos de la lista.
 */
const documentoAbierto = ref<DocumentoVisible | null>(null);

function pesoLegible(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.round(bytes / 1024)} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
}

function edadTexto(p: Paciente): string {
    return p.edad === null ? '' : `${p.edad} años`;
}
</script>

<template>
    <Head title="Pacientes" />

    <div class="space-y-6">
        <div class="flex items-center justify-between gap-4">
            <Heading
                variant="small"
                title="Pacientes"
                description="Vos y quien más administrés: cada uno tiene su propia historia clínica"
            />
            <Button @click="sheetCrearAbierto = true">
                <Plus />
                Agregar
            </Button>
        </div>

        <!--
            Tarjetas apiladas y no una tabla: en 320px una tabla con varias
            columnas fuerza scroll horizontal. Ver skill adaptar-ui-mobile.
        -->
        <div
            v-if="pacientes.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <UserRound class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no agregaste ningún paciente.
            </p>
        </div>

        <div v-else class="grid gap-3 sm:grid-cols-2">
            <Card v-for="paciente in pacientes" :key="paciente.id">
                <CardContent class="flex items-start justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                        <p class="truncate font-medium">
                            {{ paciente.nombre }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            <span v-if="paciente.edad !== null">{{
                                edadTexto(paciente)
                            }}</span>
                            <span
                                v-if="paciente.edad !== null && paciente.sexo"
                            >
                                ·
                            </span>
                            <span v-if="paciente.sexo" class="capitalize">{{
                                paciente.sexo
                            }}</span>
                        </p>
                        <p
                            v-if="paciente.grupo_sanguineo"
                            class="text-sm text-muted-foreground"
                        >
                            Grupo {{ paciente.grupo_sanguineo }}
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-wrap justify-end gap-1">
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="pacienteDeDocumentos = paciente"
                        >
                            <FileText />
                            {{ paciente.adjuntos.length || '' }}
                            <span class="sr-only">
                                Documentos de {{ paciente.nombre }}
                            </span>
                        </Button>
                        <Button
                            v-if="paciente.puedeEditar"
                            variant="ghost"
                            size="sm"
                            @click="pacienteAEditar = paciente"
                        >
                            Editar
                        </Button>
                        <Button
                            v-if="paciente.esPropietario"
                            variant="ghost"
                            size="icon-sm"
                            class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                            @click="pacienteABorrar = paciente"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Crear -->
        <Sheet v-model:open="sheetCrearAbierto">
            <SheetContent>
                <Form
                    v-bind="PacienteController.store.form()"
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    @success="sheetCrearAbierto = false"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>Agregar un paciente</SheetTitle>
                        <SheetDescription>
                            Vos mismo, o un familiar que administrés.
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
                            <Label for="fecha_nacimiento-crear">
                                Fecha de nacimiento
                            </Label>
                            <input
                                id="fecha_nacimiento-crear"
                                name="fecha_nacimiento"
                                type="date"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.fecha_nacimiento" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="sexo-crear">Sexo</Label>
                            <select
                                id="sexo-crear"
                                name="sexo"
                                :class="campoUnaLinea"
                            >
                                <option value="">Sin especificar</option>
                                <option value="femenino">Femenino</option>
                                <option value="masculino">Masculino</option>
                                <option value="otro">Otro</option>
                            </select>
                            <InputError :message="errors.sexo" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="grupo_sanguineo-crear">
                                Grupo sanguíneo
                            </Label>
                            <input
                                id="grupo_sanguineo-crear"
                                name="grupo_sanguineo"
                                type="text"
                                placeholder="Por ejemplo: O+"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.grupo_sanguineo" />
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
            :open="!!pacienteAEditar"
            @update:open="
                (v) => {
                    if (!v) pacienteAEditar = null;
                }
            "
        >
            <SheetContent v-if="pacienteAEditar">
                <Form
                    v-bind="
                        PacienteController.update.form({
                            paciente: pacienteAEditar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="pacienteAEditar = null"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle
                            >Editar a {{ pacienteAEditar.nombre }}</SheetTitle
                        >
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="nombre-editar">Nombre</Label>
                            <input
                                id="nombre-editar"
                                name="nombre"
                                type="text"
                                required
                                :value="pacienteAEditar.nombre"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.nombre" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="fecha_nacimiento-editar">
                                Fecha de nacimiento
                            </Label>
                            <input
                                id="fecha_nacimiento-editar"
                                name="fecha_nacimiento"
                                type="date"
                                :value="pacienteAEditar.fecha_nacimiento"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.fecha_nacimiento" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="sexo-editar">Sexo</Label>
                            <select
                                id="sexo-editar"
                                name="sexo"
                                :value="pacienteAEditar.sexo ?? ''"
                                :class="campoUnaLinea"
                            >
                                <option value="">Sin especificar</option>
                                <option value="femenino">Femenino</option>
                                <option value="masculino">Masculino</option>
                                <option value="otro">Otro</option>
                            </select>
                            <InputError :message="errors.sexo" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="grupo_sanguineo-editar">
                                Grupo sanguíneo
                            </Label>
                            <input
                                id="grupo_sanguineo-editar"
                                name="grupo_sanguineo"
                                type="text"
                                :value="pacienteAEditar.grupo_sanguineo"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.grupo_sanguineo" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="notas-editar">Notas</Label>
                            <textarea
                                id="notas-editar"
                                name="notas"
                                rows="3"
                                :class="campoTexto"
                                >{{ pacienteAEditar.notas }}</textarea>
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

        <!-- Documentos -->
        <Sheet
            :open="!!pacienteDeDocumentos"
            @update:open="
                (v) => {
                    if (!v) pacienteDeDocumentos = null;
                }
            "
        >
            <SheetContent v-if="pacienteDeDocumentos">
                <SheetHeader>
                    <SheetTitle>
                        Documentos de {{ pacienteDeDocumentos.nombre }}
                    </SheetTitle>
                    <SheetDescription>
                        Estudios, recetas y lo que haga falta guardar. Se
                        guardan cifrados.
                    </SheetDescription>
                </SheetHeader>

                <div class="flex-1 space-y-6 overflow-y-auto px-4">
                    <ul
                        v-if="pacienteDeDocumentos.adjuntos.length > 0"
                        class="grid gap-2"
                    >
                        <li
                            v-for="documento in pacienteDeDocumentos.adjuntos"
                            :key="documento.id"
                            class="flex items-center gap-2 rounded-md border p-2"
                        >
                            <!--
                                Abre el visor de la pantalla; no monta uno
                                propio.
                            -->
                            <button
                                type="button"
                                class="flex min-h-11 min-w-0 flex-1 items-center gap-3 text-left"
                                @click="documentoAbierto = documento"
                            >
                                <FileText
                                    class="size-5 shrink-0 text-muted-foreground"
                                />
                                <span class="min-w-0">
                                    <span class="block truncate text-sm">
                                        {{ documento.nombre }}
                                    </span>
                                    <span
                                        class="block text-sm text-muted-foreground"
                                    >
                                        {{ documento.tipo }} ·
                                        {{ pesoLegible(documento.tamanio) }}
                                    </span>
                                </span>
                            </button>

                            <Form
                                v-if="pacienteDeDocumentos.puedeEditar"
                                v-bind="
                                    AdjuntoController.destroy.form({
                                        adjunto: documento.id,
                                    })
                                "
                                :options="{ preserveScroll: true }"
                                v-slot="{ processing }"
                            >
                                <!--
                                    Se cierra el visor ANTES de borrar, o queda
                                    mostrando un archivo que ya da 404.
                                -->
                                <Button
                                    type="submit"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    :disabled="processing"
                                    :aria-label="`Eliminar ${documento.nombre}`"
                                    @click="documentoAbierto = null"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </Form>
                        </li>
                    </ul>

                    <p v-else class="text-sm text-muted-foreground">
                        Todavía no hay documentos cargados.
                    </p>

                    <Form
                        v-if="pacienteDeDocumentos.puedeEditar"
                        v-bind="
                            AdjuntoController.store.form({
                                paciente: pacienteDeDocumentos.id,
                            })
                        "
                        reset-on-success
                        :options="{ preserveScroll: true }"
                        v-slot="{ errors, processing }"
                        class="space-y-4 border-t pt-6"
                    >
                        <div class="grid gap-2">
                            <Label for="tipo-adjunto">Tipo de documento</Label>
                            <select
                                id="tipo-adjunto"
                                name="tipo"
                                :class="campoUnaLinea"
                            >
                                <option value="otro">Documento</option>
                                <option value="informe_estudio">Informe</option>
                                <option value="imagen_estudio">
                                    Imagen del estudio
                                </option>
                                <option value="orden_estudio">
                                    Orden de estudio
                                </option>
                                <option value="receta">Receta</option>
                                <option value="credencial">Credencial</option>
                            </select>
                            <InputError :message="errors.tipo" />
                        </div>

                        <SubirArchivo
                            name="archivos[]"
                            multiple
                            etiqueta="Elegir documentos"
                            :error="errors['archivos.0'] ?? errors.archivos"
                        />

                        <Button
                            type="submit"
                            class="w-full"
                            :disabled="processing"
                        >
                            Subir
                        </Button>
                    </Form>
                </div>
            </SheetContent>
        </Sheet>

        <!-- Borrar -->
        <Dialog
            :open="!!pacienteABorrar"
            @update:open="
                (v) => {
                    if (!v) pacienteABorrar = null;
                }
            "
        >
            <DialogContent v-if="pacienteABorrar">
                <Form
                    v-bind="
                        PacienteController.destroy.form({
                            paciente: pacienteABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="pacienteABorrar = null"
                    v-slot="{ processing }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            ¿Eliminar a {{ pacienteABorrar.nombre }}?
                        </DialogTitle>
                        <DialogDescription>
                            Se borra toda su historia clínica junto con el
                            paciente. Esto no se puede deshacer.
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
            UNO SOLO para toda la pantalla, fuera de todo v-for. Ver el
            comentario de `documentoAbierto`.
        -->
        <VisorDocumento
            :documento="documentoAbierto"
            @cerrar="documentoAbierto = null"
        />
    </div>
</template>
