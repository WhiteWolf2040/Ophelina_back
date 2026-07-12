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
    'allowed_origins' => ['*'],
    
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