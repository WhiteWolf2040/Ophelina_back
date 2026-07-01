<?php

return [
    //  Rutas donde se aplicará CORS
    'paths' => [
        'api/*',                    // Todas las rutas API
        'sanctum/csrf-cookie',      // Para Sanctum
        'login',                    // Login
        'logout',                   // Logout
        'register',                 // Registro
        '*',                        // O todas (para pruebas)
    ],
    
    //  Métodos HTTP permitidos
    'allowed_methods' => ['*'],
    
    // ORÍGENES PERMITIDOS (TODAS las URLs de Vercel)
    'allowed_origins' => [
        'https://ophelina-front-ces96e1a9-test-ophelina-s-projects.vercel.app',
        'https://ophelina-front.vercel.app',
        'https://ophelina-front-ro74l47l6-test-ophelina-s-projects.vercel.app',
        'http://localhost:3000',
        'http://localhost:5173',
        'http://localhost:8000',
    ],
    
    'allowed_origins_patterns' => [],
    
    //  Headers permitidos
    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'X-XSRF-TOKEN',
        'Accept',
        'Origin',
        'X-CSRF-TOKEN',
    ],
    
    //  Headers expuestos al frontend
    'exposed_headers' => [],
    
    //  Tiempo de caché para preflight (segundos)
    'max_age' => 86400, // 24 horas

    //  Permitir credenciales (cookies, tokens)
    'supports_credentials' => true,
];