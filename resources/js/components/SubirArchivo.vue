<script setup lang="ts">
import { FileText, Paperclip, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

/**
 * Selector de archivos, para las seis pantallas que suben documentos.
 *
 * El `<input type="file">` es real y vive adentro del `<Form>` de Inertia: los
 * valores viajan en inputs reales, no en estado de Vue (ver CLAUDE.md).
 *
 * **Sin `capture`.** Tentaba ponerle `capture="environment"` para que abriera
 * la cámara directo, pero `capture` FUERZA la cámara y de paso anula el
 * `multiple`. Sin él, el selector del celular ya ofrece cámara, galería y
 * archivos — que es lo que hace falta para el caso más común de esta app: un
 * PDF que llegó por mail.
 *
 * ⚠️ El formulario que lo use tiene que ir por **POST con `_method=put`** si
 * edita: PHP no parsea el cuerpo multipart de un PUT, `$request->file()` llega
 * vacío y el archivo se pierde en silencio, sin error de validación.
 */

const props = withDefaults(
    defineProps<{
        /** El `name` del input. Con `multiple`, terminalo en `[]`. */
        name: string;
        multiple?: boolean;
        error?: string;
        /** El techo de ArchivoService, en bytes. */
        maximoBytes?: number;
        etiqueta?: string;
    }>(),
    {
        multiple: false,
        maximoBytes: 12 * 1024 * 1024,
        etiqueta: 'Elegir archivo',
    },
);

/*
 * La misma lista blanca que ArchivoService, que es quien decide de verdad.
 * Acá sirve para que el selector del celular no ofrezca lo que va a rebotar.
 */
const ACEPTADOS =
    'application/pdf,image/jpeg,image/png,image/webp,image/heic,image/heif';

const entrada = ref<HTMLInputElement | null>(null);
const elegidos = ref<File[]>([]);

const demasiadoGrandes = computed(() =>
    elegidos.value.filter((a) => a.size > props.maximoBytes).map((a) => a.name),
);

const maximoLegible = computed(() => tamanio(props.maximoBytes));

function tamanio(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} MB`;
}

function esImagen(archivo: File): boolean {
    return archivo.type.startsWith('image/');
}

/** La miniatura de una imagen elegida, sin subirla todavía. */
function vistaPrevia(archivo: File): string {
    return URL.createObjectURL(archivo);
}

function alElegir(evento: Event): void {
    const input = evento.target as HTMLInputElement;
    elegidos.value = Array.from(input.files ?? []);
}

/**
 * Sacar uno de la lista.
 *
 * Hay que reconstruir un `DataTransfer` y reasignarle `files` al input: la
 * lista de un `<input type="file">` es de solo lectura, así que no alcanza con
 * sacarlo del array de Vue — el archivo se seguiría enviando igual.
 */
function quitar(indice: number): void {
    const input = entrada.value;

    if (!input) {
        return;
    }

    const quedan = elegidos.value.filter((_, i) => i !== indice);
    const bolsa = new DataTransfer();

    for (const archivo of quedan) {
        bolsa.items.add(archivo);
    }

    input.files = bolsa.files;
    elegidos.value = quedan;
}
</script>

<template>
    <div class="grid gap-3">
        <input
            :id="name"
            ref="entrada"
            type="file"
            :name="name"
            :multiple="multiple"
            :accept="ACEPTADOS"
            class="sr-only"
            @change="alElegir"
        />

        <!--
            El botón es un <label>: así el toque abre el selector sin JavaScript
            de por medio, y el input real queda oculto pero presente en el form.
        -->
        <Button as-child variant="outline" class="w-full justify-start">
            <label :for="name" class="cursor-pointer">
                <Paperclip />
                {{ etiqueta }}
            </label>
        </Button>

        <p class="text-sm text-muted-foreground">
            PDF o foto, hasta {{ maximoLegible }} por archivo.
        </p>

        <ul v-if="elegidos.length > 0" class="grid gap-2">
            <li
                v-for="(archivo, indice) in elegidos"
                :key="`${archivo.name}-${indice}`"
                class="flex items-center gap-3 rounded-md border p-2"
            >
                <img
                    v-if="esImagen(archivo)"
                    :src="vistaPrevia(archivo)"
                    alt=""
                    class="size-11 shrink-0 rounded object-cover"
                />
                <div
                    v-else
                    class="flex size-11 shrink-0 items-center justify-center rounded bg-muted"
                >
                    <FileText class="size-5 text-muted-foreground" />
                </div>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm">{{ archivo.name }}</p>
                    <p
                        class="text-sm"
                        :class="
                            archivo.size > maximoBytes
                                ? 'text-destructive'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ tamanio(archivo.size) }}
                    </p>
                </div>

                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="shrink-0"
                    :aria-label="`Quitar ${archivo.name}`"
                    @click="quitar(indice)"
                >
                    <X />
                </Button>
            </li>
        </ul>

        <!--
            El aviso de tamaño va acá y no en un toast: es un error de campo, y
            el toast obligaría a memorizar cuál de los archivos estaba mal
            antes de que se desvanezca.
        -->
        <p v-if="demasiadoGrandes.length > 0" class="text-sm text-destructive">
            {{
                demasiadoGrandes.length === 1
                    ? `"${demasiadoGrandes[0]}" pesa más de ${maximoLegible}.`
                    : `${demasiadoGrandes.length} archivos pesan más de ${maximoLegible}.`
            }}
            Sacalos de la lista o subilos más chicos.
        </p>

        <InputError :message="error" />
    </div>
</template>
