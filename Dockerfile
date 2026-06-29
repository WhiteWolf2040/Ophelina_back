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
    && mkdir -p /var/www/html/public \
    && mkdir -p /var/www/html/database

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar archivos del proyecto
COPY . .

# Instalar dependencias (SIN --no-dev para poder generar clave)
RUN composer install --optimize-autoloader --no-interaction

# Optimizar Laravel
RUN php artisan config:cache || true \
    && php artisan route:cache || true \
    && php artisan view:cache || true

# Configurar permisos
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database

# Configuración de Nginx (PUERTO 8080)
RUN echo 'user www-data;' > /etc/nginx/nginx.conf && \
    echo 'worker_processes auto;' >> /etc/nginx/nginx.conf && \
    echo 'pid /run/nginx.pid;' >> /etc/nginx/nginx.conf && \
    echo '' >> /etc/nginx/nginx.conf && \
    echo 'events {' >> /etc/nginx/nginx.conf && \
    echo '    worker_connections 1024;' >> /etc/nginx/nginx.conf && \
    echo '}' >> /etc/nginx/nginx.conf && \
    echo '' >> /etc/nginx/nginx.conf && \
    echo 'http {' >> /etc/nginx/nginx.conf && \
    echo '    include /etc/nginx/mime.types;' >> /etc/nginx/nginx.conf && \
    echo '    default_type application/octet-stream;' >> /etc/nginx/nginx.conf && \
    echo '    sendfile on;' >> /etc/nginx/nginx.conf && \
    echo '    keepalive_timeout 65;' >> /etc/nginx/nginx.conf && \
    echo '' >> /etc/nginx/nginx.conf && \
    echo '    server {' >> /etc/nginx/nginx.conf && \
    echo '        listen 8080;' >> /etc/nginx/nginx.conf && \
    echo '        server_name _;' >> /etc/nginx/nginx.conf && \
    echo '        root /var/www/html/public;' >> /etc/nginx/nginx.conf && \
    echo '        index index.php;' >> /etc/nginx/nginx.conf && \
    echo '        charset utf-8;' >> /etc/nginx/nginx.conf && \
    echo '' >> /etc/nginx/nginx.conf && \
    echo '        location / {' >> /etc/nginx/nginx.conf && \
    echo '            try_files $uri $uri/ /index.php?$query_string;' >> /etc/nginx/nginx.conf && \
    echo '        }' >> /etc/nginx/nginx.conf && \
    echo '' >> /etc/nginx/nginx.conf && \
    echo '        location ~ \.php$ {' >> /etc/nginx/nginx.conf && \
    echo '            fastcgi_pass 127.0.0.1:9000;' >> /etc/nginx/nginx.conf && \
    echo '            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;' >> /etc/nginx/nginx.conf && \
    echo '            include fastcgi_params;' >> /etc/nginx/nginx.conf && \
    echo '        }' >> /etc/nginx/nginx.conf && \
    echo '    }' >> /etc/nginx/nginx.conf && \
    echo '}' >> /etc/nginx/nginx.conf

# Configuración de Supervisor
RUN echo '[supervisord]' > /etc/supervisor/conf.d/supervisord.conf && \
    echo 'nodaemon=true' >> /etc/supervisor/conf.d/supervisord.conf && \
    echo 'logfile=/dev/null' >> /etc/supervisor/conf.d/supervisord.conf && \
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
# SCRIPT DE ARRANQUE (CORREGIDO)
# ==========================================
RUN echo '#!/bin/sh' > /usr/local/bin/start.sh && \
    echo 'set -e' >> /usr/local/bin/start.sh && \
    echo 'echo "=== INICIANDO SERVIDOR ==="' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# === LIMPIAR CACHÉ ANTES DE GENERAR .env ===' >> /usr/local/bin/start.sh && \
    echo 'rm -rf /var/www/html/bootstrap/cache/config.php' >> /usr/local/bin/start.sh && \
    echo 'rm -rf /var/www/html/bootstrap/cache/packages.php' >> /usr/local/bin/start.sh && \
    echo 'rm -rf /var/www/html/bootstrap/cache/routes-v7.php' >> /usr/local/bin/start.sh && \
    echo 'echo "✅ Archivos de caché eliminados"' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# === ELIMINAR .env ANTERIOR ===' >> /usr/local/bin/start.sh && \
    echo 'rm -f /var/www/html/.env' >> /usr/local/bin/start.sh && \
    echo 'echo "✅ .env eliminado"' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# FORZAR CACHE Y SESSION A FILE' >> /usr/local/bin/start.sh && \
    echo 'export CACHE_DRIVER=file' >> /usr/local/bin/start.sh && \
    echo 'export SESSION_DRIVER=file' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# Generar .env desde variables de entorno' >> /usr/local/bin/start.sh && \
    echo 'echo "Generando .env desde variables de entorno..."' >> /usr/local/bin/start.sh && \
    echo 'cat > /var/www/html/.env << "ENVEOF"' >> /usr/local/bin/start.sh && \
    echo 'APP_NAME=Laravel' >> /usr/local/bin/start.sh && \
    echo 'APP_ENV=production' >> /usr/local/bin/start.sh && \
    echo 'APP_KEY=${APP_KEY}' >> /usr/local/bin/start.sh && \
    echo 'APP_DEBUG=true' >> /usr/local/bin/start.sh && \
    echo 'APP_URL=https://ophelina-back-v1.onrender.com' >> /usr/local/bin/start.sh && \
    echo 'DB_CONNECTION=pgsql' >> /usr/local/bin/start.sh && \
    echo 'DB_HOST=dpg-d90rptf7f7vs73ct7nig-a.oregon-postgres.render.com' >> /usr/local/bin/start.sh && \
    echo 'DB_PORT=5432' >> /usr/local/bin/start.sh && \
    echo 'DB_DATABASE=Ophelina_v1_despliegue' >> /usr/local/bin/start.sh && \
    echo 'DB_USERNAME=root' >> /usr/local/bin/start.sh && \
    echo 'DB_PASSWORD=v11zeZoEmBpuvEgGq9D1aD71bvu0BLB5' >> /usr/local/bin/start.sh && \
    echo 'CACHE_DRIVER=file' >> /usr/local/bin/start.sh && \
    echo 'SESSION_DRIVER=file' >> /usr/local/bin/start.sh && \
    echo 'ENVEOF' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo 'echo "✅ .env generado con PostgreSQL"' >> /usr/local/bin/start.sh && \
    echo 'cat /var/www/html/.env | grep DB_' >> /usr/local/bin/start.sh && \
    echo 'cat /var/www/html/.env | grep CACHE_' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# Crear archivo SQLite para caché' >> /usr/local/bin/start.sh && \
    echo 'touch /var/www/html/database/database.sqlite' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# Limpiar caché de Laravel (sin optimize:clear)' >> /usr/local/bin/start.sh && \
    echo 'cd /var/www/html' >> /usr/local/bin/start.sh && \
    echo 'php artisan config:clear' >> /usr/local/bin/start.sh && \
    echo 'php artisan view:clear' >> /usr/local/bin/start.sh && \
    echo 'php artisan route:clear' >> /usr/local/bin/start.sh && \
    echo 'echo "✅ Cachés limpiadas"' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# Recargar configuración' >> /usr/local/bin/start.sh && \
    echo 'php artisan config:cache' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# ✅ EJECUTAR MIGRACIONES (CREA TABLAS DESDE CERO)' >> /usr/local/bin/start.sh && \
    echo 'php artisan migrate:fresh --force || echo "⚠️ Migraciones fallaron"' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# Iniciar Supervisor' >> /usr/local/bin/start.sh && \
    echo 'exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf' >> /usr/local/bin/start.sh

RUN chmod +x /usr/local/bin/start.sh

EXPOSE 8080

CMD ["/usr/local/bin/start.sh"]