<script setup lang="ts">
import { Loader2, Pause, Play, RotateCcw, RotateCw, X } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    formatoTiempo,
    SALTO_SEGUNDOS,
    useReproductor,
} from '@/composables/useReproductor';

/*
 * La barra del reproductor. UNA SOLA, montada en el layout y no en una página:
 * el estado vive en `useReproductor` y la navegación no la desmonta, así que la
 * grabación sigue sonando al cambiar de pantalla.
 *
 * Va `sticky` al final de la columna del contenido, no `fixed` sobre toda la
 * pantalla: en escritorio no tapa el pie de la barra lateral (donde está el
 * menú de la cuenta), y como ocupa su propio lugar en el flujo, el final de
 * cada página queda arriba de ella en vez de debajo.
 *
 * Todos los controles a 44 px reales (piso de las primitivas), no a los 32 de
 * un reproductor de música: el play es el botón más usado de la sección.
 */

const r = useReproductor();
const barra = ref<HTMLElement | null>(null);

/*
 * Publica el alto de la barra como variable CSS, para que los avisos —que en el
 * celular salen abajo, donde está el pulgar— aparezcan ARRIBA de ella en vez de
 * taparla (ver el `mobile-offset` del Toaster en el layout).
 */
let observador: ResizeObserver | null = null;

function publicarAlto(alto: number): void {
    document.documentElement.style.setProperty(
        '--alto-reproductor',
        `${Math.ceil(alto)}px`,
    );
}

watch(barra, (el) => {
    observador?.disconnect();

    if (el === null) {
        publicarAlto(0);

        return;
    }

    observador = new ResizeObserver(([entrada]) =>
        publicarAlto(entrada.target.getBoundingClientRect().height),
    );
    observador.observe(el);
});

onBeforeUnmount(() => {
    observador?.disconnect();
    publicarAlto(0);
});

function alMover(evento: Event): void {
    r.irA(Number((evento.target as HTMLInputElement).value));
}
</script>

<template>
    <section
        v-if="r.pista.value"
        ref="barra"
        aria-label="Reproductor de la grabación"
        class="sticky bottom-0 z-20 mt-auto border-t bg-background/95 px-3 pt-2 shadow-[0_-4px_12px_rgba(0,0,0,0.06)] backdrop-blur"
        style="padding-bottom: calc(0.5rem + env(safe-area-inset-bottom))"
    >
        <div class="flex items-center gap-2">
            <Button
                size="icon"
                class="shrink-0"
                :aria-label="r.sonando.value ? 'Pausar' : 'Reproducir'"
                @click="r.alternar()"
            >
                <Loader2
                    v-if="r.cargando.value"
                    class="size-5 animate-spin"
                    aria-hidden="true"
                />
                <Pause v-else-if="r.sonando.value" class="size-5" />
                <Play v-else class="size-5" />
            </Button>

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium">
                    {{ r.pista.value.titulo }}
                </p>
                <p class="text-sm text-muted-foreground tabular-nums">
                    {{ formatoTiempo(r.tiempo.value) }} /
                    {{ formatoTiempo(r.duracion.value || null) }}
                </p>
            </div>

            <Button
                variant="ghost"
                size="icon"
                class="shrink-0"
                aria-label="Cerrar el reproductor"
                @click="r.cerrar()"
            >
                <X class="size-5" />
            </Button>
        </div>

        <div class="flex items-center gap-1">
            <Button
                variant="ghost"
                size="icon"
                class="shrink-0"
                :aria-label="`Atrasar ${SALTO_SEGUNDOS} segundos`"
                @click="r.saltar(-SALTO_SEGUNDOS)"
            >
                <RotateCcw class="size-5" />
            </Button>

            <!--
                Un range nativo: lo maneja el teclado y el lector de pantalla sin
                nada extra, y su alto de 44 px es el área que responde al toque.
            -->
            <input
                type="range"
                min="0"
                step="1"
                :max="r.duracion.value || 0"
                :value="r.tiempo.value"
                :disabled="r.duracion.value <= 0"
                aria-label="Posición en la grabación"
                :aria-valuetext="`${formatoTiempo(r.tiempo.value)} de ${formatoTiempo(r.duracion.value || null)}`"
                class="h-11 min-w-0 flex-1 cursor-pointer accent-primary"
                @input="alMover"
            />

            <Button
                variant="ghost"
                size="icon"
                class="shrink-0"
                :aria-label="`Adelantar ${SALTO_SEGUNDOS} segundos`"
                @click="r.saltar(SALTO_SEGUNDOS)"
            >
                <RotateCw class="size-5" />
            </Button>

            <Button
                variant="outline"
                size="sm"
                class="shrink-0 tabular-nums"
                :aria-label="`Velocidad: ${r.velocidad.value} por. Tocá para cambiarla`"
                @click="r.cambiarVelocidad()"
            >
                {{ String(r.velocidad.value).replace('.', ',') }}×
            </Button>
        </div>
    </section>
</template>
