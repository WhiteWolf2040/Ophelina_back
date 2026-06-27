# syntax=docker/dockerfile:1.4

FROM php:8.4-fpm-alpine

# Instalar dependencias
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

# Crear directorios necesarios
RUN mkdir -p /etc/supervisor/conf.d \
    && mkdir -p /var/log/supervisor \
    && mkdir -p /run/nginx \
    && mkdir -p /var/www/html/public

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar archivos del proyecto
COPY . .

# Crear .env si no existe
RUN if [ ! -f .env ]; then cp .env.example .env || true; fi

# Instalar dependencias
RUN composer install --optimize-autoloader --no-interaction

# Generar APP_KEY y optimizar
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
# CONFIGURACIÓN DE SUPERVISOR (CORREGIDA)
# ==========================================
RUN echo '[supervisord]' > /etc/supervisor/conf.d/supervisord.conf && \
    echo 'nodaemon=true' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'logfile=/var/log/supervisor/supervisord.log' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'pidfile=/run/supervisord.pid' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo '' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo '[program:php-fpm]' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'command=php-fpm' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'autostart=true' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'autorestart=true' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stdout_logfile=/dev/stdout' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stdout_logfile_maxbytes=0' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stderr_logfile=/dev/stderr' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stderr_logfile_maxbytes=0' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo '' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo '[program:nginx]' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'command=nginx -g "daemon off;"' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'autostart=true' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'autorestart=true' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stdout_logfile=/dev/stdout' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stdout_logfile_maxbytes=0' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stderr_logfile=/dev/stderr' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stderr_logfile_maxbytes=0' >> /etc/supervisor/conf.d/supervisord.conf

# ==========================================
# SCRIPT DE ARRANQUE SIMPLIFICADO
# ==========================================
RUN echo '#!/bin/sh' > /usr/local/bin/start.sh && \
    echo 'set -e' >> /usr/local/bin/start.sh && \
    echo 'echo "=== INICIANDO SERVIDOR EN PUERTO: ${PORT:-8080} ==="' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# Forzar uso de PostgreSQL en lugar de MySQL' >> /usr/local/bin/start.sh && \
    echo 'export DB_CONNECTION=pgsql' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# Esperar a que la base de datos esté lista' >> /usr/local/bin/start.sh && \
    echo 'echo "Esperando 5 segundos para que la base de datos esté lista..."' >> /usr/local/bin/start.sh && \
    echo 'sleep 5' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# Ejecutar migraciones' >> /usr/local/bin/start.sh && \
    echo 'echo "Ejecutando migraciones..."' >> /usr/local/bin/start.sh && \
    echo 'php artisan migrate --force || echo "⚠️ Migraciones fallaron, continuando..."' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# Iniciar Supervisor' >> /usr/local/bin/start.sh && \
    echo 'echo "Iniciando Supervisor..."' >> /usr/local/bin/start.sh && \
    echo 'exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf' >> /usr/local/bin/start.sh

RUN chmod +x /usr/local/bin/start.sh

EXPOSE 8080

CMD ["/usr/local/bin/start.sh"]