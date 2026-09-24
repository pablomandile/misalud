<script setup lang="ts">
import {
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    X,
    ZoomIn,
    ZoomOut,
} from '@lucide/vue';
import {
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { computed, nextTick, ref, shallowRef, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

/**
 * Visor de documentos a pantalla completa, para imágenes y PDFs.
 *
 * **Uno solo por pantalla, y FUERA de cualquier `v-for`.** Uno por archivo
 * multiplicaría los overlays y los focus traps de reka-ui por la cantidad de
 * documentos que haya en la lista. La página guarda cuál está mirando y se lo
 * pasa a este único visor.
 *
 * Se usan las primitivas de reka-ui y no el `DialogContent` de `ui/dialog`:
 * ese trae `max-w-lg`, bordes redondeados y padding, que es lo contrario de una
 * pantalla completa. De reka-ui interesan el foco atrapado, el Esc y el bloqueo
 * del scroll del fondo, que sí hacen falta.
 */

export type DocumentoVisible = {
    /** La URL del controlador, no del disco: estos archivos no son públicos. */
    url: string;
    nombre: string;
    mime: string;
};

/**
 * Cuánto se espera a que abra un PDF antes de ofrecer la salida.
 *
 * Generoso a propósito: el reloj arranca DESPUÉS de que pdf.js ya está
 * cargado, y lo que queda por bajar es el archivo -hasta 12 MB- desde una
 * sala de espera con mala señal, que es el caso real de esta app.
 */
const ESPERA_MAXIMA = 45000;

const props = defineProps<{ documento: DocumentoVisible | null }>();
const emit = defineEmits<{ cerrar: [] }>();

const abierto = computed(() => props.documento !== null);
const esPdf = computed(() => props.documento?.mime === 'application/pdf');

const cargando = ref(false);
const fallo = ref(false);
const pagina = ref(1);
const paginas = ref(0);
const escala = ref(1);

const lienzo = ref<HTMLCanvasElement | null>(null);

/*
 * `shallowRef` y no `ref`: el documento de pdf.js es un objeto grande con
 * referencias circulares y su propio ciclo de vida. Envolverlo en un proxy
 * reactivo lo rompe y además cuesta carísimo.
 */
const pdf = shallowRef<{
    numPages: number;
    getPage: (n: number) => Promise<unknown>;
} | null>(null);

/*
 * La tarea de carga, aparte del documento, porque **es ella la que se
 * destruye**: `PDFDocumentProxy` tenía un `destroy()` hasta pdf.js 5 y en 6
 * ya no está. Llamarlo tiraba `destroy is not a function` al cerrar el
 * visor —silencioso en pantalla, pero dejaba el worker y el documento vivos:
 * un PDF filtrado por cada uno que se abriera—. `destroy()` de la tarea es
 * la API documentada y la que sobrevivió a las dos versiones.
 */
const tareaDeCarga = shallowRef<{ destroy: () => Promise<void> } | null>(null);

/**
 * pdf.js entra por `import()` dinámico y solo al abrir un PDF.
 *
 * Pesa cerca de un mega: en el bundle inicial se lo bancaría cada persona que
 * abre la app en una sala de espera con mala señal, para una pantalla que
 * quizás no visite.
 */
async function cargarPdf(url: string): Promise<void> {
    cargando.value = true;
    fallo.value = false;

    try {
        /*
         * El worker se instancia acá y se le pasa a pdf.js por `workerPort`,
         * en vez de darle una URL en `workerSrc`.
         *
         * Las dos formas andan -está probado en Chrome con las dos-. Se elige
         * esta porque no depende de que la URL del worker se resuelva bien en
         * tiempo de ejecución: el bundler arma el worker y entrega el
         * constructor. Con `workerSrc` hay una ruta más que puede quedar mal
         * detrás del CDN o con un `base` distinto, y el síntoma sería un visor
         * que gira para siempre.
         */
        const [pdfjs, { default: Trabajador }] = await Promise.all([
            import('pdfjs-dist'),
            import('pdfjs-dist/build/pdf.worker.min.mjs?worker'),
        ]);

        pdfjs.GlobalWorkerOptions.workerPort = new Trabajador();

        const tarea = pdfjs.getDocument({ url, withCredentials: true });
        tareaDeCarga.value = tarea;

        /*
         * Tope de tiempo. Un visor que gira para siempre es peor que uno que
         * dice que no pudo: sin esto, cualquier cuelgue futuro de pdf.js se ve
         * como "la app se colgó" y no ofrece ninguna salida.
         */
        const documento = await Promise.race([
            tarea.promise,
            new Promise<never>((_, rechazar) =>
                setTimeout(
                    () => rechazar(new Error('El documento tardó demasiado.')),
                    ESPERA_MAXIMA,
                ),
            ),
        ]);

        pdf.value = documento as unknown as typeof pdf.value;
        paginas.value = documento.numPages;
        pagina.value = 1;

        /*
         * `nextTick` antes de dibujar: el <canvas> vive dentro del portal del
         * diálogo, que Vue monta recién despues de que `documento` deje de ser
         * null. Sin esperar, `lienzo` todavía es null y `dibujar()` se va sin
         * hacer nada -y como `pagina` ya valía 1, su watch tampoco dispara-.
         */
        await nextTick();
        await dibujar();
    } catch {
        /*
         * No se muestra el detalle: los errores de pdf.js no le dicen nada a
         * nadie. Se cae al enlace de "abrir en otra pestaña", que es una salida
         * de verdad y no una pantalla en negro.
         */
        fallo.value = true;
    } finally {
        cargando.value = false;
    }
}

async function dibujar(): Promise<void> {
    const documento = pdf.value;
    const canvas = lienzo.value;

    if (!documento || !canvas) {
        return;
    }

    const hoja = (await documento.getPage(pagina.value)) as {
        getViewport: (o: { scale: number }) => {
            width: number;
            height: number;
        };
        render: (o: unknown) => { promise: Promise<void> };
    };

    /*
     * Se dibuja a la resolución real del dispositivo y se baja por CSS. Sin
     * esto, en un celular con pantalla densa el texto del PDF se ve borroso
     * justo en el aparato con el que más se lo mira.
     */
    const densidad = Math.min(window.devicePixelRatio || 1, 3);
    const vista = hoja.getViewport({ scale: escala.value * densidad });
    const contexto = canvas.getContext('2d');

    if (!contexto) {
        fallo.value = true;

        return;
    }

    canvas.width = vista.width;
    canvas.height = vista.height;
    canvas.style.width = `${vista.width / densidad}px`;
    canvas.style.height = `${vista.height / densidad}px`;

    await hoja.render({ canvasContext: contexto, viewport: vista }).promise;
}

async function soltar(): Promise<void> {
    const tarea = tareaDeCarga.value;
    pdf.value = null;
    tareaDeCarga.value = null;
    paginas.value = 0;
    escala.value = 1;

    // Libera el worker. Sin esto, abrir y cerrar varios PDFs deja uno vivo por vez.
    await tarea?.destroy().catch(() => undefined);
}

watch(
    () => props.documento,
    async (documento) => {
        await soltar();

        if (documento && documento.mime === 'application/pdf') {
            await cargarPdf(documento.url);
        } else {
            fallo.value = false;
        }
    },
);

watch([pagina, escala], () => {
    void dibujar();
});

/*
 * Red de seguridad por si el <canvas> se monta después de que el PDF ya
 * terminó de cargar. Es el mismo problema que resuelve el `nextTick`, visto
 * desde el otro lado, y cuesta tres líneas.
 */
watch(lienzo, (canvas) => {
    if (canvas && pdf.value) {
        void dibujar();
    }
});

function cambiarPagina(delta: number): void {
    pagina.value = Math.min(Math.max(pagina.value + delta, 1), paginas.value);
}

function cambiarEscala(delta: number): void {
    escala.value = Math.min(Math.max(escala.value + delta, 0.5), 3);
}

function cerrar(): void {
    emit('cerrar');
}
</script>

<template>
    <DialogRoot
        :open="abierto"
        @update:open="
            (v: boolean) => {
                if (!v) cerrar();
            }
        "
    >
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-50 bg-black/90" />

            <DialogContent
                class="fixed inset-0 z-50 flex h-[100dvh] w-screen flex-col bg-black/95 outline-none"
            >
                <!-- Para lector de pantalla: el visor no tiene encabezado visible. -->
                <DialogTitle class="sr-only">
                    {{ documento?.nombre ?? 'Documento' }}
                </DialogTitle>
                <DialogDescription class="sr-only">
                    Documento a pantalla completa. Cerrá con la X o con la tecla
                    Escape.
                </DialogDescription>

                <!--
                    La barra respeta la zona segura ARRIBA y a los COSTADOS: en
                    apaisado el notch queda al costado, que es el inset que
                    siempre se olvida.
                -->
                <div
                    class="flex items-center justify-between gap-2 px-[max(0.75rem,env(safe-area-inset-left))] pt-[max(0.75rem,env(safe-area-inset-top))] pb-3"
                >
                    <p class="min-w-0 flex-1 truncate text-sm text-white/90">
                        {{ documento?.nombre }}
                    </p>

                    <!--
                        X propia, opaca y grande. La del DialogContent de shadcn
                        va con opacity-70 y sin área táctil: sobre un PDF blanco
                        o una radiografía oscura no se ve, y en el celular no se
                        acierta.
                    -->
                    <Button
                        variant="secondary"
                        size="icon"
                        class="shrink-0 rounded-full"
                        aria-label="Cerrar el documento"
                        @click="cerrar"
                    >
                        <X class="size-5" />
                    </Button>
                </div>

                <div
                    class="flex flex-1 items-center justify-center overflow-auto px-[max(0.5rem,env(safe-area-inset-left))]"
                >
                    <div
                        v-if="cargando"
                        class="flex flex-col items-center gap-3 text-white/80"
                    >
                        <Spinner />
                        <p class="text-sm">Abriendo el documento…</p>
                    </div>

                    <!--
                        Si pdf.js no pudo, queda una salida de verdad. Antes de
                        esto la alternativa era una pantalla en negro sin
                        explicación.
                    -->
                    <div
                        v-else-if="fallo"
                        class="flex max-w-sm flex-col items-center gap-4 p-6 text-center text-white/90"
                    >
                        <p>No pudimos mostrar este documento acá.</p>
                        <Button as-child variant="secondary">
                            <a
                                :href="documento?.url"
                                target="_blank"
                                rel="noopener"
                            >
                                <ExternalLink />
                                Abrirlo en otra pestaña
                            </a>
                        </Button>
                    </div>

                    <!-- `object-contain`, nunca `cover`: recortar algo que la
                         persona abrió para leer es perder lo que fue a ver. -->
                    <img
                        v-else-if="documento && !esPdf"
                        :src="documento.url"
                        :alt="documento.nombre"
                        class="max-h-full max-w-full object-contain"
                    />

                    <canvas
                        v-show="esPdf && !fallo"
                        ref="lienzo"
                        class="mx-auto"
                    />
                </div>

                <!-- Controles abajo, donde llega el pulgar. -->
                <div
                    v-if="esPdf && !fallo && paginas > 0"
                    class="flex items-center justify-center gap-2 px-3 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]"
                >
                    <Button
                        variant="secondary"
                        size="icon"
                        aria-label="Alejar"
                        :disabled="escala <= 0.5"
                        @click="cambiarEscala(-0.25)"
                    >
                        <ZoomOut />
                    </Button>
                    <Button
                        variant="secondary"
                        size="icon"
                        aria-label="Acercar"
                        :disabled="escala >= 3"
                        @click="cambiarEscala(0.25)"
                    >
                        <ZoomIn />
                    </Button>

                    <template v-if="paginas > 1">
                        <Button
                            variant="secondary"
                            size="icon"
                            aria-label="Página anterior"
                            :disabled="pagina <= 1"
                            @click="cambiarPagina(-1)"
                        >
                            <ChevronLeft />
                        </Button>
                        <p class="min-w-20 text-center text-sm text-white/90">
                            {{ pagina }} de {{ paginas }}
                        </p>
                        <Button
                            variant="secondary"
                            size="icon"
                            aria-label="Página siguiente"
                            :disabled="pagina >= paginas"
                            @click="cambiarPagina(1)"
                        >
                            <ChevronRight />
                        </Button>
                    </template>
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
