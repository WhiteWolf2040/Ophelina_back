<?php

return [
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'login',
        'logout',
        'register',
    ],

    'allowed_methods' => ['*'],

    // ✅ En vez de '*', usa un patrón que acepte cualquier preview de Vercel + tu dominio fijo
    'allowed_origins' => [],

    'allowed_origins_patterns' => [
        '#^https://ophelina-front.*\.vercel\.app$#',
    ],

    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'X-XSRF-TOKEN',
        'Accept',
        'Origin',
        'X-CSRF-TOKEN',
    ],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => true,
];