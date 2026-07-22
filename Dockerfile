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
    postgresql-client \
    && docker-php-ext-install pdo pdo_pgsql bcmath gd

# Crear directorios necesarios
RUN mkdir -p /etc/supervisor/conf.d \
    && mkdir -p /var/log/supervisor \
    && mkdir -p /run/nginx \
    && mkdir -p /var/www/html/public \
    && mkdir -p /var/www/html/database \
    && mkdir -p /var/www/html/storage/framework/{sessions,views,cache}

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar archivos del proyecto
COPY . .

# Instalar dependencias
RUN composer install --optimize-autoloader --no-interaction --no-dev

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
    echo '' >> /etc/nginx/nginx.conf && \
    echo '        location ~ /\.ht {' >> /etc/nginx/nginx.conf && \
    echo '            deny all;' >> /etc/nginx/nginx.conf && \
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
# SCRIPT DE ARRANQUE CORREGIDO - USANDO ENV VARS
# ==========================================
RUN echo '#!/bin/sh' > /usr/local/bin/start.sh && \
    echo 'set -e' >> /usr/local/bin/start.sh && \
    echo 'echo "=== 🚀 INICIANDO SERVIDOR ==="' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# === VERIFICAR VARIABLES DE ENTORNO ===' >> /usr/local/bin/start.sh && \
    echo 'if [ -z "$DB_PASSWORD" ]; then' >> /usr/local/bin/start.sh && \
    echo '    echo "❌ ERROR: DB_PASSWORD no está definida en variables de entorno"' >> /usr/local/bin/start.sh && \
    echo '    exit 1' >> /usr/local/bin/start.sh && \
    echo 'fi' >> /usr/local/bin/start.sh && \
    echo 'echo "✅ Variables de entorno verificadas"' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# === VERIFICAR CONEXIÓN A POSTGRESQL ===' >> /usr/local/bin/start.sh && \
    echo 'echo "=== ESPERANDO BASE DE DATOS ==="' >> /usr/local/bin/start.sh && \
    echo 'MAX_RETRIES=60' >> /usr/local/bin/start.sh && \
    echo 'RETRY_COUNT=0' >> /usr/local/bin/start.sh && \
    echo 'until pg_isready -h ${DB_HOST} -p ${DB_PORT} -U ${DB_USERNAME} || [ $RETRY_COUNT -eq $MAX_RETRIES ]; do' >> /usr/local/bin/start.sh && \
    echo '    RETRY_COUNT=$((RETRY_COUNT+1))' >> /usr/local/bin/start.sh && \
    echo '    echo "⏳ Esperando PostgreSQL... $RETRY_COUNT/$MAX_RETRIES"' >> /usr/local/bin/start.sh && \
    echo '    sleep 3' >> /usr/local/bin/start.sh && \
    echo 'done' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo 'if [ $RETRY_COUNT -eq $MAX_RETRIES ]; then' >> /usr/local/bin/start.sh && \
    echo '    echo "❌ ERROR: PostgreSQL no está listo después de $MAX_RETRIES intentos"' >> /usr/local/bin/start.sh && \
    echo '    exit 1' >> /usr/local/bin/start.sh && \
    echo 'fi' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo 'sleep 5' >> /usr/local/bin/start.sh && \
    echo 'echo "✅ PostgreSQL está listo"' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# === LIMPIAR CACHÉ ===' >> /usr/local/bin/start.sh && \
    echo 'rm -rf /var/www/html/bootstrap/cache/*.php' >> /usr/local/bin/start.sh && \
    echo 'echo "🧹 Caché limpiada"' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# === GENERAR .env ===' >> /usr/local/bin/start.sh && \
    echo 'cat > /var/www/html/.env << "ENVEOF"' >> /usr/local/bin/start.sh && \
    echo 'APP_NAME=Laravel' >> /usr/local/bin/start.sh && \
    echo 'APP_ENV=production' >> /usr/local/bin/start.sh && \
    echo 'APP_KEY=${APP_KEY}' >> /usr/local/bin/start.sh && \
    echo 'APP_DEBUG=false' >> /usr/local/bin/start.sh && \
    echo 'APP_URL=${APP_URL}' >> /usr/local/bin/start.sh && \
    echo 'DB_CONNECTION=pgsql' >> /usr/local/bin/start.sh && \
    echo 'DB_HOST=${DB_HOST}' >> /usr/local/bin/start.sh && \
    echo 'DB_PORT=${DB_PORT}' >> /usr/local/bin/start.sh && \
    echo 'DB_DATABASE=${DB_DATABASE}' >> /usr/local/bin/start.sh && \
    echo 'DB_USERNAME=${DB_USERNAME}' >> /usr/local/bin/start.sh && \
    echo 'DB_PASSWORD=${DB_PASSWORD}' >> /usr/local/bin/start.sh && \
    echo 'DB_SCHEMA=public' >> /usr/local/bin/start.sh && \
    echo 'CACHE_DRIVER=file' >> /usr/local/bin/start.sh && \
    echo 'SESSION_DRIVER=file' >> /usr/local/bin/start.sh && \
    echo 'ENVEOF' >> /usr/local/bin/start.sh && \
    echo 'echo "📄 .env generado"' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# === GENERAR APP_KEY ===' >> /usr/local/bin/start.sh && \
    echo 'cd /var/www/html' >> /usr/local/bin/start.sh && \
    echo 'if [ -z "$APP_KEY" ]; then' >> /usr/local/bin/start.sh && \
    echo '    php artisan key:generate --force && echo "🔑 App Key generada"' >> /usr/local/bin/start.sh && \
    echo 'else' >> /usr/local/bin/start.sh && \
    echo '    echo "✅ App Key ya existe"' >> /usr/local/bin/start.sh && \
    echo 'fi' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo 'echo "=== DEBUG: Verificando PGPASSWORD ==="' >> /usr/local/bin/start.sh && \
echo 'export PGPASSWORD="${DB_PASSWORD}"' >> /usr/local/bin/start.sh && \
echo 'echo "PGPASSWORD length: ${#PGPASSWORD}"' >> /usr/local/bin/start.sh && \
    echo '# === PRUEBA DE CONEXIÓN ===' >> /usr/local/bin/start.sh && \
    echo 'echo "=== PRUEBA DE CONEXIÓN A LA BD ==="' >> /usr/local/bin/start.sh && \
    echo 'export PGPASSWORD="${DB_PASSWORD}"' >> /usr/local/bin/start.sh && \
    echo 'if psql -h "${DB_HOST}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "SELECT 1" > /dev/null 2>&1; then' >> /usr/local/bin/start.sh && \
    echo '    echo "✅ Conexión a la base de datos exitosa"' >> /usr/local/bin/start.sh && \
    echo 'else' >> /usr/local/bin/start.sh && \
    echo '    echo "❌ ERROR: No se puede conectar a la base de datos"' >> /usr/local/bin/start.sh && \
    echo '    echo "=== DETALLES ==="' >> /usr/local/bin/start.sh && \
    echo '    echo "Host: ${DB_HOST}"' >> /usr/local/bin/start.sh && \
    echo '    echo "User: ${DB_USERNAME}"' >> /usr/local/bin/start.sh && \
    echo '    echo "Database: ${DB_DATABASE}"' >> /usr/local/bin/start.sh && \
    echo '    psql -h "${DB_HOST}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "SELECT 1" 2>&1' >> /usr/local/bin/start.sh && \
    echo '    unset PGPASSWORD' >> /usr/local/bin/start.sh && \
    echo '    exit 1' >> /usr/local/bin/start.sh && \
    echo 'fi' >> /usr/local/bin/start.sh && \
    echo 'unset PGPASSWORD' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# === CREAR SCHEMA PUBLIC ===' >> /usr/local/bin/start.sh && \
    echo 'export PGPASSWORD="${DB_PASSWORD}"' >> /usr/local/bin/start.sh && \
    echo 'echo "📁 Verificando/Creando schema public..."' >> /usr/local/bin/start.sh && \
    echo 'psql -h "${DB_HOST}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "CREATE SCHEMA IF NOT EXISTS public;" 2>/dev/null || true' >> /usr/local/bin/start.sh && \
    echo 'psql -h "${DB_HOST}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "ALTER DATABASE ${DB_DATABASE} SET search_path TO public;" 2>/dev/null || true' >> /usr/local/bin/start.sh && \
    echo 'unset PGPASSWORD' >> /usr/local/bin/start.sh && \
  echo 'echo "✅ Schema public listo"' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
echo '# === ELIMINAR TODOS LOS ÍNDICES ===' >> /usr/local/bin/start.sh && \
echo 'export PGPASSWORD="${DB_PASSWORD}"' >> /usr/local/bin/start.sh && \
echo 'echo "🗑️ Eliminando todos los índices de la base de datos..."' >> /usr/local/bin/start.sh && \
echo 'psql -h "${DB_HOST}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -t -c "' >> /usr/local/bin/start.sh && \
echo '  SELECT indexname' >> /usr/local/bin/start.sh && \
echo '  FROM pg_indexes' >> /usr/local/bin/start.sh && \
echo '  WHERE schemaname = '\''public'\''' >> /usr/local/bin/start.sh && \
echo '  AND indexname NOT LIKE '\''%pkey%'\''' >> /usr/local/bin/start.sh && \
echo '  AND indexname NOT LIKE '\''%unique%'\''' >> /usr/local/bin/start.sh && \
echo '" | while read idx; do' >> /usr/local/bin/start.sh && \
echo '    if [ -n "$idx" ]; then' >> /usr/local/bin/start.sh && \
echo '        echo "  Eliminando índice: $idx"' >> /usr/local/bin/start.sh && \
echo '        psql -h "${DB_HOST}" -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "DROP INDEX IF EXISTS $idx;" 2>/dev/null || true' >> /usr/local/bin/start.sh && \
echo '    fi' >> /usr/local/bin/start.sh && \
echo 'done' >> /usr/local/bin/start.sh && \
echo 'unset PGPASSWORD' >> /usr/local/bin/start.sh && \
echo 'echo "✅ Todos los índices eliminados"' >> /usr/local/bin/start.sh && \
echo '' >> /usr/local/bin/start.sh && \
    echo '# === MIGRACIONES ===' >> /usr/local/bin/start.sh && \
    echo 'echo "=== EJECUTANDO MIGRACIONES ==="' >> /usr/local/bin/start.sh && \
    echo 'cd /var/www/html' >> /usr/local/bin/start.sh && \
    echo 'if php artisan migrate --force; then' >> /usr/local/bin/start.sh && \
    echo '    echo "✅ Migraciones ejecutadas correctamente"' >> /usr/local/bin/start.sh && \
    echo 'else' >> /usr/local/bin/start.sh && \
    echo '    echo "❌ ERROR: Fallaron las migraciones"' >> /usr/local/bin/start.sh && \
    echo '    php artisan migrate --force 2>&1' >> /usr/local/bin/start.sh && \
    echo '    exit 1' >> /usr/local/bin/start.sh && \
    echo 'fi' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# === LIMPIAR CONFIGURACIÓN ===' >> /usr/local/bin/start.sh && \
    echo 'php artisan config:clear' >> /usr/local/bin/start.sh && \
    echo 'php artisan view:clear' >> /usr/local/bin/start.sh && \
    echo 'php artisan route:clear' >> /usr/local/bin/start.sh && \
    echo 'php artisan config:cache' >> /usr/local/bin/start.sh && \
    echo 'echo "⚙️ Configuración optimizada"' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# === SEEDER ===' >> /usr/local/bin/start.sh && \
    echo 'echo "=== INSERTANDO DATOS INICIALES ==="' >> /usr/local/bin/start.sh && \
    echo 'php artisan db:seed --class=ImportarDatosSeeder --force || echo "⚠️ Error en seeder (continuando...)"' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# === CREDENCIALES ===' >> /usr/local/bin/start.sh && \
    echo 'echo "======================================"' >> /usr/local/bin/start.sh && \
    echo 'echo "🔐 CREDENCIALES DE ACCESO"' >> /usr/local/bin/start.sh && \
    echo 'echo "======================================"' >> /usr/local/bin/start.sh && \
    echo 'echo "📧 juanprendas@admin.com"' >> /usr/local/bin/start.sh && \
    echo 'echo "📧 tulaempeños@admin.com"' >> /usr/local/bin/start.sh && \
    echo 'echo "📧 expressempeños@admin.com"' >> /usr/local/bin/start.sh && \
    echo 'echo "🔑 password"' >> /usr/local/bin/start.sh && \
    echo 'echo "======================================"' >> /usr/local/bin/start.sh && \
    echo '' >> /usr/local/bin/start.sh && \
    echo '# === INICIAR SUPERVISOR ===' >> /usr/local/bin/start.sh && \
    echo 'echo "=== 🟢 SERVIDOR LISTO ==="' >> /usr/local/bin/start.sh && \
    echo 'exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf' >> /usr/local/bin/start.sh

RUN chmod +x /usr/local/bin/start.sh

EXPOSE 8080

CMD ["/usr/local/bin/start.sh"]