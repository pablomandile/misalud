/*
 * Las variables de los mensajes del pedido de medicamentos, para la vista previa.
 *
 * ⚠️ Es una copia del reemplazo de `App\Support\PlantillaDePedido::completar()`:
 * lo que sale en el mail lo decide el servidor, y esto solo muestra cómo va a
 * quedar. Si se suma una variable, va en los dos lados. El nombre y el apellido
 * ya llegan partidos del servidor, con el mismo corte que usa el mail.
 */

export type VariablesDelPaciente = { nombre: string; apellido: string };

export const VARIABLES = [
    { marcador: '{nombre}', rotulo: 'Nombre' },
    { marcador: '{apellido}', rotulo: 'Apellido' },
    { marcador: '{medicamento}', rotulo: 'Medicamento' },
] as const;

export function completar(
    mensaje: string,
    medicamento: string,
    paciente: VariablesDelPaciente,
): string {
    const valores: Record<string, string> = {
        nombre: paciente.nombre,
        apellido: paciente.apellido,
        medicamento,
    };

    return mensaje
        .replace(
            /\{(nombre|apellido|medicamento)\}/giu,
            (_, variable: string) => valores[variable.toLowerCase()] ?? '',
        )
        .replace(/[ \t]{2,}/gu, ' ')
        .replace(/[ \t]+([?!.,;:])/gu, '$1');
}
