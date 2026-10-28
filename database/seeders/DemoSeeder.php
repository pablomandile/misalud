<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Contracts\EsCatalogo;
use App\Contracts\TieneArchivos;
use App\Enums\EstadoEnfermedad;
use App\Enums\EstadoOrdenEstudio;
use App\Enums\EstadoReceta;
use App\Enums\EstadoTurno;
use App\Enums\Ojo;
use App\Enums\PeriodicidadToma;
use App\Enums\RolPaciente;
use App\Enums\SeveridadAlergia;
use App\Enums\TipoAdjunto;
use App\Enums\TipoCentro;
use App\Enums\TipoCobertura;
use App\Enums\TipoContacto;
use App\Enums\TipoPrescripcionOcular;
use App\Models\Centro;
use App\Models\Cobertura;
use App\Models\Enfermedad;
use App\Models\Farmacia;
use App\Models\Medicamento;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\TipoMedicion;
use App\Models\User;
use App\Models\Vacuna;
use App\Services\ArchivoService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * La familia inventada de la demo (APP_DEMO=true): la corre `demo:reset` todas
 * las noches, después de `CatalogosSeeder`, sobre una base vacía.
 *
 * - **Ana Gómez** es la cuenta de la demo (`config('demo.email')`). Tiene su
 *   ficha —con su mismo correo, así es la principal—, la de su padre Roberto
 *   (la más cargada: presión, laboratorio, anteojos, turnos) y la de su hijo
 *   Tomás (vacunas, peso y altura).
 * - **Laura Gómez**, su hermana, es otra cuenta con una clave que nadie conoce:
 *   le comparte a Ana la ficha de la abuela Elena como cuidadora, para que se
 *   vean las solapas «De Elena» de los catálogos.
 *
 * Todo se escribe **por las relaciones y los modelos**, como el código de
 * producción: así corren los observers (el pivote de propietario, los
 * recordatorios de turnos, vacunas y tratamientos) y se cifra lo que se cifra.
 * Las fechas son **relativas a hoy**, para que la demo siempre esté al día, y
 * todo lo que ya pasó cae a partir de ayer: el reset corre de madrugada, y una
 * medición "de hoy a las 8" todavía sería futura.
 *
 * Los archivos (informes, órdenes, credenciales) se generan acá —un PDF de una
 * página escrito a mano y un PNG con GD— y se guardan cifrados con
 * `ArchivoService`, igual que los que llegan por mail.
 *
 * @phpstan-type Catalogo array{hospital: Centro, laboratorio: Centro, optica: Centro, clinica: Medico, cardio: Medico, oftalmo: Medico, pediatra: Medico, farmacia: Farmacia, vitaminaD: Medicamento}
 */
class DemoSeeder extends Seeder
{
    private const ZONA = User::ZONA_POR_DEFECTO;

    private CarbonImmutable $hoy;

    public function __construct(private readonly ArchivoService $archivos) {}

    public function run(): void
    {
        mt_srand(20261027);
        $this->hoy = CarbonImmutable::now(self::ZONA)->startOfDay();

        $ana = $this->cuenta('Ana Gómez', (string) config('demo.email'), (string) config('demo.password'));
        $laura = $this->cuenta('Laura Gómez', 'laura@ejemplo.test', Str::random(40));

        $catalogo = $this->catalogoDeAna($ana);

        $fichaAna = $this->ficha($ana, [
            'nombre' => 'Ana Gómez',
            'dni' => '28.456.789',
            'fecha_nacimiento' => $this->hoy->subYears(45)->subDays(80),
            'sexo' => 'femenino',
            'grupo_sanguineo' => 'A+',
            'telefono' => '11 5555-0101',
            'email' => $ana->email,
        ]);
        $roberto = $this->ficha($ana, [
            'nombre' => 'Roberto Gómez',
            'dni' => '7.654.321',
            'fecha_nacimiento' => $this->hoy->subYears(78)->subDays(40),
            'sexo' => 'masculino',
            'grupo_sanguineo' => '0+',
            'telefono' => '11 5555-0102',
            'notas' => 'Usa audífono del lado izquierdo. Prefiere los turnos a la mañana.',
        ]);
        $tomas = $this->ficha($ana, [
            'nombre' => 'Tomás Gómez',
            'fecha_nacimiento' => $this->hoy->subYears(8)->subDays(120),
            'sexo' => 'masculino',
            'grupo_sanguineo' => 'A+',
        ]);

        $this->ana($fichaAna, $catalogo);
        $this->roberto($roberto, $catalogo);
        $this->tomas($tomas, $catalogo);
        $this->elena($laura, $ana);
        $this->contactos($ana);
    }

    // ─── Cuentas y catálogo ─────────────────────────────────────────────────

    private function cuenta(string $nombre, string $email, string $clave): User
    {
        $usuario = User::create(['name' => $nombre, 'email' => $email, 'password' => $clave]);
        $usuario->forceFill(['email_verified_at' => now()])->save();
        $usuario->aceptarTerminos();

        return $usuario;
    }

    /**
     * @return Catalogo
     */
    private function catalogoDeAna(User $ana): array
    {
        $hospital = $this->delCatalogo($ana, new Centro([
            'nombre' => 'Hospital Italiano (ejemplo)',
            'tipo' => TipoCentro::Hospital,
            'direccion' => 'Av. Siempreviva 742, CABA',
            'telefono' => '11 4959-0000',
            'whatsapp' => '11 2345-6789',
            'email' => 'turnos@hospital.ejemplo.test',
            'web' => 'https://www.hospital.ejemplo.test',
            'horarios' => 'Lunes a viernes de 8 a 20. Sábados de 8 a 13.',
        ]));
        $laboratorio = $this->delCatalogo($ana, new Centro([
            'nombre' => 'Laboratorio Central (ejemplo)',
            'tipo' => TipoCentro::Laboratorio,
            'direccion' => 'Calle Falsa 123, Vicente López',
            'telefono' => '11 4700-1234',
            'horarios' => 'Extracciones de 7 a 10, con ayuno de 8 horas.',
        ]));
        $optica = $this->delCatalogo($ana, new Centro([
            'nombre' => 'Centro Oftalmológico Norte (ejemplo)',
            'tipo' => TipoCentro::Consultorio,
            'direccion' => 'Av. Maipú 1500, Olivos',
            'telefono' => '11 4799-5678',
        ]));

        $clinica = $this->delCatalogo($ana, new Medico([
            'nombre' => 'Dra. Marta Ríos',
            'especialidad' => 'Clínica médica',
            'telefono' => '11 4959-1111',
            'email' => 'consultorio.rios@ejemplo.test',
        ]));
        $cardio = $this->delCatalogo($ana, new Medico([
            'nombre' => 'Dr. Jorge Benítez',
            'especialidad' => 'Cardiología',
            'telefono' => '11 4959-2222',
        ]));
        $oftalmo = $this->delCatalogo($ana, new Medico([
            'nombre' => 'Dra. Silvia Paz',
            'especialidad' => 'Oftalmología',
        ]));
        $pediatra = $this->delCatalogo($ana, new Medico([
            'nombre' => 'Dr. Martín Sosa',
            'especialidad' => 'Pediatría',
            'telefono' => '11 4959-3333',
            'email' => 'pediatria.sosa@ejemplo.test',
        ]));

        $clinica->centros()->attach($hospital->id);
        $cardio->centros()->attach($hospital->id);
        $pediatra->centros()->attach($hospital->id);
        $oftalmo->centros()->attach($optica->id);

        $farmacia = $this->delCatalogo($ana, new Farmacia([
            'nombre' => 'Farmacia del Barrio (ejemplo)',
            'direccion' => 'Av. Libertador 2200, Olivos',
            'telefono' => '11 4790-0000',
            'whatsapp' => '11 3456-7890',
            'email' => 'pedidos@farmacia.ejemplo.test',
            'horarios' => 'Todos los días de 8 a 22.',
        ]));

        // Uno propio, que no está en la lista compartida.
        $vitaminaD = $this->delCatalogo($ana, new Medicamento([
            'nombre_comercial' => 'Vitamina D3 100.000 UI',
            'droga' => 'Colecalciferol',
            'para_que_sirve' => 'Déficit de vitamina D',
        ]));

        return compact('hospital', 'laboratorio', 'optica', 'clinica', 'cardio', 'oftalmo', 'pediatra', 'farmacia', 'vitaminaD');
    }

    /**
     * `usuario_id` no es fillable en ningún catálogo: lo pone el controlador
     * desde la sesión, y acá se pone igual, a mano.
     *
     * @template T of Model
     *
     * @param  T  $registro
     * @return T
     */
    private function delCatalogo(User $usuario, Model $registro): Model
    {
        $registro->setAttribute('usuario_id', $usuario->id);
        $registro->save();

        return $registro;
    }

    /**
     * Una semilla compartida de `CatalogosSeeder`, por su índice ciego (el
     * nombre está cifrado: no hay `where nombre = …`).
     *
     * @template T of Model&EsCatalogo
     *
     * @param  class-string<T>  $modelo
     * @return T
     */
    private function semilla(string $modelo, string $nombre): Model
    {
        $instancia = new $modelo;
        $columna = $instancia->indicesCiegos()[$instancia->columnaNombre()];

        return $modelo::query()
            ->whereNull('usuario_id')
            ->where($columna, $modelo::hashCiego($nombre))
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function ficha(User $duenio, array $datos): Paciente
    {
        // El observer de Paciente lo vincula como propietario.
        return Paciente::create(['usuario_id' => $duenio->id] + $datos);
    }

    // ─── Ana ────────────────────────────────────────────────────────────────

    /**
     * @param  Catalogo  $c
     */
    private function ana(Paciente $ana, array $c): void
    {
        $osde = $ana->coberturas()->create([
            'tipo' => TipoCobertura::Prepaga,
            'entidad' => 'OSDE',
            'plan' => '310',
            'nro_afiliado' => '61 234567 8 01',
            'telefono' => '0810-555-6733',
            'telefono_urgencias' => '0810-888-7788',
            'sitio_web' => 'https://www.osde.com.ar',
            'vigencia_desde' => $this->hoy->subYears(3),
            'activa' => true,
        ]);
        $this->credencial($osde, 'Ana Gomez');

        $ana->alergias()->create([
            'sustancia' => 'Ácaros del polvo',
            'reaccion' => 'Rinitis y estornudos',
            'severidad' => SeveridadAlergia::Leve,
        ]);

        $this->serie($ana, 'Peso', 12, 30, fn (int $i) => [number_format(64.5 + sin($i / 2) * 1.2, 1, '.', '')]);
        $this->serie($ana, 'Altura', 1, 300, fn () => ['165']);

        $ana->turnos()->create([
            'medico_id' => $c['clinica']->id,
            'centro_id' => $c['hospital']->id,
            'fecha_hora' => $this->instante(18, 9, 15),
            'motivo' => 'Control anual',
            'estado' => EstadoTurno::Programado,
        ]);

        $ana->claves()->create([
            'sitio' => 'Portal de OSDE (ejemplo)',
            'usuario' => 'ana.gomez',
            'clave' => 'Ejemplo-Demo-2026!',
            'notas' => 'Para pedir reintegros y ver la cartilla.',
        ]);

        $this->documento($ana, TipoAdjunto::Otro, 'Resumen de historia clínica', [
            'Resumen de historia clínica (ejemplo)',
            'Paciente: Ana Gómez',
            'Antecedentes: rinitis alérgica estacional.',
            'Cirugías: apendicectomía (2001).',
            'Documento inventado para la demo de MiSalud.',
        ]);
    }

    // ─── Roberto: la ficha más cargada ──────────────────────────────────────

    /**
     * @param  Catalogo  $c
     */
    private function roberto(Paciente $roberto, array $c): void
    {
        $pami = $roberto->coberturas()->create([
            'tipo' => TipoCobertura::Pami,
            'entidad' => 'PAMI',
            'plan' => 'Jubilados',
            'nro_afiliado' => '15 07654321 0 00',
            'telefono' => '138',
            'sitio_web' => 'https://www.pami.org.ar',
            'activa' => true,
        ]);
        $this->credencial($pami, 'Roberto Gomez');

        $roberto->alergias()->create([
            'sustancia' => 'Penicilina',
            'reaccion' => 'Ronchas y picazón en todo el cuerpo',
            'severidad' => SeveridadAlergia::Moderada,
            'notas' => 'Reacción en 2015. Avisar siempre antes de un antibiótico.',
        ]);

        $hipertension = $roberto->enfermedades()->create([
            'medico_id' => $c['cardio']->id,
            'nombre' => 'Hipertensión arterial',
            'fecha_diagnostico' => $this->hoy->subYears(6)->subDays(30),
            'estado' => EstadoEnfermedad::Cronica,
            'notas' => 'Controles cada tres meses con cardiología.',
        ]);
        foreach ([[300, 'Se ajusta la dosis de losartán a 50 mg dos veces por día.'],
            [120, 'Mareos leves al levantarse. Se indica tomar la presión de pie también.'],
            [20, 'Presión estable en los últimos dos meses.']] as [$dias, $nota]) {
            $hipertension->registros()->create(['fecha' => $this->dia($dias), 'nota' => $nota]);
        }

        $colesterol = $roberto->enfermedades()->create([
            'medico_id' => $c['clinica']->id,
            'nombre' => 'Colesterol alto',
            'fecha_diagnostico' => $this->hoy->subYears(3),
            'estado' => EstadoEnfermedad::Cronica,
        ]);
        $roberto->enfermedades()->create([
            'medico_id' => $c['clinica']->id,
            'nombre' => 'Gripe',
            'fecha_diagnostico' => $this->hoy->subDays(150),
            'estado' => EstadoEnfermedad::Resuelta,
            'notas' => 'Una semana de reposo. Sin complicaciones.',
        ]);

        // Un año de presión, mañana y noche cada tres días, vinculada a la hipertensión.
        $presion = $this->semilla(TipoMedicion::class, 'Presión arterial');
        for ($dias = 360; $dias >= 1; $dias -= 3) {
            foreach ([8, 20] as $hora) {
                $tendencia = $dias / 360 * 8;
                $roberto->mediciones()->create([
                    'tipo_medicion_id' => $presion->id,
                    'enfermedad_id' => $hipertension->id,
                    'fecha' => $this->instante(-$dias, $hora, mt_rand(0, 40)),
                    'valor' => (string) (int) round(128 + $tendencia + mt_rand(-6, 6) + ($hora === 20 ? -4 : 0)),
                    'valor_secundario' => (string) (int) round(80 + $tendencia / 2 + mt_rand(-4, 4)),
                ]);
            }
        }
        $this->serie($roberto, 'Peso', 12, 30, fn (int $i) => [number_format(82 - $i * 0.25 + mt_rand(-3, 3) / 10, 1, '.', '')]);
        $this->serie($roberto, 'Altura', 1, 200, fn () => ['172']);
        $this->serie($roberto, 'Glucemia', 24, 15, fn () => [(string) mt_rand(92, 118)]);

        // Medicamentos: dos diarios con horario y uno de una vez por año.
        $losartan = $roberto->tratamientos()->create([
            'medicamento_id' => $this->semilla(Medicamento::class, 'Losartán')->id,
            'medico_id' => $c['cardio']->id,
            'enfermedad_id' => $hipertension->id,
            'dosis' => '50 mg',
            'frecuencia' => 'cada 12 horas',
            'periodicidad' => PeriodicidadToma::Diaria,
            'tomas_por_dia' => 2,
            'horas' => ['08:00', '20:00'],
            'inicio' => $this->hoy->subYears(6),
            'activo' => true,
        ]);
        $losartan->forceFill([
            'plantilla_pedido' => 'Hola, ¿me podrías hacer la receta de Losartán 50 mg para Roberto? Son dos cajas por mes. Gracias.',
        ])->save();

        $roberto->tratamientos()->create([
            'medicamento_id' => $this->semilla(Medicamento::class, 'Atorvastatina')->id,
            'medico_id' => $c['clinica']->id,
            'enfermedad_id' => $colesterol->id,
            'dosis' => '20 mg',
            'periodicidad' => PeriodicidadToma::Diaria,
            'tomas_por_dia' => 1,
            'horas' => ['22:00'],
            'inicio' => $this->hoy->subYears(3),
            'activo' => true,
            'notas' => 'Con la cena.',
        ]);

        // La próxima aplicación cae en unas seis semanas: así se ve el aviso de "falta un mes".
        $proxima = $this->hoy->addDays(45);
        $vitaminaD = $roberto->tratamientos()->create([
            'medicamento_id' => $c['vitaminaD']->id,
            'medico_id' => $c['clinica']->id,
            'dosis' => '1 ampolla bebible',
            'periodicidad' => PeriodicidadToma::Anual,
            'aplicacion_dia' => $proxima->day,
            'aplicacion_mes' => $proxima->month,
            'inicio' => $this->hoy->subYears(2),
            'activo' => true,
        ]);
        foreach ([1, 2] as $anios) {
            $roberto->aplicacionesMedicamento()->create([
                'tratamiento_id' => $vitaminaD->id,
                'fecha' => $proxima->subYears($anios),
                'notas' => 'Aplicada en casa, con el desayuno.',
            ]);
        }

        $roberto->tratamientos()->create([
            'medicamento_id' => $this->semilla(Medicamento::class, 'Amoxicilina')->id,
            'dosis' => '500 mg',
            'frecuencia' => 'cada 8 horas',
            'periodicidad' => PeriodicidadToma::Diaria,
            'tomas_por_dia' => 3,
            'horas' => ['08:00', '16:00', '00:00'],
            'inicio' => $this->hoy->subDays(160),
            'fin' => $this->hoy->subDays(153),
            'activo' => false,
            'notas' => 'Lo indicó la guardia. Ojo: es una penicilina; después se cambió.',
        ]);

        $this->laboratorio($roberto, $c, $colesterol);
        $this->anteojos($roberto, $c);

        // Turnos: dos por venir (con su aviso) y uno al que ya fue.
        $roberto->turnos()->create([
            'medico_id' => $c['cardio']->id,
            'centro_id' => $c['hospital']->id,
            'fecha_hora' => $this->instante(3, 10, 30),
            'motivo' => 'Control de presión. Llevar el registro de las últimas semanas.',
            'estado' => EstadoTurno::Programado,
        ]);
        $roberto->turnos()->create([
            'medico_id' => $c['oftalmo']->id,
            'centro_id' => $c['optica']->id,
            'fecha_hora' => $this->instante(26, 16, 0),
            'motivo' => 'Control de la vista',
            'estado' => EstadoTurno::Programado,
        ]);
        $pasado = $roberto->turnos()->create([
            'medico_id' => $c['cardio']->id,
            'centro_id' => $c['hospital']->id,
            'fecha_hora' => $this->instante(-88, 10, 0),
            'motivo' => 'Control de presión',
            'estado' => EstadoTurno::Asistido,
        ]);

        $roberto->consultas()->create([
            'medico_id' => $c['cardio']->id,
            'centro_id' => $c['hospital']->id,
            'enfermedad_id' => $hipertension->id,
            'turno_id' => $pasado->id,
            'fecha_hora' => $this->instante(-88, 10, 20),
            'motivo' => 'Control de presión',
            'notas' => "La presión está bien controlada.\nSeguir con losartán 50 mg cada 12 horas.\nCaminar 30 minutos por día y bajar la sal.\nVolver en tres meses con el registro.",
        ]);
        $roberto->consultas()->create([
            'medico_id' => $c['clinica']->id,
            'centro_id' => $c['hospital']->id,
            'enfermedad_id' => $colesterol->id,
            'fecha_hora' => $this->instante(-40, 11, 0),
            'motivo' => 'Resultados del laboratorio',
            'notas' => 'El colesterol bajó con la atorvastatina. Repetir el análisis en seis meses.',
        ]);

        // Recetas: una para usar, una ya usada y una vencida.
        $receta = $roberto->recetasMedicas()->create([
            'medico_id' => $c['cardio']->id,
            'medicamento_id' => $losartan->medicamento_id,
            'detalle' => '2 cajas x 30 comprimidos',
            'fecha' => $this->dia(5),
            'vigencia_dias' => 30,
        ]);
        $this->documento($receta, TipoAdjunto::Receta, null, [
            'Receta (ejemplo)',
            'Paciente: Roberto Gómez — PAMI 15 07654321 0 00',
            'Rp/ Losartán 50 mg, 2 cajas x 30 comprimidos.',
            'Dr. Jorge Benítez — Cardiología',
            'Documento inventado para la demo de MiSalud.',
        ]);
        $usada = $roberto->recetasMedicas()->create([
            'medico_id' => $c['cardio']->id,
            'medicamento_id' => $losartan->medicamento_id,
            'detalle' => '2 cajas x 30 comprimidos',
            'fecha' => $this->dia(35),
            'vigencia_dias' => 30,
        ]);
        $usada->forceFill(['estado' => EstadoReceta::Usada])->save();
        $roberto->recetasMedicas()->create([
            'medico_id' => $c['clinica']->id,
            'detalle' => 'Atorvastatina 20 mg x 30',
            'fecha' => $this->dia(70),
            'vigencia_dias' => 30,
        ]);

        $roberto->aplicacionesVacuna()->create([
            'vacuna_id' => $this->semilla(Vacuna::class, 'Antigripal')->id,
            'centro_id' => $c['hospital']->id,
            'fecha' => $this->dia(200),
            'proxima_dosis' => $this->hoy->subDays(200)->addYear()->toDateString(),
            'dosis' => 'Anual',
        ]);
        $roberto->aplicacionesVacuna()->create([
            'vacuna_id' => $this->semilla(Vacuna::class, 'Neumococo conjugada')->id,
            'centro_id' => $c['hospital']->id,
            'fecha' => $this->dia(500),
            'dosis' => 'Única',
        ]);

        $roberto->claves()->create([
            'sitio' => 'Mi PAMI (ejemplo)',
            'usuario' => '7654321',
            'clave' => 'Otra-Clave-De-Ejemplo-9',
            'notas' => 'Para sacar turnos y ver las recetas electrónicas.',
        ]);
    }

    /**
     * Tres análisis con los mismos parámetros, para que la evolución dibuje, y
     * dos órdenes: una ya hecha (vinculada al último) y una pendiente.
     *
     * @param  Catalogo  $c
     */
    private function laboratorio(Paciente $roberto, array $c, Enfermedad $colesterol): void
    {
        $valores = [
            300 => ['Glucemia' => '112', 'Colesterol total' => '245', 'Colesterol LDL' => '160', 'Hemoglobina glicosilada (HbA1c)' => '6.1'],
            190 => ['Glucemia' => '104', 'Colesterol total' => '212', 'Colesterol LDL' => '132', 'Hemoglobina glicosilada (HbA1c)' => '5.9'],
            45 => ['Glucemia' => '98', 'Colesterol total' => '188', 'Colesterol LDL' => '108', 'Hemoglobina glicosilada (HbA1c)' => '5.7'],
        ];
        $unidades = [
            'Glucemia' => ['mg/dl', '70 a 110'],
            'Colesterol total' => ['mg/dl', 'Menor a 200'],
            'Colesterol LDL' => ['mg/dl', 'Menor a 130'],
            'Hemoglobina glicosilada (HbA1c)' => ['%', 'Menor a 5,7'],
        ];

        $ultimo = null;
        foreach ($valores as $dias => $resultados) {
            $estudio = $roberto->estudios()->create([
                'medico_id' => $c['clinica']->id,
                'centro_id' => $c['laboratorio']->id,
                'enfermedad_id' => $colesterol->id,
                'tipo' => 'Análisis de sangre',
                'fecha' => $this->dia($dias),
                'notas' => 'Con 12 horas de ayuno.',
            ]);
            $lineas = ['Laboratorio Central (ejemplo) — Informe', 'Paciente: Roberto Gómez', 'Fecha: '.$this->dia($dias)->format('d/m/Y'), ''];
            foreach ($resultados as $parametro => $valor) {
                [$unidad, $rango] = $unidades[$parametro];
                $estudio->resultados()->create([
                    'parametro' => $parametro,
                    'valor' => $valor,
                    'unidad' => $unidad,
                    'rango_referencia' => $rango,
                ]);
                $lineas[] = "{$parametro}: ".str_replace('.', ',', $valor)." {$unidad}   (ref.: {$rango})";
            }
            $lineas[] = '';
            $lineas[] = 'Documento inventado para la demo de MiSalud.';
            $this->documento($estudio, TipoAdjunto::InformeEstudio, null, $lineas);
            $ultimo = $estudio;
        }

        $hecha = $roberto->ordenesEstudio()->create([
            'medico_id' => $c['clinica']->id,
            'estudio_solicitado' => 'Hemograma, glucemia, colesterol total y LDL, HbA1c',
            'fecha' => $this->dia(55),
            'estado' => EstadoOrdenEstudio::Hecha,
        ]);
        $hecha->setAttribute('estudio_id', $ultimo->id);
        $hecha->save();
        $this->documento($hecha, TipoAdjunto::OrdenEstudio, null, [
            'Orden de estudio (ejemplo)',
            'Paciente: Roberto Gómez',
            'Solicito: hemograma, glucemia, colesterol total y LDL, HbA1c.',
            'Dra. Marta Ríos — Clínica médica',
        ]);

        $pendiente = $roberto->ordenesEstudio()->create([
            'medico_id' => $c['cardio']->id,
            'estudio_solicitado' => 'Ecocardiograma doppler',
            'fecha' => $this->dia(10),
            'estado' => EstadoOrdenEstudio::Pendiente,
            'notas' => 'Pedir turno en el hospital. Lleva autorización de PAMI.',
        ]);
        $this->documento($pendiente, TipoAdjunto::OrdenEstudio, null, [
            'Orden de estudio (ejemplo)',
            'Paciente: Roberto Gómez',
            'Solicito: ecocardiograma doppler.',
            'Diagnóstico: hipertensión arterial.',
            'Dr. Jorge Benítez — Cardiología',
        ]);
    }

    /**
     * Dos recetas de anteojos, con los dos ojos siempre (ver "Salud ocular"):
     * así se ven la evolución y el botón de transponer.
     *
     * @param  Catalogo  $c
     */
    private function anteojos(Paciente $roberto, array $c): void
    {
        $recetas = [
            700 => [['-1.75', '-0.75', 90], ['-2.00', '-0.50', 85], '2.25'],
            90 => [['-2.25', '-0.75', 90], ['-2.50', '-0.75', 80], '2.50'],
        ];

        foreach ($recetas as $dias => [$od, $oi, $adicion]) {
            $receta = $roberto->prescripcionesOculares()->create([
                'medico_id' => $c['oftalmo']->id,
                'centro_id' => $c['optica']->id,
                'tipo' => TipoPrescripcionOcular::Progresiva,
                'fecha' => $this->dia($dias),
                'dp_total' => '64',
            ]);
            foreach ([Ojo::Derecho->value => $od, Ojo::Izquierdo->value => $oi] as $ojo => [$esfera, $cilindro, $eje]) {
                $receta->graduaciones()->create([
                    'ojo' => $ojo,
                    'esfera' => $esfera,
                    'cilindro' => $cilindro,
                    'eje' => (string) $eje,
                    'adicion' => $adicion,
                    'dp_monocular' => '32',
                ]);
            }
        }

        // El papel de la más reciente.
        $this->documento($receta, TipoAdjunto::PrescripcionOcular, null, [
            'Receta de anteojos (ejemplo)',
            'Paciente: Roberto Gómez',
            'OD: -2,25 esf. -0,75 cil. x 90°',
            'OI: -2,50 esf. -0,75 cil. x 80°',
            'Adición: +2,50. Lentes progresivos.',
            'Dra. Silvia Paz — Oftalmología',
        ]);
    }

    // ─── Tomás ──────────────────────────────────────────────────────────────

    /**
     * @param  Catalogo  $c
     */
    private function tomas(Paciente $tomas, array $c): void
    {
        $osde = $tomas->coberturas()->create([
            'tipo' => TipoCobertura::Prepaga,
            'entidad' => 'OSDE',
            'plan' => '310',
            'nro_afiliado' => '61 234567 8 03',
            'activa' => true,
        ]);
        $this->credencial($osde, 'Tomas Gomez');

        $nacimiento = CarbonImmutable::parse($tomas->fecha_nacimiento?->toDateString(), self::ZONA);
        $dosis = [
            ['BCG', 0, 'Única', null],
            ['Hepatitis B', 0, '1ª dosis', null],
            ['Quíntuple pentavalente', 2, '1ª dosis', 4],
            ['Quíntuple pentavalente', 4, '2ª dosis', 6],
            ['Quíntuple pentavalente', 6, '3ª dosis', null],
            ['Neumococo conjugada', 2, '1ª dosis', 4],
            ['Neumococo conjugada', 4, '2ª dosis', 12],
            ['Neumococo conjugada', 12, 'Refuerzo', null],
            ['Triple viral (sarampión, rubéola, paperas)', 12, '1ª dosis', 60],
            ['Triple viral (sarampión, rubéola, paperas)', 60, '2ª dosis', null],
            ['Varicela', 15, '1ª dosis', 60],
            ['Varicela', 60, '2ª dosis', null],
            ['Hepatitis A', 12, 'Única', null],
            ['Triple bacteriana (dTpa)', 60, 'Refuerzo de ingreso escolar', 132],
        ];
        foreach ($dosis as [$vacuna, $meses, $cual, $proxima]) {
            $ultima = $tomas->aplicacionesVacuna()->create([
                'vacuna_id' => $this->semilla(Vacuna::class, $vacuna)->id,
                'centro_id' => $c['hospital']->id,
                'fecha' => $nacimiento->addMonths($meses)->toDateString(),
                'proxima_dosis' => $proxima === null ? null : $nacimiento->addMonths($proxima)->toDateString(),
                'dosis' => $cual,
            ]);
        }
        $tomas->aplicacionesVacuna()->create([
            'vacuna_id' => $this->semilla(Vacuna::class, 'Antigripal')->id,
            'centro_id' => $c['hospital']->id,
            'fecha' => $this->dia(170),
            'dosis' => 'Anual',
        ]);
        // El comprobante de la última dosis.
        $this->documento($ultima, TipoAdjunto::Vacuna, null, [
            'Certificado de vacunación (ejemplo)',
            'Paciente: Tomás Gómez',
            'Vacuna: Triple bacteriana (dTpa), refuerzo de ingreso escolar.',
            'Lote: DEMO-1234',
        ]);

        // Peso y altura cada tres meses: con los dos se ve el IMC.
        $this->serie($tomas, 'Peso', 8, 90, fn (int $i) => [number_format(27.5 - $i * 0.6, 1, '.', '')]);
        $this->serie($tomas, 'Altura', 8, 90, fn (int $i) => [(string) (130 - $i * 1.5)]);

        $tomas->turnos()->create([
            'medico_id' => $c['pediatra']->id,
            'centro_id' => $c['hospital']->id,
            'fecha_hora' => $this->instante(9, 17, 30),
            'motivo' => 'Control de salud y certificado para la escuela',
            'estado' => EstadoTurno::Programado,
        ]);
        $tomas->consultas()->create([
            'medico_id' => $c['pediatra']->id,
            'centro_id' => $c['hospital']->id,
            'fecha_hora' => $this->instante(-60, 18, 0),
            'motivo' => 'Tos y fiebre',
            'notas' => 'Angina viral. Paracetamol si tiene fiebre, mucho líquido. Volver si sigue más de tres días.',
        ]);
    }

    // ─── Elena: la ficha que comparte Laura ─────────────────────────────────

    private function elena(User $laura, User $ana): void
    {
        $geriatra = $this->delCatalogo($laura, new Medico([
            'nombre' => 'Dra. Paula Ferreyra',
            'especialidad' => 'Geriatría',
            'telefono' => '11 4555-4444',
            'email' => 'consultorio.ferreyra@ejemplo.test',
        ]));
        $this->delCatalogo($laura, new Medico([
            'nombre' => 'Dr. Ernesto Molina',
            'especialidad' => 'Traumatología',
        ]));
        $this->delCatalogo($laura, new Farmacia([
            'nombre' => 'Farmacia San Martín (ejemplo)',
            'direccion' => 'San Martín 450, San Isidro',
            'telefono' => '11 4747-0000',
        ]));

        $elena = $this->ficha($laura, [
            'nombre' => 'Elena Ruiz',
            'fecha_nacimiento' => $this->hoy->subYears(91)->subDays(200),
            'sexo' => 'femenino',
            'grupo_sanguineo' => 'B+',
            'notas' => 'Vive con Laura. Camina con bastón.',
        ]);
        $ana->pacientes()->attach($elena->id, ['rol' => RolPaciente::Cuidador->value]);

        $artrosis = $elena->enfermedades()->create([
            'medico_id' => $geriatra->id,
            'nombre' => 'Artrosis de rodilla',
            'fecha_diagnostico' => $this->hoy->subYears(10),
            'estado' => EstadoEnfermedad::Cronica,
        ]);
        $elena->tratamientos()->create([
            'medicamento_id' => $this->semilla(Medicamento::class, 'Paracetamol')->id,
            'medico_id' => $geriatra->id,
            'enfermedad_id' => $artrosis->id,
            'dosis' => '500 mg',
            'frecuencia' => 'si duele, hasta 3 por día',
            'periodicidad' => PeriodicidadToma::Diaria,
            'tomas_por_dia' => 1,
            'horas' => ['09:00'],
            'inicio' => $this->hoy->subYears(2),
            'activo' => true,
        ]);
        $this->serie($elena, 'Presión arterial', 10, 21, fn () => [(string) mt_rand(125, 140), (string) mt_rand(72, 82)]);
        $elena->turnos()->create([
            'medico_id' => $geriatra->id,
            'fecha_hora' => $this->instante(12, 11, 0),
            'motivo' => 'Control mensual',
            'estado' => EstadoTurno::Programado,
        ]);
    }

    private function contactos(User $ana): void
    {
        $ana->contactos()->create(['nombre' => 'OSDE — Autorizaciones (ejemplo)', 'email' => 'autorizaciones@osde.ejemplo.test', 'tipo' => TipoContacto::ObraSocial]);
        $ana->contactos()->create(['nombre' => 'Óptica Visión (ejemplo)', 'email' => 'ventas@optica.ejemplo.test', 'tipo' => TipoContacto::Optica]);
    }

    // ─── Ayudas ─────────────────────────────────────────────────────────────

    /**
     * Una serie de una variable compartida: `$cantidad` tomas cada `$cada`
     * días, la última ayer. `$valores` recibe cuántas tomas faltan hasta ayer
     * (0 = la última) y devuelve [valor] o [valor, secundario].
     *
     * @param  callable(int): list<string>  $valores
     */
    private function serie(Paciente $paciente, string $variable, int $cantidad, int $cada, callable $valores): void
    {
        $tipo = $this->semilla(TipoMedicion::class, $variable);

        for ($i = $cantidad - 1; $i >= 0; $i--) {
            $v = $valores($i);
            $paciente->mediciones()->create([
                'tipo_medicion_id' => $tipo->id,
                'fecha' => $this->instante(-1 - $i * $cada, 8, mt_rand(0, 50)),
                'valor' => $v[0],
                'valor_secundario' => $v[1] ?? null,
            ]);
        }
    }

    /** Una fecha de calendario, `$dias` días antes de hoy. */
    private function dia(int $dias): CarbonImmutable
    {
        return $this->hoy->subDays($dias);
    }

    /**
     * Un instante a la hora local de la familia, `$dias` días desde hoy
     * (negativo, en el pasado), guardado en UTC como todo `datetime`.
     */
    private function instante(int $dias, int $hora, int $minuto): CarbonImmutable
    {
        return $this->hoy->addDays($dias)->setTime($hora, $minuto)->utc();
    }

    /**
     * Un PDF de una página con esas líneas, cifrado en el disco y colgado de
     * su dueño, por el mismo camino que un archivo que llega por mail.
     *
     * @param  list<string>  $lineas
     */
    private function documento(Model&TieneArchivos $duenio, TipoAdjunto $tipo, ?string $titulo, array $lineas): void
    {
        $nombre = Str::slug($titulo ?? $lineas[0]).'.pdf';
        $datos = $this->archivos->guardarContenido($this->pdf($lineas), $nombre, $duenio->carpetaDeArchivos());

        $duenio->adjuntos()->create($datos + ['tipo' => $tipo, 'titulo' => $titulo]);
    }

    /**
     * La credencial: una tarjeta con los colores de la marca y los datos
     * inventados, como la foto que alguien le saca a la suya.
     */
    private function credencial(Cobertura $cobertura, string $titular): void
    {
        $tarjeta = imagecreatetruecolor(428, 270);
        $fondo = (int) imagecolorallocate($tarjeta, 14, 107, 107);
        $franja = (int) imagecolorallocate($tarjeta, 228, 87, 46);
        $blanco = (int) imagecolorallocate($tarjeta, 255, 255, 255);

        imagefilledrectangle($tarjeta, 0, 0, 427, 269, $fondo);
        imagefilledrectangle($tarjeta, 0, 196, 427, 214, $franja);
        imagestring($tarjeta, 5, 22, 20, $cobertura->entidad.' - '.$cobertura->plan, $blanco);
        imagestring($tarjeta, 3, 22, 44, 'CREDENCIAL DE EJEMPLO - DEMO', $blanco);
        imagestring($tarjeta, 5, 22, 110, $titular, $blanco);
        imagestring($tarjeta, 5, 22, 136, 'Afiliado: '.$cobertura->nro_afiliado, $blanco);
        imagestring($tarjeta, 2, 22, 232, 'Documento inventado para la demo de MiSalud', $blanco);

        // Dibujada chica y agrandada: la fuente de GD es de 9 px.
        $grande = imagescale($tarjeta, 856, 540, IMG_NEAREST_NEIGHBOUR);
        ob_start();
        imagepng($grande === false ? $tarjeta : $grande);
        $png = (string) ob_get_clean();

        $datos = $this->archivos->guardarContenido($png, 'credencial.png', $cobertura->carpetaDeArchivos());
        $cobertura->adjuntos()->create($datos + ['tipo' => TipoAdjunto::Credencial, 'descripcion' => 'Frente']);
    }

    /**
     * Un PDF mínimo escrito a mano: una página A4 con texto en Helvetica. No
     * hace falta una librería para esto, y pdf.js lo dibuja igual que a uno de
     * verdad. Helvetica de PDF habla WinAnsi, no UTF-8: por eso la conversión.
     *
     * @param  list<string>  $lineas
     */
    private function pdf(array $lineas): string
    {
        $texto = "BT\n/F1 13 Tf\n56 780 Td\n16 TL\n";
        foreach ($lineas as $linea) {
            $winAnsi = (string) mb_convert_encoding($linea, 'Windows-1252', 'UTF-8');
            $texto .= '('.addcslashes($winAnsi, '()\\').") Tj T*\n";
        }
        $texto .= 'ET';

        $objetos = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Length '.strlen($texto)." >>\nstream\n{$texto}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $posiciones = [];
        foreach ($objetos as $i => $objeto) {
            $posiciones[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$objeto}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objetos) + 1)."\n0000000000 65535 f \n";
        foreach ($posiciones as $posicion) {
            $pdf .= sprintf("%010d 00000 n \n", $posicion);
        }

        return $pdf.'trailer << /Size '.(count($objetos) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
