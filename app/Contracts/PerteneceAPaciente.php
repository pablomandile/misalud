<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Paciente;

/**
 * Lo implementa todo registro que viva dentro de la historia clínica de un
 * paciente: estudios, tratamientos, mediciones, adjuntos, todo.
 *
 * Existe para que haya **una sola Policy** para el dominio clínico entero
 * (`RegistroClinicoPolicy`) en vez de una copia por modelo. Todas las reglas
 * son las mismas —las decide el rol en el pivote `paciente_usuario`— y lo único
 * que cambia de un modelo a otro es cómo se llega al paciente.
 *
 * **Por qué un método y no una relación:** la mayoría de los registros cuelgan
 * del paciente con un `paciente_id` y lo resuelven en un paso, pero un
 * `Adjunto` es polimórfico y tiene que subir primero por `adjuntable`. Si el
 * contrato pidiera una relación `BelongsTo`, el adjunto no podría cumplirlo.
 */
interface PerteneceAPaciente
{
    /**
     * El paciente cuya historia clínica contiene este registro.
     *
     * Devuelve `null` cuando la cadena está rota —un adjunto cuyo dueño ya no
     * existe, por ejemplo—. **La Policy trata el `null` como "no", nunca como
     * "no hay restricción"**: es la diferencia entre un registro huérfano
     * inaccesible y uno que queda abierto a cualquiera.
     */
    public function pacienteDelRegistro(): ?Paciente;
}
