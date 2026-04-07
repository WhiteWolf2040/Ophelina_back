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
    Route::get('/', [DashboardController::class, 'index']);        // Resumen completo
    Route::get('/activos', [DashboardController::class, 'activos']);   // Empeños activos
    Route::get('/vencidos', [DashboardController::class, 'vencidos']); // Empeños vencidos
    Route::get('/proximos', [DashboardController::class, 'proximos']); // Próximos a vencer
    Route::get('/morosidad', [DashboardController::class, 'morosidad']); // Morosidad (pagos atrasados)
      Route::get('/distribucion-categorias', [DashboardController::class, 'distribucionCategorias']);
});

// RUTAS DE CLIENTES
Route::prefix('clientes')->group(function () {
    Route::get('/', [ClienteController::class, 'index']);          // GET /api/clientes
    Route::post('/', [ClienteController::class, 'store']);         // POST /api/clientes
    Route::get('/{id}', [ClienteController::class, 'show']);       // GET /api/clientes/{id}
    Route::put('/{id}', [ClienteController::class, 'update']);     // PUT /api/clientes/{id}
    Route::delete('/{id}', [ClienteController::class, 'destroy']); // DELETE /api/clientes/{id}
    Route::get('/buscar-cp/{cp}', [ClienteController::class, 'buscarCP']); // GET /api/clientes/buscar-cp/{cp}
    Route::get('/historial/{id}', [ClienteController::class, 'historial']); // GET /api/clientes/historial/{id}
});

Route::prefix('amortizacion')->group(function () {
        Route::get('/pendiente/{id_empeno}', [AmortizacionController::class, 'pendiente']);
        Route::get('/empeno/{id_empeno}', [AmortizacionController::class, 'porEmpeno']);
    });

    Route::prefix('pagos')->group(function () {
        Route::get('/', [PagoController::class, 'index']);               // GET /api/pagos
        Route::post('/', [PagoController::class, 'store']);              // POST /api/pagos
        Route::get('/{id}', [PagoController::class, 'show']);            // GET /api/pagos/{id}
        Route::delete('/{id}', [PagoController::class, 'destroy']);      // DELETE /api/pagos/{id}
        Route::get('/cliente/{id}', [PagoController::class, 'porCliente']); // GET /api/pagos/cliente/{id}
         Route::get('/empeno/{id_empeno}/count', [PagoController::class, 'countByEmpeno']);
    });

     Route::prefix('empenos')->group(function () {
        Route::get('/', [EmpenoController::class, 'index']);
         Route::post('/', [EmpenoController::class, 'store']);
        Route::get('/activos-con-saldo', [EmpenoController::class, 'activosConSaldo']);
        Route::get('/{id}', [EmpenoController::class, 'show']);
    });

     Route::prefix('roles')->group(function () {
        Route::get('/', [RolController::class, 'index']);
        Route::post('/', [RolController::class, 'store']);
        Route::get('/{id}', [RolController::class, 'show']);
        Route::put('/{id}', [RolController::class, 'update']);
        Route::delete('/{id}', [RolController::class, 'destroy']);
    });
    
    // Permisos
    Route::prefix('permisos')->group(function () {
        Route::get('/', [PermisoController::class, 'index']);
        Route::get('/agrupados', [PermisoController::class, 'agrupados']);
        Route::post('/', [PermisoController::class, 'store']);
        Route::put('/{id}', [PermisoController::class, 'update']);
        Route::delete('/{id}', [PermisoController::class, 'destroy']);
    });

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

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/precio-oro', [PrecioOroController::class, 'getPrecioActual']);
        Route::get('/precio-oro/quilates', [PrecioOroController::class, 'getPreciosQuilates']);
        Route::get('/precio-oro/historial', [PrecioOroController::class, 'historialPrecios']);
        Route::post('/precio-oro/actualizar', [PrecioOroController::class, 'actualizarPrecio']);
    });

});