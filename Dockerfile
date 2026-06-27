# syntax=docker/dockerfile:1.4

FROM php:8.4-fpm-alpine

# Instalar dependencias del sistema y extensiones de PHP
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

WORKDIR /var/www/html

# Copiar los archivos del proyecto
COPY . .

# ==========================================
# CREAR .env SI NO EXISTE
# ==========================================
RUN if [ ! -f .env ]; then \
        cp .env.example .env || true; \
    fi

# ==========================================
# INSTALAR DEPENDENCIAS
# ==========================================
RUN composer install --optimize-autoloader --no-interaction

# ==========================================
# GENERAR APP_KEY Y OPTIMIZAR LARAVEL
# ==========================================
RUN php artisan key:generate --force || true
RUN php artisan config:cache || true
RUN php artisan route:cache || true
RUN php artisan view:cache || true

# Configurar permisos
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
RUN chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# ==========================================
# CONFIGURACIÓN DE NGINX (CORREGIDA)
# ==========================================
RUN echo 'user www-data; \
worker_processes auto; \
pid /run/nginx.pid; \
events { \
    worker_connections 1024; \
} \
http { \
    include /etc/nginx/mime.types; \
    default_type application/octet-stream; \
    sendfile on; \
    keepalive_timeout 65; \
    server { \
        listen 8080; \
        server_name _; \
        root /var/www/html/public; \
        add_header X-Frame-Options "SAMEORIGIN"; \
        add_header X-Content-Type-Options "nosniff"; \
        index index.php; \
        charset utf-8; \
        location / { \
            try_files $$uri $$uri/ /index.php?$$query_string; \
        } \
        location = /favicon.ico { access_log off; log_not_found off; } \
        location = /robots.txt { access_log off; log_not_found off; } \
        error_page 404 /index.php; \
        location ~ \.php$$ { \
            fastcgi_pass 127.0.0.1:9000; \
            fastcgi_param SCRIPT_FILENAME $$realpath_root$$fastcgi_script_name; \
            fastcgi_param PATH_INFO $$fastcgi_path_info; \
            include fastcgi_params; \
        } \
        location ~ /\.ht { deny all; } \
    } \
}' > /etc/nginx/nginx.conf

# ==========================================
# CONFIGURACIÓN DE SUPERVISOR
# ==========================================
RUN echo '[supervisord] \
nodaemon=true \
logfile=/dev/null \
logfile_maxbytes=0 \
pidfile=/run/supervisord.pid \
[program:php-fpm] \
command=php-fpm \
stdout_logfile=/dev/stdout \
stdout_logfile_maxbytes=0 \
stderr_logfile=/dev/stderr \
stderr_logfile_maxbytes=0 \
[program:nginx] \
command=nginx -g "daemon off;" \
stdout_logfile=/dev/stdout \
stdout_logfile_maxbytes=0 \
stderr_logfile=/dev/stderr \
stderr_logfile_maxbytes=0' > /etc/supervisor/conf.d/supervisord.conf

# ==========================================
# SCRIPT DE ARRANQUE (CORREGIDO)
# ==========================================
RUN echo '#!/bin/sh \
echo "=== INICIANDO SERVIDOR EN PUERTO: $PORT ===" \
# Render asigna el puerto automáticamente, usamos 8080 internamente \
echo "Configurando Nginx para escuchar en el puerto $PORT..." \
# Crear archivo de configuración de Nginx con el puerto correcto \
cat > /etc/nginx/nginx.conf << EOF \
user www-data; \
worker_processes auto; \
pid /run/nginx.pid; \
events { \
    worker_connections 1024; \
} \
http { \
    include /etc/nginx/mime.types; \
    default_type application/octet-stream; \
    sendfile on; \
    keepalive_timeout 65; \
    server { \
        listen $PORT; \
        server_name _; \
        root /var/www/html/public; \
        add_header X-Frame-Options "SAMEORIGIN"; \
        add_header X-Content-Type-Options "nosniff"; \
        index index.php; \
        charset utf-8; \
        location / { \
            try_files $$uri $$uri/ /index.php?$$query_string; \
        } \
        location = /favicon.ico { access_log off; log_not_found off; } \
        location = /robots.txt { access_log off; log_not_found off; } \
        error_page 404 /index.php; \
        location ~ \.php$$ { \
            fastcgi_pass 127.0.0.1:9000; \
            fastcgi_param SCRIPT_FILENAME $$realpath_root$$fastcgi_script_name; \
            fastcgi_param PATH_INFO $$fastcgi_path_info; \
            include fastcgi_params; \
        } \
        location ~ /\.ht { deny all; } \
    } \
} \
EOF \
# Ejecutar migraciones \
if [ -n "$DB_CONNECTION" ]; then \
    echo "Ejecutando migraciones..." \
    php artisan migrate --force || echo "Migraciones fallaron, continuando..." \
fi \
# Iniciar Supervisor \
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf' > /usr/local/bin/start.sh

RUN chmod +x /usr/local/bin/start.sh

# ==========================================
# EXPONER PUERTO (Render asigna automáticamente)
# ==========================================
EXPOSE 8080

# ==========================================
# INICIAR CON EL SCRIPT
# ==========================================
CMD ["/usr/local/bin/start.sh"]