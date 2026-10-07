<?php

declare(strict_types=1);

use App\Enums\TipoAdjunto;
use App\Models\AplicacionVacuna;
use App\Models\Cobertura;
use App\Models\Medicamento;
use App\Models\Medicion;
use App\Models\OrdenEstudio;
use App\Models\Paciente;
use App\Models\Receta;
use App\Models\TipoMedicion;
use App\Models\Tratamiento;
use App\Models\Turno;
use App\Models\User;
use App\Models\Vacuna;

it('sin pacientes, no ofrece ninguna credencial', function (): void {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('pacienteActivo', null)
            ->where('credenciales', [])
        );
});

it('sin paciente activo en sesión, usa el primero por nombre', function (): void {
    $usuario = User::factory()->create();
    Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Zoe']);
    $primero = Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Ana']);

    $this->actingAs($usuario)
        ->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p
            ->where('pacienteActivo.id', $primero->id)
            ->where('pacienteActivo.nombre', 'Ana')
        );
});

it('respeta el paciente activo de la sesión', function (): void {
    $usuario = User::factory()->create();
    Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Ana']);
    $activo = Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Zoe']);

    $this->actingAs($usuario)
        ->withSession(['paciente_activo_id' => $activo->id])
        ->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p->where('pacienteActivo.id', $activo->id));
});

it('ignora un paciente activo en sesión que ya no es accesible', function (): void {
    $usuario = User::factory()->create();
    $propio = Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Ana']);
    $ajeno = Paciente::factory()->create();

    $this->actingAs($usuario)
        ->withSession(['paciente_activo_id' => $ajeno->id])
        ->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p->where('pacienteActivo.id', $propio->id));
});

it('sin coberturas, la credencial dice "sin datos" y no explota', function (): void {
    $usuario = User::factory()->create();
    Paciente::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('credenciales', []));
});

it('ofrece la credencial de una cobertura activa', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $cobertura = Cobertura::factory()->for($paciente)->create([
        'entidad' => 'OSDE',
        'activa' => true,
    ]);
    $adjunto = $cobertura->adjuntos()->create([
        'tipo' => TipoAdjunto::Credencial,
        'ruta' => 'coberturas/1/frente.cif',
        'nombre_original' => 'frente.jpg',
        'mime' => 'image/jpeg',
        'tamanio_bytes' => 2048,
    ]);

    $this->actingAs($usuario)
        ->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p
            ->has('credenciales', 1)
            ->where('credenciales.0.entidad', 'OSDE')
            ->where('credenciales.0.nombre', 'frente.jpg')
            ->where('credenciales.0.url', route('credenciales.show', $adjunto))
        );
});

it('NO ofrece la credencial de una cobertura inactiva', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $cobertura = Cobertura::factory()->for($paciente)->create(['activa' => false]);
    $cobertura->adjuntos()->create([
        'tipo' => TipoAdjunto::Credencial,
        'ruta' => 'coberturas/1/frente.cif',
        'nombre_original' => 'frente.jpg',
        'mime' => 'image/jpeg',
        'tamanio_bytes' => 2048,
    ]);

    $this->actingAs($usuario)
        ->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p->where('credenciales', []));
});

it('NO ofrece un adjunto que no es de tipo credencial', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $cobertura = Cobertura::factory()->for($paciente)->create(['activa' => true]);
    $cobertura->adjuntos()->create([
        'tipo' => TipoAdjunto::Otro,
        'ruta' => 'coberturas/1/algo.cif',
        'nombre_original' => 'algo.pdf',
        'mime' => 'application/pdf',
        'tamanio_bytes' => 1024,
    ]);

    $this->actingAs($usuario)
        ->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p->where('credenciales', []));
});

it('exige sesión para ver el panel', function (): void {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

/*
|--------------------------------------------------------------------------
| Tratamientos activos (paso 8.2)
|--------------------------------------------------------------------------
*/

it('sin tratamientos, dice "sin datos" y no explota', function (): void {
    $usuario = User::factory()->create();
    Paciente::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)
        ->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p->where('tratamientosActivos', []));
});

it('ofrece los tratamientos activos del paciente activo', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $medicamento = Medicamento::factory()->for($usuario, 'usuario')->create(['nombre_comercial' => 'Actron']);
    Tratamiento::factory()->for($paciente)->for($medicamento)->create([
        'dosis' => '400mg',
        'frecuencia' => 'Cada 8 horas',
    ]);

    $this->actingAs($usuario)
        ->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p
            ->has('tratamientosActivos', 1)
            ->where('tratamientosActivos.0.medicamento', 'Actron')
            ->where('tratamientosActivos.0.dosis', '400mg')
        );
});

it('NO ofrece un tratamiento inactivo', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $medicamento = Medicamento::factory()->for($usuario, 'usuario')->create();
    Tratamiento::factory()->for($paciente)->for($medicamento)->inactivo()->create();

    $this->actingAs($usuario)
        ->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p->where('tratamientosActivos', []));
});

/*
|--------------------------------------------------------------------------
| Etapa 15.1: el resto de las tarjetas y el selector de ficha
|--------------------------------------------------------------------------
*/

it('ofrece elegir entre las fichas, por nombre, y elegir una la vuelve la activa', function (): void {
    $usuario = User::factory()->create();
    $zoe = Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Zoe']);
    Paciente::factory()->for($usuario, 'usuario')->create(['nombre' => 'Ana']);
    Paciente::factory()->create(['nombre' => 'Ajena']);

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p
            ->where('pacientes.0.nombre', 'Ana')
            ->where('pacientes.1.nombre', 'Zoe')
            ->has('pacientes', 2)
            ->where('pacienteActivo.nombre', 'Ana'));

    $this->actingAs($usuario)
        ->put(route('paciente-activo.update', $zoe))
        ->assertRedirect();

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p->where('pacienteActivo.nombre', 'Zoe'));
});

it('los próximos turnos: solo los que siguen en pie y vienen, el más cercano primero', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    Turno::factory()->for($paciente)->create(['fecha_hora' => now()->addDays(10), 'motivo' => 'Lejano']);
    Turno::factory()->for($paciente)->create(['fecha_hora' => now()->addDays(2), 'motivo' => 'Cercano']);
    Turno::factory()->for($paciente)->cancelado()->create(['fecha_hora' => now()->addDay(), 'motivo' => 'Cancelado']);
    Turno::factory()->for($paciente)->asistido()->create(['motivo' => 'Pasado']);

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p
            ->where('proximosTurnos.total', 2)
            ->where('proximosTurnos.filas.0.motivo', 'Cercano')
            ->where('proximosTurnos.filas.1.motivo', 'Lejano'));
});

it('las órdenes: solo las pendientes, con el total aunque se muestren tres', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    OrdenEstudio::factory()->for($paciente)->count(4)->create();
    OrdenEstudio::factory()->for($paciente)->hecha()->create();

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p
            ->where('ordenesPendientes.total', 4)
            ->has('ordenesPendientes.filas', 3));
});

it('las próximas vacunas: la de la última dosis de cada una, y solo si no pasó', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $hepatitis = Vacuna::factory()->for($usuario, 'usuario')->create(['nombre' => 'Hepatitis B']);
    $gripe = Vacuna::factory()->for($usuario, 'usuario')->create(['nombre' => 'Antigripal']);

    // La primera dosis anunciaba una próxima que ya se cumplió con la segunda.
    AplicacionVacuna::factory()->for($paciente)->create([
        'vacuna_id' => $hepatitis->id, 'fecha' => now()->subDays(60), 'proxima_dosis' => now()->addDays(5),
    ]);
    AplicacionVacuna::factory()->for($paciente)->create([
        'vacuna_id' => $hepatitis->id, 'fecha' => now()->subDays(10), 'proxima_dosis' => now()->addDays(170),
    ]);
    // Una próxima dosis que ya pasó no es "próxima".
    AplicacionVacuna::factory()->for($paciente)->create([
        'vacuna_id' => $gripe->id, 'fecha' => now()->subDays(400), 'proxima_dosis' => now()->subDays(35),
    ]);

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p
            ->has('proximasVacunas', 1)
            ->where('proximasVacunas.0.vacuna', 'Hepatitis B')
            ->where('proximasVacunas.0.fechaVisible', now()->addDays(170)->format('d/m/Y')));
});

it('las últimas mediciones: una por variable, la más reciente, con el mismo formato que su pantalla', function (): void {
    $usuario = User::factory()->create();
    $paciente = Paciente::factory()->for($usuario, 'usuario')->create();
    $peso = TipoMedicion::factory()->for($usuario, 'usuario')->create(['nombre' => 'Peso', 'unidad' => 'kg', 'decimales' => 1]);
    Medicion::factory()->for($paciente)->create(['tipo_medicion_id' => $peso->id, 'fecha' => now()->subDays(9), 'valor' => '80']);
    Medicion::factory()->for($paciente)->create(['tipo_medicion_id' => $peso->id, 'fecha' => now()->subDay(), 'valor' => '72.5']);

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p
            ->has('ultimasMediciones', 1)
            ->where('ultimasMediciones.0.tipo', 'Peso')
            ->where('ultimasMediciones.0.valor', '72,5')
            ->where('ultimasMediciones.0.unidad', 'kg'));
});

it('las recetas: sin casilla ni recetas la tarjeta no existe; con recetas cuenta solo las disponibles', function (): void {
    $usuario = User::factory()->create();
    Paciente::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p->where('recetas', null));

    Receta::factory()->create(['usuario_id' => $usuario->id, 'asunto' => 'Disponible']);
    Receta::factory()->vencida()->create(['usuario_id' => $usuario->id]);
    Receta::factory()->usada()->create(['usuario_id' => $usuario->id]);
    // La de otra persona no aparece nunca.
    Receta::factory()->create(['asunto' => 'Ajena']);

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertInertia(fn ($p) => $p
            ->where('recetas.total', 1)
            ->where('recetas.filas.0.asunto', 'Disponible'));
});
