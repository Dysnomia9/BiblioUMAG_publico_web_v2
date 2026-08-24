<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogoLibroController;
use App\Http\Controllers\Api\CodigoAccesoController;
use App\Http\Controllers\Api\ConfiguracionInstitucionalController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EjemplarController;
use App\Http\Controllers\Api\EntradaController;
use App\Http\Controllers\Api\EquipoController;
use App\Http\Controllers\Api\EstadoLibroPersonalizadoController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\LdapAuthController;
use App\Http\Controllers\Api\LibroController;
use App\Http\Controllers\Api\PortalController;
use App\Http\Controllers\Api\PortalReservaLibroController;
use App\Http\Controllers\Api\PrestamoController;
use App\Http\Controllers\Api\RegistroAbsolutoController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\ReservaLibroController;
use App\Http\Controllers\Api\SalaController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\TipoMaterialController;
use App\Http\Controllers\Api\UbicacionController;
use App\Http\Controllers\Api\UsuarioAuthController;
use App\Http\Controllers\Api\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
Route::post('/auth/usuario/login', [UsuarioAuthController::class, 'login'])->middleware('throttle:6,1');

// Login unificado de LoginV2View.vue (staff o usuario, según a quién pertenezca
// el email/identidad que confirme el proveedor externo) — ver GoogleAuthController/
// LdapAuthController y su nota de "no configurado todavía" en .env.example.
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect']);
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);
Route::post('/auth/ldap/login', [LdapAuthController::class, 'login'])->middleware('throttle:6,1');

Route::middleware(['auth:sanctum', 'staff'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/dashboard/resumen', [DashboardController::class, 'resumen']);

    Route::get('/salas', [SalaController::class, 'index']);
    Route::post('/salas/scan-logia', [SalaController::class, 'scanLogia']);
    Route::post('/reservas', [SalaController::class, 'storeReserva']);
    Route::delete('/reservas/{reserva}', [SalaController::class, 'destroyReserva']);
    Route::patch('/reservas/{reserva}/devolver', [SalaController::class, 'devolverReserva']);
    Route::patch('/reservas/{reserva}/llegada', [SalaController::class, 'confirmarLlegada']);
    Route::patch('/reservas/{reserva}/liberar', [SalaController::class, 'liberarReserva']);

    Route::get('/staff', [StaffController::class, 'index']);

    Route::get('/usuarios', [UsuarioController::class, 'index']);
    Route::get('/usuarios/rut/{rut}', [UsuarioController::class, 'porRut']);

    Route::get('/entrada', [EntradaController::class, 'index']);
    Route::post('/entrada', [EntradaController::class, 'store']);
    Route::post('/entrada/externo', [EntradaController::class, 'storeExterno']);
    Route::post('/entrada/convenio', [EntradaController::class, 'storeConvenio']);
    Route::post('/entrada/visita', [EntradaController::class, 'storeVisita']);

    Route::get('/prestamos', [PrestamoController::class, 'index']);
    Route::post('/prestamos', [PrestamoController::class, 'store']);
    Route::patch('/prestamos/{prestamo}/devolver', [PrestamoController::class, 'devolver']);
    Route::patch('/prestamos/{prestamo}/multa/pagar', [PrestamoController::class, 'pagarMulta']);

    Route::get('/libros/historial', [LibroController::class, 'historial']);
    Route::get('/libros/{libro}', [LibroController::class, 'show'])->whereNumber('libro');
    Route::get('/libros', [LibroController::class, 'index']);

    // Rutas literales de /ejemplares/... van ANTES del wildcard /ejemplares/{codigo} —
    // si no, el wildcard las captura a todas como si fueran un código de barras.
    Route::get('/ejemplares/historial-estado', [EjemplarController::class, 'historialEstado']);
    Route::get('/ejemplares/siguiente-codigo-barras', [EjemplarController::class, 'siguienteCodigoBarras'])->middleware('admin');
    Route::get('/ejemplares/{codigo}', [EjemplarController::class, 'buscarPorCodigo']);
    Route::patch('/ejemplares/{ejemplar}/estado', [EjemplarController::class, 'cambiarEstado']);

    Route::get('/equipos', [EquipoController::class, 'index']);
    Route::get('/equipos/{codigo}', [EquipoController::class, 'buscarPorCodigo']);

    Route::get('/estados-libro-personalizados', [EstadoLibroPersonalizadoController::class, 'index']);

    Route::get('/autores', [CatalogoLibroController::class, 'autores']);
    Route::get('/categorias', [CatalogoLibroController::class, 'categorias']);
    Route::get('/carreras', [CatalogoLibroController::class, 'carreras']);
    Route::get('/ubicaciones', [UbicacionController::class, 'index']);
    Route::get('/tipos-material', [TipoMaterialController::class, 'index']);

    Route::get('/configuracion', [ConfiguracionInstitucionalController::class, 'show']);

    Route::middleware('admin')->group(function () {
        Route::post('/libros', [LibroController::class, 'store']);
        Route::patch('/libros/{libro}', [LibroController::class, 'update']);
        Route::post('/ejemplares', [EjemplarController::class, 'store']);
        Route::post('/ejemplares/cambio-masivo/preview', [EjemplarController::class, 'cambioMasivoPreview']);
        Route::post('/ejemplares/cambio-masivo/ejecutar', [EjemplarController::class, 'cambioMasivoEjecutar']);
        Route::post('/equipos', [EquipoController::class, 'store']);
        Route::patch('/equipos/{equipo}/activo', [EquipoController::class, 'cambiarActivo']);
        Route::post('/estados-libro-personalizados', [EstadoLibroPersonalizadoController::class, 'store']);
        Route::patch('/estados-libro-personalizados/{estadoLibroPersonalizado}/activo', [EstadoLibroPersonalizadoController::class, 'cambiarActivo']);
        Route::put('/configuracion', [ConfiguracionInstitucionalController::class, 'actualizar']);
        Route::post('/ubicaciones', [UbicacionController::class, 'store']);
        Route::post('/tipos-material', [TipoMaterialController::class, 'store']);
        Route::get('/registro-absoluto', [RegistroAbsolutoController::class, 'index']);
    });

    Route::get('/reservas-libro', [ReservaLibroController::class, 'index']);
    Route::post('/reservas-libro', [ReservaLibroController::class, 'store']);
    Route::patch('/reservas-libro/{reservaLibro}/cancelar', [ReservaLibroController::class, 'cancelar']);

    Route::get('/reportes/opciones', [ReporteController::class, 'opciones']);
    Route::get('/reportes/resumen', [ReporteController::class, 'resumen']);
    Route::get('/reportes/multas-pendientes', [ReporteController::class, 'multasPendientes']);

    Route::get('/codigo-acceso', [CodigoAccesoController::class, 'show']);
    Route::post('/codigo-acceso/regenerar', [CodigoAccesoController::class, 'regenerar']);
});

Route::middleware(['auth:sanctum', 'usuario'])->group(function () {
    Route::get('/auth/usuario/me', [UsuarioAuthController::class, 'me']);
    Route::post('/auth/usuario/logout', [UsuarioAuthController::class, 'logout']);

    Route::get('/mi/estado', [PortalController::class, 'estado']);
    Route::get('/mi/multas', [PortalController::class, 'misMultas']);
    Route::get('/mi/configuracion', [ConfiguracionInstitucionalController::class, 'show']);
    Route::post('/mi/entrada', [PortalController::class, 'registrarEntrada']);
    Route::get('/mi/catalogo', [PortalController::class, 'catalogo']);

    Route::get('/mi/salas', [PortalController::class, 'salas']);
    Route::post('/mi/reservas', [PortalController::class, 'reservarSala']);
    Route::delete('/mi/reservas/{reserva}', [PortalController::class, 'cancelarReservaSala']);

    Route::get('/mi/reservas-libro', [PortalReservaLibroController::class, 'index']);
    Route::post('/mi/reservas-libro', [PortalReservaLibroController::class, 'store']);
    Route::patch('/mi/reservas-libro/{reservaLibro}/cancelar', [PortalReservaLibroController::class, 'cancelar']);
});
