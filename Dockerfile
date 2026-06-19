# syntax=docker/dockerfile:1.4

# Cambiamos a PHP 8.4 para igualar la versión de tu entorno local y composer.lock
FROM php:8.4-fpm-alpine

# Instalar dependencias del sistema y extensiones de PHP necesarias para Laravel y PostgreSQL
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    libpng-dev \
    libxml2-dev \
    zip \
    unzip \
    git \
    postgresql-dev \
    && docker-php-ext-install pdo pdo_pgsql bcmath gd

# Instalar Composer globalmente
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar el directorio de trabajo
WORKDIR /var/www/html

# Copiar los archivos del proyecto al contenedor
COPY . .

# Instalar las dependencias de producción de Laravel
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Configurar permisos correctos para Laravel
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# ==========================================
# CREACIÓN DE ARCHIVOS DE CONFIGURACIÓN
# ==========================================

# 1. Crear el archivo de configuración de Nginx
COPY <<-"EOF" /etc/nginx/nginx.conf
user www-data;
worker_processes auto;
pid /run/nginx.pid;

events {
    worker_connections 1024;
}

http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    sendfile on;
    keepalive_timeout 65;

    server {
        listen 80;
        server_name localhost;
        root /var/www/html/public;

        index index.php index.html;
        charset utf-8;

        location / {
            try_files $uri $uri/ /index.php?$query_string;
        }

        location = /favicon.ico { access_log off; log_not_found off; }
        location = /robots.txt  { access_log off; log_not_found off; }

        error_page 404 /index.php;

        location ~ \.php$ {
            fastcgi_pass 127.0.0.1:9000;
            fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
            include fastcgi_params;
        }

        location ~ /\.ht {
            deny all;
        }
    }
}
EOF

# 2. Crear el archivo de configuración de Supervisor
COPY <<-"EOF" /etc/supervisor/conf.d/supervisord.conf
[supervisord]
nodaemon=true
logfile=/dev/null
logfile_maxbytes=0
pidfile=/run/supervisord.pid

[program:php-fpm]
command=php-fpm
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:nginx]
command=nginx -g "daemon off;"
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
EOF

# ==========================================
# ARRANQUE DEL SERVIDOR
# ==========================================

# Exponer el puerto que Render asignará dinámicamente
EXPOSE 80

# Iniciar Supervisor para controlar Nginx y PHP-FPM juntos
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]