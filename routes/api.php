<?php
// routes/api.php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\API\PagoController;
use App\Http\Controllers\API\AmortizacionController;
use App\Http\Controllers\API\EmpenoController;
use App\Http\Controllers\API\RolController;
use App\Http\Controllers\API\PermisoController;
use App\Http\Controllers\API\PrecioOroController;
use App\Http\Controllers\API\ContactController;
use App\Http\Controllers\API\ReportesController;
use App\Http\Controllers\Api\TiendaController;
use App\Http\Controllers\Api\PrendaController; // ← AGREGAR ESTO

/*
|--------------------------------------------------------------------------
| RUTAS PÚBLICAS
|--------------------------------------------------------------------------
*/
Route::post('/send-email', [ContactController::class, 'SendEmail']);

Route::post('/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| RUTAS PÚBLICAS DE TIENDA (Catálogo sin autenticación)
|--------------------------------------------------------------------------
*/
Route::prefix('public')->group(function () {
    Route::get('/productos', [TiendaController::class, 'catalogoPublico']);
    Route::get('/productos/{id}', [TiendaController::class, 'detallePublico']);
});

/*
|--------------------------------------------------------------------------
| RUTAS PROTEGIDAS (requieren token)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    /*
    ==========================
    USUARIO ACTUAL
    ==========================
    */
    Route::get('/user', [AuthController::class, 'user']);

    /*
    ==========================
    CERRAR SESIÓN
    ==========================
    */
    Route::post('/logout', [AuthController::class, 'logout']);

    /*
    ==========================
    DASHBOARD
    ==========================
    */
    Route::prefix('dashboard')->group(function () {
        Route::get('/', [DashboardController::class, 'index']);
        Route::get('/activos', [DashboardController::class, 'activos']);
        Route::get('/vencidos', [DashboardController::class, 'vencidos']);
        Route::get('/proximos', [DashboardController::class, 'proximos']);
        Route::get('/morosidad', [DashboardController::class, 'morosidad']);
        Route::get('/distribucion-categorias', [DashboardController::class, 'distribucionCategorias']);
        
        Route::get('/resumen', [DashboardController::class, 'resumen']);
        Route::get('/ventas', [DashboardController::class, 'ventas']);
        Route::get('/reportes', [DashboardController::class, 'reportes']);
    });

    /*
    ==========================
    CLIENTES
    ==========================
    */
    Route::prefix('clientes')->group(function () {
        Route::get('/', [ClienteController::class, 'index']);
        Route::post('/', [ClienteController::class, 'store']);
        Route::get('/{id}', [ClienteController::class, 'show']);
        Route::put('/{id}', [ClienteController::class, 'update']);
        Route::delete('/{id}', [ClienteController::class, 'destroy']);
        Route::get('/buscar-cp/{cp}', [ClienteController::class, 'buscarCP']);
        Route::get('/historial/{id}', [ClienteController::class, 'historial']);
    });

    /*
    ==========================
    AMORTIZACIÓN
    ==========================
    */
    Route::prefix('amortizacion')->group(function () {
        Route::get('/pendiente/{id_empeno}', [AmortizacionController::class, 'pendiente']);
        Route::get('/empeno/{id_empeno}', [AmortizacionController::class, 'porEmpeno']);
    });

    /*
    ==========================
    PAGOS
    ==========================
    */
    Route::prefix('pagos')->group(function () {
        Route::get('/', [PagoController::class, 'index']);
        Route::post('/', [PagoController::class, 'store']);
        Route::get('/{id}', [PagoController::class, 'show']);
        Route::delete('/{id}', [PagoController::class, 'destroy']);
        Route::get('/cliente/{id}', [PagoController::class, 'porCliente']);
        Route::get('/empeno/{id_empeno}/count', [PagoController::class, 'countByEmpeno']);
    });

    /*
    ==========================
    EMPEÑOS
    ==========================
    */
    Route::prefix('empenos')->group(function () {
        Route::get('/', [EmpenoController::class, 'index']);
        Route::get('/activos-con-saldo', [EmpenoController::class, 'activosConSaldo']);
        Route::get('/estadisticas', [EmpenoController::class, 'estadisticas']);
        Route::get('/{id}', [EmpenoController::class, 'show']);
        
        Route::post('/', [EmpenoController::class, 'store']);
        Route::post('/prendas', [EmpenoController::class, 'storePrenda']);
        
        Route::post('/{id}/recuperar', [EmpenoController::class, 'recuperar']);
        Route::post('/{id}/renovar', [EmpenoController::class, 'renovar']);
        
        Route::post('/publicar-vencidos', [EmpenoController::class, 'publicarVencidos']);
        Route::post('/enviar-recordatorios', [EmpenoController::class, 'enviarRecordatorios']);
        
        Route::get('/clientes', [EmpenoController::class, 'getClientes']);
        Route::get('/prendas-disponibles', [EmpenoController::class, 'getPrendasDisponibles']);
        Route::get('/tasas', [EmpenoController::class, 'getTasas']);
    });

    /*
    ==========================
    PRENDAS (Rutas adicionales - compatibilidad)
    ==========================
    */
    Route::get('/prendas/disponibles', [EmpenoController::class, 'getPrendasDisponibles']);
    Route::post('/prendas', [EmpenoController::class, 'storePrenda']);

    /*
    ==========================
    TASAS DE INTERÉS
    ==========================
    */
    Route::get('/tasas-interes', [EmpenoController::class, 'getTasasInteres']);

    /*
    ==========================
    TIENDA ONLINE
    ==========================
    */
    Route::prefix('tienda')->group(function () {
        Route::get('/productos', [TiendaController::class, 'index']);
        Route::get('/productos/estadisticas', [TiendaController::class, 'estadisticas']);
        Route::post('/productos', [TiendaController::class, 'store']);
        Route::put('/productos/{id}', [TiendaController::class, 'update']);
        Route::delete('/productos/{id}', [TiendaController::class, 'destroy']);
        
        Route::patch('/productos/{id}/visibilidad', [TiendaController::class, 'toggleVisibilidad']);
        Route::patch('/productos/{id}/destacado', [TiendaController::class, 'toggleDestacado']);
        
        Route::post('/publicacion-automatica', [TiendaController::class, 'publicacionAutomatica']);
        Route::post('/configurar-dias-gracia', [TiendaController::class, 'configurarDiasGracia']);
    });

    /*
    ==========================
    INVENTARIO (PRENDAS) - NUEVO
    ==========================
    */
    Route::prefix('prendas')->group(function () {
        Route::get('/', [PrendaController::class, 'index']);          // Listar todas
        Route::get('/{id}', [PrendaController::class, 'show']);       // Ver una
        Route::put('/{id}', [PrendaController::class, 'update']);     // Editar
        Route::delete('/{id}', [PrendaController::class, 'destroy']); // Eliminar
    });

    /*
    ==========================
    ROLES
    ==========================
    */
    Route::prefix('roles')->group(function () {
        Route::get('/', [RolController::class, 'index']);
        Route::post('/', [RolController::class, 'store']);
        Route::get('/{id}', [RolController::class, 'show']);
        Route::put('/{id}', [RolController::class, 'update']);
        Route::delete('/{id}', [RolController::class, 'destroy']);
    });

    /*
    ==========================
    PERMISOS
    ==========================
    */
    Route::prefix('permisos')->group(function () {
        Route::get('/', [PermisoController::class, 'index']);
        Route::get('/agrupados', [PermisoController::class, 'agrupados']);
        Route::get('/estadisticas', [PermisoController::class, 'estadisticas']);
        Route::get('/modulo/{modulo}', [PermisoController::class, 'porModulo']);
        Route::post('/', [PermisoController::class, 'store']);
        Route::post('/masivo', [PermisoController::class, 'storeMasivo']);
        Route::get('/{id}', [PermisoController::class, 'show']);
        Route::put('/{id}', [PermisoController::class, 'update']);
        Route::delete('/{id}', [PermisoController::class, 'destroy']);
        Route::delete('/masivo', [PermisoController::class, 'destroyMasivo']);
    });

    /*
    ==========================
    PRECIO DEL ORO
    ==========================
    */
    Route::prefix('precio-oro')->group(function () {
        Route::get('/', [PrecioOroController::class, 'getPrecioActual']);
        Route::get('/quilates', [PrecioOroController::class, 'getPreciosQuilates']);
        Route::get('/historial', [PrecioOroController::class, 'historialPrecios']);
        Route::post('/actualizar', [PrecioOroController::class, 'actualizarPrecio']);
    });

    /*
    ==========================
    REPORTES
    ==========================
    */
    Route::prefix('reportes')->group(function () {
        Route::get('/kpis', [ReportesController::class, 'kpis']);
        Route::get('/empenos', [ReportesController::class, 'empenos']);
        Route::get('/flujo-caja', [ReportesController::class, 'flujoCaja']);
        Route::get('/clientes', [ReportesController::class, 'clientes']);
        Route::get('/inventario', [ReportesController::class, 'inventario']);
    });

}); // fin auth:sanctum