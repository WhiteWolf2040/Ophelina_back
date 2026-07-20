<?php

use App\Http\Controllers\API\MisEmpenosController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\ClienteController;
use App\Http\Controllers\API\PagoController;
use App\Http\Controllers\API\AmortizacionController;
use App\Http\Controllers\API\EmpenoController;
use App\Http\Controllers\API\RolController;
use App\Http\Controllers\API\PermisoController;
use App\Http\Controllers\API\PrecioOroController;
use App\Http\Controllers\API\StripeController;
use App\Http\Controllers\API\ContactController;
use App\Http\Controllers\API\TiendaController;
use App\Http\Controllers\API\ReportesController;
use App\Http\Controllers\API\ConfiguracionController;
use App\Http\Controllers\API\OpheliaHomeController;


/*
|--------------------------------------------------------------------------
| RUTAS PÚBLICAS (No requieren autenticación)
|--------------------------------------------------------------------------
*/

// Contacto
Route::post('/send-email', [ContactController::class, 'SendEmail']);

// Autenticación
Route::post('/login', [AuthController::class, 'login']);

// ✅ Stripe - Rutas PÚBLICAS (no requieren token de autenticación)
Route::prefix('stripe')->group(function () {
    Route::post('/create-checkout-session', [StripeController::class, 'createCheckoutSession']);
    Route::post('/activate-free-plan', [StripeController::class, 'activateFreePlan']);
});

/*
|--------------------------------------------------------------------------
| RUTAS PROTEGIDAS (requieren token de autenticación)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    ==========================
    STRIPE - RUTAS PROTEGIDAS
    ==========================
    */
    Route::prefix('stripe')->group(function () {
        Route::post('/verify-payment', [StripeController::class, 'verifyPayment']);
        Route::get('/check-subscription/{empresaId}', [StripeController::class, 'checkSubscription']);
    });

    /*
    ==========================
    USUARIO ACTUAL & LOGOUT
    ==========================
    */
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    /*
    ==========================
    DASHBOARD (Todos los planes pueden ver)
    ==========================
    */
    Route::prefix('home')->group(function () {
        Route::get('/', [DashboardController::class, 'index']);
        Route::get('/activos', [DashboardController::class, 'activos']);
        Route::get('/vencidos', [DashboardController::class, 'vencidos']);
        Route::get('/proximos', [DashboardController::class, 'proximos']);
        Route::get('/morosidad', [DashboardController::class, 'morosidad']);
        Route::get('/distribucion-categorias', [DashboardController::class, 'distribucionCategorias']);
        Route::get('/amortizaciones-pendientes', [DashboardController::class, 'amortizacionPendiente']);
    });

    /*
    ==========================
    CLIENTES (Todos los planes pueden ver)
    ==========================
    */
    Route::prefix('clientes')->group(function () {
        Route::get('/', [ClienteController::class, 'index'])->middleware('check.permission:ver_clientes');
        Route::post('/', [ClienteController::class, 'store'])->middleware('check.permission:crear_clientes');
        Route::get('/{id}', [ClienteController::class, 'show'])->middleware('check.permission:ver_clientes');
        Route::put('/{id}', [ClienteController::class, 'update'])->middleware('check.permission:editar_clientes');
        Route::delete('/{id}', [ClienteController::class, 'destroy'])->middleware('check.permission:eliminar_clientes');
        Route::get('/buscar-cp/{cp}', [ClienteController::class, 'buscarCP']);
        Route::get('/historial/{id}', [ClienteController::class, 'historial']);
    });

    /*
    ==========================
    EMPEÑOS (Todos los planes pueden ver)
    ==========================
    */
    Route::prefix('empenos')->group(function () {
        Route::get('/', [EmpenoController::class, 'index'])->middleware('check.permission:ver_empenos');
        Route::post('/', [EmpenoController::class, 'store'])->middleware('check.permission:crear_empenos');
        Route::get('/activos-con-saldo', [EmpenoController::class, 'activosConSaldo']);
        Route::get('/{id}', [EmpenoController::class, 'show'])->middleware('check.permission:ver_empenos');
    });

    /*
    ==========================
    PAGOS (Todos los planes pueden ver, pero verificamos permiso)
    ==========================
    */
    Route::prefix('pagos')->group(function () {
        Route::get('/', [PagoController::class, 'index'])->middleware('check.permission:ver_pagos');
        Route::post('/', [PagoController::class, 'store'])->middleware('check.permission:registrar_pagos');
        Route::get('/{id}', [PagoController::class, 'show'])->middleware('check.permission:ver_pagos');
        Route::delete('/{id}', [PagoController::class, 'destroy'])->middleware('check.permission:eliminar_pagos');
        Route::get('/cliente/{id}', [PagoController::class, 'porCliente']);
        Route::get('/empeno/{id_empeno}/count', [PagoController::class, 'countByEmpeno']);
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
    PRENDAS
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
    PRECIO DEL ORO (Todos los planes pueden ver)
    ==========================
    */
    Route::get('/precio-oro', [PrecioOroController::class, 'getPrecioActual']);
    Route::get('/precio-oro/quilates', [PrecioOroController::class, 'getPreciosQuilates']);
    Route::get('/precio-oro/historial', [PrecioOroController::class, 'historialPrecios']);
    Route::post('/precio-oro/actualizar', [PrecioOroController::class, 'actualizarPrecio']);

    /*
    ==========================
    TIENDA EN LÍNEA (Solo plan Premium - id_plan=4)
    ==========================
    */
    /* Route::middleware(['check.plan:tienda'])->group(function () {
        Route::prefix('tienda')->group(function () {
            Route::get('/', [TiendaController::class, 'index']);
            Route::get('/productos', [TiendaController::class, 'getProductos']);
            Route::get('/productos/{id}', [TiendaController::class, 'show']);
            Route::post('/productos', [TiendaController::class, 'store']);
            Route::put('/productos/{id}', [TiendaController::class, 'update']);
            Route::delete('/productos/{id}', [TiendaController::class, 'destroy']);
            Route::post('/apartados', [TiendaController::class, 'apartar']);
            Route::get('/apartados', [TiendaController::class, 'getApartados']);
            Route::get('/ventas', [TiendaController::class, 'getVentas']);
        });
    }); */

    /*
    ==========================
    REPORTES (Solo plan Premium - id_plan=4)
    ==========================
    */
    /* Route::middleware(['check.plan:reportes'])->group(function () {
        Route::prefix('reportes')->group(function () {
            Route::get('/', [ReportesController::class, 'index']);
            Route::get('/ventas', [ReportesController::class, 'ventas']);
            Route::get('/morosidad', [ReportesController::class, 'reporteMorosidad']);
            Route::get('/ganancias', [ReportesController::class, 'ganancias']);
            Route::get('/exportar', [ReportesController::class, 'exportar']);
        });
    }); */

    /*
    ==========================
    ROLES (Solo plan Premium - id_plan=4)
    ==========================
    */
    Route::middleware(['check.plan:roles'])->group(function () {
        Route::prefix('roles')->group(function () {
            Route::get('/', [RolController::class, 'index']);
            Route::post('/', [RolController::class, 'store']);
            Route::get('/{id}', [RolController::class, 'show']);
            Route::put('/{id}', [RolController::class, 'update']);
            Route::delete('/{id}', [RolController::class, 'destroy']);
        });
    });

    /*
    ==========================
    PERMISOS (Solo plan Premium - id_plan=4)
    ==========================
    */
    Route::middleware(['check.plan:permisos'])->group(function () {
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
    });


    Route::middleware('auth:sanctum')->group(function () {
        // Dashboard del cliente
        Route::get('/homecliente', [OpheliaHomeController::class, 'index']);
    });

    /*
    ==========================
    CONFIGURACIÓN (Todos los planes pueden ver, pero algunos campos limitados)
    ==========================
    */
    /* Route::middleware(['check.plan:configuracion'])->group(function () {
        Route::prefix('configuracion')->group(function () {
            Route::get('/', [ConfiguracionController::class, 'index']);
            Route::get('/empresa', [ConfiguracionController::class, 'getEmpresa']);
            Route::put('/empresa', [ConfiguracionController::class, 'updateEmpresa']);
            Route::get('/usuarios', [ConfiguracionController::class, 'getUsuarios']);
            Route::post('/usuarios', [ConfiguracionController::class, 'storeUsuario']);
            Route::put('/usuarios/{id}', [ConfiguracionController::class, 'updateUsuario']);
            Route::delete('/usuarios/{id}', [ConfiguracionController::class, 'deleteUsuario']);
        });
    }); */

});