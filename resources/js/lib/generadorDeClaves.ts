/**
 * Generador de claves, traído del proyecto Secretos.
 *
 * Usa `crypto.getRandomValues` (no `Math.random`, que no es para secretos) y
 * muestreo por rechazo: un `% largo` a secas favorece a los primeros
 * caracteres del alfabeto cuando 2³² no es múltiplo del largo.
 *
 * Sin los caracteres que se confunden (`O0Il1|`): con la letra grande y una
 * persona mayor copiando la clave a mano en otro aparato, una `l` leída como
 * `1` es una clave que no entra nunca.
 */

const MAYUSCULAS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
const MINUSCULAS = 'abcdefghijkmnopqrstuvwxyz';
const DIGITOS = '23456789';
const SIMBOLOS = '!@#$%&*-_=+?';

const ALFABETO = MAYUSCULAS + MINUSCULAS + DIGITOS + SIMBOLOS;

export const LARGO_POR_DEFECTO = 16;

/** Entero uniforme en [0, tope) sin sesgo de módulo. */
function enteroAlAzar(tope: number): number {
    const limite = Math.floor(0x1_0000_0000 / tope) * tope;
    const buffer = new Uint32Array(1);
    let x: number;

    do {
        crypto.getRandomValues(buffer);
        x = buffer[0];
    } while (x >= limite);

    return x % tope;
}

/**
 * Una clave con al menos una mayúscula, una minúscula, un dígito y un símbolo:
 * muchos portales rechazan la que no los tiene, y el rechazo llega recién al
 * pegarla en el sitio.
 */
export function generarClave(largo = LARGO_POR_DEFECTO): string {
    const grupos = [MAYUSCULAS, MINUSCULAS, DIGITOS, SIMBOLOS];

    for (;;) {
        let clave = '';

        for (let i = 0; i < largo; i++) {
            clave += ALFABETO[enteroAlAzar(ALFABETO.length)];
        }

        // El alfabeto es todo ASCII: `split('')` separa bien cada caracter.
        if (grupos.every((g) => g.split('').some((c) => clave.includes(c)))) {
            return clave;
        }
    }
}
