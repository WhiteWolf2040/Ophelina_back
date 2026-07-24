<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cloudinary Configuration
    |--------------------------------------------------------------------------
    |
    | Esta configuración lee las variables directamente del entorno del sistema
    | (getenv) como respaldo si no están en el archivo .env
    |
    */
    
    'cloud_url' => env('CLOUDINARY_URL', getenv('CLOUDINARY_URL')),
    
    'cloud_name' => env('CLOUDINARY_CLOUD_NAME', getenv('CLOUDINARY_CLOUD_NAME')),
    
    'api_key' => env('CLOUDINARY_API_KEY', getenv('CLOUDINARY_API_KEY')),
    
    'api_secret' => env('CLOUDINARY_API_SECRET', getenv('CLOUDINARY_API_SECRET')),
];