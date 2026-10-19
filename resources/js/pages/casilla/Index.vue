<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Copy, FileText, Inbox, Mail, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import CasillaController from '@/actions/App/Http/Controllers/CasillaController';
import DocumentoPorRevisarController from '@/actions/App/Http/Controllers/DocumentoPorRevisarController';
import PacienteController from '@/actions/App/Http/Controllers/PacienteController';
import RecetaController from '@/actions/App/Http/Controllers/RecetaController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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
 * La casilla: a qué dirección reenviar las recetas, órdenes y estudios, cómo
 * escribir el asunto para que se guarden solos, y lo que llegó y quedó para
 * revisar.
 *
 * Ya no se configura nada: el buzón es uno solo, el de la app.
 */

type Documento = DocumentoVisible & { id: number; tamanio: number };

type PorRevisar = {
    id: number;
    remitente: string;
    asunto: string | null;
    motivo: string;
    tipoSugerido: string | null;
    pacienteId: number | null;
    pacienteNombre: string | null;
    fechaVisible: string;
    adjuntos: Documento[];
};

const props = defineProps<{
    direccion: string | null;
    maximoMb: number;
    tuCorreo: string | null;
    fichas: Array<{ id: number; nombre: string; email: string | null }>;
    tipos: Array<{ valor: string; etiqueta: string }>;
    porRevisar: PorRevisar[];
    hayRecetasAnteriores: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Casilla de recetas', href: CasillaController.index() },
        ],
    },
});

const campoUnaLinea =
    'flex min-h-11 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

const ejemplos = ['Receta Enalapril', 'Orden Ecografía', 'Estudio Laboratorio'];

const aGuardar = ref<PorRevisar | null>(null);
const aDescartar = ref<PorRevisar | null>(null);
const documentoAbierto = ref<DocumentoVisible | null>(null);

async function copiarDireccion(): Promise<void> {
    if (!props.direccion) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.direccion);
        toast.success('Se copió la dirección.');
    } catch {
        toast.error('No se pudo copiar. Anotala a mano.', {
            duration: Infinity,
        });
    }
}

function pesoLegible(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.round(bytes / 1024)} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
}
</script>

<template>
    <Head title="Casilla de recetas" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Mandá tus recetas, órdenes y estudios por mail"
            :description="`En PDF, JPG o PNG, de hasta ${maximoMb} MB cada uno. Se guardan solos en la ficha del paciente.`"
        />

        <!-- La dirección: lo único que hay que anotar. -->
        <Card>
            <CardContent class="space-y-3">
                <p class="text-sm text-muted-foreground">Reenvialos a</p>
                <template v-if="direccion">
                    <p
                        class="font-mono text-lg font-semibold break-all sm:text-xl"
                    >
                        {{ direccion }}
                    </p>
                    <Button variant="outline" @click="copiarDireccion()">
                        <Copy />
                        Copiar la dirección
                    </Button>
                </template>
                <p v-else class="text-sm">
                    El buzón todavía no está configurado. Volvé a mirar en un
                    rato.
                </p>
            </CardContent>
        </Card>

        <!-- Cómo funciona -->
        <Card>
            <CardContent class="space-y-4">
                <h2 class="font-medium">Cómo funciona</h2>

                <ol class="list-decimal space-y-3 pl-5 text-sm">
                    <li>
                        Mandá el mail <strong>desde tu correo</strong> o desde
                        el correo cargado en la ficha del paciente, con el
                        documento adjunto.
                    </li>
                    <li>
                        En el asunto poné <strong>Receta</strong>,
                        <strong>Orden</strong> o <strong>Estudio</strong>, y si
                        querés, qué es. Por ejemplo:
                        <span class="mt-2 flex flex-wrap gap-2">
                            <span
                                v-for="ejemplo in ejemplos"
                                :key="ejemplo"
                                class="rounded-md bg-muted px-2 py-1 font-mono"
                            >
                                {{ ejemplo }}
                            </span>
                        </span>
                    </li>
                    <li v-if="fichas.length > 1">
                        Como administrás varias fichas, agregá el nombre del
                        paciente:
                        <span
                            class="rounded-md bg-muted px-2 py-1 font-mono whitespace-nowrap"
                        >
                            Receta {{ fichas[0].nombre.split(' ')[0] }}
                        </span>
                    </li>
                    <li>
                        Lo guardamos en Recetas, Órdenes o Estudios de esa ficha
                        y te contestamos para avisarte.
                    </li>
                </ol>

                <div class="space-y-2 rounded-md bg-muted/60 p-3 text-sm">
                    <p class="font-medium">Queda para revisar acá abajo si…</p>
                    <ul class="list-disc space-y-1 pl-5">
                        <li>
                            el asunto no dice si es una receta, una orden o un
                            estudio;
                        </li>
                        <li>no supimos de cuál de tus fichas es;</li>
                        <li>
                            no pudimos confirmar que el mail viniera de verdad
                            de esa dirección.
                        </li>
                    </ul>
                    <p class="text-muted-foreground">
                        Un mail de una dirección que no conocemos no se guarda
                        en ningún lado.
                    </p>
                </div>
            </CardContent>
        </Card>

        <!-- Desde qué correos -->
        <Card>
            <CardContent class="space-y-3">
                <h2 class="font-medium">Desde qué correos te reconocemos</h2>
                <ul class="space-y-2 text-sm">
                    <li v-if="tuCorreo" class="flex items-start gap-2">
                        <Mail
                            class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        />
                        <span class="min-w-0 break-all">
                            {{ tuCorreo }}
                            <span class="text-muted-foreground">
                                (tu cuenta, para cualquiera de tus fichas)
                            </span>
                        </span>
                    </li>
                    <li
                        v-for="ficha in fichas"
                        :key="ficha.id"
                        class="flex items-start gap-2"
                    >
                        <Mail
                            class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        />
                        <span class="min-w-0 break-all">
                            <template v-if="ficha.email">
                                {{ ficha.email }}
                                <span class="text-muted-foreground">
                                    (de {{ ficha.nombre }})
                                </span>
                            </template>
                            <template v-else>
                                <span class="text-muted-foreground">
                                    {{ ficha.nombre }} no tiene correo cargado.
                                </span>
                            </template>
                        </span>
                    </li>
                </ul>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="PacienteController.index()">
                        Cargar el correo de una ficha
                    </Link>
                </Button>
            </CardContent>
        </Card>

        <!-- Por revisar -->
        <section class="space-y-3">
            <h2 class="font-medium">Por revisar</h2>

            <div
                v-if="porRevisar.length === 0"
                class="rounded-lg border border-dashed p-6 text-center"
            >
                <Inbox class="mx-auto size-8 text-muted-foreground" />
                <p class="mt-3 text-sm text-muted-foreground">
                    No hay nada para revisar.
                </p>
            </div>

            <Card v-for="documento in porRevisar" :key="documento.id">
                <CardContent class="space-y-3">
                    <div class="min-w-0 space-y-1">
                        <p class="font-medium wrap-break-word">
                            {{ documento.asunto || 'Sin asunto' }}
                        </p>
                        <p class="text-sm break-all text-muted-foreground">
                            De {{ documento.remitente }} ·
                            {{ documento.fechaVisible }}
                        </p>
                        <p
                            v-if="documento.pacienteNombre"
                            class="text-sm text-muted-foreground"
                        >
                            Para la ficha de {{ documento.pacienteNombre }}
                        </p>
                        <p class="text-sm">{{ documento.motivo }}</p>
                    </div>

                    <ul class="grid gap-2">
                        <li
                            v-for="archivo in documento.adjuntos"
                            :key="archivo.id"
                        >
                            <button
                                type="button"
                                class="flex min-h-11 w-full min-w-0 items-center gap-3 rounded-md border p-2 text-left"
                                @click="documentoAbierto = archivo"
                            >
                                <FileText
                                    class="size-5 shrink-0 text-muted-foreground"
                                />
                                <span class="min-w-0">
                                    <span class="block truncate text-sm">
                                        {{ archivo.nombre }}
                                    </span>
                                    <span
                                        class="block text-sm text-muted-foreground"
                                    >
                                        {{ pesoLegible(archivo.tamanio) }}
                                    </span>
                                </span>
                            </button>
                        </li>
                    </ul>

                    <div class="flex flex-wrap gap-2">
                        <Button
                            :disabled="fichas.length === 0"
                            @click="aGuardar = documento"
                        >
                            Guardar en una ficha
                        </Button>
                        <Button
                            variant="ghost"
                            class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                            @click="aDescartar = documento"
                        >
                            <Trash2 />
                            Descartar
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </section>

        <p v-if="hayRecetasAnteriores" class="text-sm">
            <Link
                :href="RecetaController.index()"
                class="inline-flex min-h-11 items-center underline underline-offset-4"
            >
                Ver las recetas que llegaron con la casilla de antes
            </Link>
        </p>

        <!-- Guardar en una ficha -->
        <Sheet
            :open="!!aGuardar"
            @update:open="
                (v: boolean) => {
                    if (!v) aGuardar = null;
                }
            "
        >
            <SheetContent v-if="aGuardar">
                <Form
                    v-bind="
                        DocumentoPorRevisarController.guardar.form({
                            documento: aGuardar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                    @success="aGuardar = null"
                >
                    <SheetHeader>
                        <SheetTitle>Guardar en una ficha</SheetTitle>
                        <SheetDescription>
                            {{ aGuardar.asunto || 'Sin asunto' }}
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="revisar-paciente">Paciente</Label>
                            <select
                                id="revisar-paciente"
                                name="paciente_id"
                                required
                                :value="
                                    aGuardar.pacienteId ??
                                    (fichas.length === 1 ? fichas[0].id : '')
                                "
                                :class="campoUnaLinea"
                            >
                                <option
                                    value=""
                                    disabled
                                    class="bg-background text-foreground"
                                >
                                    Elegí una ficha
                                </option>
                                <option
                                    v-for="ficha in fichas"
                                    :key="ficha.id"
                                    :value="ficha.id"
                                    class="bg-background text-foreground"
                                >
                                    {{ ficha.nombre }}
                                </option>
                            </select>
                            <InputError :message="errors.paciente_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="revisar-tipo">Qué es</Label>
                            <select
                                id="revisar-tipo"
                                name="tipo"
                                required
                                :value="aGuardar.tipoSugerido ?? ''"
                                :class="campoUnaLinea"
                            >
                                <option
                                    value=""
                                    disabled
                                    class="bg-background text-foreground"
                                >
                                    Elegí qué es
                                </option>
                                <option
                                    v-for="tipo in tipos"
                                    :key="tipo.valor"
                                    :value="tipo.valor"
                                    class="bg-background text-foreground"
                                >
                                    {{ tipo.etiqueta }}
                                </option>
                            </select>
                            <InputError :message="errors.tipo" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="revisar-texto">Descripción</Label>
                            <input
                                id="revisar-texto"
                                name="texto"
                                type="text"
                                placeholder="Por ejemplo: Enalapril 10 mg"
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.texto" />
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

        <!-- Descartar -->
        <Dialog
            :open="!!aDescartar"
            @update:open="
                (v: boolean) => {
                    if (!v) aDescartar = null;
                }
            "
        >
            <DialogContent v-if="aDescartar">
                <Form
                    v-bind="
                        DocumentoPorRevisarController.destroy.form({
                            documento: aDescartar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                    @success="aDescartar = null"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>¿Descartar este documento?</DialogTitle>
                        <DialogDescription>
                            Se borra con sus archivos y no se puede recuperar.
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
                            Descartar
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>

        <!-- UNO SOLO para toda la pantalla, fuera de todo v-for. -->
        <VisorDocumento
            :documento="documentoAbierto"
            @cerrar="documentoAbierto = null"
        />
    </div>
</template>
