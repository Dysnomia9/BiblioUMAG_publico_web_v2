<?php

use Illuminate\Support\Facades\Route;

// API-only: no hay vistas Blade. El healthcheck real vive en /up
// (definido por bootstrap/app.php -> withRouting(health: '/up')).
Route::get('/', fn () => response()->json(['message' => 'Biblioteca UMAG API']));
