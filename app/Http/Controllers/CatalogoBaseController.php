<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\EsCatalogo;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lo que comparten los cuatro catálogos (médicos, centros, medicamentos,
 * vacunas) y, en la Etapa 6, los tipos de medición.
 *
 * **Qué vive acá y qué no**, que es la parte que importa al copiar esto:
 *
 * - `index()` es idéntico en todos y está completo acá.
 * - `store()`, `update()` y `destroy()` **no** pueden vivir acá, y no es por
 *   prolijidad: `store`/`update` reciben un FormRequest distinto por catálogo
 *   —cada uno valida campos distintos— y el contenedor de Laravel inyecta el
 *   tipo que dice la firma, no una subclase. `destroy` necesita un type-hint
 *   concreto para que funcione el route-model binding. Así que el controlador
 *   concreto escribe esos tres métodos, de tres líneas cada uno, llamando a
 *   los helpers protegidos de abajo.
 *
 * Es menos magia y más repetición que una clase que lo haga todo, a cambio de
 * que las firmas digan la verdad y el binding funcione.
 *
 * El `@template` no es decoración: sin él, las firmas nativas solo pueden
 * decir `Model&EsCatalogo`, y desde ahí no se ven ni las columnas del
 * catalogo concreto ni sus scopes. Cada controlador concreto cierra el
 * genérico con `@extends CatalogoBaseController<Medico>`.
 *
 * @template TCatalogo of Model&EsCatalogo
 */
abstract class CatalogoBaseController extends Controller
{
    /** @return class-string<TCatalogo> */
    abstract protected function modelo(): string;

    /** La página Inertia: `catalogos/Medicos`. */
    abstract protected function pagina(): string;

    /**
     * Cómo viaja un registro al frontend.
     *
     * @param  TCatalogo  $registro
     * @return array<string, mixed>
     */
    abstract protected function serializar(Model&EsCatalogo $registro): array;

    /**
     * Props extra para la pantalla, además de `registros`.
     *
     * Vacío por defecto: casi ningún catálogo necesita más que su propio
     * listado. `centros` la pisa para mandar el catálogo de médicos con el
     * que arma el checklist de "quién atiende acá" — ninguna otra pieza de
     * `CatalogoBaseController` sabe de pivotes, y no hace falta que lo sepa.
     *
     * @return array<string, mixed>
     */
    protected function propsExtra(): array
    {
        return [];
    }

    /**
     * Relaciones a traer con el listado, además del registro mismo.
     *
     * Vacío por defecto, por la misma razón que `propsExtra()`: no es que
     * los otros catálogos no puedan tener relaciones, es que hoy no las
     * necesitan. `medicamentos` la pisa con `['adjuntos']` para poder
     * mostrar el prospecto sin una consulta por fila -"todo listado con
     * eager loading explícito", CLAUDE.md-.
     *
     * @return array<int, string>
     */
    protected function conEager(): array
    {
        return [];
    }

    /**
     * El listado: lo del usuario más las semillas compartidas.
     *
     * El nombre está cifrado, así que **no hay `orderBy` en SQL**: se traen
     * todas -un catálogo personal son decenas de filas- y se ordenan en PHP
     * (ver CLAUDE.md).
     */
    public function index(): Response
    {
        $modelo = $this->modelo();

        $registros = $modelo::query()
            ->with($this->conEager())
            ->where($this->visiblesPara(auth()->user()))
            ->get()
            ->sortBy(fn (Model&EsCatalogo $registro): string => mb_strtolower($registro->nombreVisible()))
            ->values()
            ->map(fn (Model&EsCatalogo $registro): array => $this->serializar($registro))
            ->all();

        return Inertia::render($this->pagina(), [
            'registros' => $registros,
            ...$this->propsExtra(),
        ]);
    }

    /**
     * Lo que una persona puede VER de un catálogo: lo suyo más las semillas
     * compartidas.
     *
     * Devuelve una CLAUSURA para meter en un `where()`, y no condiciones
     * sueltas encadenadas: sin ese paréntesis, al sumar cualquier otro
     * `where` el `orWhereNull` se mezcla y las semillas se cuelan en
     * cualquier filtro. Es el modo de falla clásico de un OR sin agrupar, y
     * no da error: devuelve de más.
     *
     * @return \Closure(Builder): void
     */
    protected function visiblesPara(User $usuario): \Closure
    {
        return function ($consulta) use ($usuario): void {
            $consulta->where('usuario_id', $usuario->id)->orWhereNull('usuario_id');
        };
    }

    /**
     * Alta en el catálogo DEL USUARIO.
     *
     * `usuario_id` se pone acá, desde la sesión, y no es fillable: que venga
     * de un formulario sería dejar elegir en el catálogo de quién escribir.
     *
     * @param  array<string, mixed>  $datos
     * @return TCatalogo
     */
    protected function crear(array $datos): Model&EsCatalogo
    {
        Gate::authorize('create', $this->modelo());

        $modelo = $this->modelo();
        $registro = new $modelo($datos);

        // `setAttribute` y no `->usuario_id = ...`: desde el genérico, la
        // propiedad mágica no existe para el analizador; el método sí, y es
        // el mismo camino que usa `CatalogoPolicy` para leerla.
        $registro->setAttribute('usuario_id', auth()->id());
        $registro->save();

        return $registro;
    }

    /**
     * @param  TCatalogo  $registro
     * @param  array<string, mixed>  $datos
     */
    protected function actualizar(Model&EsCatalogo $registro, array $datos): void
    {
        Gate::authorize('update', $registro);

        $registro->update($datos);
    }

    /** @param TCatalogo $registro */
    protected function eliminar(Model&EsCatalogo $registro): RedirectResponse
    {
        Gate::authorize('delete', $registro);

        $nombre = $registro->nombreVisible();
        $registro->delete();

        return back()->with('exito', "Se eliminó a {$nombre}.");
    }

    /**
     * Copia una semilla compartida al catálogo propio, para poder editarla.
     *
     * Es la única salida que tiene alguien frente a una semilla: no se
     * editan, se duplican (regla 5 de CLAUDE.md). También sirve para copiar
     * un registro propio, que no molesta a nadie.
     *
     * @param  TCatalogo  $registro
     */
    protected function duplicarRegistro(Model&EsCatalogo $registro): RedirectResponse
    {
        Gate::authorize('duplicar', $registro);

        $modelo = $this->modelo();
        $nombre = $registro->nombreVisible();

        /*
         * Si ya tiene uno con ese nombre, no se duplica: el UNIQUE de la base
         * lo frenaría con un 500, y "ya lo tenés" es una respuesta mucho mejor
         * que una pantalla de error.
         */
        $yaLoTiene = $modelo::query()
            ->where('usuario_id', auth()->id())
            ->where($registro->indicesCiegos()[$registro->columnaNombre()], $modelo::hashCiego($nombre))
            ->exists();

        if ($yaLoTiene) {
            return back()->with('error', "Ya tenés a {$nombre} en tu catálogo.");
        }

        $copia = $registro->replicate(['usuario_id']);
        $copia->setAttribute('usuario_id', auth()->id());
        $copia->save();

        return back()->with('exito', "Se copió a {$nombre} a tu catálogo.");
    }
}
