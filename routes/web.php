<?php

use App\Http\Controllers\PacienteActivoController;
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

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::put('paciente-activo/{paciente}', [PacienteActivoController::class, 'update'])
        ->name('paciente-activo.update');
});

require __DIR__.'/settings.php';
