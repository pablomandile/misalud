<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

/**
 * Elegir de un catálogo con un `<select>` de verdad, o escribir uno nuevo con
 * la última opción, «Otro: lo escribo». Lo usan el medicamento de un
 * tratamiento y la vacuna de una dosis.
 *
 * Reemplazó a un `<datalist>`: sus sugerencias aparecen solo al empezar a
 * escribir y en el celular casi no se ven, así que nadie encontraba las
 * opciones comunes ya cargadas (lo reportó el usuario).
 *
 * Sin estado en Vue, a propósito: el campo para escribir aparece por CSS
 * (`:has` sobre la opción elegida). Con un `v-model` acá, elegir una opción
 * redibujaría el formulario de edición y pisaría lo tipeado en los demás
 * campos (la regla de la Etapa 10). Y el campo oculto no lleva `required`: el
 * navegador no deja enviar un formulario con un campo obligatorio que no se
 * ve. Lo valida el servidor (`EligeDelCatalogoOEscribe`).
 */
defineProps<{
    id: string;
    etiqueta: string;
    nombreId: string;
    nombreTexto: string;
    opciones: { id: number; nombre: string }[];
    elegido?: number | null;
    etiquetaTexto: string;
    error?: string;
    claseCampo: string;
}>();

/** El mismo valor que `EligeDelCatalogoOEscribe::OTRO`. */
const OTRO = 'otro';
</script>

<template>
    <div class="group/catalogo grid gap-2">
        <Label :for="id">{{ etiqueta }}</Label>
        <select
            :id="id"
            :name="nombreId"
            :value="elegido ?? ''"
            :class="claseCampo"
        >
            <option value="" disabled class="bg-background text-foreground">
                Elegí de la lista
            </option>
            <option
                v-for="opcion in opciones"
                :key="opcion.id"
                :value="opcion.id"
                class="bg-background text-foreground"
            >
                {{ opcion.nombre }}
            </option>
            <option :value="OTRO" class="bg-background text-foreground">
                Otro: lo escribo
            </option>
        </select>

        <div
            class="hidden gap-2 group-has-[option[value=otro]:checked]/catalogo:grid"
        >
            <Label :for="`${id}-texto`">{{ etiquetaTexto }}</Label>
            <input
                :id="`${id}-texto`"
                :name="nombreTexto"
                type="text"
                autocomplete="off"
                :class="claseCampo"
            />
            <p class="text-sm text-muted-foreground">
                Se agrega a tu lista para la próxima vez.
            </p>
        </div>

        <InputError :message="error" />
    </div>
</template>
