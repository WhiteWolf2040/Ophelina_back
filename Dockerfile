# syntax=docker/dockerfile:1.4

FROM php:8.4-fpm-alpine

# ==========================================
# INSTALAR DEPENDENCIAS (INCLUYENDO SUPERVISOR)
# ==========================================
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

# ==========================================
# CREAR DIRECTORIOS NECESARIOS PARA SUPERVISOR
# ==========================================
RUN mkdir -p /etc/supervisor/conf.d \
    && mkdir -p /var/log/supervisor

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar archivos del proyecto
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
# CONFIGURACIÓN DE NGINX
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
    echo 'logfile=/dev/null' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'logfile_maxbytes=0' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'pidfile=/run/supervisord.pid' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo '' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo '[program:php-fpm]' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'command=php-fpm' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stdout_logfile=/dev/stdout' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stdout_logfile_maxbytes=0' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stderr_logfile=/dev/stderr' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stderr_logfile_maxbytes=0' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo '' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo '[program:nginx]' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'command=nginx -g "daemon off;"' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stdout_logfile=/dev/stdout' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stdout_logfile_maxbytes=0' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stderr_logfile=/dev/stderr' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'stderr_logfile_maxbytes=0' >> /etc/supervisor/conf.d/supervisord.conf

# ==========================================
# SCRIPT DE ARRANQUE
# ==========================================
RUN echo '#!/bin/sh' > /usr/local/bin/start.sh && \
    echo 'echo "=== INICIANDO SERVIDOR EN PUERTO: $PORT ==="' >> /usr/local/bin/start.sh && \
    echo 'echo "Configurando Nginx para escuchar en el puerto $PORT..."' >> /usr/local/bin/start.sh && \
    echo 'cat > /etc/nginx/nginx.conf << "NGINXEOF"' >> /usr/local/bin/start.sh && \
    echo 'user www-data;' >> /usr/local/bin/start.sh && \
    echo 'worker_processes auto;' >> /usr/local/bin/start.sh && \
    echo 'pid /run/nginx.pid;' >> /usr/local/bin/start.sh && \
    echo 'events {' >> /usr/local/bin/start.sh && \
    echo '    worker_connections 1024;' >> /usr/local/bin/start.sh && \
    echo '}' >> /usr/local/bin/start.sh && \
    echo 'http {' >> /usr/local/bin/start.sh && \
    echo '    include /etc/nginx/mime.types;' >> /usr/local/bin/start.sh && \
    echo '    default_type application/octet-stream;' >> /usr/local/bin/start.sh && \
    echo '    sendfile on;' >> /usr/local/bin/start.sh && \
    echo '    keepalive_timeout 65;' >> /usr/local/bin/start.sh && \
    echo '    server {' >> /usr/local/bin/start.sh && \
    echo '        listen $PORT;' >> /usr/local/bin/start.sh && \
    echo '        server_name _;' >> /usr/local/bin/start.sh && \
    echo '        root /var/www/html/public;' >> /usr/local/bin/start.sh && \
    echo '        add_header X-Frame-Options "SAMEORIGIN";' >> /usr/local/bin/start.sh && \
    echo '        add_header X-Content-Type-Options "nosniff";' >> /usr/local/bin/start.sh && \
    echo '        index index.php;' >> /usr/local/bin/start.sh && \
    echo '        charset utf-8;' >> /usr/local/bin/start.sh && \
    echo '        location / {' >> /usr/local/bin/start.sh && \
    echo '            try_files $$uri $$uri/ /index.php?$$query_string;' >> /usr/local/bin/start.sh && \
    echo '        }' >> /usr/local/bin/start.sh && \
    echo '        location = /favicon.ico { access_log off; log_not_found off; }' >> /usr/local/bin/start.sh && \
    echo '        location = /robots.txt { access_log off; log_not_found off; }' >> /usr/local/bin/start.sh && \
    echo '        error_page 404 /index.php;' >> /usr/local/bin/start.sh && \
    echo '        location ~ \.php$$ {' >> /usr/local/bin/start.sh && \
    echo '            fastcgi_pass 127.0.0.1:9000;' >> /usr/local/bin/start.sh && \
    echo '            fastcgi_param SCRIPT_FILENAME $$realpath_root$$fastcgi_script_name;' >> /usr/local/bin/start.sh && \
    echo '            fastcgi_param PATH_INFO $$fastcgi_path_info;' >> /usr/local/bin/start.sh && \
    echo '            include fastcgi_params;' >> /usr/local/bin/start.sh && \
    echo '        }' >> /usr/local/bin/start.sh && \
    echo '        location ~ /\.ht { deny all; }' >> /usr/local/bin/start.sh && \
    echo '    }' >> /usr/local/bin/start.sh && \
    echo '}' >> /usr/local/bin/start.sh && \
    echo 'NGINXEOF' >> /usr/local/bin/start.sh && \
    echo 'if [ -n "$DB_CONNECTION" ]; then' >> /usr/local/bin/start.sh && \
    echo '    echo "Ejecutando migraciones..."' >> /usr/local/bin/start.sh && \
    echo '    php artisan migrate --force || echo "Migraciones fallaron, continuando..."' >> /usr/local/bin/start.sh && \
    echo 'fi' >> /usr/local/bin/start.sh && \
    echo 'exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf' >> /usr/local/bin/start.sh

RUN chmod +x /usr/local/bin/start.sh

EXPOSE 8080

CMD ["/usr/local/bin/start.sh"]