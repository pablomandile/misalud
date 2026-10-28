<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ArrowLeft, FileText, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import AdjuntoController from '@/actions/App/Http/Controllers/AdjuntoController';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
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

/*
 * Los documentos sueltos de una ficha: lo que no cuelga de una orden, un
 * estudio o una cobertura.
 *
 * Pantalla propia y no un panel lateral: el visor a pantalla completa se abría
 * desde adentro del panel, y dos capas modales una encima de la otra se
 * peleaban el foco y el toque. Acá el visor es lo único que se abre encima.
 */

type Documento = DocumentoVisible & {
    id: number;
    titulo: string | null;
    descripcion: string | null;
    archivo: string;
    tamanio: number;
    tipo: string;
};

defineProps<{
    paciente: { id: number; nombre: string; puedeEditar: boolean };
    documentos: Documento[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pacientes', href: PacienteController.index() },
            { title: 'Documentos', href: '' },
        ],
    },
});

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

/** UNO SOLO para toda la pantalla, y fuera de todo v-for. */
const documentoAbierto = ref<DocumentoVisible | null>(null);
const documentoAEditar = ref<Documento | null>(null);

function pesoLegible(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.round(bytes / 1024)} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
}
</script>

<template>
    <Head :title="`Documentos de ${paciente.nombre}`" />

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="`Documentos de ${paciente.nombre}`"
            description="Estudios, recetas y lo que haga falta guardar. Se guardan cifrados. Tocá uno para verlo."
        />

        <div
            v-if="documentos.length === 0"
            class="rounded-lg border border-dashed p-8 text-center"
        >
            <FileText class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 text-sm text-muted-foreground">
                Todavía no hay documentos cargados.
            </p>
        </div>

        <ul v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <li v-for="documento in documentos" :key="documento.id">
                <Card class="h-full py-0">
                    <CardContent class="flex h-full flex-col gap-2 p-3">
                        <!-- Tocar el documento lo abre a pantalla completa. -->
                        <button
                            type="button"
                            class="flex min-h-11 min-w-0 flex-1 items-start gap-3 rounded-md text-left focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            @click="documentoAbierto = documento"
                        >
                            <FileText
                                class="mt-0.5 size-6 shrink-0 text-muted-foreground"
                            />
                            <span class="min-w-0 space-y-0.5">
                                <span class="block font-medium wrap-break-word">
                                    {{ documento.nombre }}
                                </span>
                                <span
                                    v-if="documento.descripcion"
                                    class="block text-sm wrap-break-word whitespace-pre-line"
                                >
                                    {{ documento.descripcion }}
                                </span>
                                <span
                                    class="block text-sm text-muted-foreground"
                                >
                                    {{ documento.tipo }} ·
                                    {{ pesoLegible(documento.tamanio) }}
                                </span>
                                <span
                                    v-if="documento.titulo"
                                    class="block text-sm break-all text-muted-foreground"
                                >
                                    {{ documento.archivo }}
                                </span>
                            </span>
                        </button>

                        <div
                            v-if="paciente.puedeEditar"
                            class="flex flex-wrap justify-end gap-1"
                        >
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="documentoAEditar = documento"
                            >
                                Editar
                            </Button>

                            <Form
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
                                    size="sm"
                                    class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    :disabled="processing"
                                    :aria-label="`Eliminar ${documento.nombre}`"
                                    @click="documentoAbierto = null"
                                >
                                    <Trash2 />
                                    Eliminar
                                </Button>
                            </Form>
                        </div>
                    </CardContent>
                </Card>
            </li>
        </ul>

        <!-- Subir -->
        <Card v-if="paciente.puedeEditar">
            <CardContent>
                <Form
                    v-bind="
                        AdjuntoController.store.form({
                            paciente: paciente.id,
                        })
                    "
                    reset-on-success
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="space-y-4"
                >
                    <h2 class="font-medium">Subir documentos</h2>

                    <div class="grid gap-2">
                        <Label for="tipo-adjunto">Tipo de documento</Label>
                        <select
                            id="tipo-adjunto"
                            name="tipo"
                            :class="campoUnaLinea"
                        >
                            <option
                                value="otro"
                                class="bg-background text-foreground"
                            >
                                Documento
                            </option>
                            <option
                                value="informe_estudio"
                                class="bg-background text-foreground"
                            >
                                Informe
                            </option>
                            <option
                                value="imagen_estudio"
                                class="bg-background text-foreground"
                            >
                                Imagen del estudio
                            </option>
                            <option
                                value="orden_estudio"
                                class="bg-background text-foreground"
                            >
                                Orden de estudio
                            </option>
                            <option
                                value="receta"
                                class="bg-background text-foreground"
                            >
                                Receta
                            </option>
                            <option
                                value="credencial"
                                class="bg-background text-foreground"
                            >
                                Credencial
                            </option>
                        </select>
                        <InputError :message="errors.tipo" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="titulo-adjunto">Nombre (opcional)</Label>
                        <input
                            id="titulo-adjunto"
                            name="titulo"
                            type="text"
                            placeholder="Por ejemplo: Análisis de marzo"
                            aria-describedby="titulo-adjunto-ayuda"
                            :class="campoUnaLinea"
                        />
                        <p
                            id="titulo-adjunto-ayuda"
                            class="text-sm text-muted-foreground"
                        >
                            Si lo dejás vacío, se ve el nombre del archivo.
                        </p>
                        <InputError :message="errors.titulo" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="descripcion-adjunto">
                            Descripción (opcional)
                        </Label>
                        <textarea
                            id="descripcion-adjunto"
                            name="descripcion"
                            rows="2"
                            :class="campoTexto"
                        ></textarea>
                        <InputError :message="errors.descripcion" />
                    </div>

                    <SubirArchivo
                        name="archivos[]"
                        multiple
                        etiqueta="Elegir documentos"
                        :error="errors['archivos.0'] ?? errors.archivos"
                    />

                    <Button
                        type="submit"
                        class="w-full sm:w-auto"
                        :disabled="processing"
                    >
                        Subir
                    </Button>
                </Form>
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

        <!--
            Editar el nombre y la descripción de un documento ya subido. Va por
            PUT y sin archivo: el archivo no se toca.
        -->
        <Dialog
            :open="!!documentoAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) documentoAEditar = null;
                }
            "
        >
            <DialogContent v-if="documentoAEditar">
                <Form
                    v-bind="
                        AdjuntoController.update.form({
                            adjunto: documentoAEditar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="space-y-4"
                    @success="documentoAEditar = null"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>Nombre y descripción</DialogTitle>
                        <DialogDescription class="break-all">
                            {{ documentoAEditar.archivo }}
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="titulo-editar">Nombre</Label>
                        <input
                            id="titulo-editar"
                            name="titulo"
                            type="text"
                            :value="documentoAEditar.titulo ?? ''"
                            :class="campoUnaLinea"
                        />
                        <InputError :message="errors.titulo" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="descripcion-editar">Descripción</Label>
                        <textarea
                            id="descripcion-editar"
                            name="descripcion"
                            rows="3"
                            :class="campoTexto"
                            >{{ documentoAEditar.descripcion ?? '' }}</textarea>
                        <InputError :message="errors.descripcion" />
                    </div>

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" :disabled="processing">
                            Guardar
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>

        <!-- El visor a pantalla completa, con su X. -->
        <VisorDocumento
            :documento="documentoAbierto"
            :puede-girar="paciente.puedeEditar"
            @cerrar="documentoAbierto = null"
        />
    </div>
</template>
