<script setup lang="ts">
import {
    CategoryScale,
    Chart,
    Filler,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
    type ChartOptions,
    type Plugin,
} from 'chart.js';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Line } from 'vue-chartjs';

/**
 * La evolución de una variable en el tiempo.
 *
 * Es el patrón de gráfico que después reusan los resultados de estudios
 * (Etapa 9) y la graduación ocular (Etapa 10), así que las tres reglas de
 * abajo valen para los tres y no son de este componente:
 *
 * 1. **El eje Y NO arranca en cero.** Una presión que va de 12 a 14 se ve
 *    como una línea plana en una escala de 0 a 14, y esos dos puntos son
 *    justamente el dato. La escala se ajusta a los valores, con un respiro
 *    arriba y abajo.
 * 2. **El rango de referencia va como banda de fondo**, no como una línea de
 *    corte ni como un color de alarma sobre los puntos. Es información, del
 *    mismo tipo que el rango impreso al costado de un análisis: la regla 1
 *    del proyecto dice que el sistema registra y no aconseja.
 * 3. **El gráfico nunca es la única fuente.** Al lado va siempre la lista con
 *    fechas y valores: por lector de pantalla —un canvas no se lee— y porque
 *    dos tomas del mismo día quedan una encima de la otra.
 *
 * Un `<canvas>` no lo lee un lector de pantalla, así que el contenedor va
 * con `role="img"` y un resumen en `aria-label`. El detalle está en la lista.
 */

Chart.register(
    LineController,
    LineElement,
    PointElement,
    LinearScale,
    CategoryScale,
    Tooltip,
    Filler,
);

type Punto = {
    fechaIso: string;
    fechaVisible: string;
    valor: number;
    valorSecundario: number | null;
};

const props = defineProps<{
    puntos: Punto[];
    etiquetaPrincipal: string;
    etiquetaSecundaria: string | null;
    unidad: string;
    decimales: number;
    minNormal: number | null;
    maxNormal: number | null;
    minNormalSecundario: number | null;
    maxNormalSecundario: number | null;
    zonaHoraria: string | null;
    resumenAccesible: string;
}>();

/*
 * El tema puede cambiar mientras el gráfico está en pantalla: la preferencia
 * "según el sistema" sigue al reloj del sistema operativo. Los colores se
 * leen de las variables CSS, y este contador los vuelve a leer cuando eso
 * pasa; sin él, el gráfico se queda con los colores del tema anterior.
 */
const version = ref(0);
let observador: MutationObserver | null = null;
const consulta =
    typeof window !== 'undefined'
        ? window.matchMedia('(prefers-color-scheme: dark)')
        : null;
const alCambiarTema = (): void => {
    version.value++;
};

onMounted(() => {
    consulta?.addEventListener('change', alCambiarTema);
    observador = new MutationObserver(alCambiarTema);
    observador.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class', 'data-texto'],
    });
});

onBeforeUnmount(() => {
    consulta?.removeEventListener('change', alCambiarTema);
    observador?.disconnect();
});

function variableCss(nombre: string, respaldo: string): string {
    if (typeof window === 'undefined') {
        return respaldo;
    }
    const valor = getComputedStyle(document.documentElement)
        .getPropertyValue(nombre)
        .trim();

    return valor === '' ? respaldo : valor;
}

/**
 * El tamaño de letra del gráfico sigue al de la app.
 *
 * Chart.js mide sus fuentes en px, así que no hereda el `font-size` de la
 * raíz como sí hace todo lo demás (Tailwind mide en rem). Sin esto, quien
 * eligió "Muy grande" tendría toda la app escalada y los números del eje
 * chiquitos, que es justo donde más molesta.
 */
function tipografia(): number {
    if (typeof window === 'undefined') {
        return 14;
    }

    return (
        parseFloat(
            getComputedStyle(document.documentElement).fontSize || '16',
        ) * 0.8
    );
}

const colores = computed(() => {
    version.value; // eslint-disable-line @typescript-eslint/no-unused-expressions
    return {
        principal: variableCss('--chart-1', 'hsl(12 76% 61%)'),
        secundario: variableCss('--chart-2', 'hsl(173 58% 39%)'),
        texto: variableCss('--muted-foreground', 'hsl(0 0% 45%)'),
        grilla: variableCss('--border', 'hsl(0 0% 90%)'),
    };
});

const enOrden = computed(() =>
    [...props.puntos].sort(
        (a, b) => Date.parse(a.fechaIso) - Date.parse(b.fechaIso),
    ),
);

function formatearFecha(ms: number): string {
    return new Intl.DateTimeFormat('es-AR', {
        day: '2-digit',
        month: '2-digit',
        // La zona de la CUENTA, no la del navegador: es la misma con la que
        // el servidor armó las fechas del listado.
        timeZone: props.zonaHoraria ?? undefined,
    }).format(new Date(ms));
}

function formatearValor(valor: number): string {
    return new Intl.NumberFormat('es-AR', {
        minimumFractionDigits: props.decimales,
        maximumFractionDigits: props.decimales,
    }).format(valor);
}

/** Todos los valores dibujados, para calcular la escala. */
const valores = computed(() => {
    const lista = enOrden.value.map((p) => p.valor);
    for (const p of enOrden.value) {
        if (p.valorSecundario !== null) {
            lista.push(p.valorSecundario);
        }
    }
    return lista;
});

/**
 * La escala del eje Y: **ajustada a los datos, sin arrancar en cero**.
 *
 * El respiro es un 12% del recorrido. Cuando todos los valores son iguales
 * -o hay uno solo- el recorrido es cero y hay que inventar uno, o el gráfico
 * queda degenerado con la línea pegada al borde.
 */
const escalaY = computed(() => {
    if (valores.value.length === 0) {
        return { min: undefined, max: undefined };
    }

    const minimo = Math.min(...valores.value);
    const maximo = Math.max(...valores.value);
    const recorrido = maximo - minimo;
    const respiro =
        recorrido > 0 ? recorrido * 0.12 : Math.max(Math.abs(maximo) * 0.05, 1);

    return { min: minimo - respiro, max: maximo + respiro };
});

/**
 * La banda de referencia, dibujada DETRÁS de las líneas.
 *
 * Chart.js no tiene bandas, así que es un plugin de doce líneas en vez de
 * otra dependencia. Se recorta contra el área del gráfico: si el rango es
 * más ancho que lo medido, se ve todo el fondo sombreado, que es la lectura
 * correcta -todo lo medido cae dentro de la referencia-.
 */
const bandas: Plugin<'line'> = {
    id: 'bandaDeReferencia',
    beforeDatasetsDraw(grafico) {
        const { ctx, chartArea, scales } = grafico;
        const y = scales.y;

        if (!y || !chartArea) {
            return;
        }

        const rangos: Array<[number | null, number | null, string]> = [
            [props.minNormal, props.maxNormal, colores.value.principal],
            [
                props.minNormalSecundario,
                props.maxNormalSecundario,
                colores.value.secundario,
            ],
        ];

        for (const [min, max, color] of rangos) {
            if (min === null && max === null) {
                continue;
            }

            const arriba = y.getPixelForValue(max ?? y.max);
            const abajo = y.getPixelForValue(min ?? y.min);
            const tope = Math.max(chartArea.top, Math.min(arriba, abajo));
            const piso = Math.min(chartArea.bottom, Math.max(arriba, abajo));

            if (piso <= tope) {
                continue;
            }

            ctx.save();
            ctx.globalAlpha = 0.1;
            ctx.fillStyle = color;
            ctx.fillRect(
                chartArea.left,
                tope,
                chartArea.right - chartArea.left,
                piso - tope,
            );
            ctx.restore();
        }
    },
};

const datos = computed(() => {
    const puntos = enOrden.value;
    const comun = {
        // Líneas y puntos generosos: esto lo mira gente que agrandó la letra
        // de toda la app, y una línea de 1px con puntos de 2px no se ve.
        borderWidth: 3,
        pointRadius: 5,
        pointHoverRadius: 8,
        tension: 0.2,
    };

    const conjuntos = [
        {
            ...comun,
            label: props.etiquetaPrincipal,
            data: puntos.map((p) => ({
                x: Date.parse(p.fechaIso),
                y: p.valor,
            })),
            borderColor: colores.value.principal,
            backgroundColor: colores.value.principal,
        },
    ];

    if (props.etiquetaSecundaria !== null) {
        conjuntos.push({
            ...comun,
            label: props.etiquetaSecundaria,
            data: puntos
                .filter((p) => p.valorSecundario !== null)
                .map((p) => ({
                    x: Date.parse(p.fechaIso),
                    y: p.valorSecundario as number,
                })),
            borderColor: colores.value.secundario,
            backgroundColor: colores.value.secundario,
        });
    }

    return { datasets: conjuntos };
});

const opciones = computed<ChartOptions<'line'>>(() => ({
    responsive: true,
    // El contenedor manda: en apaisado el gráfico se ensancha y se redibuja
    // solo, que es el caso de uso real de rotar el celular.
    maintainAspectRatio: false,
    interaction: { mode: 'nearest', intersect: false },
    plugins: {
        legend: { display: false },
        tooltip: {
            titleFont: { size: tipografia() },
            bodyFont: { size: tipografia() },
            padding: 10,
            /*
             * Chart.js tipa lo interpretado como `number | null` porque un
             * punto puede venir salteado. Acá no pasa -las series no tienen
             * huecos-, pero se contempla igual en vez de forzar el tipo: un
             * cast taparía el día que sí haya uno y saldría "NaN" en
             * pantalla.
             */
            callbacks: {
                title: (elementos) => {
                    const x = elementos[0]?.parsed.x;

                    return typeof x === 'number' ? formatearFecha(x) : '';
                },
                label: (elemento) => {
                    const y = elemento.parsed.y;

                    return typeof y === 'number'
                        ? `${elemento.dataset.label}: ${formatearValor(y)} ${props.unidad}`
                        : '';
                },
            },
        },
    },
    scales: {
        x: {
            type: 'linear',
            // Linear con la fecha en milisegundos, y no una escala de
            // tiempo: esa necesita un adaptador de fechas -otra dependencia-
            // y acá alcanza con formatear la marca. Además respeta la
            // separación real entre tomas, que una escala de categorías
            // aplanaría (diez tomas de una semana y una de hace un año se
            // verían igual de separadas).
            ticks: {
                color: colores.value.texto,
                font: { size: tipografia() },
                maxRotation: 0,
                autoSkipPadding: 16,
                callback: (valor) => formatearFecha(Number(valor)),
            },
            grid: { color: colores.value.grilla },
        },
        y: {
            // Sin `beginAtZero`: ver la regla 1 del comentario de arriba.
            min: escalaY.value.min,
            max: escalaY.value.max,
            ticks: {
                color: colores.value.texto,
                font: { size: tipografia() },
                callback: (valor) => formatearValor(Number(valor)),
            },
            grid: { color: colores.value.grilla },
        },
    },
}));
</script>

<template>
    <div class="h-64 w-full sm:h-72" role="img" :aria-label="resumenAccesible">
        <Line :data="datos" :options="opciones" :plugins="[bandas]" />
    </div>
</template>
