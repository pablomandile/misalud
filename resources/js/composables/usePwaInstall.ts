import { onMounted, onUnmounted, ref, type Ref } from 'vue';

type PromptDeInstalacion = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

declare global {
    interface Window {
        __pwaInstall?: {
            prompt: PromptDeInstalacion | null;
            instalada: boolean;
        };
    }
}

export type UsePwaInstallReturn = {
    /** Hay que ofrecer instalar: ni está instalada ni es un navegador que no puede. */
    sePuedeOfrecer: Ref<boolean>;
    /** En iOS no hay prompt: la instalación es a mano y hay que explicarla. */
    esIos: Ref<boolean>;
    /** Ya se usó el prompt del navegador; de acá en más va el instructivo. */
    promptGastado: Ref<boolean>;
    instalar: () => Promise<void>;
};

function estaInstalada(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        // iOS no implementa `display-mode: standalone` en matchMedia.
        (window.navigator as Navigator & { standalone?: boolean })
            .standalone === true
    );
}

function detectarIos(): boolean {
    if (typeof navigator === 'undefined') {
        return false;
    }

    /*
     * iPadOS se declara como Mac desde iOS 13, así que el user agent solo no
     * alcanza. Lo que lo delata es que tenga pantalla táctil: una Mac no.
     */
    const esIpadDisfrazado =
        /Macintosh/.test(navigator.userAgent) && navigator.maxTouchPoints > 1;

    return /iPad|iPhone|iPod/.test(navigator.userAgent) || esIpadDisfrazado;
}

/**
 * Estado del botón "Instalar la app".
 *
 * El evento `beforeinstallprompt` lo captura un script en el `<head>`, antes de
 * que monte Vue: para cuando este composable corre, ya pasó. Acá solo se lee lo
 * que ese script guardó y se escuchan los eventos propios que dispara.
 */
export function usePwaInstall(): UsePwaInstallReturn {
    const esIos = ref(false);
    const hayPrompt = ref(false);
    const instalada = ref(false);
    const promptGastado = ref(false);
    const sePuedeOfrecer = ref(false);

    function recalcular(): void {
        /*
         * En iOS el botón se muestra igual aunque no haya prompt: Safari nunca
         * dispara `beforeinstallprompt` y la instalación es manual. Si se
         * condicionara el botón a que exista el prompt, en iPhone no aparecería
         * nunca — y es justo donde más se usa esta app.
         *
         * `promptGastado` lo mantiene visible después de usar el prompt: ahí
         * ya no hay evento que lanzar, pero sigue haciendo falta el botón
         * para mostrar el instructivo del menú del navegador.
         */
        sePuedeOfrecer.value =
            !instalada.value &&
            (hayPrompt.value || esIos.value || promptGastado.value);
    }

    function alInstalable(): void {
        hayPrompt.value = true;
        recalcular();
    }

    function alInstalada(): void {
        instalada.value = true;
        recalcular();
    }

    onMounted(() => {
        esIos.value = detectarIos();
        instalada.value = estaInstalada();
        hayPrompt.value = !!window.__pwaInstall?.prompt;
        recalcular();

        window.addEventListener('pwa:instalable', alInstalable);
        window.addEventListener('pwa:instalada', alInstalada);
    });

    onUnmounted(() => {
        window.removeEventListener('pwa:instalable', alInstalable);
        window.removeEventListener('pwa:instalada', alInstalada);
    });

    async function instalar(): Promise<void> {
        const prompt = window.__pwaInstall?.prompt;

        if (!prompt) {
            return;
        }

        await prompt.prompt();
        await prompt.userChoice;

        /*
         * El prompt se consume una sola vez, incluso si la persona lo descarta.
         * Por eso no se esconde el botón: quien lo cerró sin querer tiene que
         * poder reintentar, y en una SPA una recarga completa casi no pasa. A
         * partir de acá el botón muestra el instructivo del navegador.
         */
        window.__pwaInstall!.prompt = null;
        hayPrompt.value = false;
        promptGastado.value = true;
        recalcular();
    }

    return { sePuedeOfrecer, esIos, promptGastado, instalar };
}
