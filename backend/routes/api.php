<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BitacoraController;
use App\Http\Controllers\Api\CatalogoLibroController;
use App\Http\Controllers\Api\CategoriaRecursoController;
use App\Http\Controllers\Api\CodigoAccesoController;
use App\Http\Controllers\Api\ConfiguracionInstitucionalController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EjemplarController;
use App\Http\Controllers\Api\EntradaController;
use App\Http\Controllers\Api\EquipoController;
use App\Http\Controllers\Api\DiaSinAtencionController;
use App\Http\Controllers\Api\EstadoLibroPersonalizadoController;
use App\Http\Controllers\Api\FasciculoController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\IngresoController;
use App\Http\Controllers\Api\LdapAuthController;
use App\Http\Controllers\Api\LibroController;
use App\Http\Controllers\Api\MultaController;
use App\Http\Controllers\Api\PersonalController;
use App\Http\Controllers\Api\PortalController;
use App\Http\Controllers\Api\PortalReservaLibroController;
use App\Http\Controllers\Api\PrestamoController;
use App\Http\Controllers\Api\ReglaPrestamoController;
use App\Http\Controllers\Api\RegistroAbsolutoController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\ReservaLibroController;
use App\Http\Controllers\Api\RolController;
use App\Http\Controllers\Api\SalaController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\TipoMaterialController;
use App\Http\Controllers\Api\UbicacionController;
use App\Http\Controllers\Api\UsuarioAuthController;
use App\Http\Controllers\Api\UsuarioController;
use App\Http\Controllers\Api\VigenciaController;
use Illuminate\Support\Facades\Route;

// El login único (LoginView.vue): correo o RUT y contraseña, para el personal y los
// usuarios; responde a qué sesión corresponde o, si la persona tiene las dos cuentas,
// pide elegir (LoginUnificadoService).
Route::post('/auth/ingresar', [IngresoController::class, 'ingresar'])->middleware('throttle:login');
// Los ingresos de antes, solo del personal (correo) o solo del portal (RUT): siguen
// respondiendo para quien los use directamente.
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/auth/usuario/login', [UsuarioAuthController::class, 'login'])->middleware('throttle:login');

// Google y LDAP en el mismo login (ver la nota de "no configurado todavía" en .env.example).
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect']);
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);
Route::post('/auth/google/canjear', [GoogleAuthController::class, 'canjear'])->middleware('throttle:login');
Route::post('/auth/ldap/login', [LdapAuthController::class, 'login'])->middleware('throttle:login');

// Panel del personal. Cada grupo exige un permiso del catálogo App\Support\Permisos;
// qué permisos tiene cada rol se administra en la pantalla "Personal y roles".
Route::middleware(['auth:sanctum', 'staff'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::patch('/auth/password', [AuthController::class, 'cambiarPassword'])->middleware('throttle:6,1');

    Route::get('/dashboard/resumen', [DashboardController::class, 'resumen']);

    // Consultas de solo lectura que comparten varios módulos (buscar un usuario por
    // RUT, un ejemplar o equipo por código de barras, los catálogos para selectores).
    Route::get('/staff', [StaffController::class, 'index']);
    Route::get('/usuarios/rut/{rut}', [UsuarioController::class, 'porRut']);
    Route::get('/libros/{libro}', [LibroController::class, 'show'])->whereNumber('libro');
    Route::get('/libros/plantillas', [LibroController::class, 'plantillas']);
    Route::get('/libros', [LibroController::class, 'index']);
    Route::get('/equipos', [EquipoController::class, 'index']);
    Route::get('/equipos/{codigo}', [EquipoController::class, 'buscarPorCodigo']);
    Route::get('/estados-libro-personalizados', [EstadoLibroPersonalizadoController::class, 'index']);
    Route::get('/autores', [CatalogoLibroController::class, 'autores']);
    Route::get('/categorias', [CatalogoLibroController::class, 'categorias']);
    Route::get('/carreras', [CatalogoLibroController::class, 'carreras']);
    Route::get('/ubicaciones', [UbicacionController::class, 'index']);
    Route::get('/tipos-material', [TipoMaterialController::class, 'index']);
    Route::get('/categorias-recurso', [CategoriaRecursoController::class, 'index']);
    Route::get('/configuracion', [ConfiguracionInstitucionalController::class, 'show']);
    Route::get('/configuracion/horario', [ConfiguracionInstitucionalController::class, 'horario']);
    Route::get('/dias-sin-atencion', [DiaSinAtencionController::class, 'index']);
    Route::get('/libros/{libro}/fasciculos', [FasciculoController::class, 'index'])->whereNumber('libro');

    // Rutas literales de /ejemplares/... van ANTES del wildcard /ejemplares/{codigo} —
    // si no, el wildcard las captura a todas como si fueran un código de barras.
    Route::get('/ejemplares/dadas-de-baja', [EjemplarController::class, 'dadasDeBaja'])->middleware('permiso:libros.estado');
    Route::get('/ejemplares/historial-estado', [EjemplarController::class, 'historialEstado'])->middleware('permiso:historiales.ver');
    Route::get('/ejemplares/siguiente-codigo-barras', [EjemplarController::class, 'siguienteCodigoBarras'])->middleware('permiso:libros.catalogar');
    Route::post('/ejemplares/etiquetas-lomo', [EjemplarController::class, 'etiquetasLomo'])->middleware('permiso:libros.catalogar');
    Route::get('/ejemplares/{codigo}', [EjemplarController::class, 'buscarPorCodigo']);

    Route::middleware('permiso:entradas.registrar')->group(function () {
        Route::get('/entrada', [EntradaController::class, 'index']);
        Route::post('/entrada', [EntradaController::class, 'store']);
        Route::post('/entrada/externo', [EntradaController::class, 'storeExterno']);
        Route::post('/entrada/convenio', [EntradaController::class, 'storeConvenio']);
        Route::post('/entrada/visita', [EntradaController::class, 'storeVisita']);
    });

    Route::get('/prestamos', [PrestamoController::class, 'index'])->middleware('permiso:prestamos.gestionar,listados.ver');
    Route::middleware('permiso:prestamos.gestionar')->group(function () {
        Route::get('/prestamos/condiciones', [PrestamoController::class, 'condiciones']);
        Route::post('/prestamos', [PrestamoController::class, 'store']);
        Route::patch('/prestamos/{prestamo}/devolver', [PrestamoController::class, 'devolver']);
        Route::get('/reservas-libro', [ReservaLibroController::class, 'index']);
        Route::post('/reservas-libro', [ReservaLibroController::class, 'store']);
        Route::patch('/reservas-libro/{reservaLibro}/cancelar', [ReservaLibroController::class, 'cancelar']);
    });

    Route::middleware('permiso:salas.gestionar')->group(function () {
        Route::get('/salas', [SalaController::class, 'index']);
        Route::post('/salas/scan-logia', [SalaController::class, 'scanLogia']);
        Route::post('/reservas', [SalaController::class, 'storeReserva']);
        Route::patch('/reservas/{reserva}/llegada', [SalaController::class, 'confirmarLlegada']);
        Route::patch('/reservas/{reserva}/liberar', [SalaController::class, 'liberarReserva']);
    });
    // Listado de reservas: lo consulta quien ve los listados; cambiar sala, día u
    // horario es del permiso de editar reservas, y cancelar, cerrar la devolución o
    // corregir integrantes lo hace tanto el mesón de salas como ese permiso.
    Route::get('/reservas', [SalaController::class, 'listado'])->middleware('permiso:salas.gestionar,listados.ver');
    Route::put('/reservas/{reserva}', [SalaController::class, 'editarReserva'])->middleware('permiso:reservas.editar');
    Route::middleware('permiso:salas.gestionar,reservas.editar')->group(function () {
        Route::delete('/reservas/{reserva}', [SalaController::class, 'destroyReserva']);
        Route::patch('/reservas/{reserva}/devolver', [SalaController::class, 'devolverReserva']);
        Route::patch('/reservas/{reserva}/liberar-resto', [SalaController::class, 'liberarRestoDelBloque']);
        Route::post('/reservas/{reserva}/multa-atraso', [SalaController::class, 'multarAtraso']);
        Route::put('/reservas/{reserva}/integrantes', [SalaController::class, 'actualizarIntegrantes']);
    });

    Route::get('/usuarios', [UsuarioController::class, 'index'])->middleware('permiso:usuarios.ver,usuarios.gestionar');
    // Habilitar a quien está inactivo lo hace todo el personal; desactivar, solo el Administrador/a.
    Route::patch('/usuarios/{usuario}/habilitar', [UsuarioController::class, 'habilitar'])->whereNumber('usuario')->middleware('permiso:usuarios.habilitar');
    Route::patch('/usuarios/{usuario}/desactivar', [UsuarioController::class, 'desactivar'])->whereNumber('usuario')->middleware('permiso:usuarios.desactivar');
    Route::middleware('permiso:usuarios.gestionar')->group(function () {
        Route::get('/usuarios/opciones', [UsuarioController::class, 'opciones']);
        Route::post('/usuarios', [UsuarioController::class, 'store']);
        Route::put('/usuarios/{usuario}', [UsuarioController::class, 'update'])->whereNumber('usuario');
        Route::post('/usuarios/importar/revisar', [UsuarioController::class, 'revisarImportacion']);
        Route::post('/usuarios/importar', [UsuarioController::class, 'importar']);
    });

    // Cuánto dura la cuenta de cada tipo al renovarla: la ven quienes renuevan y cargan usuarios; solo el Administrador/a la cambia.
    Route::get('/vigencia', [VigenciaController::class, 'index'])->middleware('permiso:usuarios.habilitar,usuarios.gestionar,usuarios.ver,vigencia.gestionar');
    Route::put('/vigencia', [VigenciaController::class, 'update'])->middleware('permiso:vigencia.gestionar');

    Route::get('/multas', [MultaController::class, 'index'])->middleware('permiso:multas.ver');
    Route::get('/multas/resumen', [MultaController::class, 'resumen'])->middleware('permiso:multas.ver');
    Route::post('/multas', [MultaController::class, 'store'])->middleware('permiso:multas.crear');
    Route::patch('/multas/{multa}/pagar', [MultaController::class, 'pagar'])->middleware('permiso:multas.cobrar');
    Route::patch('/multas/{multa}/anular', [MultaController::class, 'anular'])->middleware('permiso:multas.anular');

    Route::middleware('permiso:libros.catalogar')->group(function () {
        Route::post('/libros', [LibroController::class, 'store']);
        Route::patch('/libros/{libro}', [LibroController::class, 'update']);
        Route::post('/ejemplares', [EjemplarController::class, 'store']);
        Route::patch('/ejemplares/{ejemplar}', [EjemplarController::class, 'update'])->whereNumber('ejemplar');
        Route::post('/libros/{libro}/fasciculos', [FasciculoController::class, 'store'])->whereNumber('libro');
        Route::patch('/fasciculos/{fasciculo}', [FasciculoController::class, 'update']);
        Route::patch('/fasciculos/{fasciculo}/recibir', [FasciculoController::class, 'recibir']);
    });
    Route::patch('/ejemplares/{ejemplar}/estado', [EjemplarController::class, 'cambiarEstado'])->middleware('permiso:libros.estado');
    Route::post('/libros/{libro}/baja', [LibroController::class, 'darDeBaja'])->whereNumber('libro')->middleware('permiso:libros.estado');
    Route::middleware('permiso:libros.cambio_masivo')->group(function () {
        Route::post('/ejemplares/cambio-masivo/preview', [EjemplarController::class, 'cambioMasivoPreview']);
        Route::post('/ejemplares/cambio-masivo/ejecutar', [EjemplarController::class, 'cambioMasivoEjecutar']);
    });
    Route::middleware('permiso:equipos.gestionar')->group(function () {
        Route::post('/equipos', [EquipoController::class, 'store']);
        Route::post('/equipos/lote', [EquipoController::class, 'storeLote']);
        Route::patch('/equipos/{equipo}/activo', [EquipoController::class, 'cambiarActivo']);
    });

    Route::get('/libros/historial', [LibroController::class, 'historial'])->middleware('permiso:historiales.ver');
    Route::get('/libros/{libro}/historial', [LibroController::class, 'historialDeLibro'])->whereNumber('libro')->middleware('permiso:historiales.ver');

    Route::middleware('permiso:reportes.ver')->group(function () {
        Route::get('/reportes/opciones', [ReporteController::class, 'opciones']);
        Route::get('/reportes/resumen', [ReporteController::class, 'resumen']);
    });
    Route::middleware('permiso:registro_absoluto.ver')->group(function () {
        Route::get('/registro-absoluto', [RegistroAbsolutoController::class, 'index']);
        Route::get('/bitacora', [BitacoraController::class, 'index']);
        Route::get('/bitacora/opciones', [BitacoraController::class, 'opciones']);
    });

    Route::get('/codigo-acceso', [CodigoAccesoController::class, 'show'])->middleware('permiso:codigo_qr.ver,codigo_qr.regenerar');
    Route::post('/codigo-acceso/regenerar', [CodigoAccesoController::class, 'regenerar'])->middleware('permiso:codigo_qr.regenerar');

    Route::put('/configuracion', [ConfiguracionInstitucionalController::class, 'actualizar'])->middleware('permiso:configuracion.editar');
    Route::middleware('permiso:catalogos.gestionar')->group(function () {
        Route::post('/estados-libro-personalizados', [EstadoLibroPersonalizadoController::class, 'store']);
        Route::patch('/estados-libro-personalizados/{estadoLibroPersonalizado}/activo', [EstadoLibroPersonalizadoController::class, 'cambiarActivo']);
        Route::post('/ubicaciones', [UbicacionController::class, 'store']);
        Route::post('/tipos-material', [TipoMaterialController::class, 'store']);
        Route::patch('/tipos-material/{tipoMaterial}', [TipoMaterialController::class, 'update']);
        Route::post('/categorias-recurso', [CategoriaRecursoController::class, 'store']);
        Route::patch('/categorias-recurso/{categoriaRecurso}', [CategoriaRecursoController::class, 'update']);
        Route::get('/reglas-prestamo', [ReglaPrestamoController::class, 'index']);
        Route::post('/reglas-prestamo', [ReglaPrestamoController::class, 'store']);
        Route::put('/reglas-prestamo/{reglaPrestamo}', [ReglaPrestamoController::class, 'update']);
        Route::delete('/reglas-prestamo/{reglaPrestamo}', [ReglaPrestamoController::class, 'destroy']);
        Route::post('/dias-sin-atencion', [DiaSinAtencionController::class, 'store']);
        Route::patch('/dias-sin-atencion/{diaSinAtencion}', [DiaSinAtencionController::class, 'update']);
        Route::delete('/dias-sin-atencion/{diaSinAtencion}', [DiaSinAtencionController::class, 'destroy']);
    });

    Route::middleware('permiso:personal.gestionar')->group(function () {
        Route::get('/personal', [PersonalController::class, 'index']);
        Route::post('/personal', [PersonalController::class, 'store']);
        Route::put('/personal/{staff}', [PersonalController::class, 'update']);
        Route::patch('/personal/{staff}/activo', [PersonalController::class, 'cambiarActivo']);
        Route::delete('/personal/{staff}', [PersonalController::class, 'eliminar']);
        Route::patch('/personal/{staff}/password', [PersonalController::class, 'cambiarPassword']);
        Route::get('/roles', [RolController::class, 'index']);
        Route::post('/roles', [RolController::class, 'store']);
        Route::put('/roles/{rol}', [RolController::class, 'update']);
        Route::delete('/roles/{rol}', [RolController::class, 'destroy']);
    });
});

Route::middleware(['auth:sanctum', 'usuario'])->group(function () {
    Route::get('/auth/usuario/me', [UsuarioAuthController::class, 'me']);
    Route::post('/auth/usuario/logout', [UsuarioAuthController::class, 'logout']);

    Route::get('/mi/estado', [PortalController::class, 'estado']);
    Route::get('/mi/actividad', [PortalController::class, 'actividad']);
    Route::get('/mi/ingresos', [PortalController::class, 'ingresos']);
    Route::get('/mi/multas', [PortalController::class, 'misMultas']);
    Route::get('/mi/configuracion', [ConfiguracionInstitucionalController::class, 'show']);
    Route::post('/mi/entrada', [PortalController::class, 'registrarEntrada']);
    Route::get('/mi/catalogo', [PortalController::class, 'catalogo']);
    Route::get('/mi/catalogo/areas', [PortalController::class, 'areasCatalogo']);

    Route::get('/mi/salas', [PortalController::class, 'salas']);
    // Con tope propio: cada intento dice si los RUT ingresados están registrados.
    Route::post('/mi/reservas', [PortalController::class, 'reservarSala'])->middleware('throttle:20,1');
    Route::delete('/mi/reservas/{reserva}', [PortalController::class, 'cancelarReservaSala']);
    Route::patch('/mi/reservas/{reserva}/salir', [PortalController::class, 'salirDeReservaSala']);

    Route::get('/mi/reservas-libro', [PortalReservaLibroController::class, 'index']);
    Route::get('/mi/reservas-libro/condiciones', [PortalReservaLibroController::class, 'condiciones']);
    Route::post('/mi/reservas-libro', [PortalReservaLibroController::class, 'store']);
    Route::patch('/mi/reservas-libro/{reservaLibro}/cancelar', [PortalReservaLibroController::class, 'cancelar']);
});
