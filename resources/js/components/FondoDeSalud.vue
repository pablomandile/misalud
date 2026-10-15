<script lang="ts">
/*
 * El fondo de la app: corazones con una cruz, estetoscopios y cofias de enfermera en tonos
 * pálidos, desparramados detrás de todo el contenido. Pura decoración:
 * `aria-hidden` y sin eventos.
 *
 * Es un <pattern> de SVG y no una imagen de fondo ni íconos sueltos:
 *
 * - Un mosaico cubre una pantalla de CUALQUIER alto —una lista de cien
 *   mediciones— sin calcular cuántos íconos hacen falta ni tocar nada al
 *   hacer scroll.
 * - Los colores salen de las variables de la paleta (`--tono-*-fuerte`), así
 *   que el modo oscuro los cambia solo. Un SVG en `background-image` (data URI)
 *   no ve las variables CSS de la página y quedaría con los colores del claro.
 *
 * Las posiciones se sortean UNA vez, cuando se carga el módulo —por eso este
 * bloque es un <script> común y no el `setup`—. El fondo vive en el layout, que
 * no se remonta al navegar: sorteando en el setup, los íconos saltarían de
 * lugar en cada vuelta al ingreso. Así cambian al abrir la app y se quedan
 * quietos mientras se usa.
 *
 * Va con `absolute -z-10`, así que el contenedor necesita `isolate` (lo tienen
 * AppContent y el layout de ingreso).
 */

type Figura = 'corazon' | 'estetoscopio' | 'cofia';

type Ubicacion = {
    figura: Figura;
    x: number;
    y: number;
    tamanio: number;
    giro: number;
};

/** Lado del mosaico, en px. Grande para que la repetición no se note. */
const LADO = 560;
const CANTIDAD = 12;
/** Distancia mínima entre centros, para que no se encimen. */
const SEPARACION = 120;

const figuras: Figura[] = ['corazon', 'estetoscopio', 'cofia'];

function sortear(): Ubicacion[] {
    const ubicaciones: Ubicacion[] = [];

    for (
        let intento = 0;
        ubicaciones.length < CANTIDAD && intento < 2000;
        intento++
    ) {
        const tamanio = 30 + Math.random() * 22;
        // Lejos del borde del mosaico: un ícono que cruza el borde se corta
        // a la mitad, porque cada copia del patrón recorta lo suyo.
        const margen = tamanio;
        const x = margen + Math.random() * (LADO - 2 * margen);
        const y = margen + Math.random() * (LADO - 2 * margen);

        if (
            ubicaciones.some((u) => Math.hypot(u.x - x, u.y - y) < SEPARACION)
        ) {
            continue;
        }

        ubicaciones.push({
            // Repartidas en orden y no al azar: las tres aparecen parejas.
            figura: figuras[ubicaciones.length % figuras.length],
            x,
            y,
            tamanio,
            giro: -25 + Math.random() * 50,
        });
    }

    return ubicaciones;
}

const ubicaciones = sortear();
</script>

<script setup lang="ts">
/*
 * Cada figura con su tono, el mismo de su sección en el resto de la app: el
 * corazón en rosa, el estetoscopio en celeste (médicos y consultas), la cofia
 * en lavanda.
 */
const color: Record<Figura, string> = {
    corazon: 'var(--tono-rosa-fuerte)',
    estetoscopio: 'var(--tono-celeste-fuerte)',
    cofia: 'var(--tono-lavanda-fuerte)',
};
</script>

<template>
    <svg
        aria-hidden="true"
        focusable="false"
        class="fondo-de-salud pointer-events-none absolute inset-0 -z-10 size-full opacity-[0.16] dark:opacity-10"
    >
        <defs>
            <!--
                Las tres figuras se dibujan en una caja de 24×24, con trazo y
                sin relleno: un ícono de línea se lee como dibujo y no como
                mancha, que es lo que hace que no moleste.
            -->
            <!--
                El corazón con una cruz adentro, el de "una vida más" de los
                videojuegos. La cruz va en el centro óptico del corazón, que no
                es el de la caja: está más arriba, donde el corazón es ancho.
            -->
            <g id="figura-corazon">
                <path
                    d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"
                />
                <path d="M12 8.2v5.6M9.2 11h5.6" />
            </g>
            <g id="figura-estetoscopio">
                <path d="M11 2v2M5 2v2" />
                <path
                    d="M5 3H4a2 2 0 0 0-2 2v4a6 6 0 0 0 12 0V5a2 2 0 0 0-2-2h-1"
                />
                <path d="M8 15a6 6 0 0 0 12 0v-3" />
                <circle cx="20" cy="10" r="2" />
            </g>
            <g id="figura-cofia">
                <!--
                    La cofia de frente: más ancha arriba que abajo (las alas se
                    doblan hacia atrás), con la vincha. Con la copa redonda y
                    la base ancha se leía como una gorra con visera. La cruz va sola en el centro de la
                    copa, sin ninguna línea que la cruce: es lo que la hace
                    reconocible en chico.
                -->
                <path
                    d="M2.5 7.5C8.5 4.6 15.5 4.6 21.5 7.5L18.6 17.5C14.2 16.4 9.8 16.4 5.4 17.5Z"
                />
                <path d="M6.1 14.9c3.9-.9 7.9-.9 11.8 0" />
                <path d="M12 7.4v4.8M9.6 9.8h4.8" />
            </g>

            <pattern
                id="patron-de-salud"
                :width="LADO"
                :height="LADO"
                patternUnits="userSpaceOnUse"
            >
                <use
                    v-for="(u, i) in ubicaciones"
                    :key="i"
                    :href="`#figura-${u.figura}`"
                    :stroke="color[u.figura]"
                    :transform="`translate(${u.x} ${u.y}) rotate(${u.giro}) scale(${u.tamanio / 24}) translate(-12 -12)`"
                />
            </pattern>
        </defs>

        <rect width="100%" height="100%" fill="url(#patron-de-salud)" />
    </svg>
</template>

<style scoped>
/*
 * Trazo fino y sin relleno. La opacidad va en las clases del <svg>
 * (`dark:opacity-10`) y NO acá con `:global(.dark) .fondo-de-salud`: Vue
 * compila ese selector como `.dark` a secas, y la opacidad le tocaba a la
 * página entera —el modo oscuro quedaba casi negro—.
 */
.fondo-de-salud {
    fill: none;
    stroke-width: 1.5;
    stroke-linecap: round;
    stroke-linejoin: round;
}
</style>
