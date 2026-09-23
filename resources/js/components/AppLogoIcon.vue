<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<!--
    La marca de MiSalud: una cruz de puntas redondeadas.

    Es provisoria y genérica a propósito, hasta que exista la marca definitiva.
    Acá va **solo la cruz**, sin fondo, porque quien la usa (`AppLogo`) ya la
    mete en un cuadrado de color; el fondo vive en `resources/marca/icono.svg`,
    que es la fuente de los íconos de la PWA.

    Cambiando este archivo se actualizan de una vez la barra lateral, el
    encabezado y las pantallas de ingreso. Los íconos de la PWA **no**: esos se
    regeneran con `npm run generar:iconos`.
-->
<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        :class="className"
        v-bind="$attrs"
    >
        <g fill="currentColor">
            <rect x="9.4" y="3" width="5.2" height="18" rx="1.6" />
            <rect x="3" y="9.4" width="18" height="5.2" rx="1.6" />
        </g>
    </svg>
</template>
