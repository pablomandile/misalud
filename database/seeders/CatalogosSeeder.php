<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Contracts\CifraDatos;
use App\Models\Medicamento;
use App\Models\TipoMedicion;
use App\Models\Vacuna;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Semillas compartidas: filas con `usuario_id` NULL, visibles para
 * cualquiera y editables por nadie (regla 5 de CLAUDE.md, y ver la sección
 * "Semillas compartidas"). Pensado para correr **en producción**, así que
 * tiene que poder ejecutarse más de una vez sin duplicar nada:
 *
 *     php artisan db:seed --class="Database\Seeders\CatalogosSeeder"
 *
 * ⚠️ **El `UNIQUE(usuario_id, nombre_hash)` no protege acá.** MySQL admite
 * cualquier cantidad de filas con `usuario_id` NULL en un índice único, así
 * que dos semillas con el mismo nombre entrarían sin chistar. La
 * idempotencia la garantiza este seeder por su cuenta, chequeando por el
 * índice ciego antes de crear -mismo mecanismo que ya usa
 * `CatalogoBaseController::duplicarRegistro()` para avisar "ya la tenés" en
 * vez de chocar contra el UNIQUE-.
 *
 * **Solo medicamentos y vacunas.** Ni médicos ni centros tienen semillas:
 * nadie publica una lista de médicos o de consultorios compartida entre
 * usuarios (ver el comentario de la migración de `medicos`). El día que
 * `tipos_medicion` (Etapa 6) necesite las suyas, se suma un método más acá,
 * no un seeder aparte.
 */
class CatalogosSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->medicamentos() as $datos) {
            $this->crearSemillaSiNoExiste(Medicamento::class, 'nombre_comercial', $datos);
        }

        foreach ($this->vacunas() as $nombre) {
            $this->crearSemillaSiNoExiste(Vacuna::class, 'nombre', ['nombre' => $nombre]);
        }

        foreach ($this->tiposMedicion() as $datos) {
            $this->crearSemillaSiNoExiste(TipoMedicion::class, 'nombre', $datos);
        }
    }

    /**
     * Crea una semilla si no existe ya OTRA semilla con el mismo nombre.
     *
     * `usuario_id` no es fillable en ningún catálogo (ver CLAUDE.md), así
     * que ni hace falta pasarlo: al no venir en `$datos` queda NULL, que es
     * justo lo que define una semilla.
     *
     * Escribe con **`forceFill` y no con `create`** porque algunos de estos
     * campos no son fillable a propósito —`tipos_medicion.clave` la pone
     * solo este seeder, nunca un formulario—. Los índices ciegos se calculan
     * igual: los llena el evento `saving` de `CifraCampos`.
     *
     * @param  class-string<Model&CifraDatos>  $modelo
     * @param  array<string, mixed>  $datos
     */
    private function crearSemillaSiNoExiste(string $modelo, string $campo, array $datos): void
    {
        $nombre = $datos[$campo];
        $columnaHash = (new $modelo)->indicesCiegos()[$campo];

        $yaExiste = $modelo::query()
            ->whereNull('usuario_id')
            ->where($columnaHash, $modelo::hashCiego($nombre))
            ->exists();

        if (! $yaExiste) {
            (new $modelo)->forceFill($datos)->save();
        }
    }

    /**
     * Las siete variables que cubren casi todo lo que sigue una persona en
     * casa. Con estas cargadas, nadie tiene que crear nada para empezar a
     * medir: la pantalla de "Variables" queda para el caso raro.
     *
     * ⚠️ Los rangos son **de referencia, no un veredicto** (regla 1: el
     * sistema registra, no aconseja). Se muestran al lado del valor como en
     * un análisis de laboratorio; ninguna pantalla pinta un número de rojo.
     * Son los valores generales de adulto: el rango que le corresponde a una
     * persona concreta lo dice su médico, y por eso cualquiera puede
     * duplicar una de estas y ponerle el suyo.
     *
     * @return list<array<string, mixed>>
     */
    private function tiposMedicion(): array
    {
        return [
            [
                'clave' => TipoMedicion::CLAVE_PESO,
                'nombre' => 'Peso',
                'unidad' => 'kg',
                'decimales' => 1,
            ],
            [
                'clave' => TipoMedicion::CLAVE_ALTURA,
                'nombre' => 'Altura',
                'unidad' => 'cm',
                'decimales' => 0,
            ],
            [
                // El caso de dos valores, y el que motivó el esquema entero.
                'nombre' => 'Presión arterial',
                'unidad' => 'mmHg',
                'etiqueta_principal' => 'Sistólica',
                'etiqueta_secundaria' => 'Diastólica',
                'min_normal' => 90,
                'max_normal' => 140,
                'min_normal_secundario' => 60,
                'max_normal_secundario' => 90,
                'decimales' => 0,
            ],
            [
                'nombre' => 'Glucemia',
                'unidad' => 'mg/dl',
                'min_normal' => 70,
                'max_normal' => 110,
                'decimales' => 0,
            ],
            [
                'nombre' => 'Temperatura',
                'unidad' => '°C',
                'min_normal' => 36,
                'max_normal' => 37.5,
                'decimales' => 1,
            ],
            [
                'nombre' => 'Saturación de oxígeno',
                'unidad' => '%',
                'min_normal' => 95,
                'max_normal' => 100,
                'decimales' => 0,
            ],
            [
                'nombre' => 'Pulso',
                'unidad' => 'lpm',
                'min_normal' => 60,
                'max_normal' => 100,
                'decimales' => 0,
            ],
        ];
    }

    /**
     * Medicamentos de venta común en Argentina, priorizando los crónicos
     * -presión, colesterol, tiroides, diabetes- que es lo que más carga
     * alguien que administra la salud de una persona mayor.
     *
     * @return list<array{nombre_comercial: string, droga: string, para_que_sirve: string}>
     */
    private function medicamentos(): array
    {
        return [
            ['nombre_comercial' => 'Paracetamol', 'droga' => 'Paracetamol', 'para_que_sirve' => 'Dolor y fiebre'],
            ['nombre_comercial' => 'Ibuprofeno', 'droga' => 'Ibuprofeno', 'para_que_sirve' => 'Dolor, inflamación y fiebre'],
            ['nombre_comercial' => 'Aspirina', 'droga' => 'Ácido acetilsalicílico', 'para_que_sirve' => 'Dolor y fiebre; en dosis bajas, antiagregante'],
            ['nombre_comercial' => 'Amoxicilina', 'droga' => 'Amoxicilina', 'para_que_sirve' => 'Infecciones bacterianas'],
            ['nombre_comercial' => 'Omeprazol', 'droga' => 'Omeprazol', 'para_que_sirve' => 'Acidez y reflujo'],
            ['nombre_comercial' => 'Losartán', 'droga' => 'Losartán', 'para_que_sirve' => 'Presión arterial alta'],
            ['nombre_comercial' => 'Enalapril', 'droga' => 'Enalapril', 'para_que_sirve' => 'Presión arterial alta'],
            ['nombre_comercial' => 'Amlodipina', 'droga' => 'Amlodipina', 'para_que_sirve' => 'Presión arterial alta'],
            ['nombre_comercial' => 'Atorvastatina', 'droga' => 'Atorvastatina', 'para_que_sirve' => 'Colesterol alto'],
            ['nombre_comercial' => 'Metformina', 'droga' => 'Metformina', 'para_que_sirve' => 'Diabetes tipo 2'],
            ['nombre_comercial' => 'Levotiroxina', 'droga' => 'Levotiroxina', 'para_que_sirve' => 'Hipotiroidismo'],
            ['nombre_comercial' => 'Furosemida', 'droga' => 'Furosemida', 'para_que_sirve' => 'Retención de líquidos'],
            ['nombre_comercial' => 'Diclofenac', 'droga' => 'Diclofenac', 'para_que_sirve' => 'Dolor e inflamación'],
            ['nombre_comercial' => 'Loratadina', 'droga' => 'Loratadina', 'para_que_sirve' => 'Alergias'],
            ['nombre_comercial' => 'Clonazepam', 'droga' => 'Clonazepam', 'para_que_sirve' => 'Ansiedad'],
        ];
    }

    /**
     * Vacunas del Calendario Nacional de Vacunación argentino, más las que
     * se dan por temporada o por edad (antigripal, herpes zóster). Cubre
     * toda la familia -de un bebé a un abuelo-, no solo las de adultos
     * mayores: un catálogo de vacunas que las use un solo paciente tipo
     * dejaría afuera a la mitad de las que carga esta app.
     *
     * @return list<string>
     */
    private function vacunas(): array
    {
        return [
            'BCG',
            'Hepatitis B',
            'Sabin (poliomielitis)',
            'Quíntuple pentavalente',
            'Neumococo conjugada',
            'Rotavirus',
            'Triple viral (sarampión, rubéola, paperas)',
            'Varicela',
            'Hepatitis A',
            'VPH (virus del papiloma humano)',
            'Triple bacteriana (dTpa)',
            'Doble bacteriana (dT)',
            'Antigripal',
            'Fiebre amarilla',
            'COVID-19',
            'Herpes zóster',
            'Meningococo',
        ];
    }
}
