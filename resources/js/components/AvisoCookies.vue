<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Cookie } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { useAvisoCookies } from '@/composables/useAvisoCookies';
import { cookies } from '@/routes';

/*
 * Aviso de cookies: informativo, con un solo botón.
 *
 * Todo lo que MiSalud guarda en el navegador es necesario para que funcione
 * —sesión, protección de formularios, preferencias, la credencial sin señal—,
 * así que no hay nada que rechazar. Un «Rechazar» sería de adorno: lo único
 * que podría apagar es el ingreso. El detalle está en la página de cookies.
 *
 * Abajo en el celular, donde está el pulgar; desde `md`, una tarjeta angosta
 * abajo a la DERECHA: a la izquierda está el menú de la cuenta, y los avisos
 * (toasts) en escritorio salen arriba.
 *
 * Publica cuánto ocupa desde el borde de abajo en `--alto-aviso`, igual que el
 * reproductor publica `--alto-reproductor`: la barra de la grabación y los
 * toasts del celular suben en vez de quedar tapados.
 */

const { visible, cerrar } = useAvisoCookies();

const caja = ref<HTMLElement | null>(null);
let observador: ResizeObserver | null = null;

function publicarAlto() {
    const raiz = document.documentElement;

    if (!caja.value) {
        raiz.style.removeProperty('--alto-aviso');

        return;
    }

    // Desde el borde de abajo de la ventana hasta el tope del aviso: vale igual
    // pegado al borde (celular) que flotando con margen (escritorio).
    const ocupa = window.innerHeight - caja.value.getBoundingClientRect().top;
    raiz.style.setProperty('--alto-aviso', `${Math.max(0, ocupa)}px`);
}

watch(caja, (elemento) => {
    observador?.disconnect();
    observador = null;

    if (elemento) {
        observador = new ResizeObserver(publicarAlto);
        observador.observe(elemento);
    }

    publicarAlto();
});

onBeforeUnmount(() => {
    observador?.disconnect();
    document.documentElement.style.removeProperty('--alto-aviso');
});
</script>

<template>
    <div
        v-if="visible"
        ref="caja"
        role="region"
        aria-label="Aviso de cookies"
        class="fixed inset-x-0 bottom-0 z-50 border-t border-border bg-card px-4 pr-[max(1rem,env(safe-area-inset-right))] pb-[env(safe-area-inset-bottom)] pl-[max(1rem,env(safe-area-inset-left))] shadow-lg md:inset-x-auto md:right-4 md:bottom-4 md:w-sm md:rounded-xl md:border md:pb-0"
    >
        <div class="flex flex-col gap-3 py-3">
            <div class="flex gap-3">
                <Cookie
                    class="mt-0.5 size-5 shrink-0 text-primary"
                    aria-hidden="true"
                />
                <p class="text-sm text-pretty wrap-anywhere">
                    MiSalud usa cookies y guarda datos en tu dispositivo solo
                    para funcionar: mantener tu sesión, recordar tus
                    preferencias y dejarte ver la credencial sin señal. Nada de
                    publicidad ni seguimiento.
                </p>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2">
                <Link
                    :href="cookies()"
                    class="inline-flex min-h-11 items-center px-2 text-sm text-muted-foreground underline-offset-4 transition-colors hover:text-foreground hover:underline"
                >
                    Más información
                </Link>

                <Button size="sm" @click="cerrar">Entendido</Button>
            </div>
        </div>
    </div>
</template>
