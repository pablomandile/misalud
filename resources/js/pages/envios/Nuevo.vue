<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Eye, Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ContactoController from '@/actions/App/Http/Controllers/ContactoController';
import EnvioController from '@/actions/App/Http/Controllers/EnvioController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
 * Armar un envío: a quién, qué documentos y con qué mensaje.
 *
 * ⚠️ TODOS los campos tienen estado local (`v-model`), no solo los que lo
 * necesitan. La lista de documentos lo necesita para sumar el tamaño en vivo, y
 * en cuanto un campo de un componente maneja estado propio, cada cambio vuelve
 * a renderizar y pisa los `:value` de los hermanos con el valor del prop: el
 * asunto o el mensaje ya tipeados volverían a lo que vino del servidor. Es la
 * regla que dejó la Etapa 10 (ver "Salud ocular" en CLAUDE.md).
 *
 * ⚠️ El destinatario NO viene elegido, ni siquiera cuando hay uno solo. Mandar
 * documentos clínicos a alguien tiene que ser una elección hecha a propósito, y
 * el botón final repite el nombre para que se lea antes de tocarlo.
 */

type Documento = DocumentoVisible & {
    id: number;
    descripcion: string;
    tamanio: number;
    elegido: boolean;
};

type Contacto = {
    id: number;
    nombre: string;
    email: string;
    tipoEtiqueta: string;
};

const props = defineProps<{
    documentos: Documento[];
    contactos: Contacto[];
    pacienteNombre: string | null;
    asuntoSugerido: string;
    maximoBytes: number;
    tipos: Array<{ valor: string; etiqueta: string }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Contactos', href: ContactoController.index() },
            { title: 'Mandar documentos', href: '' },
        ],
    },
});

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;
const campoTexto = `${campoBase} min-h-24`;

const contactoId = ref<number | null>(null);
const elegidos = ref<number[]>(
    props.documentos.filter((d) => d.elegido).map((d) => d.id),
);
const asunto = ref(props.asuntoSugerido);
const mensaje = ref('');

const sheetContactoAbierto = ref(false);
const documentoAbierto = ref<DocumentoVisible | null>(null);

/*
 * Un contacto recién cargado desde acá queda elegido: es para eso que se lo
 * cargó. Se lo reconoce porque su id no estaba antes.
 */
watch(
    () => props.contactos.map((c) => c.id),
    (ahora, antes) => {
        const nuevo = ahora.find((id) => !antes.includes(id));

        if (nuevo !== undefined) {
            contactoId.value = nuevo;
        }
    },
);

const contactoElegido = computed(
    () => props.contactos.find((c) => c.id === contactoId.value) ?? null,
);

const pesoElegido = computed(() =>
    props.documentos
        .filter((d) => elegidos.value.includes(d.id))
        .reduce((total, d) => total + d.tamanio, 0),
);

const pasaDelTope = computed(() => pesoElegido.value > props.maximoBytes);

const textoBoton = computed(() => {
    const cuantos = elegidos.value.length;
    const que = cuantos === 1 ? '1 documento' : `${cuantos} documentos`;

    return contactoElegido.value
        ? `Mandar ${que} a ${contactoElegido.value.nombre}`
        : `Mandar ${que}`;
});

function megas(bytes: number): string {
    return (bytes / (1024 * 1024)).toFixed(1).replace('.', ',');
}

function pesoLegible(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.round(bytes / 1024)} KB`
        : `${megas(bytes)} MB`;
}
</script>

<template>
    <Head title="Mandar documentos" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Mandar documentos"
            :description="
                pacienteNombre
                    ? `Documentación de ${pacienteNombre}`
                    : 'Por mail, a alguien de tu libreta'
            "
        />

        <Form
            v-bind="EnvioController.store.form()"
            :options="{ preserveScroll: true }"
            v-slot="{ errors, processing }"
            class="space-y-8"
        >
            <!-- 1. A quién -->
            <section class="space-y-3">
                <h2 class="font-medium">¿A quién?</h2>

                <p
                    v-if="contactos.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Todavía no tenés a nadie en tu libreta. Agregá a quién se lo
                    querés mandar.
                </p>

                <div v-else class="grid gap-2">
                    <label
                        v-for="contacto in contactos"
                        :key="contacto.id"
                        class="flex min-h-11 cursor-pointer items-start gap-3 rounded-md border p-3 has-[:checked]:border-primary"
                    >
                        <input
                            v-model="contactoId"
                            type="radio"
                            name="contacto_id"
                            :value="contacto.id"
                            class="mt-1 size-5 shrink-0"
                        />
                        <span class="min-w-0">
                            <span class="block font-medium break-words">
                                {{ contacto.nombre }}
                            </span>
                            <span class="block text-sm text-muted-foreground">
                                {{ contacto.tipoEtiqueta }}
                            </span>
                            <!-- La dirección completa: es lo que hay que revisar. -->
                            <span class="block text-sm break-all">
                                {{ contacto.email }}
                            </span>
                        </span>
                    </label>
                </div>

                <Button
                    type="button"
                    variant="secondary"
                    @click="sheetContactoAbierto = true"
                >
                    <Plus />
                    Agregar a alguien
                </Button>
                <InputError :message="errors.contacto_id" />
            </section>

            <!-- 2. Qué -->
            <section class="space-y-3">
                <h2 class="font-medium">¿Qué documentos?</h2>

                <ul class="grid gap-2">
                    <li
                        v-for="documento in documentos"
                        :key="documento.id"
                        class="flex items-center gap-2 rounded-md border p-2"
                    >
                        <label
                            class="flex min-h-11 min-w-0 flex-1 cursor-pointer items-center gap-3"
                        >
                            <input
                                v-model="elegidos"
                                type="checkbox"
                                name="adjuntos[]"
                                :value="documento.id"
                                class="size-5 shrink-0"
                            />
                            <span class="min-w-0">
                                <span
                                    class="block text-sm font-medium break-words"
                                >
                                    {{ documento.descripcion }}
                                </span>
                                <span
                                    class="block truncate text-sm text-muted-foreground"
                                >
                                    {{ documento.nombre }} ·
                                    {{ pesoLegible(documento.tamanio) }}
                                </span>
                            </span>
                        </label>
                        <!-- Fuera del label: tocarlo abre el documento, no lo tilda. -->
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :aria-label="`Ver ${documento.descripcion}`"
                            @click="documentoAbierto = documento"
                        >
                            <Eye class="size-4" />
                        </Button>
                    </li>
                </ul>

                <p
                    class="text-sm"
                    :class="
                        pasaDelTope ? 'font-medium' : 'text-muted-foreground'
                    "
                >
                    Suman {{ megas(pesoElegido) }} MB de
                    {{ megas(maximoBytes) }} MB que entran en un mail.
                    <template v-if="pasaDelTope">
                        Sacá alguno y mandalo en otro envío.
                    </template>
                </p>
                <InputError :message="errors.adjuntos" />
            </section>

            <!-- 3. Con qué mensaje -->
            <section class="space-y-4">
                <div class="grid gap-2">
                    <Label for="asunto">Asunto</Label>
                    <input
                        id="asunto"
                        v-model="asunto"
                        name="asunto"
                        type="text"
                        required
                        :class="campoUnaLinea"
                    />
                    <InputError :message="errors.asunto" />
                </div>

                <div class="grid gap-2">
                    <Label for="mensaje">Mensaje (opcional)</Label>
                    <textarea
                        id="mensaje"
                        v-model="mensaje"
                        name="mensaje"
                        rows="4"
                        placeholder="Por ejemplo: les mando la orden para autorizar."
                        :class="campoTexto"
                    ></textarea>
                    <p class="text-sm text-muted-foreground">
                        Si te contestan, la respuesta te llega a vos.
                    </p>
                    <InputError :message="errors.mensaje" />
                </div>
            </section>

            <!--
                El envío es síncrono: puede tardar unos segundos. Sin el cambio de
                texto parece que no hizo nada, y un segundo toque lo mandaría dos
                veces.
            -->
            <Button
                type="submit"
                size="lg"
                class="w-full sm:w-auto"
                :disabled="
                    processing ||
                    contactoId === null ||
                    elegidos.length === 0 ||
                    pasaDelTope
                "
            >
                {{ processing ? 'Mandando…' : textoBoton }}
            </Button>
        </Form>

        <!-- Cargar un contacto sin perder lo elegido -->
        <Sheet v-model:open="sheetContactoAbierto">
            <SheetContent>
                <!--
                    `preserveState`: el alta vuelve a esta misma URL, y sin esto la
                    pantalla se remontaría perdiendo los documentos tildados, el
                    asunto y el mensaje.
                -->
                <Form
                    v-bind="ContactoController.store.form()"
                    reset-on-success
                    :options="{ preserveScroll: true, preserveState: true }"
                    @success="sheetContactoAbierto = false"
                    v-slot="{ errors, processing }"
                    class="flex h-full flex-col"
                >
                    <SheetHeader>
                        <SheetTitle>Agregar un contacto</SheetTitle>
                        <SheetDescription>
                            Revisá bien la dirección: es a donde van a ir los
                            documentos.
                        </SheetDescription>
                    </SheetHeader>

                    <div class="flex-1 space-y-4 overflow-y-auto px-4">
                        <div class="grid gap-2">
                            <Label for="contacto-nombre">Nombre</Label>
                            <input
                                id="contacto-nombre"
                                name="nombre"
                                type="text"
                                required
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.nombre" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="contacto-email"
                                >Dirección de correo</Label
                            >
                            <input
                                id="contacto-email"
                                name="email"
                                type="email"
                                inputmode="email"
                                autocomplete="off"
                                required
                                :class="campoUnaLinea"
                            />
                            <InputError :message="errors.email" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="contacto-tipo">Qué es</Label>
                            <select
                                id="contacto-tipo"
                                name="tipo"
                                required
                                :class="campoUnaLinea"
                            >
                                <option
                                    v-for="tipo in tipos"
                                    :key="tipo.valor"
                                    :value="tipo.valor"
                                >
                                    {{ tipo.etiqueta }}
                                </option>
                            </select>
                            <InputError :message="errors.tipo" />
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

        <VisorDocumento
            :documento="documentoAbierto"
            @cerrar="documentoAbierto = null"
        />
    </div>
</template>
