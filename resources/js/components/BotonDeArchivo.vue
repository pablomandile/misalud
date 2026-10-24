<script setup lang="ts">
import {
    File as IconoArchivo,
    FileText as IconoPdf,
    Image as IconoImagen,
} from '@lucide/vue';
import { computed } from 'vue';

/**
 * Un archivo adjunto como una pastilla con su tipo («Imagen», «PDF»), que lo
 * abre en el visor. Pedido del usuario: el nombre del archivo ("IMG_20261023
 * _174606.jpg") no le dice nada a nadie y ocupa la fila entera.
 *
 * Con varios del mismo tipo van numerados («Imagen 1», «Imagen 2»): sin el
 * número, dos pastillas iguales no se distinguen. El nombre real queda en el
 * nombre accesible, para quien usa lector de pantalla.
 *
 * El rótulo se calcula acá con la lista entera a la vista, y no en cada
 * pantalla: así el criterio es uno solo.
 */
type Archivo = { id: number; nombre: string; mime: string };

const props = defineProps<{ documento: Archivo; lista: Archivo[] }>();
const emit = defineEmits<{ abrir: [] }>();

function tipo(mime: string): 'Imagen' | 'PDF' | 'Archivo' {
    if (mime.startsWith('image/')) {
        return 'Imagen';
    }

    return mime === 'application/pdf' ? 'PDF' : 'Archivo';
}

const clase = computed(() => tipo(props.documento.mime));

const rotulo = computed(() => {
    const delMismoTipo = props.lista.filter(
        (otro) => tipo(otro.mime) === clase.value,
    );

    if (delMismoTipo.length < 2) {
        return clase.value;
    }

    const numero =
        delMismoTipo.findIndex((otro) => otro.id === props.documento.id) + 1;

    return `${clase.value} ${numero}`;
});

const icono = computed(() =>
    clase.value === 'Imagen'
        ? IconoImagen
        : clase.value === 'PDF'
          ? IconoPdf
          : IconoArchivo,
);
</script>

<template>
    <button
        type="button"
        class="inline-flex min-h-11 shrink-0 items-center gap-2 rounded-full border bg-muted/40 px-4 text-sm font-medium hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
        :aria-label="`Ver ${rotulo}: ${documento.nombre}`"
        @click="emit('abrir')"
    >
        <component :is="icono" class="size-4 shrink-0" aria-hidden="true" />
        {{ rotulo }}
    </button>
</template>
