<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\PacienteActivoController;
use App\Http\Controllers\PacienteController;
use App\Http\Controllers\Settings\TamanioTextoController;
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
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::put('paciente-activo/{paciente}', [PacienteActivoController::class, 'update'])
        ->name('paciente-activo.update');

    Route::get('pacientes', [PacienteController::class, 'index'])->name('pacientes.index');
    Route::post('pacientes', [PacienteController::class, 'store'])->name('pacientes.store');
    Route::put('pacientes/{paciente}', [PacienteController::class, 'update'])->name('pacientes.update');
    Route::delete('pacientes/{paciente}', [PacienteController::class, 'destroy'])->name('pacientes.destroy');
});

require __DIR__.'/settings.php';
