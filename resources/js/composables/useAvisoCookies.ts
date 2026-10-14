import { onMounted, readonly, ref } from 'vue';

/**
 * Si el aviso de cookies ya se cerró en este dispositivo.
 *
 * El estado vive a nivel de módulo y no por componente: el aviso está en tres
 * layouts (el de la app, el de ingreso y el público) y cerrarlo en uno tiene
 * que cerrarlo en todos, sin releer nada al navegar.
 *
 * La marca guarda una **versión**, no un sí/no. Hoy todo lo que MiSalud guarda
 * es necesario y el aviso es informativo; si algún día se suma algo que no lo
 * es (analíticas, por ejemplo), se sube `VERSION` y el aviso vuelve a aparecer
 * para todos. Ese cambio además exige pedir permiso ANTES de cargarlo.
 */
const CLAVE = 'misalud:aviso-cookies';
const VERSION = '1';

const visible = ref(false);
let leido = false;

function yaSeCerro(): boolean {
    try {
        return localStorage.getItem(CLAVE) === VERSION;
    } catch {
        // Sin localStorage (modo privado): se muestra, y cerrarlo dura la pestaña.
        return false;
    }
}

function cerrar() {
    visible.value = false;

    try {
        localStorage.setItem(CLAVE, VERSION);
    } catch {
        // Sin almacenamiento el cierre dura lo que dure la pestaña.
    }
}

export function useAvisoCookies() {
    /*
     * Se decide después de montar y no en el primer render: leer localStorage
     * durante el setup haría que quien ya lo cerró lo vea aparecer y
     * desaparecer en cada carga. Y una sola vez por pestaña: al pasar del
     * ingreso a la app cambia el layout, y releer no cambia nada.
     */
    onMounted(() => {
        if (!leido) {
            leido = true;
            visible.value = !yaSeCerro();
        }
    });

    return { visible: readonly(visible), cerrar };
}
