import { toast } from 'vue-sonner';

/**
 * Copiar una clave y borrarla del portapapeles a los 30 segundos, traído del
 * proyecto Secretos (igual que la bóveda web de Bitwarden).
 *
 * Borra solo si el portapapeles sigue teniendo la misma clave: si en el medio
 * la persona copió otra cosa, no se la pisamos. Si el navegador no deja leer el
 * portapapeles, se sobrescribe igual: entre perder lo que copió después y dejar
 * una contraseña ahí, gana lo primero.
 */
const BORRAR_A_LOS_MS = 30_000;

/**
 * Acepta el valor o una PROMESA del valor, y no es un capricho: la clave se
 * pide al servidor recién al tocar «Copiar», y Safari en iOS rechaza un
 * `writeText()` que llega después de un `await` —considera que el toque ya
 * terminó—. Un `ClipboardItem` con una promesa adentro se escribe en el mismo
 * toque y se completa cuando llega la respuesta.
 */
export async function copiarSecreto(
    valor: string | Promise<string>,
): Promise<void> {
    let copiado: string;

    try {
        if (typeof ClipboardItem !== 'undefined' && valor instanceof Promise) {
            const texto = valor;
            await navigator.clipboard.write([
                new ClipboardItem({
                    'text/plain': texto.then(
                        (t) => new Blob([t], { type: 'text/plain' }),
                    ),
                }),
            ]);
            copiado = await texto;
        } else {
            copiado = await valor;
            await navigator.clipboard.writeText(copiado);
        }
    } catch {
        toast.error('No se pudo copiar. Tocá «Mostrar» y copiala a mano.', {
            duration: Infinity,
        });

        return;
    }

    toast.success('Copiada. Se borra del portapapeles en 30 segundos.');

    window.setTimeout(async () => {
        try {
            if ((await navigator.clipboard.readText()) === copiado) {
                await navigator.clipboard.writeText('');
            }
        } catch {
            try {
                await navigator.clipboard.writeText('');
            } catch {
                // Sin permiso: no hay nada más que hacer.
            }
        }
    }, BORRAR_A_LOS_MS);
}
