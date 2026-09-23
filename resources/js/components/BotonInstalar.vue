<script setup lang="ts">
import { Share, SquarePlus } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { usePwaInstall } from '@/composables/usePwaInstall';

const { sePuedeOfrecer, esIos, promptGastado, instalar } = usePwaInstall();

const mostrarInstructivo = ref(false);

async function alTocar(): Promise<void> {
    /*
     * En iOS no hay prompt que lanzar, y una vez usado el del navegador tampoco
     * se puede volver a lanzar: en los dos casos queda explicar el camino
     * manual, que es lo único que sigue funcionando.
     */
    if (esIos.value || promptGastado.value) {
        mostrarInstructivo.value = true;

        return;
    }

    await instalar();
}
</script>

<template>
    <Button v-if="sePuedeOfrecer" variant="outline" @click="alTocar">
        <SquarePlus />
        Instalar la app
    </Button>

    <Dialog v-model:open="mostrarInstructivo">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Instalar MiSalud</DialogTitle>
                <DialogDescription>
                    Así la abrís desde la pantalla de inicio, como cualquier
                    otra app.
                </DialogDescription>
            </DialogHeader>

            <ol v-if="esIos" class="space-y-3 text-sm">
                <li class="flex items-start gap-3">
                    <Share
                        class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                    />
                    <span>
                        Tocá <strong>Compartir</strong>, el cuadradito con la
                        flecha hacia arriba, abajo de la pantalla.
                    </span>
                </li>
                <li class="flex items-start gap-3">
                    <SquarePlus
                        class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                    />
                    <span>
                        Bajá hasta <strong>Agregar a inicio</strong> y tocalo.
                    </span>
                </li>
                <li class="flex items-start gap-3">
                    <span class="mt-0.5 size-5 shrink-0 text-center">✓</span>
                    <span>Confirmá con <strong>Agregar</strong>.</span>
                </li>
            </ol>

            <!--
                No-iOS con el prompt ya gastado: el navegador no lo vuelve a
                ofrecer hasta recargar, así que queda el menú.
            -->
            <p v-else class="text-sm">
                Abrí el menú del navegador —los tres puntitos— y elegí
                <strong>Instalar aplicación</strong> o
                <strong>Agregar a la pantalla de inicio</strong>.
            </p>
        </DialogContent>
    </Dialog>
</template>
