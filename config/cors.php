<?php

return [
    //  Rutas donde se aplicará CORS
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'login',
        'logout',
        'register',
        '*',
    ],
    
    //  Métodos HTTP permitidos
    'allowed_methods' => ['*'],
    
    //  ORÍGENES PERMITIDOS (TODAS las URLs de Vercel)
    'allowed_origins' => [
        // URLs de Vercel (producción y previews)
        'https://ophelina-front.vercel.app',
        'https://ophelina-front-4a6ksaggp-test-ophelina-s-projects.vercel.app', // ✅ NUEVA URL
        'https://ophelina-front-ces96e1a9-test-ophelina-s-projects.vercel.app',
        'https://ophelina-front-ro74l47l6-test-ophelina-s-projects.vercel.app',
        
        // URLs locales para desarrollo
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
    'max_age' => 86400,
    
    //  Permitir credenciales (cookies, tokens)
    'supports_credentials' => true,
];