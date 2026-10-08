/**
 * Los tonos pastel de cada sección (ver la paleta en app.css).
 *
 * Un tono IDENTIFICA una sección —turnos siempre lavanda, vacunas siempre
 * limón—, nunca juzga un valor: ninguna pantalla pinta un número según si
 * "está bien" (regla 1). Por eso se elige por sección y no por dato.
 *
 * Las clases van escritas enteras y no armadas con `bg-${tono}`: Tailwind
 * genera solo las clases que encuentra escritas en el código, y una armada en
 * tiempo de ejecución no existiría en el CSS.
 */
export type Tono =
    | 'lavanda'
    | 'menta'
    | 'durazno'
    | 'celeste'
    | 'rosa'
    | 'limon'
    | 'turquesa';

type ClasesDeTono = {
    /** La pastilla del ícono: fondo pastel y el ícono en el tono fuerte. */
    insignia: string;
    /** El tinte de una tarjeta: un degradé suave desde la esquina. */
    tarjeta: string;
};

export const tonos: Record<Tono, ClasesDeTono> = {
    lavanda: {
        insignia: 'bg-lavanda text-lavanda-fuerte',
        tarjeta: 'from-lavanda/70 border-lavanda-fuerte/15',
    },
    menta: {
        insignia: 'bg-menta text-menta-fuerte',
        tarjeta: 'from-menta/70 border-menta-fuerte/15',
    },
    durazno: {
        insignia: 'bg-durazno text-durazno-fuerte',
        tarjeta: 'from-durazno/70 border-durazno-fuerte/15',
    },
    celeste: {
        insignia: 'bg-celeste text-celeste-fuerte',
        tarjeta: 'from-celeste/70 border-celeste-fuerte/15',
    },
    rosa: {
        insignia: 'bg-rosa text-rosa-fuerte',
        tarjeta: 'from-rosa/70 border-rosa-fuerte/15',
    },
    limon: {
        insignia: 'bg-limon text-limon-fuerte',
        tarjeta: 'from-limon/70 border-limon-fuerte/15',
    },
    turquesa: {
        insignia: 'bg-turquesa text-turquesa-fuerte',
        tarjeta: 'from-turquesa/70 border-turquesa-fuerte/15',
    },
};
