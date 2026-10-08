<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import IconoSeccion from '@/components/IconoSeccion.vue';
import { Card, CardContent } from '@/components/ui/card';
import { tonos } from '@/lib/tonos';
import type { Tono } from '@/lib/tonos';
import { cn } from '@/lib/utils';

/*
 * Una tarjeta del panel: el ícono de su sección en su color, el título, y un
 * tinte suave del mismo tono desde la esquina. El tinte es por SECCIÓN y no
 * por contenido: una tarjeta no cambia de color según lo que muestra (regla 1).
 */
const props = defineProps<{
    icon: LucideIcon;
    tono: Tono;
    titulo: string;
}>();
</script>

<template>
    <Card
        :class="
            cn(
                'bg-linear-to-br via-card to-card py-5 shadow-sm',
                tonos[props.tono].tarjeta,
            )
        "
    >
        <CardContent class="flex h-full flex-col gap-3 px-5">
            <div class="flex items-center gap-3">
                <IconoSeccion :icon="icon" :tono="tono" tamanio="grande" />
                <h2 class="text-lg font-semibold">{{ titulo }}</h2>
            </div>
            <slot />
            <!-- La acción va al pie, alineada entre tarjetas de distinto largo. -->
            <div v-if="$slots.accion" class="mt-auto pt-1">
                <slot name="accion" />
            </div>
        </CardContent>
    </Card>
</template>
