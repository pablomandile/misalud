<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Enums\TipoAdjunto;
use App\Models\Alergia;
use App\Models\AplicacionVacuna;
use App\Models\Centro;
use App\Models\Cobertura;
use App\Models\Contacto;
use App\Models\CuentaMail;
use App\Models\Enfermedad;
use App\Models\Estudio;
use App\Models\Medicamento;
use App\Models\Medicion;
use App\Models\Medico;
use App\Models\OrdenEstudio;
use App\Models\Paciente;
use App\Models\PrescripcionOcular;
use App\Models\Receta;
use App\Models\RegistroEnfermedad;
use App\Models\ResultadoEstudio;
use App\Models\TipoMedicion;
use App\Models\Tratamiento;
use App\Models\Turno;
use App\Models\User;
use App\Models\Vacuna;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Rutas;

/*
|--------------------------------------------------------------------------
| El barrido de privacidad (paso 14.3)
|--------------------------------------------------------------------------
|
| Compartir una ficha es la única etapa donde un error no rompe nada visible:
| simplemente filtra. Por eso esto no es un test por ruta escrito a mano -que se
| olvida la ruta número cuarenta- sino uno que recorre TODAS las rutas de la app:
|
| - A. Un LECTOR de la ficha prueba cada ruta de escritura sobre ella: 403.
| - B. Un EXTRAÑO prueba cada ruta que recibe un registro ajeno, de lectura o de
|      escritura: 403.
|
| Los pedidos van SIN datos a propósito: si una ruta validara antes de autorizar,
| al intruso le contestaría un error de validación -y de paso le confirmaría que
| el registro existe- en vez de un 403. "Autorizar va antes de validar" es regla
| del proyecto, y este es el test que la hace cumplir en todas partes.
|
| Una ruta con un parámetro que este test no conoce lo hace FALLAR: toda ruta
| nueva tiene que quedar clasificada acá, a conciencia.
|
*/

/**
 * Las rutas que no entran en el barrido, cada una con su motivo.
 *
 * @return array<string, string>
 */
function rutasFueraDelBarrido(): array
{
    return [
        'invitaciones.mostrar' => 'pública a propósito: la protege la firma (ver CompartirFichaTest)',
        'invitaciones.aceptar' => 'pública a propósito: la protege la firma (ver CompartirFichaTest)',
        'paciente-activo.update' => 'elegir qué ficha ver primero es de lectura: un lector puede',
        // Del andamiaje de autenticación, no reciben registros de nadie:
        'password.reset' => 'la protege el token de la URL (Fortify)',
        'verification.verify' => 'URL firmada con id y hash (Fortify)',
        'passkey.destroy' => 'el paquete verifica que la passkey sea del usuario logueado (403)',
    ];
}

/**
 * Una ficha con un registro de cada cosa, y los catálogos y la casilla de su
 * dueño. Devuelve parámetro de ruta => modelo.
 *
 * @return array<string, Model>
 */
function fichaParaBarrer(User $duenio, User $cuidador): array
{
    $paciente = Paciente::factory()->for($duenio, 'usuario')->create();
    $paciente->cuidadores()->attach($cuidador, ['rol' => RolPaciente::Cuidador->value]);

    $cobertura = Cobertura::factory()->for($paciente)->create();
    $enfermedad = Enfermedad::factory()->for($paciente)->create();
    $estudio = Estudio::factory()->for($paciente)->create();
    $turno = Turno::factory()->for($paciente)->create();
    $tipo = TipoMedicion::factory()->for($duenio, 'usuario')->create();

    // Una credencial: es lo único que sirve `credenciales.show`.
    $credencial = $cobertura->adjuntos()->create([
        'tipo' => TipoAdjunto::Credencial,
        'ruta' => 'coberturas/x/y.cif',
        'nombre_original' => 'frente.jpg',
        'mime' => 'image/jpeg',
        'tamanio_bytes' => 10,
    ]);

    return [
        'paciente' => $paciente,
        'cobertura' => $cobertura,
        'enfermedad' => $enfermedad,
        'registro' => RegistroEnfermedad::factory()->for($enfermedad)->create(),
        'alergia' => Alergia::factory()->for($paciente)->create(),
        'medicion' => Medicion::factory()->for($paciente)->create(['tipo_medicion_id' => $tipo->id]),
        'tratamiento' => Tratamiento::factory()->for($paciente)->create(),
        'orden' => OrdenEstudio::factory()->for($paciente)->create(),
        'estudio' => $estudio,
        'resultado' => ResultadoEstudio::factory()->for($estudio)->create(),
        'prescripcion' => PrescripcionOcular::factory()->for($paciente)->create(),
        'aplicacion' => AplicacionVacuna::factory()->for($paciente)->create([
            'vacuna_id' => Vacuna::factory()->for($duenio, 'usuario')->create()->id,
        ]),
        'turno' => $turno,
        'recordatorio' => $turno->recordatorios()->sole(),
        'adjunto' => $credencial,
        // Los accesos: alguien a quien un intruso intentaría cambiarle el permiso o sacar.
        'usuario' => $cuidador,

        // Lo del USUARIO, no del paciente: solo entra en el barrido del extraño.
        'medico' => Medico::factory()->for($duenio, 'usuario')->create(),
        'centro' => Centro::factory()->for($duenio, 'usuario')->create(),
        'medicamento' => Medicamento::factory()->for($duenio, 'usuario')->create(),
        'vacuna' => Vacuna::factory()->for($duenio, 'usuario')->create(),
        'tipo_medicion' => $tipo,
        'cuenta' => CuentaMail::factory()->for($duenio, 'usuario')->create(),
        'contacto' => Contacto::factory()->for($duenio, 'usuario')->create(),
        'receta' => Receta::factory()->create(['usuario_id' => $duenio->id]),
    ];
}

/** Los parámetros que cuelgan de la FICHA (los otros son del usuario). */
function parametrosDeLaFicha(): array
{
    return [
        'paciente', 'cobertura', 'enfermedad', 'registro', 'alergia', 'medicion',
        'tratamiento', 'orden', 'estudio', 'resultado', 'prescripcion', 'aplicacion', 'turno',
        'recordatorio', 'adjunto', 'usuario',
    ];
}

/**
 * Las rutas web con parámetros, ya clasificadas.
 *
 * @param  array<string, Model>  $ficha
 * @return list<array{nombre: string, metodo: string, url: string, parametros: list<string>}>
 */
function rutasConParametros(array $ficha): array
{
    $rutas = [];
    $sinClasificar = [];

    foreach (Rutas::getRoutes()->getRoutes() as $ruta) {
        /** @var Route $ruta */
        $nombre = $ruta->getName();
        $parametros = $ruta->parameterNames();

        if ($nombre === null || $parametros === [] || array_key_exists($nombre, rutasFueraDelBarrido())) {
            continue;
        }

        // Solo las de la app (las de Fortify, Wayfinder, etc. no reciben registros).
        if (! in_array('web', $ruta->gatherMiddleware(), true)) {
            continue;
        }

        $desconocidos = array_diff($parametros, array_keys($ficha));

        if ($desconocidos !== []) {
            $sinClasificar[] = $nombre.' ('.implode(', ', $desconocidos).')';

            continue;
        }

        $metodo = collect($ruta->methods())->first(fn (string $m): bool => $m !== 'HEAD');

        $rutas[] = [
            'nombre' => $nombre,
            'metodo' => $metodo,
            'url' => route($nombre, array_intersect_key($ficha, array_flip($parametros))),
            'parametros' => $parametros,
        ];
    }

    expect($sinClasificar)->toBe([], 'Rutas con parámetros que el barrido no sabe armar. '
        .'Sumá el parámetro a fichaParaBarrer() o la ruta a rutasFueraDelBarrido(), con su motivo: '
        ."\n - ".implode("\n - ", $sinClasificar));

    return $rutas;
}

it('⚠️ A. un LECTOR recibe 403 en cada ruta de escritura de la ficha', function (): void {
    $duenio = User::factory()->create();
    $ficha = fichaParaBarrer($duenio, User::factory()->create());

    $lector = User::factory()->create();
    $ficha['paciente']->cuidadores()->attach($lector, ['rol' => RolPaciente::Lector->value]);

    $noFrenadas = [];
    $revisadas = 0;

    foreach (rutasConParametros($ficha) as $ruta) {
        $esDeLaFicha = array_diff($ruta['parametros'], parametrosDeLaFicha()) === [];

        if ($ruta['metodo'] === 'GET' || ! $esDeLaFicha) {
            continue;
        }

        $revisadas++;
        $estado = $this->actingAs($lector)->call($ruta['metodo'], $ruta['url'])->getStatusCode();

        if ($estado !== 403) {
            $noFrenadas[] = "{$ruta['metodo']} {$ruta['nombre']} -> {$estado}";
        }
    }

    expect($noFrenadas)->toBe([], "\n - ".implode("\n - ", $noFrenadas)."\n")
        // Que el barrido de verdad haya barrido algo.
        ->and($revisadas)->toBeGreaterThan(25);
});

it('⚠️ B. un EXTRAÑO recibe 403 en cada ruta que recibe un registro ajeno', function (): void {
    $duenio = User::factory()->create();
    $ficha = fichaParaBarrer($duenio, User::factory()->create());

    $extranio = User::factory()->create();

    $noFrenadas = [];
    $revisadas = 0;

    foreach (rutasConParametros($ficha) as $ruta) {
        $revisadas++;
        $estado = $this->actingAs($extranio)->call($ruta['metodo'], $ruta['url'])->getStatusCode();

        if ($estado !== 403) {
            $noFrenadas[] = "{$ruta['metodo']} {$ruta['nombre']} -> {$estado}";
        }
    }

    expect($noFrenadas)->toBe([], "\n - ".implode("\n - ", $noFrenadas)."\n")
        ->and($revisadas)->toBeGreaterThan(50);
});

it('un CUIDADOR sí puede escribir en la ficha: el barrido no frena de más', function (): void {
    /*
     * El control de que el 403 del lector se debe a su rol y no a que las rutas
     * fallen para cualquiera: con el mismo pedido sin datos, el cuidador pasa la
     * autorización y le contesta la validación (o lo que corresponda), nunca 403.
     */
    $duenio = User::factory()->create();
    $cuidador = User::factory()->create();
    $ficha = fichaParaBarrer($duenio, $cuidador);

    $estado = $this->actingAs($cuidador)
        ->put(route('turnos.update', $ficha['turno']))
        ->getStatusCode();

    expect($estado)->not->toBe(403);
});
