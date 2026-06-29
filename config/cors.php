<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout', 'register','*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'https://ophelina-front.vercel.app',  // ← TU FRONTEND EN PRODUCCIÓN
        'https://ophelina-front-kj2sqv2b7-test-ophelina-s-projects.vercel.app', // ← Preview (opcional)
        'http://localhost:5173',
        'http://localhost:3000',
        'http://localhost:8000',
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,  // ← IMPORTANTE para tokens
];