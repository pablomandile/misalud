import { router, usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

export type TamanioTexto = 'normal' | 'grande' | 'muy-grande';

export type OpcionTamanio = {
    valor: TamanioTexto;
    etiqueta: string;
    descripcion: string;
    /** El porcentaje como número (100, 112.5, 125), para calcular la muestra. */
    escala: number;
};

export type UseTamanioTextoReturn = {
    tamanio: ComputedRef<TamanioTexto>;
    opciones: ComputedRef<OpcionTamanio[]>;
    cambiarTamanio: (valor: TamanioTexto) => void;
};

/**
 * Aplica el tamaño al documento en el acto.
 *
 * El servidor ya escribe `data-texto` en el `<html>` de arranque, así que al
 * cargar no hay nada que hacer y no hay parpadeo. Esto es para el otro momento:
 * cuando la persona elige un tamaño desde Configuración. Inertia no recarga el
 * documento, así que el atributo lo tiene que mover el cliente o el cambio no
 * se ve hasta la próxima navegación completa.
 */
function aplicarAlDocumento(valor: TamanioTexto): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.dataset.texto = valor;
}

export function useTamanioTexto(): UseTamanioTextoReturn {
    const page = usePage();

    const tamanio = computed<TamanioTexto>(
        () => (page.props.tamanioTexto as TamanioTexto | undefined) ?? 'grande',
    );

    const opciones = computed<OpcionTamanio[]>(
        () => (page.props.tamaniosTexto as OpcionTamanio[] | undefined) ?? [],
    );

    function cambiarTamanio(valor: TamanioTexto): void {
        if (valor === tamanio.value) {
            return;
        }

        /*
         * Se aplica antes de que conteste el servidor: es un cambio visual puro
         * y esperar el viaje de ida y vuelta lo haría sentir trabado. Si la
         * petición falla, el `onError` lo deja como estaba.
         */
        const anterior = tamanio.value;

        aplicarAlDocumento(valor);

        router.put(
            '/tamanio-texto',
            { tamanio_texto: valor },
            {
                preserveScroll: true,
                /*
                 * `preserveState: false` para que `tamanioTexto` se relea de los
                 * props. Con el estado preservado, el selector seguiría marcando
                 * la opción vieja aunque la letra ya hubiera cambiado.
                 */
                preserveState: false,
                onError: () => aplicarAlDocumento(anterior),
            },
        );
    }

    return { tamanio, opciones, cambiarTamanio };
}
