<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { FileText, Inbox, Send, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import CuentaMailController from '@/actions/App/Http/Controllers/CuentaMailController';
import EnvioController from '@/actions/App/Http/Controllers/EnvioController';
import RecetaController from '@/actions/App/Http/Controllers/RecetaController';
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
import type { DocumentoVisible } from '@/components/VisorDocumento.vue';
import VisorDocumento from '@/components/VisorDocumento.vue';

/*
 * La bandeja de recetas: contesta "¿qué receta puedo usar hoy?".
 *
 * Las disponibles van arriba, ordenadas por la que vence primero, y la
 * separación entre disponibles e historial la hace el SERVIDOR: "vencida"
 * depende de la hora, y con el reloj del celular corrido una receta vencida
 * aparecería como disponible justo en el mostrador. Por eso acá no hay ningún
 * `computed()` que separe listas: llegan ya separadas.
 *
 * El vencimiento se dice con palabras ("vence mañana") y nunca con un color de
 * alarma: es un dato, no un juicio (regla 1).
 */

type Documento = DocumentoVisible & { id: number; tamanio: number };

type Receta = {
    id: number;
    remitente: string;
    asunto: string | null;
    llegoVisible: string;
    venceVisible: string;
    diasParaVencer: number;
    vigencia_dias: number;
    estado: string;
    estadoEtiqueta: string;
    estaVencida: boolean;
    usoVisible: string | null;
    adjuntos: Documento[];
};

const props = defineProps<{
    disponibles: Receta[];
    historial: Receta[];
    mes: { nombre: string; llegaron: number };
    tieneCasilla: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Recetas', href: RecetaController.index() }],
    },
});

const campoUnaLinea =
    'flex min-h-11 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

const recetaAEditar = ref<Receta | null>(null);
const recetaABorrar = ref<Receta | null>(null);
const documentoAbierto = ref<DocumentoVisible | null>(null);

const hayAlguna = () =>
    props.disponibles.length > 0 || props.historial.length > 0;

function cuandoVence(receta: Receta): string {
    if (receta.diasParaVencer <= 0) {
        return 'Vence hoy';
    }

    if (receta.diasParaVencer === 1) {
        return 'Vence mañana';
    }

    return `Vence en ${receta.diasParaVencer} días`;
}

function titulo(receta: Receta): string {
    return receta.asunto && receta.asunto !== '' ? receta.asunto : 'Receta';
}

function pesoLegible(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.round(bytes / 1024)} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
}
</script>

<template>
    <Head title="Recetas" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Recetas"
            description="Las que te llegaron por mail"
        />

        <!--
            El contador es una vista sobre lo importado: cuántas llegaron este
            mes, contadas en el mes de la cuenta y no en el de UTC.
        -->
        <Card v-if="hayAlguna()">
            <CardContent class="space-y-1">
                <p class="font-medium">
                    {{
                        disponibles.length === 1
                            ? 'Tenés 1 receta para usar.'
                            : `Tenés ${disponibles.length} recetas para usar.`
                    }}
                </p>
                <p class="text-sm text-muted-foreground">
                    En {{ mes.nombre }}
                    {{
                        mes.llegaron === 1
                            ? 'llegó 1 receta.'
                            : `llegaron ${mes.llegaron} recetas.`
                    }}
                </p>
            </CardContent>
        </Card>

        <!-- Vacío: sin casilla hay que decir "configurá una", no "no llegó nada". -->
        <div
            v-if="!hayAlguna()"
            class="space-y-4 rounded-lg border border-dashed p-8 text-center"
        >
            <Inbox class="mx-auto size-8 text-muted-foreground" />
            <template v-if="tieneCasilla">
                <p class="text-sm text-muted-foreground">
                    Todavía no llegó ninguna receta. Se buscan solas cada hora
                    en tu casilla.
                </p>
            </template>
            <template v-else>
                <p class="text-sm text-muted-foreground">
                    Para que las recetas lleguen solas, primero configurá la
                    casilla de correo donde te las mandan.
                </p>
                <Button as-child>
                    <Link :href="CuentaMailController.index()">
                        Configurar la casilla
                    </Link>
                </Button>
            </template>
        </div>

        <template
            v-for="grupo in [
                { titulo: 'Para usar', lista: disponibles, abierta: true },
                {
                    titulo: 'Usadas y vencidas',
                    lista: historial,
                    abierta: false,
                },
            ]"
            :key="grupo.titulo"
        >
            <section v-if="grupo.lista.length > 0" class="space-y-3">
                <h2 class="text-sm text-muted-foreground">
                    {{ grupo.titulo }}
                </h2>

                <Card v-for="receta in grupo.lista" :key="receta.id">
                    <CardContent class="space-y-3">
                        <div class="min-w-0 space-y-1">
                            <p class="font-medium break-words">
                                {{ titulo(receta) }}
                            </p>
                            <p class="text-sm break-all text-muted-foreground">
                                De {{ receta.remitente }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Llegó el {{ receta.llegoVisible }}
                            </p>

                            <p v-if="grupo.abierta" class="font-medium">
                                {{ cuandoVence(receta) }} ({{
                                    receta.venceVisible
                                }})
                            </p>
                            <p v-else-if="receta.usoVisible" class="text-sm">
                                Usada el {{ receta.usoVisible }}
                            </p>
                            <p v-else class="text-sm">
                                Venció el {{ receta.venceVisible }}
                            </p>
                        </div>

                        <ul
                            v-if="receta.adjuntos.length > 0"
                            class="grid gap-2"
                        >
                            <li
                                v-for="documento in receta.adjuntos"
                                :key="documento.id"
                            >
                                <button
                                    type="button"
                                    class="flex min-h-11 w-full min-w-0 items-center gap-3 rounded-md border p-2 text-left"
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
                                            {{ pesoLegible(documento.tamanio) }}
                                        </span>
                                    </span>
                                </button>
                            </li>
                        </ul>

                        <div class="flex flex-wrap gap-2">
                            <!-- Marcar o desmarcar el uso: es lo que se toca en el mostrador. -->
                            <Form
                                v-if="grupo.abierta || receta.usoVisible"
                                v-bind="
                                    RecetaController.uso.form({
                                        receta: receta.id,
                                    })
                                "
                                :options="{ preserveScroll: true }"
                                v-slot="{ processing }"
                            >
                                <input
                                    type="hidden"
                                    name="usada"
                                    :value="grupo.abierta ? '1' : '0'"
                                />
                                <Button
                                    type="submit"
                                    :variant="
                                        grupo.abierta ? 'default' : 'secondary'
                                    "
                                    :disabled="processing"
                                >
                                    {{
                                        grupo.abierta
                                            ? 'Ya la usé'
                                            : 'No la usé'
                                    }}
                                </Button>
                            </Form>

                            <!-- Mandársela a la farmacia: el uso que motiva la etapa 13. -->
                            <Button
                                v-if="receta.adjuntos.length > 0"
                                as-child
                                variant="secondary"
                            >
                                <Link
                                    :href="
                                        EnvioController.create({
                                            query: {
                                                adjuntos: receta.adjuntos.map(
                                                    (d) => d.id,
                                                ),
                                            },
                                        })
                                    "
                                >
                                    <Send />
                                    Enviar
                                </Link>
                            </Button>

                            <Button
                                variant="ghost"
                                @click="recetaAEditar = receta"
                            >
                                Cambiar vigencia
                            </Button>

                            <Button
                                variant="ghost"
                                size="icon"
                                class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                :aria-label="`Borrar la receta ${titulo(receta)}`"
                                @click="recetaABorrar = receta"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </section>
        </template>

        <!-- Cambiar la vigencia -->
        <Sheet
            :open="!!recetaAEditar"
            @update:open="
                (v: boolean) => {
                    if (!v) recetaAEditar = null;
                }
            "
        >
            <SheetContent v-if="recetaAEditar">
                <Form
                    v-bind="
                        RecetaController.update.form({
                            receta: recetaAEditar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="recetaAEditar = null"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>Cambiar la vigencia</SheetTitle>
                        <SheetDescription>
                            Se cuenta desde que llegó el mail, el
                            {{ recetaAEditar.llegoVisible }}.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="vigencia">Vale por (días)</Label>
                            <!--
                                `text` con `inputmode="numeric"` y no
                                `type="number"`: es el criterio de toda la app
                                para lo que se tipea con el teclado numérico.
                            -->
                            <input
                                id="vigencia"
                                name="vigencia_dias"
                                type="text"
                                inputmode="numeric"
                                required
                                :value="recetaAEditar.vigencia_dias"
                                :class="campoUnaLinea"
                            />
                            <p class="text-sm text-muted-foreground">
                                Las recetas suelen valer 30 días. Algunas de
                                tratamientos crónicos valen más.
                            </p>
                            <InputError :message="errors.vigencia_dias" />
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
            :open="!!recetaABorrar"
            @update:open="
                (v: boolean) => {
                    if (!v) recetaABorrar = null;
                }
            "
        >
            <DialogContent v-if="recetaABorrar">
                <Form
                    v-bind="
                        RecetaController.destroy.form({
                            receta: recetaABorrar.id,
                        })
                    "
                    :options="{ preserveScroll: true }"
                    @success="recetaABorrar = null"
                    v-slot="{ processing }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>¿Borrar esta receta?</DialogTitle>
                        <DialogDescription>
                            Sale de la bandeja y no se vuelve a importar, aunque
                            el mail siga en tu casilla. Sirve para sacar algo
                            que entró y no era una receta.
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
                            Borrar
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>

        <!-- Uno solo para toda la pantalla, fuera de cualquier v-for. -->
        <VisorDocumento
            :documento="documentoAbierto"
            @cerrar="documentoAbierto = null"
        />
    </div>
</template>
