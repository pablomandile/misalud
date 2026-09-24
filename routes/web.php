<?php

use App\Http\Controllers\AdjuntoController;
use App\Http\Controllers\AlergiaController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\CentroController;
use App\Http\Controllers\CoberturaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnfermedadController;
use App\Http\Controllers\EstudioController;
use App\Http\Controllers\MedicamentoController;
use App\Http\Controllers\MedicionController;
use App\Http\Controllers\MedicoController;
use App\Http\Controllers\OrdenEstudioController;
use App\Http\Controllers\PacienteActivoController;
use App\Http\Controllers\PacienteController;
use App\Http\Controllers\PrescripcionOcularController;
use App\Http\Controllers\RegistroEnfermedadController;
use App\Http\Controllers\ResultadoEstudioController;
use App\Http\Controllers\Settings\TamanioTextoController;
use App\Http\Controllers\TipoMedicionController;
use App\Http\Controllers\TratamientoController;
use App\Http\Controllers\VacunaController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Respaldo que sirve el service worker cuando no hay conexión. Es una vista
// suelta, no Inertia: tiene que poder mostrarse sin los assets compilados.
Route::view('offline', 'offline')->name('offline');

/*
 * El tamaño de letra se cambia CON O SIN sesión: quien no llega a leer la
 * pantalla de ingreso es justamente el que necesita agrandarla, y ahí todavía
 * no hay cuenta. Sin sesión queda solo en la cookie.
 */
Route::put('tamanio-texto', [TamanioTextoController::class, 'update'])
    ->name('tamanio-texto.update');

/*
 * Ingreso con Google. Van en inglés y bajo /auth como el resto de las rutas de
 * autenticación, que las publica Fortify; el español es para el dominio.
 *
 * `guest`: alguien con la sesión abierta que llega acá ya está adentro, y
 * rehacer el flujo solo puede terminar cambiándole la cuenta sin querer.
 */
Route::middleware('guest')->group(function () {
    Route::get('auth/google/redirect', [GoogleController::class, 'redirigir'])
        ->name('google.redirect');

    Route::get('auth/google/callback', [GoogleController::class, 'volver'])
        ->name('google.callback');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::put('paciente-activo/{paciente}', [PacienteActivoController::class, 'update'])
        ->name('paciente-activo.update');

    /*
     * Los archivos SIEMPRE por controlador: viven cifrados en el disco
     * privado y no hay ninguna URL que los sirva sin pasar por la Policy.
     */
    Route::post('pacientes/{paciente}/adjuntos', [AdjuntoController::class, 'store'])
        ->name('pacientes.adjuntos.store');
    Route::get('adjuntos/{adjunto}', [AdjuntoController::class, 'show'])->name('adjuntos.show');
    Route::delete('adjuntos/{adjunto}', [AdjuntoController::class, 'destroy'])->name('adjuntos.destroy');

    /*
     * Ruta APARTE para la credencial: es la única que el service worker
     * cachea para verse sin señal (ver AdjuntoController::showCredencial).
     * Separarla de /adjuntos/{adjunto} es lo que le permite al service
     * worker reconocerla por el pathname, sin tener que consultarle nada al
     * servidor para saber si algo es cacheable.
     */
    Route::get('credenciales/{adjunto}', [AdjuntoController::class, 'showCredencial'])
        ->name('credenciales.show');

    /*
     * Coberturas: no tienen index propio, viajan en el prop de la ficha del
     * paciente. store va bajo /pacientes/{paciente} porque hace falta saber
     * a quién pertenece; update y destroy van sobre su propio id, como los
     * adjuntos -el registro ya sabe de qué paciente es-.
     */
    Route::post('pacientes/{paciente}/coberturas', [CoberturaController::class, 'store'])
        ->name('pacientes.coberturas.store');
    Route::put('coberturas/{cobertura}', [CoberturaController::class, 'update'])
        ->name('coberturas.update');
    Route::delete('coberturas/{cobertura}', [CoberturaController::class, 'destroy'])
        ->name('coberturas.destroy');
    Route::post('coberturas/{cobertura}/adjuntos', [AdjuntoController::class, 'storeParaCobertura'])
        ->name('coberturas.adjuntos.store');

    /*
     * Catálogos del USUARIO -no de un paciente-: médicos es el primero y la
     * plantilla de los otros tres. `duplicar` es la única salida frente a una
     * semilla compartida, que no se puede editar (regla 5 de CLAUDE.md).
     */
    Route::get('medicos', [MedicoController::class, 'index'])->name('medicos.index');
    Route::post('medicos', [MedicoController::class, 'store'])->name('medicos.store');
    Route::put('medicos/{medico}', [MedicoController::class, 'update'])->name('medicos.update');
    Route::delete('medicos/{medico}', [MedicoController::class, 'destroy'])->name('medicos.destroy');
    Route::post('medicos/{medico}/duplicar', [MedicoController::class, 'duplicar'])
        ->name('medicos.duplicar');

    Route::get('centros', [CentroController::class, 'index'])->name('centros.index');
    Route::post('centros', [CentroController::class, 'store'])->name('centros.store');
    Route::put('centros/{centro}', [CentroController::class, 'update'])->name('centros.update');
    Route::delete('centros/{centro}', [CentroController::class, 'destroy'])->name('centros.destroy');
    Route::post('centros/{centro}/duplicar', [CentroController::class, 'duplicar'])
        ->name('centros.duplicar');

    Route::get('medicamentos', [MedicamentoController::class, 'index'])->name('medicamentos.index');
    Route::post('medicamentos', [MedicamentoController::class, 'store'])->name('medicamentos.store');
    Route::put('medicamentos/{medicamento}', [MedicamentoController::class, 'update'])
        ->name('medicamentos.update');
    Route::delete('medicamentos/{medicamento}', [MedicamentoController::class, 'destroy'])
        ->name('medicamentos.destroy');
    Route::post('medicamentos/{medicamento}/duplicar', [MedicamentoController::class, 'duplicar'])
        ->name('medicamentos.duplicar');
    // El prospecto: mismo patrón que la credencial de una cobertura.
    Route::post('medicamentos/{medicamento}/adjuntos', [AdjuntoController::class, 'storeParaMedicamento'])
        ->name('medicamentos.adjuntos.store');

    Route::get('vacunas', [VacunaController::class, 'index'])->name('vacunas.index');
    Route::post('vacunas', [VacunaController::class, 'store'])->name('vacunas.store');
    Route::put('vacunas/{vacuna}', [VacunaController::class, 'update'])->name('vacunas.update');
    Route::delete('vacunas/{vacuna}', [VacunaController::class, 'destroy'])->name('vacunas.destroy');
    Route::post('vacunas/{vacuna}/duplicar', [VacunaController::class, 'duplicar'])
        ->name('vacunas.duplicar');

    Route::get('tipos-medicion', [TipoMedicionController::class, 'index'])->name('tipos-medicion.index');
    Route::post('tipos-medicion', [TipoMedicionController::class, 'store'])->name('tipos-medicion.store');
    Route::put('tipos-medicion/{tipo_medicion}', [TipoMedicionController::class, 'update'])
        ->name('tipos-medicion.update');
    Route::delete('tipos-medicion/{tipo_medicion}', [TipoMedicionController::class, 'destroy'])
        ->name('tipos-medicion.destroy');
    Route::post('tipos-medicion/{tipo_medicion}/duplicar', [TipoMedicionController::class, 'duplicar'])
        ->name('tipos-medicion.duplicar');

    /*
     * Mediciones: van por PACIENTE y no por "paciente activo". Una medición
     * pertenece a una persona concreta, y confundirse de ficha acá es
     * cargarle el peso de un familiar a otro.
     */
    Route::get('pacientes/{paciente}/mediciones', [MedicionController::class, 'index'])
        ->name('pacientes.mediciones.index');
    Route::post('pacientes/{paciente}/mediciones', [MedicionController::class, 'store'])
        ->name('pacientes.mediciones.store');
    Route::put('mediciones/{medicion}', [MedicionController::class, 'update'])->name('mediciones.update');
    Route::delete('mediciones/{medicion}', [MedicionController::class, 'destroy'])->name('mediciones.destroy');

    /*
     * Enfermedades, su bitácora y las alergias: una sola pantalla por
     * paciente. Ni la bitácora ni las alergias tienen `index` propio —viajan
     * como prop—, el mismo patrón que las coberturas.
     */
    Route::get('pacientes/{paciente}/enfermedades', [EnfermedadController::class, 'index'])
        ->name('pacientes.enfermedades.index');
    Route::post('pacientes/{paciente}/enfermedades', [EnfermedadController::class, 'store'])
        ->name('pacientes.enfermedades.store');
    Route::put('enfermedades/{enfermedad}', [EnfermedadController::class, 'update'])
        ->name('enfermedades.update');
    Route::delete('enfermedades/{enfermedad}', [EnfermedadController::class, 'destroy'])
        ->name('enfermedades.destroy');

    Route::post('enfermedades/{enfermedad}/registros', [RegistroEnfermedadController::class, 'store'])
        ->name('enfermedades.registros.store');
    Route::delete('registros-enfermedad/{registro}', [RegistroEnfermedadController::class, 'destroy'])
        ->name('registros-enfermedad.destroy');

    Route::post('pacientes/{paciente}/alergias', [AlergiaController::class, 'store'])
        ->name('pacientes.alergias.store');
    Route::put('alergias/{alergia}', [AlergiaController::class, 'update'])->name('alergias.update');
    Route::delete('alergias/{alergia}', [AlergiaController::class, 'destroy'])->name('alergias.destroy');

    /*
     * Órdenes de estudio: el papel que da el médico ANTES. La pantalla
     * existe para una sola pregunta -qué falta hacerse-, y el archivo de la
     * orden cuelga de ella como cualquier otro adjunto.
     */
    Route::get('pacientes/{paciente}/ordenes', [OrdenEstudioController::class, 'index'])
        ->name('pacientes.ordenes.index');
    Route::post('pacientes/{paciente}/ordenes', [OrdenEstudioController::class, 'store'])
        ->name('pacientes.ordenes.store');
    Route::put('ordenes/{orden}', [OrdenEstudioController::class, 'update'])->name('ordenes.update');
    Route::delete('ordenes/{orden}', [OrdenEstudioController::class, 'destroy'])->name('ordenes.destroy');
    Route::post('ordenes/{orden}/adjuntos', [AdjuntoController::class, 'storeParaOrden'])
        ->name('ordenes.adjuntos.store');

    /*
     * Estudios: lo que se hizo, después de la orden. Los resultados no
     * tienen index propio -viajan anidados en la ficha del estudio-, mismo
     * patrón que la bitácora de una enfermedad.
     */
    Route::get('pacientes/{paciente}/estudios', [EstudioController::class, 'index'])
        ->name('pacientes.estudios.index');
    Route::post('pacientes/{paciente}/estudios', [EstudioController::class, 'store'])
        ->name('pacientes.estudios.store');
    Route::put('estudios/{estudio}', [EstudioController::class, 'update'])->name('estudios.update');
    Route::delete('estudios/{estudio}', [EstudioController::class, 'destroy'])->name('estudios.destroy');
    Route::post('estudios/{estudio}/adjuntos', [AdjuntoController::class, 'storeParaEstudio'])
        ->name('estudios.adjuntos.store');

    Route::post('estudios/{estudio}/resultados', [ResultadoEstudioController::class, 'store'])
        ->name('estudios.resultados.store');
    Route::put('resultados/{resultado}', [ResultadoEstudioController::class, 'update'])
        ->name('resultados.update');
    Route::delete('resultados/{resultado}', [ResultadoEstudioController::class, 'destroy'])
        ->name('resultados.destroy');

    /*
     * Salud ocular: las recetas de anteojos. Las graduaciones NO tienen
     * rutas propias -viajan adentro de su receta y se guardan con ella, las
     * dos juntas-, que es lo que sostiene el invariante de "una receta tiene
     * dos ojos".
     *
     * `salud-ocular` y no `recetas`: en este proyecto "receta" ya significa
     * la de medicamentos que llega por mail (Etapa 12).
     */
    Route::get('pacientes/{paciente}/salud-ocular', [PrescripcionOcularController::class, 'index'])
        ->name('pacientes.salud-ocular.index');
    Route::post('pacientes/{paciente}/salud-ocular', [PrescripcionOcularController::class, 'store'])
        ->name('pacientes.salud-ocular.store');
    Route::put('salud-ocular/{prescripcion}', [PrescripcionOcularController::class, 'update'])
        ->name('salud-ocular.update');
    Route::delete('salud-ocular/{prescripcion}', [PrescripcionOcularController::class, 'destroy'])
        ->name('salud-ocular.destroy');
    Route::post('salud-ocular/{prescripcion}/adjuntos', [AdjuntoController::class, 'storeParaPrescripcionOcular'])
        ->name('salud-ocular.adjuntos.store');

    Route::get('pacientes/{paciente}/tratamientos', [TratamientoController::class, 'index'])
        ->name('pacientes.tratamientos.index');
    Route::post('pacientes/{paciente}/tratamientos', [TratamientoController::class, 'store'])
        ->name('pacientes.tratamientos.store');
    Route::put('tratamientos/{tratamiento}', [TratamientoController::class, 'update'])
        ->name('tratamientos.update');
    Route::delete('tratamientos/{tratamiento}', [TratamientoController::class, 'destroy'])
        ->name('tratamientos.destroy');

    Route::get('pacientes', [PacienteController::class, 'index'])->name('pacientes.index');
    Route::post('pacientes', [PacienteController::class, 'store'])->name('pacientes.store');
    Route::put('pacientes/{paciente}', [PacienteController::class, 'update'])->name('pacientes.update');
    Route::delete('pacientes/{paciente}', [PacienteController::class, 'destroy'])->name('pacientes.destroy');
});

require __DIR__.'/settings.php';
