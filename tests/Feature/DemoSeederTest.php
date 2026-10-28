<?php

declare(strict_types=1);

use App\Enums\RolPaciente;
use App\Enums\TipoAdjunto;
use App\Models\Adjunto;
use App\Models\Medicion;
use App\Models\ResultadoEstudio;
use App\Models\TipoMedicion;
use App\Models\Turno;
use App\Models\User;
use App\Services\ArchivoService;
use App\Services\PacientePrincipal;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| La familia de la demo: lo que `demo:reset` siembra cada noche
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(CatalogosSeeder::class);
    $this->seed(DemoSeeder::class);
    $this->ana = User::where('email', config('demo.email'))->firstOrFail();
});

it('arma la cuenta de la demo lista para entrar, con su ficha como principal', function (): void {
    expect($this->ana->hasVerifiedEmail())->toBeTrue()
        ->and($this->ana->terminos_version)->toBe(config('legales.version'))
        ->and(Hash::check((string) config('demo.password'), $this->ana->password))->toBeTrue()
        ->and(PacientePrincipal::de($this->ana)?->nombre)->toBe('Ana Gómez');
});

it('tiene tres fichas propias y una que le comparten como cuidadora', function (): void {
    $roles = $this->ana->pacientes()->get()
        ->mapWithKeys(fn ($p) => [$p->nombre => $p->pivot->rol])
        ->sortKeys()
        ->all();

    expect($roles)->toBe([
        'Ana Gómez' => RolPaciente::Propietario->value,
        'Elena Ruiz' => RolPaciente::Cuidador->value,
        'Roberto Gómez' => RolPaciente::Propietario->value,
        'Tomás Gómez' => RolPaciente::Propietario->value,
    ]);
});

it('carga series que el gráfico de evolución puede dibujar', function (): void {
    // Más de un punto por variable y por parámetro: con uno solo no se dibuja
    // nada. La altura de un adulto va una sola vez, a propósito: alcanza para el IMC.
    $altura = TipoMedicion::where('clave', TipoMedicion::CLAVE_ALTURA)->value('id');
    $porVariable = Medicion::query()->where('tipo_medicion_id', '!=', $altura)->get()
        ->groupBy(fn ($m) => $m->paciente_id.'-'.$m->tipo_medicion_id);
    $porParametro = ResultadoEstudio::query()->get()->groupBy('parametro_hash');

    expect($porVariable)->not->toBeEmpty()
        ->and($porVariable->every(fn ($serie) => $serie->count() >= 2))->toBeTrue()
        ->and($porParametro)->toHaveCount(4)
        ->and($porParametro->every(fn ($serie) => $serie->count() === 3))->toBeTrue();
});

it('no deja ninguna medición en el futuro: el reset corre de madrugada', function (): void {
    expect(Medicion::query()->where('fecha', '>', now())->exists())->toBeFalse();
});

it('deja turnos por venir, cada uno con su aviso', function (): void {
    $proximos = Turno::query()->where('fecha_hora', '>', now())->get();

    expect($proximos)->not->toBeEmpty()
        ->and($proximos->every(fn (Turno $t) => $t->recordatorios()->exists()))->toBeTrue();
});

it('guarda los archivos cifrados en el disco, y descifrados son un PDF o un PNG', function (): void {
    $archivos = app(ArchivoService::class);
    $adjuntos = Adjunto::all();

    expect($adjuntos->where('tipo', TipoAdjunto::Credencial))->toHaveCount(3)
        ->and($adjuntos->where('mime', 'application/pdf'))->not->toBeEmpty();

    foreach ($adjuntos as $adjunto) {
        Storage::disk('local')->assertExists($adjunto->ruta);
        $contenido = $archivos->contenido($adjunto);

        expect(str_starts_with($contenido, '%PDF-') || str_starts_with($contenido, "\x89PNG"))->toBeTrue();
    }
});
