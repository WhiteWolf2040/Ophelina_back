<?php

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
use App\Http\Controllers\API\PrendaController;

// ✅ NUEVOS IMPORTS PARA CLIENTE
use App\Http\Controllers\API\OpheliaHomeController;
use App\Http\Controllers\API\NotificacionController;
use App\Http\Controllers\API\MisEmpenosController;
use App\Http\Controllers\API\ApartadoController;
use App\Http\Controllers\API\StripeWebhookController;
use App\Http\Controllers\API\OpheliaTiendaController;

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

// ✅ WEBHOOK DE STRIPE - DEBE SER PÚBLICO
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);

// ✅ RUTAS PÚBLICAS PARA TIENDA (catálogo sin autenticación)
Route::prefix('public')->group(function () {
    Route::get('/productos', [TiendaController::class, 'catalogoPublico']);
    Route::get('/productos/{id}', [TiendaController::class, 'detallePublico']);
});

// ✅ RUTAS PÚBLICAS PARA CLIENTE - TIENDA (catálogo visible sin login)
Route::get('/tienda/productos', [OpheliaTiendaController::class, 'getProductos']);

// ✅ RUTAS PÚBLICAS PARA APARTADOS (crear sesión de pago)
Route::post('/apartados/crear-sesion', [ApartadoController::class, 'crearSesion']);

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
    USUARIO ACTUAL, PERFIL & LOGOUT
    ==========================
    */
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/user', [AuthController::class, 'updateProfile']); // ✅ AGREGADO
    Route::post('/logout', [AuthController::class, 'logout']);

    /*
    ==========================
    NOTIFICACIONES (CLIENTE)
    ==========================
    */
    Route::get('/notificaciones', [NotificacionController::class, 'index']); // ✅ AGREGADO

    /*
    ==========================
    HOME DEL CLIENTE (DASHBOARD CLIENTE)
    ==========================
    */
    Route::get('/homecliente', [OpheliaHomeController::class, 'index']); // ✅ AGREGADO

    /*
    ==========================
    MIS EMPEÑOS (CLIENTE)
    ==========================
    */
    Route::get('/cliente/empenos', [MisEmpenosController::class, 'getMisEmpenos']); // ✅ AGREGADO
    Route::get('/cliente/empenos/resumen', [MisEmpenosController::class, 'getResumenMisEmpenos']); // ✅ AGREGADO
    Route::get('/cliente/empenos/{id}', [MisEmpenosController::class, 'getMisEmpenosDetalle']); // ✅ AGREGADO

    /*
    ==========================
    CLIENTE - TIENDA (apartados)
    ==========================
    */
    // ✅ RUTA CORREGIDA: /cliente/tienda/productos (para que coincida con el frontend)
    Route::prefix('cliente/tienda')->group(function () {
        Route::get('/productos', [OpheliaTiendaController::class, 'getProductos']);
    });

    // ✅ RUTAS PARA APARTAR Y VER APARTADOS
    Route::prefix('tienda')->group(function () {
        Route::post('/productos/{id}/apartar', [OpheliaTiendaController::class, 'apartar']);
        Route::get('/apartados', [OpheliaTiendaController::class, 'misApartados']);
    });

    /*
    ==========================
    DASHBOARD (ADMIN) - Todos los planes pueden ver
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
    CLIENTES (ADMIN) - Todos los planes pueden ver
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
    EMPEÑOS (ADMIN) - Todos los planes pueden ver
    ==========================
    */
    Route::prefix('empenos')->group(function () {
        Route::get('/', [EmpenoController::class, 'index'])->middleware('check.permission:ver_empenos');
        Route::post('/', [EmpenoController::class, 'store'])->middleware('check.permission:crear_empenos');
        Route::get('/activos-con-saldo', [EmpenoController::class, 'activosConSaldo']);
        Route::get('/todos', [EmpenoController::class, 'todos']);
        Route::post('/actualizar-estados', [EmpenoController::class, 'actualizarEstados']);
        Route::get('/{id}', [EmpenoController::class, 'show'])->middleware('check.permission:ver_empenos');
        Route::get('/clientes', [EmpenoController::class, 'getClientes']);
        Route::get('/prendas-disponibles', [EmpenoController::class, 'getPrendasDisponibles']);
        Route::get('/tasas', [EmpenoController::class, 'getTasasInteres']);
    });

    /*
    ==========================
    PAGOS (ADMIN) - Todos los planes pueden ver, pero verificamos permiso
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
    AMORTIZACIÓN (ADMIN)
    ==========================
    */
    Route::prefix('amortizacion')->group(function () {
        Route::get('/pendiente/{id_empeno}', [AmortizacionController::class, 'pendiente']);
        Route::get('/empeno/{id_empeno}', [AmortizacionController::class, 'porEmpeno']);
    });

    /*
    ==========================
    PRENDAS (ADMIN)
    ==========================
    */
    Route::get('/prendas/disponibles', [EmpenoController::class, 'getPrendasDisponibles']);
    Route::post('/prendas', [EmpenoController::class, 'storePrenda']);

    /*
    ==========================
    TASAS DE INTERÉS (ADMIN)
    ==========================
    */
    Route::get('/tasas-interes', [EmpenoController::class, 'getTasasInteres']);

    /*
    ==========================
    PRECIO DEL ORO (ADMIN) - Todos los planes pueden ver
    ==========================
    */
    Route::get('/precio-oro', [PrecioOroController::class, 'getPrecioActual']);
    Route::get('/precio-oro/quilates', [PrecioOroController::class, 'getPreciosQuilates']);
    Route::get('/precio-oro/historial', [PrecioOroController::class, 'historialPrecios']);
    Route::post('/precio-oro/actualizar', [PrecioOroController::class, 'actualizarPrecio']);

    /*
    ==========================
    REPORTES (ADMIN) - NUEVAS RUTAS
    ==========================
    */
    Route::prefix('reportes')->group(function () {
        Route::get('/kpis', [ReportesController::class, 'kpis']);
        Route::get('/empenos', [ReportesController::class, 'empenos']);
        Route::get('/flujo-caja', [ReportesController::class, 'flujoCaja']);
        Route::get('/clientes', [ReportesController::class, 'clientes']);
        Route::get('/inventario', [ReportesController::class, 'inventario']);
    });

    /*
    ==========================
    TIENDA EN LÍNEA (ADMIN) - Solo plan Premium - id_plan=4
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
    INVENTARIO (PRENDAS) - ADMIN
    ==========================
    */
    Route::prefix('prendas')->group(function () {
        Route::get('/', [PrendaController::class, 'index']);
        Route::get('/{id}', [PrendaController::class, 'show']);
        Route::put('/{id}', [PrendaController::class, 'update']);
        Route::delete('/{id}', [PrendaController::class, 'destroy']);
    });

    /*
    ==========================
    ROLES (ADMIN) - Solo plan Premium - id_plan=4
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
    PERMISOS (ADMIN) - Solo plan Premium - id_plan=4
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

});