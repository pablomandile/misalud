<script setup lang="ts">
import { computed, reactive } from 'vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

/*
 * Un ojo de una receta de anteojos: el dibujo arriba, los campos abajo.
 *
 * ## El diagrama orienta; los campos cargan
 *
 * Que sea lindo no lo exime: **el SVG no es una forma de cargar datos**. No
 * recibe foco, no responde a ningún toque y va con `aria-hidden`. Todo lo
 * que se escribe se escribe en un `<input>` rotulado, navegable por teclado
 * y legible por un lector de pantalla. En una app para personas mayores, un
 * dibujo al que hay que acertarle a una zona es exactamente lo contrario de
 * lo que hace falta.
 *
 * Lo que el dibujo sí hace, y ningún campo puede hacer, es mostrar **el eje
 * como un ángulo**. "x 90" no le dice nada a nadie; una línea vertical sobre
 * un ojo se entiende sin explicación. Por eso el número va igual al lado: el
 * dibujo agrega, nunca reemplaza.
 *
 * ## ⚠️ Los ocho campos van por `v-model`, y NO por `:value`
 *
 * Es la corrección de un bug encontrado en Chrome, no una preferencia de
 * estilo. Con `:value` -como el resto de los formularios de esta app-, el
 * eje tenía que ser igual `v-model` para que el dibujo lo siguiera en vivo;
 * y en cuanto un solo campo del componente maneja estado propio, **cada
 * tecla que se escribe en él vuelve a renderizar el componente entero y
 * pisa los `:value` de los hermanos con el valor del prop**.
 *
 * Medido: escribir la esfera, el cilindro y recién después el eje -el orden
 * natural, el del papel- dejaba la esfera y el cilindro **vacíos** al primer
 * tecleo del eje. Y como los dos son opcionales, la receta se guardaba con
 * el ojo en blanco y un "Se agregó la receta" en verde: el peor modo de
 * falla posible, sin ningún síntoma.
 *
 * La regla que queda: **si un campo del formulario necesita estado local,
 * todos los de ese componente lo necesitan.** Mezclar `:value` con `v-model`
 * en el mismo componente es lo que rompe.
 *
 * ## Por qué un solo componente para cargar y para leer
 *
 * `modo` decide si abajo van inputs o texto, pero el rótulo del ojo y el
 * lado en el que cae viven **una sola vez**. Con dos componentes, el
 * formulario y el listado podrían terminar en desacuerdo sobre cuál ojo es
 * cuál, y un error así no se ve en pantalla: termina en unos anteojos
 * fabricados al revés.
 *
 * ## El dibujo es simétrico, y no es por vagancia
 *
 * Un ojo espejado por lado —el lagrimal hacia adentro— sería más realista y
 * sugeriría algo falso: que la escala del eje también se espeja. **No se
 * espeja.** El eje se mide igual para los dos ojos, tal como los ve quien
 * está enfrente, y de ahí sale que un astigmatismo simétrico se anote 20° en
 * un ojo y 160° en el otro. Un almendro simétrico no promete nada que
 * después no se cumpla.
 */

export type ValoresDeOjo = {
    esfera: string | null;
    esferaVisible: string | null;
    cilindro: string | null;
    cilindroVisible: string | null;
    eje: string | null;
    adicion: string | null;
    adicionVisible: string | null;
    dp_monocular: string | null;
    prisma: string | null;
    base: string | null;
    agudeza_visual: string | null;
    resumen: string | null;
    sinDatos: boolean;
};

const props = withDefaults(
    defineProps<{
        ojo: 'od' | 'oi';
        modo?: 'carga' | 'lectura';
        valores?: ValoresDeOjo | null;
        errores?: Record<string, string | undefined>;
        /*
         * Solo tiene efecto en modo lectura: muestra la esfera, el
         * cilindro y el eje en su forma equivalente (transposición), sin
         * tocar lo guardado. Ver el comentario largo sobre `transposicion`
         * más abajo.
         */
        transpuesto?: boolean;
    }>(),
    { modo: 'carga', valores: null, errores: () => ({}), transpuesto: false },
);

const campoBase =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';
const campoUnaLinea = `${campoBase} min-h-11`;

/*
 * El rótulo SIEMPRE lleva el nombre completo además de la sigla: la mayoría
 * de la gente no sabe qué es OD, y acá confundirse no queda en un dato raro.
 */
const rotulo = computed(() =>
    props.ojo === 'od' ? 'OD · ojo derecho' : 'OI · ojo izquierdo',
);

const campos = [
    {
        clave: 'esfera',
        etiqueta: 'Esfera',
        ayuda: 'con signo: -1,25',
        teclado: 'decimal',
    },
    {
        clave: 'cilindro',
        etiqueta: 'Cilindro',
        ayuda: 'con signo: -0,50',
        teclado: 'decimal',
    },
    { clave: 'eje', etiqueta: 'Eje', ayuda: 'de 0 a 180', teclado: 'numeric' },
    {
        clave: 'adicion',
        etiqueta: 'Adición',
        ayuda: 'para cerca: +2,00',
        teclado: 'decimal',
    },
    {
        clave: 'dp_monocular',
        etiqueta: 'Distancia pupilar',
        ayuda: 'en mm',
        teclado: 'decimal',
    },
    {
        clave: 'prisma',
        etiqueta: 'Prisma',
        ayuda: 'solo si la receta lo trae',
        teclado: 'decimal',
    },
    { clave: 'base', etiqueta: 'Base', ayuda: 'del prisma', teclado: null },
    {
        clave: 'agudeza_visual',
        etiqueta: 'Agudeza visual',
        ayuda: '20/20',
        teclado: null,
    },
] as const;

function valorInicial(clave: string): string {
    const valores = props.valores as Record<string, unknown> | null;
    const valor = valores?.[clave];

    return typeof valor === 'string' ? valor : '';
}

/*
 * El estado de los ocho campos. Se arranca del prop UNA vez: el componente se
 * remonta cuando cambia la receta que se está editando (ver el `:key` del
 * `<Form>` en la pantalla), así que no hace falta seguir el prop después.
 *
 * Se llama `escrito` y no `valores` **a propósito**: `valores` ya es el
 * nombre del prop, y una constante local con ese nombre lo taparía en el
 * template -el modo lectura pasaría a leer los campos del formulario en vez
 * de lo guardado, y mostraría "sin datos" en toda receta-.
 */
const escrito = reactive<Record<string, string>>(
    Object.fromEntries(
        campos.map((campo) => [campo.clave, valorInicial(campo.clave)]),
    ),
);

/*
 * Igual que `GraduacionOcular::formatearDioptria()` en el backend: signo
 * obligatorio salvo en cero, dos decimales, coma. Se repite acá -y no se
 * manda formateada desde el servidor- porque la transposición es un cálculo
 * que solo tiene sentido hacerlo con el número ya en la mano, del lado
 * donde se decide mostrarlo o no.
 */
function formatearDioptria(valor: number): string {
    const limpio = valor === 0 ? 0 : valor;
    const texto =
        limpio === 0
            ? (0).toFixed(2)
            : `${limpio > 0 ? '+' : ''}${limpio.toFixed(2)}`;

    return texto.replace('.', ',');
}

/*
 * La transposición: la misma corrección óptica, escrita con el cilindro en
 * el signo contrario.
 *
 *   esfera' = esfera + cilindro
 *   cilindro' = -cilindro
 *   eje' = eje + 90 (o -90 si eje ya pasa los 90, para quedar en 0-180)
 *
 * Es aritmética, no una opinión: las dos formas describen el mismo lente.
 * Se guarda SIEMPRE la que trae el papel (ver `PrescripcionOcularController`
 * y CLAUDE.md) y esto solo cambia lo que se MUESTRA, nunca lo guardado -por
 * eso vive acá, del lado de la lectura, y no toca ningún campo del alta.
 *
 * Sin cilindro no hay nada que transponer: la esfera sola no tiene "la otra
 * forma".
 */
const transposicion = computed(() => {
    const v = props.valores;
    const cilindro =
        v?.cilindro !== null && v?.cilindro !== undefined
            ? Number(v.cilindro)
            : null;

    if (
        !v ||
        cilindro === null ||
        cilindro === 0 ||
        v.esfera === null ||
        v.eje === null
    ) {
        return null;
    }

    const esfera = Number(v.esfera);
    const eje = Number(v.eje);

    const esferaT = esfera + cilindro;
    const cilindroT = -cilindro;
    const ejeT = eje <= 90 ? eje + 90 : eje - 90;

    const esferaVisible = formatearDioptria(esferaT);
    const cilindroVisible = formatearDioptria(cilindroT);

    const partes = [
        esferaVisible,
        `${cilindroVisible} x ${ejeT}°`,
        v.adicionVisible ? `Add ${v.adicionVisible}` : null,
    ].filter((p): p is string => p !== null);

    return {
        esferaVisible,
        cilindroVisible,
        eje: String(ejeT),
        resumen: partes.join('  '),
    };
});

// Lo efectivamente mostrado: transpuesto solo si se pidió Y hay algo que
// transponer -sin cilindro, `transposicion` es null y se cae al original-.
const mostrado = computed(() => {
    if (
        props.modo === 'carga' ||
        !props.transpuesto ||
        transposicion.value === null
    ) {
        return {
            esferaVisible: props.valores?.esferaVisible ?? null,
            cilindroVisible: props.valores?.cilindroVisible ?? null,
            eje: props.valores?.eje ?? null,
            resumen: props.valores?.resumen ?? null,
        };
    }

    return transposicion.value;
});

const ejeDibujado = computed<number | null>(() => {
    const crudo =
        props.modo === 'carga' ? escrito.eje : (mostrado.value.eje ?? '');
    const numero = Number(String(crudo ?? '').trim());

    if (!crudo || !Number.isFinite(numero) || numero < 0 || numero > 180) {
        return null;
    }

    return numero;
});

const CENTRO_X = 70;
const CENTRO_Y = 45;
const LARGO_EJE = 33;

/*
 * Un eje es un MERIDIANO: la línea cruza el ojo entera, no es una flecha.
 * El ángulo se mide en sentido antihorario desde la horizontal, así que en
 * coordenadas SVG -donde la `y` crece hacia abajo- el seno va restado.
 */
const lineaDelEje = computed(() => {
    const grados = ejeDibujado.value;

    if (grados === null) {
        return null;
    }

    const radianes = (grados * Math.PI) / 180;
    const dx = Math.cos(radianes) * LARGO_EJE;
    const dy = Math.sin(radianes) * LARGO_EJE;

    return {
        x1: CENTRO_X - dx,
        y1: CENTRO_Y + dy,
        x2: CENTRO_X + dx,
        y2: CENTRO_Y - dy,
    };
});

function errorDe(clave: string): string | undefined {
    return props.errores?.[`${props.ojo}.${clave}`];
}

// Las filas del modo lectura, sin las que no tienen nada cargado: un campo
// vacío ocupa lugar y no dice nada (regla 2, "sin datos" se dice una vez).
// Esfera, cilindro y eje salen de `mostrado` -el original o el transpuesto,
// según el toggle-; el resto de la receta no cambia con la transposición.
const filasVisibles = computed(() =>
    [
        { etiqueta: 'Esfera', valor: mostrado.value.esferaVisible },
        {
            etiqueta: 'Cilindro',
            valor: mostrado.value.cilindroVisible
                ? `${mostrado.value.cilindroVisible}${mostrado.value.eje ? ` x ${mostrado.value.eje}°` : ''}`
                : null,
        },
        { etiqueta: 'Adición', valor: props.valores?.adicionVisible },
        {
            etiqueta: 'Distancia pupilar',
            valor: props.valores?.dp_monocular
                ? `${props.valores.dp_monocular} mm`
                : null,
        },
        {
            etiqueta: 'Prisma',
            valor: props.valores?.prisma
                ? `${props.valores.prisma}${props.valores.base ? ` · base ${props.valores.base}` : ''}`
                : null,
        },
        { etiqueta: 'Agudeza visual', valor: props.valores?.agudeza_visual },
    ].filter(
        (fila): fila is { etiqueta: string; valor: string } => !!fila.valor,
    ),
);
</script>

<template>
    <fieldset class="space-y-3 rounded-lg border p-4">
        <!--
            `legend` y no un `<p>`: es lo que hace que un lector de pantalla
            anuncie "OD · ojo derecho" junto a CADA campo de adentro. Con
            dos ojos en la misma pantalla, un "Esfera" suelto es ambiguo.
        -->
        <legend class="px-1 text-sm font-medium">{{ rotulo }}</legend>

        <div class="flex justify-center">
            <!--
                Decoración con un propósito: orienta, no informa nada que no
                esté también escrito. Por eso `aria-hidden` y sin foco.
            -->
            <svg
                viewBox="0 0 140 90"
                class="h-auto w-full max-w-55"
                aria-hidden="true"
                focusable="false"
            >
                <path
                    d="M 10 45 Q 70 6 130 45 Q 70 84 10 45 Z"
                    class="fill-muted stroke-border"
                    stroke-width="2"
                />
                <circle
                    :cx="CENTRO_X"
                    :cy="CENTRO_Y"
                    r="22"
                    class="fill-background stroke-border"
                    stroke-width="1.5"
                />
                <circle
                    :cx="CENTRO_X"
                    :cy="CENTRO_Y"
                    r="9"
                    class="fill-foreground/70"
                />

                <!-- La referencia de 0°–180°, para que el ángulo se lea. -->
                <line
                    :x1="CENTRO_X - LARGO_EJE"
                    :y1="CENTRO_Y"
                    :x2="CENTRO_X + LARGO_EJE"
                    :y2="CENTRO_Y"
                    class="stroke-border"
                    stroke-width="1"
                    stroke-dasharray="3 3"
                />

                <line
                    v-if="lineaDelEje"
                    :x1="lineaDelEje.x1"
                    :y1="lineaDelEje.y1"
                    :x2="lineaDelEje.x2"
                    :y2="lineaDelEje.y2"
                    class="stroke-primary"
                    stroke-width="3"
                    stroke-linecap="round"
                />
            </svg>
        </div>

        <p
            v-if="ejeDibujado !== null"
            class="text-center text-sm text-muted-foreground"
        >
            Eje {{ ejeDibujado }}°
        </p>

        <!-- ===================== Cargar ===================== -->
        <div v-if="modo === 'carga'" class="grid gap-3 sm:grid-cols-2">
            <div v-for="campo in campos" :key="campo.clave" class="grid gap-2">
                <Label :for="`${campo.clave}-${ojo}`">
                    {{ campo.etiqueta }}
                    <span class="font-normal text-muted-foreground">
                        ({{ campo.ayuda }})
                    </span>
                </Label>

                <!--
                    ⚠️ `type="text"` con `inputmode`, NUNCA `type="number"`:
                    en un navegador es-AR, un `number` con coma entrega un
                    valor VACÍO al enviar, porque considera el campo
                    inválido y no expone lo tipeado. Misma regla que las
                    mediciones.

                    Y `v-model` en los ocho, no en uno solo: ver el
                    comentario de arriba, es lo que evita que escribir el
                    eje borre la esfera.
                -->
                <input
                    :id="`${campo.clave}-${ojo}`"
                    v-model="escrito[campo.clave]"
                    :name="`${ojo}[${campo.clave}]`"
                    type="text"
                    :inputmode="campo.teclado ?? undefined"
                    autocomplete="off"
                    :class="campoUnaLinea"
                />

                <InputError :message="errorDe(campo.clave)" />
            </div>
        </div>

        <!-- ===================== Leer ===================== -->
        <template v-else>
            <p
                v-if="valores?.sinDatos"
                class="text-center text-sm text-muted-foreground"
            >
                Sin datos
            </p>

            <template v-else>
                <p class="text-center font-medium">
                    {{ mostrado.resumen }}
                    <span
                        v-if="transpuesto && transposicion !== null"
                        class="font-normal text-muted-foreground"
                    >
                        (transpuesto)
                    </span>
                </p>

                <dl class="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-2">
                    <div
                        v-for="fila in filasVisibles"
                        :key="fila.etiqueta"
                        class="flex justify-between gap-2 border-b py-1"
                    >
                        <dt class="text-muted-foreground">
                            {{ fila.etiqueta }}
                        </dt>
                        <dd class="text-right">{{ fila.valor }}</dd>
                    </div>
                </dl>
            </template>
        </template>
    </fieldset>
</template>
