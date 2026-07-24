<?php
 
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
 
    'allowed_methods' => ['*'],
 
    // ⚠️ Con supports_credentials = true, NUNCA se puede usar '*' aquí.
    // Lista los dominios exactos que sí conoces (producción):
    'allowed_origins' => [
        'https://ophelina-front.vercel.app',
    ],
 
    // Y usa un patrón para cubrir las URLs de preview de Vercel,
    // que cambian en cada deploy (ej. ophelina-front-XXXXX-test-...vercel.app)
    'allowed_origins_patterns' => [
        '#^https://ophelina-front(-[a-z0-9]+)?(-[a-z0-9-]+)?\.vercel\.app$#',
    ],
 
    'allowed_headers' => ['*'],
 
    'exposed_headers' => [],
 
    'max_age' => 0,
 
    'supports_credentials' => true,
];
 
