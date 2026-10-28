<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\EsCatalogo;
use App\Enums\TipoAdjunto;
use App\Http\Requests\CentroGuardarRequest;
use App\Models\Adjunto;
use App\Models\AplicacionVacuna;
use App\Models\Centro;
use App\Models\Consulta;
use App\Models\Estudio;
use App\Models\Medico;
use App\Models\PrescripcionOcular;
use App\Models\Turno;
use App\Models\User;
use App\Support\CatalogoDeLaFicha;
use App\Support\UsoDeCatalogo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;

/**
 * El catálogo de centros. Copiado de `MedicoController` (ver ese
 * controlador); lo único que suma es sincronizar `medicos` después de
 * guardar, que no puede vivir en `CatalogoBaseController` porque ningún otro
 * catálogo tiene un pivote.
 *
 * @extends CatalogoBaseController<Centro>
 */
class CentroController extends CatalogoBaseController
{
    /**
     * Lo que se guarda del formulario. Una sola lista para el alta y la
     * edición: con dos copias, un campo nuevo se guardaba al crear y se perdía
     * en silencio al editar (o al revés). `medicos` va aparte, por el pivote.
     */
    private const CAMPOS = [
        'nombre', 'tipo', 'direccion', 'telefono', 'whatsapp', 'email', 'email_recetas', 'web', 'horarios', 'notas',
    ];

    protected function modelo(): string
    {
        return Centro::class;
    }

    protected function pagina(): string
    {
        return 'catalogos/Centros';
    }

    /**
     * Los médicos y las imágenes de cada centro, con el listado: sin esto, cada
     * tarjeta dispara dos consultas propias.
     */
    protected function conEager(): array
    {
        return ['medicos', 'adjuntos'];
    }

    /**
     * El catálogo de médicos, para armar el checklist de "quién atiende
     * acá": los propios, las semillas y los de los dueños de las fichas que
     * esta persona cuida, porque puede editar sus centros. Cada médico dice de
     * quién es (`usuarioId`) y la pantalla ofrece, para cada centro, solo los
     * de su mismo catálogo: la misma regla que valida `CentroGuardarRequest`.
     * Ordenado en PHP por el mismo motivo de siempre: el nombre está cifrado.
     *
     * @return array<string, mixed>
     */
    protected function propsExtra(): array
    {
        $usuario = auth()->user();
        assert($usuario instanceof User);
        $queCuida = CatalogoDeLaFicha::dueniosQueCuida($usuario);

        return [
            'medicosDisponibles' => Medico::query()
                ->where(fn ($consulta) => $consulta
                    ->where($this->visiblesPara($usuario))
                    ->orWhereIn('usuario_id', $queCuida))
                ->get()
                ->sortBy(fn (Medico $medico): string => mb_strtolower($medico->nombre))
                ->values()
                ->map(fn (Medico $medico): array => [
                    'id' => $medico->id,
                    'nombre' => $medico->nombre,
                    'usuarioId' => $medico->usuario_id,
                ])
                ->all(),
        ];
    }

    /**
     * @param  Centro  $registro
     * @return array<string, mixed>
     */
    protected function serializar(Model&EsCatalogo $registro): array
    {
        return [
            'id' => $registro->id,
            'nombre' => $registro->nombre,
            'tipo' => $registro->tipo->value,
            'tipoEtiqueta' => $registro->tipo->etiqueta(),
            'direccion' => $registro->direccion,
            'telefono' => $registro->telefono,
            'whatsapp' => $registro->whatsapp,
            'whatsappEnlace' => $registro->enlaceWhatsapp(),
            'email' => $registro->email,
            'email_recetas' => $registro->email_recetas,
            'web' => $registro->web,
            'horarios' => $registro->horarios,
            'notas' => $registro->notas,
            'esSemilla' => $registro->esSemilla(),
            // Las fotos del edificio, el QR de WhatsApp: lo que se subió al centro.
            'imagenes' => $registro->adjuntosDe(TipoAdjunto::ImagenCentro)
                ->map(fn (Adjunto $adjunto): array => $adjunto->paraLista())
                ->values()
                ->all(),
            // De qué catálogo son los médicos que se le pueden vincular.
            'usuarioId' => $registro->usuario_id,
            /*
             * Nombres ya en claro y no ids sueltos: la pantalla los muestra
             * tal cual, sin tener que pedir el catálogo de médicos aparte
             * para poder pintarlos.
             */
            'medicos' => $registro->medicos
                ->map(fn (Medico $medico): array => [
                    'id' => $medico->id,
                    'nombre' => $medico->nombre,
                ])
                ->all(),
        ];
    }

    /**
     * Mientras un centro figure en un estudio, una receta de anteojos o un
     * turno, no se borra. Los médicos que atienden acá no lo frenan: es
     * configuración del catálogo, y el pivote se va solo con el borrado.
     */
    public function usos(): array
    {
        return [
            new UsoDeCatalogo('consulta', 'consultas', Consulta::class, 'centro_id'),
            new UsoDeCatalogo('dosis de vacuna', 'dosis de vacuna', AplicacionVacuna::class, 'centro_id'),
            new UsoDeCatalogo('estudio', 'estudios', Estudio::class, 'centro_id'),
            new UsoDeCatalogo('receta de anteojos', 'recetas de anteojos', PrescripcionOcular::class, 'centro_id'),
            new UsoDeCatalogo('turno', 'turnos', Turno::class, 'centro_id'),
        ];
    }

    public function store(CentroGuardarRequest $peticion): RedirectResponse
    {
        // `safe($claves)` con claves devuelve un array plano y no un
        // ValidatedInput -a diferencia de `safe()` sin argumentos-, así que
        // acá no hay `->all()` que encadenar.
        $centro = $this->crear($peticion->safe(self::CAMPOS));
        $centro->medicos()->sync($peticion->medicosIds());

        return back()->with('exito', "Se agregó a {$centro->nombre}.");
    }

    public function update(CentroGuardarRequest $peticion, Centro $centro): RedirectResponse
    {
        $this->actualizar($centro, $peticion->safe(self::CAMPOS));
        $centro->medicos()->sync($peticion->medicosIds());

        return back()->with('exito', 'Se guardaron los cambios.');
    }

    public function destroy(Centro $centro): RedirectResponse
    {
        return $this->eliminar($centro);
    }

    public function duplicar(Centro $centro): RedirectResponse
    {
        /*
         * Duplicar NO copia los médicos vinculados: la copia es de la
         * semilla, con sus propios datos; a quién atiende ahí es algo que
         * cada usuario arma con SUS médicos, no con los de quien publicó la
         * semilla -que además de nadie no tendría médicos vinculados nunca,
         * pero un centro propio duplicado sí podría tenerlos-.
         */
        return $this->duplicarRegistro($centro);
    }
}
