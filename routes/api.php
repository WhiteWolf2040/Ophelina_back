<?php

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

/*
|--------------------------------------------------------------------------
| AUTENTICACIÓN
|--------------------------------------------------------------------------
*/

Route::post('/login',[AuthController::class,'login']);

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
    Route::get('/user',[AuthController::class,'user']);

    /*
    ==========================
    CERRAR SESIÓN
    ==========================
    */
    Route::post('/logout',[AuthController::class,'logout']);


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
        Route::post('/', [EmpenoController::class, 'store']);
        Route::get('/', [EmpenoController::class, 'getClientes']);
        Route::get('/activos-con-saldo', [EmpenoController::class, 'activosConSaldo']);
        Route::get('/{id}', [EmpenoController::class, 'show']);
    });

    /*
    ==========================  
    PRENDAS (FALTABAN ESTAS)
    ==========================
    */
    Route::get('/prendas/disponibles', [EmpenoController::class, 'getPrendasDisponibles']);
    Route::post('/prendas', [EmpenoController::class, 'storePrenda']);

    /*
    ==========================
    TASAS DE INTERÉS (FALTABA ESTA)
    ==========================
    */
    Route::get('/tasas-interes', [EmpenoController::class, 'getTasasInteres']);

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
    Route::get('/precio-oro', [PrecioOroController::class, 'getPrecioActual']);
    Route::get('/precio-oro/quilates', [PrecioOroController::class, 'getPreciosQuilates']);
    Route::get('/precio-oro/historial', [PrecioOroController::class, 'historialPrecios']);
    Route::post('/precio-oro/actualizar', [PrecioOroController::class, 'actualizarPrecio']);

});