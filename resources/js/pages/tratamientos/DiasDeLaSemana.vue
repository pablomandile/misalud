<script setup lang="ts">
import InputError from '@/components/InputError.vue';

/**
 * Los días en que se toma, para "Algunos días de la semana": siete casillas
 * L M X J V S D, con el nombre completo para el lector de pantalla.
 *
 * Sin estado local, a propósito: son checkboxes reales del `<Form>` y nada
 * más. Con un `v-model` acá, cada toque redibujaría el formulario de edición y
 * pisaría con el valor del prop lo ya tipeado en los campos con `:value`.
 * Cuándo se ven lo decide el CSS del contenedor (ver `Index.vue`): por eso la
 * raíz no lleva clase de display propia, el `hidden`/`grid` lo pone quien lo usa.
 */
defineProps<{
    dias: { valor: number; inicial: string; nombre: string }[];
    seleccionados?: number[];
    error?: string;
}>();
</script>

<template>
    <fieldset class="gap-2">
        <legend class="mb-2 text-sm font-medium">Qué días</legend>
        <div class="flex flex-wrap gap-2">
            <label
                v-for="dia in dias"
                :key="dia.valor"
                class="inline-flex size-11 cursor-pointer items-center justify-center rounded-full border border-input text-base font-semibold select-none has-checked:border-primary has-checked:bg-primary has-checked:text-primary-foreground has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
            >
                <input
                    type="checkbox"
                    name="dias_semana[]"
                    :value="dia.valor"
                    :checked="seleccionados?.includes(dia.valor)"
                    class="sr-only"
                />
                <span aria-hidden="true">{{ dia.inicial }}</span>
                <span class="sr-only">{{ dia.nombre }}</span>
            </label>
        </div>
        <InputError :message="error" />
    </fieldset>
</template>
