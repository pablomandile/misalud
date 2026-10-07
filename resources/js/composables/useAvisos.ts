import { usePage } from '@inertiajs/vue3';
import { nextTick, onMounted, watch } from 'vue';
import { toast } from 'vue-sonner';

type Flash = {
    exito?: string | null;
    error?: string | null;
    id: string;
};

/**
 * Convierte el flash del servidor en un toast.
 *
 * Se llama **una sola vez, desde el layout**. Dispararlo desde el componente
 * que hizo el submit no funciona cuando la acción termina en un redirect: ese
 * componente se desmonta antes de que el toast llegue a montarse, y el aviso se
 * pierde sin que nada lo delate.
 */
export function useAvisos(): void {
    const page = usePage();

    function mostrar(): void {
        const flash = page.props.flash as Flash | null;

        if (!flash) {
            return;
        }

        if (flash.error) {
            /*
             * Los errores no se cierran solos. Son los que hay que poder leer
             * dos veces, y a quien le cuesta leer rápido un cartel que se
             * desvanece a los cuatro segundos no le sirve de nada.
             */
            toast.error(flash.error, { duration: Infinity });

            return;
        }

        if (flash.exito) {
            toast.success(flash.exito);
        }
    }

    /*
     * `watch` y no solo `onMounted`: en una SPA el layout no se vuelve a montar
     * entre navegaciones, así que con `onMounted` aparecería el primer mensaje de
     * la sesión y ninguno más.
     *
     * Se observa el `id` —que el servidor renueva en cada mensaje— y no el
     * texto. Mirando el texto, dos éxitos seguidos iguales ("Se guardó el
     * estudio") disparan un solo toast, porque el watch no ve ningún cambio. El
     * síntoma se lee como "a veces no avisa" y es muy difícil de reproducir a
     * propósito.
     */
    watch(() => (page.props.flash as Flash | null)?.id, mostrar);

    /*
     * ⚠️ El aviso que llega JUNTO CON el montaje del layout va acá, y no con un
     * `immediate: true` en el watch. Ese `immediate` corría durante el setup del
     * layout, ANTES de que su `<Toaster>` hijo estuviera montado y escuchando:
     * el toast se pedía y se perdía, sin ningún error. Pasaba cada vez que el
     * layout con menú se montaba por primera vez trayendo un aviso -por ejemplo,
     * al aceptar una invitación, que viene del layout de ingreso-. Medido en
     * Chrome: el flash estaba en las props, el Toaster existía, y había 0 toasts.
     *
     * `onMounted` del layout corre después de los `mounted` de sus hijos, y el
     * `nextTick` le da al Toaster su vuelta para suscribirse.
     */
    onMounted(() => nextTick(mostrar));
}
