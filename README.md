Paso 1: composer install (en la carpeta donde estara el back) 

Paso 2: Crear el archivo de entorno:  cp .env.example .env

Paso 3: Generar la clave única de la aplicación: php artisan key:generate

Conexion a la bd, cuando crean el proyecto 




DB_CONNECTION=mysql

 DB_HOST=127.0.0.1
 
 DB_PORT=3306
 
 DB_DATABASE=nombre de la bd
 
 DB_USERNAME=root
 
DB_PASSWORD= contraseña de laravel de ustedes 
