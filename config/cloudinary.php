<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cloudinary Configuration
    |--------------------------------------------------------------------------
    */

    'cloud_url' => env('CLOUDINARY_URL', getenv('CLOUDINARY_URL')),

    'upload_preset' => env('CLOUDINARY_UPLOAD_PRESET', getenv('CLOUDINARY_UPLOAD_PRESET')),

    'notification_url' => env('CLOUDINARY_NOTIFICATION_URL', getenv('CLOUDINARY_NOTIFICATION_URL')),

    /*
    |--------------------------------------------------------------------------
    | Advanced Configuration
    |--------------------------------------------------------------------------
    */
    'cloud' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME', getenv('CLOUDINARY_CLOUD_NAME')),
        'api_key'    => env('CLOUDINARY_API_KEY', getenv('CLOUDINARY_API_KEY')),
        'api_secret' => env('CLOUDINARY_API_SECRET', getenv('CLOUDINARY_API_SECRET')),
    ],

];