<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import {
    useTamanioTexto,
    type TamanioTexto,
} from '@/composables/useTamanioTexto';

const { tamanio, opciones, cambiarTamanio } = useTamanioTexto();

const escalaActual = computed(
    () => opciones.value.find((o) => o.valor === tamanio.value)?.escala ?? 100,
);

/*
 * La muestra se dibuja al tamaño que tendría la app si se eligiera esa opción.
 *
 * Se calcula en `em` contra el tamaño vigente, y no en píxeles fijos. Si fuera
 * en píxeles, la muestra mentiría para cualquiera que haya cambiado el tamaño
 * base de su navegador — que es justamente la gente que viene a esta pantalla.
 */
function escalaDeLaMuestra(escala: number): string {
    return `${escala / escalaActual.value}em`;
}

function esElegido(valor: TamanioTexto): boolean {
    return valor === tamanio.value;
}
</script>

<template>
    <fieldset class="space-y-3">
        <legend class="sr-only">Tamaño de la letra</legend>

        <label
            v-for="opcion in opciones"
            :key="opcion.valor"
            :class="[
                'flex cursor-pointer gap-3 rounded-lg border p-4 transition-colors',
                'has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-offset-2',
                esElegido(opcion.valor)
                    ? 'border-primary bg-muted'
                    : 'border-border hover:bg-muted/50',
            ]"
        >
            <!--
                Un radio de verdad, no un div con rol. Da navegación con flechas,
                selección con la barra y lectura correcta del estado, sin que
                haya que reimplementar nada de eso a mano. Se oculta a la vista
                pero no a la accesibilidad: `sr-only`, no `display: none`.
            -->
            <input
                type="radio"
                name="tamanio_texto"
                class="sr-only"
                :value="opcion.valor"
                :checked="esElegido(opcion.valor)"
                @change="cambiarTamanio(opcion.valor)"
            />

            <div
                :class="[
                    'mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full border-2',
                    esElegido(opcion.valor)
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'border-muted-foreground/40',
                ]"
                aria-hidden="true"
            >
                <Check v-if="esElegido(opcion.valor)" class="size-4" />
            </div>

            <div class="min-w-0 flex-1 space-y-2">
                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <span class="font-medium">{{ opcion.etiqueta }}</span>
                    <span
                        v-if="esElegido(opcion.valor)"
                        class="text-xs text-muted-foreground"
                        >(el que estás usando)</span
                    >
                </div>

                <p class="text-sm text-muted-foreground">
                    {{ opcion.descripcion }}
                </p>

                <!--
                    La muestra, al tamaño real. Un selector que dice "Grande" en
                    letra chica no le sirve a quien lo necesita: hay que poder
                    ver la diferencia antes de elegir, no después.

                    El texto es contenido de la app y no un "Lorem ipsum": lo que
                    se quiere saber es si se va a poder leer una presión arterial
                    de un vistazo.
                -->
                <p
                    class="border-t border-border/60 pt-2 leading-snug break-words"
                    :style="{ fontSize: escalaDeLaMuestra(opcion.escala) }"
                >
                    Presión 12/8 · 23 de septiembre
                </p>
            </div>
        </label>
    </fieldset>
</template>
