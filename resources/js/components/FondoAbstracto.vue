<script setup lang="ts">
/*
 * El fondo de la app: manchas pastel difusas y dos formas orgánicas, detrás de
 * todo el contenido. Pura decoración: `aria-hidden` y sin eventos.
 *
 * Las manchas son degradés radiales y no `filter: blur()`: un blur grande se
 * recalcula en cada scroll y en un celular viejo se nota. Un degradé es una
 * imagen fija y no cuesta nada.
 *
 * Va con `absolute -z-10`, así que el contenedor necesita `isolate` (lo tienen
 * AppContent y el layout de ingreso): sin eso, el `-z-10` lo manda detrás del
 * fondo del propio contenedor y no se ve.
 */
</script>

<template>
    <div
        aria-hidden="true"
        class="fondo-abstracto pointer-events-none absolute inset-0 -z-10 overflow-hidden"
    >
        <svg
            class="absolute -top-28 -right-24 size-[26rem] text-lavanda opacity-60 dark:opacity-40"
            viewBox="0 0 200 200"
        >
            <path
                fill="currentColor"
                d="M43.6,-58.2C55.5,-50.7,63.2,-36.2,67.6,-20.8C72,-5.4,73.1,10.9,67.6,24.6C62.1,38.3,50,49.4,36.2,57.6C22.4,65.8,6.9,71.1,-9.4,71.3C-25.7,71.5,-42.8,66.6,-54.4,55.6C-66,44.6,-72.1,27.5,-73.1,10.6C-74.1,-6.3,-70,-23,-60.6,-35.6C-51.2,-48.2,-36.5,-56.7,-22,-62.6C-7.5,-68.5,6.8,-71.8,20.5,-69.5C34.2,-67.2,31.7,-65.7,43.6,-58.2Z"
                transform="translate(100 100)"
            />
        </svg>
        <svg
            class="absolute top-72 -left-32 size-[22rem] text-menta opacity-50 dark:opacity-30"
            viewBox="0 0 200 200"
        >
            <path
                fill="currentColor"
                d="M38.9,-51.6C50.2,-45.6,59,-34.2,64.4,-20.9C69.8,-7.6,71.8,7.6,67.1,20.5C62.4,33.4,51,44,38.3,52.6C25.6,61.2,11.6,67.8,-3.3,72.3C-18.2,76.8,-34.1,79.2,-45.5,72.2C-56.9,65.2,-63.9,48.8,-67.6,32.6C-71.3,16.4,-71.7,0.4,-67.7,-14C-63.7,-28.4,-55.3,-41.2,-43.9,-47.3C-32.5,-53.4,-18.1,-52.8,-3.4,-48.2C11.3,-43.6,27.6,-57.6,38.9,-51.6Z"
                transform="translate(100 100)"
            />
        </svg>
    </div>
</template>

<style scoped>
/*
 * Las manchas difusas: tres degradés con los tonos de la paleta, que en modo
 * oscuro ya vienen apagados desde las variables.
 */
.fondo-abstracto {
    background-image:
        radial-gradient(
            40rem 28rem at 100% 0%,
            color-mix(in oklch, var(--tono-celeste) 70%, transparent),
            transparent 70%
        ),
        radial-gradient(
            36rem 30rem at 0% 35%,
            color-mix(in oklch, var(--tono-rosa) 55%, transparent),
            transparent 70%
        ),
        radial-gradient(
            44rem 30rem at 70% 100%,
            color-mix(in oklch, var(--tono-durazno) 50%, transparent),
            transparent 70%
        );
}
</style>
