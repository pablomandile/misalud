<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\EsCatalogo;
use App\Contracts\TieneArchivos;
use App\Models\User;
use App\Services\ArchivoService;
use App\Support\CatalogoVisible;
use App\Support\UsoDeCatalogo;
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
     * Dónde se usa un registro de este catálogo.
     *
     * Vacío por defecto. ⚠️ **Toda FK que apunte a este catálogo tiene que
     * figurar acá**: es lo único que frena el borrado, y uno que no figure se
     * borra igual —con la FK haciendo lo que diga la migración—. No queda librado
     * a la memoria de nadie: `ReferenciasACatalogosTest` lee las FK reales del
     * esquema y falla si alguna no está declarada.
     *
     * Pública para que esa guardia pueda leerla.
     *
     * @return list<UsoDeCatalogo>
     */
    public function usos(): array
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
     * La condición vive en `CatalogoVisible` y no acá porque la necesitan
     * también lugares que no heredan de este controlador —la validación de
     * un centro, la de una medición—, y tres copias de un OR que hay que
     * agrupar bien son tres oportunidades de agruparlo mal.
     *
     * @return \Closure(Builder): void
     */
    protected function visiblesPara(User $usuario): \Closure
    {
        return CatalogoVisible::para($usuario->id);
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

    /**
     * Borra un registro del catálogo, **solo si nada lo usa**, y de verdad.
     *
     * Las dos mitades se sostienen entre sí:
     *
     * - **Si algo lo usa, no se borra.** Un médico que figura en un estudio, en
     *   un turno o en un tratamiento es parte de esa historia: borrarlo dejaría
     *   esos registros sin médico. Mismo freno que ya tenían los medicamentos y
     *   las variables, ahora para los cinco catálogos (decisión del usuario).
     * - **Si nada lo usa, se borra sin papelera.** No hay nada que recuperar, y
     *   mandarlo a la papelera lo dejaba ocupando su `nombre_hash`: volver a
     *   cargar el mismo nombre pasaba la validación (que no mira la papelera) y
     *   la base lo rechazaba con un 500.
     *
     * Autoriza **antes** de mirar los usos: al revés, la respuesta le contaría a
     * un extraño —o a quien intenta borrar una semilla— si ese registro tiene
     * datos cargados.
     *
     * @param  TCatalogo  $registro
     */
    protected function eliminar(Model&EsCatalogo $registro): RedirectResponse
    {
        Gate::authorize('delete', $registro);

        $nombre = $registro->nombreVisible();
        $id = (int) $registro->getKey();

        $enUso = [];
        $total = 0;
        $hayEnPapelera = false;

        foreach ($this->usos() as $uso) {
            [$cuantos, $enPapelera] = $uso->contar($id);

            if ($cuantos === 0) {
                continue;
            }

            $total += $cuantos;
            $hayEnPapelera = $hayEnPapelera || $enPapelera > 0;
            $enUso[] = $cuantos === 1 ? "1 {$uso->singular}" : "{$cuantos} {$uso->plural}";
        }

        if ($enUso !== []) {
            $mensaje = sprintf(
                'No se puede eliminar %s: %s %s.',
                $nombre,
                $total === 1 ? 'lo usa' : 'lo usan',
                $this->enumerar($enUso),
            );

            /*
             * Sin esto, alguien que ve cero estudios en pantalla lee "lo usa 1
             * estudio" y no entiende de dónde sale: lo que está en la papelera
             * se puede restaurar, y por eso también cuenta.
             */
            if ($hayEnPapelera) {
                $mensaje .= ' Se cuentan también registros que borraste, porque todavía se pueden recuperar.';
            }

            return back()->with('error', $mensaje);
        }

        $this->borrarDeVerdad($registro);

        return back()->with('exito', "Se eliminó a {$nombre}.");
    }

    /**
     * El registro y, si tiene, sus archivos: **primero el disco, después las
     * filas** (la regla de los adjuntos). Al revés, un archivo cifrado quedaría
     * sin nada que lo referencie, invisible y para siempre.
     *
     * Con la papelera de los adjuntos incluida: un prospecto que se borró antes
     * ya no tiene archivo, pero su fila sigue ahí apuntando a este registro.
     *
     * Las filas del pivote `centro_medico` se van solas: su FK es
     * `cascadeOnDelete`, que con un borrado de verdad sí dispara. Que un médico
     * atienda en un centro no frena el borrado: es configuración del catálogo,
     * no un registro de la historia de nadie.
     */
    private function borrarDeVerdad(Model&EsCatalogo $registro): void
    {
        if ($registro instanceof TieneArchivos) {
            $archivos = app(ArchivoService::class);

            foreach ($registro->adjuntos()->withTrashed()->get() as $adjunto) {
                $archivos->borrar($adjunto);
                $adjunto->forceDelete();
            }
        }

        $registro->forceDelete();
    }

    /**
     * "2 estudios, 1 turno y 3 tratamientos".
     *
     * @param  list<string>  $partes
     */
    private function enumerar(array $partes): string
    {
        $ultima = array_pop($partes);

        return $partes === [] ? $ultima : implode(', ', $partes).' y '.$ultima;
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
