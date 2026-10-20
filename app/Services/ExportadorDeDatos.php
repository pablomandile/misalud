<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\TieneArchivos;
use App\Models\Adjunto;
use App\Models\Centro;
use App\Models\Clave;
use App\Models\Farmacia;
use App\Models\Medicamento;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\TipoMedicion;
use App\Models\User;
use App\Models\Vacuna;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Arma el ZIP de "Descargar mis datos": el derecho de acceso de la Ley 25.326,
 * ejercido desde el perfil sin tener que pedírselo a nadie.
 *
 * Adentro van `datos.json` (todo lo cargado, descifrado y legible), los
 * archivos tal como se subieron, y un `LEEME.txt` que explica las dos cosas.
 *
 * Qué entra, y por qué:
 *
 * - **Las fichas de las que la cuenta es dueña**, con todo su historial y sus
 *   archivos. Las que otra persona le compartió no: son de esa persona, y es
 *   ella quien se las lleva.
 * - El catálogo propio (no las semillas compartidas), la casilla **sin la
 *   contraseña**, las recetas con su PDF, los contactos y el historial de envíos.
 * - Nada de la papelera: lo borrado no se ofrece como si siguiera ahí.
 * - Ninguna columna `*_hash`: son índices internos, no datos de nadie.
 *
 * Los archivos se escriben de a uno a una carpeta temporal y se agregan desde
 * ahí: `ZipArchive::addFromString` guardaría todo en memoria hasta cerrar el
 * ZIP, y una historia con varias grabaciones no entra en el `memory_limit` del
 * hosting.
 */
final class ExportadorDeDatos
{
    /** Lo que de una ficha se exporta, en este orden. Cada relación de `Paciente`. */
    private const SECCIONES = [
        'coberturas' => [],
        'alergias' => [],
        'enfermedades' => ['registros'],
        'mediciones' => [],
        'tratamientos' => [],
        'aplicacionesVacuna' => [],
        'ordenesEstudio' => [],
        'recetasMedicas' => [],
        'documentosPorRevisar' => [],
        'estudios' => ['resultados'],
        'prescripcionesOculares' => ['graduaciones'],
        'consultas' => [],
        'turnos' => [],
    ];

    private ZipArchive $zip;

    private string $temporal;

    public function __construct(private readonly ArchivoService $archivos) {}

    /**
     * Arma el ZIP y devuelve su ruta. Quien lo manda lo borra después de mandarlo.
     */
    public function armar(User $usuario): string
    {
        $this->temporal = storage_path('app/private/exportaciones/'.Str::ulid()->toString());
        File::ensureDirectoryExists($this->temporal);

        $ruta = $this->temporal.'.zip';
        $this->zip = new ZipArchive;

        if ($this->zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo de la exportación.');
        }

        try {
            $datos = [
                'generado' => now()->toIso8601String(),
                'cuenta' => [
                    'nombre' => $usuario->name,
                    'email' => $usuario->email,
                    'zona_horaria' => $usuario->zona()->getName(),
                    'tamanio_texto' => $usuario->tamanio_texto->value,
                    'ingreso_con_google' => $usuario->google_id !== null,
                    'creada' => $usuario->created_at?->toIso8601String(),
                    'terminos_aceptados' => $usuario->terminos_version,
                ],
                'fichas' => Paciente::where('usuario_id', $usuario->id)
                    ->orderBy('id')
                    ->get()
                    ->map(fn (Paciente $paciente) => $this->ficha($paciente))
                    ->all(),
                'catalogo' => [
                    'medicos' => $this->filas(Medico::where('usuario_id', $usuario->id)->get()),
                    'centros' => $this->filas(Centro::where('usuario_id', $usuario->id)->get()),
                    'farmacias' => $this->filas(Farmacia::where('usuario_id', $usuario->id)->get()),
                    'medicamentos' => $this->conArchivos(Medicamento::where('usuario_id', $usuario->id)->get(), 'catalogo/medicamentos'),
                    'vacunas' => $this->filas(Vacuna::where('usuario_id', $usuario->id)->get()),
                    'variables' => $this->filas(TipoMedicion::where('usuario_id', $usuario->id)->get()),
                ],
                // Lo que llegó al buzón desde tu correo y no tiene ficha todavía.
                'documentos_por_revisar' => $this->conArchivos(
                    $usuario->documentosPorRevisar()->whereNull('paciente_id')->get(),
                    'por-revisar',
                ),
                'recetas' => $this->conArchivos($usuario->recetas()->get(), 'recetas'),
                'contactos' => $this->filas($usuario->contactos()->get()),
                'envios' => $usuario->envios()->with('archivos')->get()->map(fn (Model $envio) => [
                    ...$this->fila($envio),
                    'archivos' => $this->filas($envio->getRelation('archivos')),
                ])->all(),
            ];

            $this->zip->addFromString(
                'datos.json',
                (string) json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
            $this->zip->addFromString('LEEME.txt', $this->leeme());
            $this->zip->close();
        } finally {
            // Los archivos descifrados no se quedan en el disco: el ZIP ya los
            // leyó al cerrarse.
            File::deleteDirectory($this->temporal);
        }

        return $ruta;
    }

    /**
     * @return array<string, mixed>
     */
    private function ficha(Paciente $paciente): array
    {
        $carpeta = 'fichas/'.$paciente->id.'-'.Str::slug($paciente->nombre ?: 'ficha');

        $ficha = [
            ...$this->fila($paciente),
            'archivos' => $this->archivosDe($paciente, $carpeta.'/documentos'),
        ];

        foreach (self::SECCIONES as $relacion => $hijas) {
            $registros = $paciente->{$relacion}()->get();

            $ficha[Str::snake($relacion)] = $registros->map(function (Model $registro) use ($hijas, $carpeta, $relacion): array {
                $fila = $this->fila($registro);

                foreach ($hijas as $hija) {
                    /** @var Collection<int, Model> $hijos */
                    $hijos = $registro->load($hija)->getRelation($hija);
                    $fila[$hija] = $this->filas($hijos);
                }

                if ($registro instanceof TieneArchivos) {
                    $fila['archivos'] = $this->archivosDe($registro, $carpeta.'/'.Str::snake($relacion).'/'.$registro->getKey());
                }

                return $fila;
            })->all();
        }

        /*
         * Las claves de los sitios de salud, CON la contraseña: son datos de la
         * persona y la exportación promete todo descifrado. Van aparte del
         * recorrido de arriba porque `fila()` respeta el `$hidden` del modelo,
         * que existe justamente para que la clave no salga por descuido.
         */
        $ficha['claves'] = $paciente->claves()->get()
            ->map(fn (Clave $clave): array => [...$this->fila($clave), 'clave' => $clave->clave])
            ->all();

        return $ficha;
    }

    /**
     * Las filas con sus archivos, para los dueños de archivos sueltos (recetas,
     * prospectos).
     *
     * @param  Collection<int, covariant Model>  $registros
     * @return list<array<string, mixed>>
     */
    private function conArchivos(Collection $registros, string $carpeta): array
    {
        return array_values($registros->map(fn (Model $registro) => [
            ...$this->fila($registro),
            'archivos' => $registro instanceof TieneArchivos
                ? $this->archivosDe($registro, $carpeta.'/'.$registro->getKey())
                : [],
        ])->all());
    }

    /**
     * Agrega al ZIP los archivos de un dueño y devuelve qué es cada uno y dónde
     * quedó, para que `datos.json` diga cuál es cuál.
     *
     * @return list<array<string, mixed>>
     */
    private function archivosDe(TieneArchivos $duenio, string $carpeta): array
    {
        $lista = [];

        foreach ($duenio->adjuntos()->orderBy('id')->get() as $adjunto) {
            $nombre = $carpeta.'/'.$adjunto->id.'-'.$this->nombreSeguro($adjunto->nombre_original);

            $agregado = $this->agregarArchivo($adjunto, $nombre);

            $lista[] = [
                'tipo' => $adjunto->tipo->value,
                'nombre_original' => $adjunto->nombre_original,
                'descripcion' => $adjunto->descripcion,
                'subido' => $adjunto->created_at?->toIso8601String(),
                'archivo' => $agregado ? $nombre : null,
            ];
        }

        return $lista;
    }

    private function agregarArchivo(Adjunto $adjunto, string $nombre): bool
    {
        try {
            if (! $adjunto->tipo->seGuardaCifrado()) {
                // Una grabación está en claro en el disco: va directo.
                $ruta = $this->archivos->rutaSinCifrar($adjunto);

                return is_file($ruta) && $this->zip->addFile($ruta, $nombre);
            }

            $temporal = $this->temporal.'/'.$adjunto->id;
            File::put($temporal, $this->archivos->contenido($adjunto));

            return $this->zip->addFile($temporal, $nombre);
        } catch (RuntimeException) {
            // Un archivo que falta en el disco no frena la exportación del resto:
            // en datos.json figura con `archivo: null`.
            return false;
        }
    }

    /**
     * @param  Collection<int, covariant Model>  $registros
     * @return list<array<string, mixed>>
     */
    private function filas(Collection $registros): array
    {
        return array_values($registros->map(fn (Model $registro) => $this->fila($registro))->all());
    }

    /**
     * Una fila legible: los valores ya descifrados por sus casts, sin los
     * índices ciegos ni lo que el modelo esconde.
     *
     * @return array<string, mixed>
     */
    private function fila(Model $registro): array
    {
        return collect($registro->attributesToArray())
            ->reject(fn ($valor, string $columna) => str_ends_with($columna, '_hash') || $columna === 'deleted_at')
            ->all();
    }

    private function nombreSeguro(string $nombre): string
    {
        $nombre = basename(str_replace('\\', '/', $nombre));

        return preg_replace('/[^\pL\pN._ -]+/u', '_', $nombre) ?: 'archivo';
    }

    private function leeme(): string
    {
        return <<<'TXT'
            Tus datos de MiSalud
            ====================

            datos.json
              Todo lo que cargaste: tu cuenta, cada ficha de la que sos dueño con su
              historial (coberturas, alergias, enfermedades, mediciones, tratamientos,
              vacunas, órdenes, estudios, salud ocular, consultas, turnos y las
              claves de sus sitios de salud), tu
              catálogo (médicos, centros, medicamentos, vacunas y variables), tus
              recetas, contactos y envíos. Las fechas están en UTC.

              Los registros se relacionan por su "id": un tratamiento dice su
              "medicamento_id", y ese medicamento está en "catalogo".

            fichas/, recetas/, catalogo/
              Los archivos tal como los subiste (PDF, fotos, grabaciones). Cada uno
              figura en datos.json, en "archivos", con su nombre y dónde está acá.

            Lo que NO está:
              - las fichas que otra persona te compartió (se las lleva su dueño);
              - lo que mandaste a la papelera.

            Este archivo tiene tu historia clínica sin cifrar, y también las
            contraseñas que guardaste en Claves: guardalo en un lugar seguro y no
            lo compartas.
            TXT;
    }
}
