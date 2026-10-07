import { computed, readonly, ref } from 'vue';
import { toast } from 'vue-sonner';

/*
 * El reproductor de grabaciones de consultas.
 *
 * ⚠️ **El estado vive en el MÓDULO, no en el componente**, con UN SOLO
 * elemento `Audio` para toda la app. Es lo que permite que una grabación siga
 * sonando mientras se navega a otra pantalla: en una SPA la navegación
 * reemplaza la página, y un `<audio>` que viviera en ella se cortaría en cada
 * toque. Por lo mismo, la barra (`Reproductor.vue`) se monta en el layout.
 *
 * El audio llega por `/adjuntos/{id}`, que contesta `206` con `Range`: sin eso
 * iOS Safari no reproduce y no se podría adelantar (ver
 * `TipoAdjunto::seGuardaCifrado()`).
 */

export type Pista = {
    /** El id del adjunto: identifica la pista, para saber cuál está sonando. */
    id: number;
    url: string;
    /** "Dr. Pérez · 15/03/2026": lo que se ve en la barra y en la pantalla bloqueada. */
    titulo: string;
};

/** Para escuchar a un médico: más lento ayuda a entender, más rápido a repasar. */
export const VELOCIDADES = [0.75, 1, 1.25, 1.5] as const;

/** Lo que adelantan y atrasan los botones: lo bastante para volver a una frase. */
export const SALTO_SEGUNDOS = 15;

let audio: HTMLAudioElement | null = null;

const pista = ref<Pista | null>(null);
const sonando = ref(false);
const cargando = ref(false);
const tiempo = ref(0);
const duracion = ref(0);
const velocidad = ref<number>(1);

function elemento(): HTMLAudioElement {
    if (audio !== null) {
        return audio;
    }

    audio = new Audio();
    audio.preload = 'metadata';

    audio.addEventListener('play', () => {
        sonando.value = true;
        actualizarSesion();
    });
    audio.addEventListener('pause', () => {
        sonando.value = false;
        actualizarSesion();
    });
    audio.addEventListener('ended', () => {
        sonando.value = false;
    });
    audio.addEventListener('waiting', () => (cargando.value = true));
    audio.addEventListener('playing', () => (cargando.value = false));
    audio.addEventListener('canplay', () => (cargando.value = false));
    audio.addEventListener('timeupdate', () => {
        tiempo.value = audio?.currentTime ?? 0;
        actualizarPosicion();
    });
    audio.addEventListener('loadedmetadata', () => {
        const d = audio?.duration ?? 0;
        duracion.value = Number.isFinite(d) ? d : 0;
    });
    audio.addEventListener('error', () => {
        cargando.value = false;
        sonando.value = false;
        /*
         * Un aviso y no silencio: un botón de play que no hace nada es el modo
         * de falla que se lee como "la app no anda". Toast de error, que no se
         * cierra solo (ver useAvisos).
         */
        toast.error('No se pudo reproducir la grabación.', {
            duration: Infinity,
        });
    });

    return audio;
}

/**
 * Media Session: controlar la grabación desde la pantalla bloqueada o los
 * auriculares. Una consulta de 40 minutos no se escucha con la pantalla
 * prendida.
 */
function actualizarSesion(): void {
    if (!('mediaSession' in navigator) || pista.value === null) {
        return;
    }

    navigator.mediaSession.metadata = new MediaMetadata({
        title: pista.value.titulo,
        artist: 'MiSalud',
    });
    navigator.mediaSession.playbackState = sonando.value ? 'playing' : 'paused';
}

function actualizarPosicion(): void {
    if (
        !('mediaSession' in navigator) ||
        !('setPositionState' in navigator.mediaSession) ||
        duracion.value <= 0
    ) {
        return;
    }

    try {
        navigator.mediaSession.setPositionState({
            duration: duracion.value,
            position: Math.min(tiempo.value, duracion.value),
            playbackRate: velocidad.value,
        });
    } catch {
        // Algunos navegadores rechazan estados transitorios: no es un error para la persona.
    }
}

function registrarControlesDelSistema(): void {
    if (!('mediaSession' in navigator)) {
        return;
    }

    const sesion = navigator.mediaSession;
    const manejar = (
        accion: MediaSessionAction,
        fn: (d: MediaSessionActionDetails) => void,
    ) => {
        try {
            sesion.setActionHandler(accion, fn);
        } catch {
            // Acción no soportada en este navegador: se ignora.
        }
    };

    manejar('play', () => void elemento().play());
    manejar('pause', () => elemento().pause());
    manejar('seekbackward', () => saltar(-SALTO_SEGUNDOS));
    manejar('seekforward', () => saltar(SALTO_SEGUNDOS));
    manejar('seekto', (d) => {
        if (d.seekTime !== undefined) {
            irA(d.seekTime);
        }
    });
}

let controlesRegistrados = false;

function reproducir(nueva: Pista): void {
    const a = elemento();

    if (!controlesRegistrados) {
        registrarControlesDelSistema();
        controlesRegistrados = true;
    }

    // La misma pista: alterna, no vuelve a empezar.
    if (pista.value?.id === nueva.id) {
        alternar();

        return;
    }

    pista.value = nueva;
    tiempo.value = 0;
    duracion.value = 0;
    cargando.value = true;
    a.src = nueva.url;
    a.playbackRate = velocidad.value;
    actualizarSesion();
    void a.play().catch(() => {
        // Lo informa el evento `error`; un rechazo por autoplay no aplica: lo
        // dispara siempre un toque.
        cargando.value = false;
    });
}

function alternar(): void {
    const a = elemento();

    if (a.paused) {
        void a.play().catch(() => (cargando.value = false));
    } else {
        a.pause();
    }
}

function saltar(segundos: number): void {
    const a = elemento();
    irA(a.currentTime + segundos);
}

function irA(segundos: number): void {
    const a = elemento();
    const tope = duracion.value > 0 ? duracion.value : a.duration;
    a.currentTime = Math.max(
        0,
        Number.isFinite(tope) ? Math.min(segundos, tope) : segundos,
    );
    tiempo.value = a.currentTime;
}

function cambiarVelocidad(): void {
    const actual = VELOCIDADES.indexOf(
        velocidad.value as (typeof VELOCIDADES)[number],
    );
    velocidad.value = VELOCIDADES[(actual + 1) % VELOCIDADES.length];
    elemento().playbackRate = velocidad.value;
    actualizarPosicion();
}

/**
 * Cerrar la barra: para y suelta el archivo. Se llama también cuando se borra
 * la grabación que está sonando, o quedaría sonando algo que ya no existe.
 */
function cerrar(): void {
    if (audio !== null) {
        audio.pause();
        audio.removeAttribute('src');
        audio.load();
    }

    pista.value = null;
    sonando.value = false;
    cargando.value = false;
    tiempo.value = 0;
    duracion.value = 0;

    if ('mediaSession' in navigator) {
        navigator.mediaSession.metadata = null;
        navigator.mediaSession.playbackState = 'none';
    }
}

/** "3:05" o "1:02:09". */
export function formatoTiempo(segundos: number | null | undefined): string {
    if (
        segundos === null ||
        segundos === undefined ||
        !Number.isFinite(segundos)
    ) {
        return '--:--';
    }

    const total = Math.max(0, Math.floor(segundos));
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = String(total % 60).padStart(2, '0');

    return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
}

export function useReproductor() {
    return {
        pista: readonly(pista),
        sonando: readonly(sonando),
        cargando: readonly(cargando),
        tiempo: readonly(tiempo),
        duracion: readonly(duracion),
        velocidad: readonly(velocidad),
        visible: computed(() => pista.value !== null),
        reproducir,
        alternar,
        saltar,
        irA,
        cambiarVelocidad,
        cerrar,
    };
}
