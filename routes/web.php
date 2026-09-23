<?php

use App\Http\Controllers\AdjuntoController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\CoberturaController;
use App\Http\Controllers\DashboardController;
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

    Route::get('pacientes', [PacienteController::class, 'index'])->name('pacientes.index');
    Route::post('pacientes', [PacienteController::class, 'store'])->name('pacientes.store');
    Route::put('pacientes/{paciente}', [PacienteController::class, 'update'])->name('pacientes.update');
    Route::delete('pacientes/{paciente}', [PacienteController::class, 'destroy'])->name('pacientes.destroy');
});

require __DIR__.'/settings.php';
