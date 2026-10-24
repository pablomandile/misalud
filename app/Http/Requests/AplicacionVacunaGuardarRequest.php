<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\EligeDelCatalogoOEscribe;
use App\Models\AplicacionVacuna;
use App\Models\Paciente;
use App\Models\User;
use App\Models\Vacuna;
use App\Rules\FechaNoFutura;
use App\Support\CatalogoDeLaFicha;
use App\Support\CatalogoVisible;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AplicacionVacunaGuardarRequest extends FormRequest
{
    use EligeDelCatalogoOEscribe;

    /**
     * La vacuna sale de la lista o se escribe con «Otro» (ver
     * `EligeDelCatalogoOEscribe`). Si no coincide con ninguna, queda sin id y
     * el controlador la crea en el catálogo propio.
     */
    protected function prepareForValidation(): void
    {
        $this->resolverEleccionDeCatalogo(
            Vacuna::class,
            'vacuna_id',
            'vacuna_nombre',
            CatalogoDeLaFicha::elegiblesPorColumna('vacuna_id', $this->pacienteId(), $this->idsYaUsados('vacuna_id')),
        );
    }

    /**
     * Autorizar ANTES de validar: si no, a alguien sin permiso le contesta
     * primero la validación y de paso le confirma que la ficha existe.
     */
    public function authorize(): bool
    {
        $aplicacion = $this->aplicacionDeLaRuta();

        if ($aplicacion !== null) {
            return Gate::allows('update', $aplicacion);
        }

        $paciente = $this->route('paciente');

        return $paciente instanceof Paciente
            && Gate::allows('crearEn', [AplicacionVacuna::class, $paciente]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User $usuario */
        $usuario = $this->user();

        return [
            /*
             * Propia, semilla, **o una que esta ficha ya viene usando**: una
             * ficha compartida la escriben varios, y el catálogo es de cada uno
             * (ver "Una ficha compartida comparte su vocabulario").
             *
             * ⚠️ Los paréntesis no sobran: ver `CatalogoVisible`.
             */
            'vacuna_nombre' => ['nullable', 'string', 'max:255'],
            'vacuna_id' => [
                'required_without:vacuna_nombre',
                'nullable',
                'integer',
                Rule::exists('vacunas', 'id')->where(
                    fn (Builder $consulta) => $consulta
                        ->whereNull('deleted_at')
                        ->where(fn (Builder $ambito) => $ambito
                            ->where(CatalogoVisible::para($usuario->id))
                            ->orWhereIn('id', CatalogoDeLaFicha::elegiblesPorColumna('vacuna_id', $this->pacienteId(), $this->idsYaUsados('vacuna_id')))
                        ),
                ),
            ],

            'centro_id' => [
                'nullable',
                'integer',
                Rule::exists('centros', 'id')->where(
                    fn (Builder $consulta) => $consulta
                        ->whereNull('deleted_at')
                        ->where(fn (Builder $ambito) => $ambito
                            ->where(CatalogoVisible::para($usuario->id))
                            ->orWhereIn('id', CatalogoDeLaFicha::elegiblesPorColumna('centro_id', $this->pacienteId(), $this->idsYaUsados('centro_id')))
                        ),
                ),
            ],

            // Registra algo que ya pasó: una dosis de mañana todavía no se aplicó.
            'fecha' => ['required', 'date', new FechaNoFutura($usuario)],

            /*
             * Sin tope hacia adelante —es justamente una fecha futura—, pero
             * después de la dosis: una "próxima" anterior a la que se está
             * cargando es un tipeo.
             */
            'proxima_dosis' => ['nullable', 'date', 'after:fecha'],

            'dosis' => ['nullable', 'string', 'max:100'],
            'lote' => ['nullable', 'string', 'max:100'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function aplicacionDeLaRuta(): ?AplicacionVacuna
    {
        $aplicacion = $this->route('aplicacion');

        return $aplicacion instanceof AplicacionVacuna ? $aplicacion : null;
    }

    private function pacienteId(): ?int
    {
        $paciente = $this->route('paciente');

        if ($paciente instanceof Paciente) {
            return $paciente->id;
        }

        return $this->aplicacionDeLaRuta()?->paciente_id;
    }

    /**
     * @return list<int>
     */
    private function idsYaUsados(string $columna): array
    {
        $pacienteId = $this->pacienteId();

        if ($pacienteId === null) {
            return [];
        }

        return array_values(array_map(
            'intval',
            AplicacionVacuna::query()
                ->where('paciente_id', $pacienteId)
                ->whereNotNull($columna)
                ->pluck($columna)
                ->unique()
                ->all(),
        ));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'vacuna_id' => 'vacuna',
            'vacuna_nombre' => 'nombre de la vacuna',
            'centro_id' => 'centro',
            'fecha' => 'fecha',
            'proxima_dosis' => 'próxima dosis',
            'dosis' => 'dosis',
            'lote' => 'lote',
            'notas' => 'notas',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vacuna_id.exists' => 'Elegí una vacuna de tu lista.',
            'vacuna_id.required_without' => 'Elegí la vacuna de la lista o escribí su nombre.',
            'centro_id.exists' => 'Elegí un centro de tu lista.',
            'proxima_dosis.after' => 'La próxima dosis tiene que ser después de esta.',
        ];
    }
}
