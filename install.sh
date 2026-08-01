#!/usr/bin/env bash
# Instalador de IPTV·WATCH en un servidor LAMP.
# Ejecutar como root, desde dentro de esta misma carpeta:
#   chmod +x install.sh && ./install.sh

set -euo pipefail

APP_DIR_DEFAULT="/var/www/html"
DB_NAME_DEFAULT="iptvwatch"
DB_USER_DEFAULT="iptvwatch_user"

echo "== IPTV·WATCH — instalador =="
echo "Este script crea la base de datos, copia los archivos, configura permisos y el cron."
echo

read -rp "Directorio de instalación [$APP_DIR_DEFAULT]: " APP_DIR
APP_DIR="${APP_DIR:-$APP_DIR_DEFAULT}"

read -rp "Nombre de la base de datos [$DB_NAME_DEFAULT]: " DB_NAME
DB_NAME="${DB_NAME:-$DB_NAME_DEFAULT}"

read -rp "Usuario MySQL a crear [$DB_USER_DEFAULT]: " DB_USER
DB_USER="${DB_USER:-$DB_USER_DEFAULT}"

DB_PASS_AUTO="$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 24)"
read -rp "Contraseña MySQL [presiona Enter para generar una aleatoria]: " DB_PASS
DB_PASS="${DB_PASS:-$DB_PASS_AUTO}"

ADMIN_USER_DEFAULT="admin"
read -rp "Usuario admin del panel [$ADMIN_USER_DEFAULT]: " ADMIN_USER
ADMIN_USER="${ADMIN_USER:-$ADMIN_USER_DEFAULT}"

while true; do
    read -rsp "Contraseña del admin del panel: " ADMIN_PASS
    echo
    if [[ -z "$ADMIN_PASS" ]]; then
        echo "La contraseña no puede quedar vacía."
        continue
    fi
    read -rsp "Confirma la contraseña: " ADMIN_PASS_CONFIRM
    echo
    if [[ "$ADMIN_PASS" != "$ADMIN_PASS_CONFIRM" ]]; then
        echo "Las contraseñas no coinciden, intenta de nuevo."
        continue
    fi
    break
done
unset ADMIN_PASS_CONFIRM

echo
echo "Resumen:"
echo "  Directorio        : $APP_DIR"
echo "  Base datos        : $DB_NAME"
echo "  Usuario DB        : $DB_USER"
echo "  Usuario admin panel: $ADMIN_USER"
echo
read -rp "¿Continuar? [s/N]: " CONFIRM
if [[ "${CONFIRM,,}" != "s" ]]; then
    echo "Cancelado."
    exit 0
fi

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "== Copiando archivos a $APP_DIR =="
mkdir -p "$APP_DIR"
cp -r "$SRC_DIR/api" "$APP_DIR/"
cp -r "$SRC_DIR/includes" "$APP_DIR/"
cp -r "$SRC_DIR/cron" "$APP_DIR/"
cp "$SRC_DIR/index.html" "$APP_DIR/index.html"
cp "$SRC_DIR/login.html" "$APP_DIR/login.html"
cp "$SRC_DIR/register.html" "$APP_DIR/register.html"
cp "$SRC_DIR/logo.png" "$APP_DIR/logo.png"

echo "== Escribiendo config.php =="
# Llave para cifrar la contraseña de sesión del panel XUI·ONE (ver
# includes/Crypto.php) — única por instalación, nunca se reutiliza.
XUI_SESSION_ENC_KEY="$(php -r 'echo base64_encode(random_bytes(32));')"
cat > "$APP_DIR/config.php" <<PHP
<?php
return [
    'db_host' => 'localhost',
    'db_name' => '$DB_NAME',
    'db_user' => '$DB_USER',
    'db_pass' => '$DB_PASS',
    'xui_session_enc_key' => '$XUI_SESSION_ENC_KEY',
];
PHP

# .htaccess: bloqueo de respaldo si el AllowOverride del VirtualHost lo
# permite. No es la protección principal (ver más abajo, "Bloqueando acceso
# HTTP directo a config.php"), que no depende de esto — es solo una capa
# extra por si acaso.
cat > "$APP_DIR/.htaccess" <<'HTACCESS'
<Files "config.php">
    Require all denied
</Files>
HTACCESS

echo "== Creando base de datos y usuario MySQL =="
mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "== Importando esquema =="
mysql -u root "$DB_NAME" < "$SRC_DIR/sql/schema.sql"

echo "== Creando usuario admin del panel =="
# La contraseña se pasa por variable de entorno (no como argumento de php -r)
# para que no quede visible en la lista de procesos (ps aux) del servidor.
# role='admin', status='approved': siempre debe quedar al menos una cuenta
# ya utilizable, capaz de aprobar los registros que lleguen después desde
# el módulo de administración (api/admin_users.php).
ADMIN_HASH="$(ADMIN_PASS="$ADMIN_PASS" php -r 'echo password_hash(getenv("ADMIN_PASS"), PASSWORD_DEFAULT);')"
unset ADMIN_PASS
mysql -u root "$DB_NAME" <<SQL
INSERT INTO users (username, password_hash, role, status) VALUES ('$ADMIN_USER', '$ADMIN_HASH', 'admin', 'approved')
ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), role='admin', status='approved';
SQL

echo "== Ajustando permisos =="
WEB_USER="www-data"
if id "apache" &>/dev/null && ! id "www-data" &>/dev/null; then
    WEB_USER="apache"
fi
chown -R "$WEB_USER":"$WEB_USER" "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 750 {} \;
find "$APP_DIR" -type f -exec chmod 640 {} \;

echo "== Configurando cron (cada 1 minuto) =="
# Corre cada minuto en vez de cada 5: con proveedores configurados a intervalos
# cortos (ej. 5 min), un cron de 5 min puede tardar hasta el doble de lo esperado
# en detectar que ya toca sincronizar, por el desfase entre next_check_at y el
# propio reloj del cron. El script sale rápido cuando no hay nada pendiente.
CRON_LINE="* * * * * php $APP_DIR/cron/check_providers.php >> $APP_DIR/cron/cron.log 2>&1"
( crontab -l 2>/dev/null | grep -v "check_providers.php" || true; echo "$CRON_LINE" ) | crontab -

touch "$APP_DIR/cron/cron.log"
chown "$WEB_USER":"$WEB_USER" "$APP_DIR/cron/cron.log"

echo "== Habilitando compresión gzip para respuestas JSON =="
DEFLATE_CONF="/etc/apache2/mods-enabled/deflate.conf"
if command -v a2enmod &>/dev/null; then
    a2enmod deflate &>/dev/null || true
fi
if [[ -f "$DEFLATE_CONF" ]]; then
    if ! grep -q 'application/json' "$DEFLATE_CONF"; then
        cp "$DEFLATE_CONF" "$DEFLATE_CONF.bak"
        sed -i '/AddOutputFilterByType DEFLATE application\/xml/a\    AddOutputFilterByType DEFLATE application/json' "$DEFLATE_CONF"
        if apachectl configtest &>/dev/null; then
            systemctl reload apache2
            echo "  Gzip para JSON habilitado (api/*.php ahora se comprime)."
        else
            echo "  ADVERTENCIA: la configuración de Apache quedó inválida tras el cambio;"
            echo "  se restauró desde el backup. Revisa $DEFLATE_CONF manualmente."
            cp "$DEFLATE_CONF.bak" "$DEFLATE_CONF"
        fi
    else
        echo "  Ya estaba habilitado, no se tocó nada."
    fi
else
    echo "  No se encontró $DEFLATE_CONF (¿Nginx, o Apache en otra ruta?)."
    echo "  Agrega manualmente 'AddOutputFilterByType DEFLATE application/json' a tu"
    echo "  configuración de compresión para que api/channels.php viaje comprimido."
fi

echo "== Bloqueando acceso HTTP directo a config.php (protección real, en Apache) =="
# Esta es la protección que importa — no depende de que AllowOverride esté
# habilitado (el .htaccess de arriba es solo un respaldo si lo está). Hoy
# config.php no imprime nada al pedirlo por HTTP (es un simple "return"),
# pero esta regla evita depender de que eso siga siendo así para siempre —
# un backup mal restaurado, un cambio de configuración, o abrirlo con un
# manejador que no sea PHP dejaría la contraseña de la BD y la llave de
# cifrado en texto plano sin esto.
SECURITY_CONF="/etc/apache2/conf-available/iptv-watch-security.conf"
if command -v a2enconf &>/dev/null; then
    cat > "$SECURITY_CONF" <<APACHECONF
<Directory "$APP_DIR">
    <Files "config.php">
        Require all denied
    </Files>
</Directory>
<Directory "$APP_DIR/uploads">
    Require all denied
</Directory>
APACHECONF
    a2enconf iptv-watch-security &>/dev/null || true
    if apachectl configtest &>/dev/null; then
        systemctl reload apache2

        # Verificación real (no solo "debería funcionar"): si el directorio
        # instalado está dentro del DocumentRoot por defecto, se prueba con
        # una petición HTTP de verdad.
        DOCROOT_GUESS="/var/www/html"
        if [[ "$APP_DIR" == "$DOCROOT_GUESS"* ]] && command -v curl &>/dev/null; then
            WEB_PATH="${APP_DIR#$DOCROOT_GUESS}"
            TMP_BODY="$(mktemp)"
            HTTP_CODE=$(curl -s -o "$TMP_BODY" -w "%{http_code}" "http://localhost${WEB_PATH}/config.php" || echo "000")
            if [[ "$HTTP_CODE" == "403" ]]; then
                echo "  Verificado con una petición real: config.php responde 403 Forbidden. Bloqueo activo."
            elif grep -q "db_pass" "$TMP_BODY" 2>/dev/null; then
                echo "  ¡ALERTA! config.php respondió su contenido en texto plano (HTTP $HTTP_CODE)."
                echo "  Esto expone la contraseña de la base de datos — revisa la configuración de Apache ya mismo."
            else
                echo "  config.php respondió HTTP $HTTP_CODE (sin exponer contenido, pero sin devolver 403 tampoco — revísalo tú mismo)."
            fi
            rm -f "$TMP_BODY"
        else
            echo "  No se pudo verificar con una petición real (ruta fuera de $DOCROOT_GUESS) —"
            echo "  confirma tú mismo que 'curl -I http://TU_DOMINIO_O_IP/config.php' responda 403."
        fi
    else
        echo "  ADVERTENCIA: la regla dejó la configuración de Apache inválida; se deshabilitó."
        a2disconf iptv-watch-security &>/dev/null || true
        rm -f "$SECURITY_CONF"
        systemctl reload apache2 &>/dev/null || true
    fi
else
    echo "  No se encontró a2enconf (¿no es Apache en Debian/Ubuntu?). Agrega manualmente:"
    echo "    <Directory \"$APP_DIR\"><Files \"config.php\">Require all denied</Files></Directory>"
    echo "  a tu configuración del servidor y recarga."
fi

echo
echo "== Instalación completa =="
echo "  App              : $APP_DIR  (servido como index.html)"
echo "  Base datos       : $DB_NAME"
echo "  Usuario DB       : $DB_USER"
echo "  Contraseña DB    : $DB_PASS"
echo "  Usuario admin panel: $ADMIN_USER (la contraseña es la que ingresaste, no se vuelve a mostrar)"
echo
echo "Guarda la contraseña de la base de datos ahora; no volverá a mostrarse."
if [[ "$APP_DIR" == "/var/www/html" ]]; then
    echo "Accede vía http://TU_IP_O_DOMINIO/ (instalaste directo en el DocumentRoot)."
else
    echo "Verifica que exista un VirtualHost/DocumentRoot apuntando a $APP_DIR,"
    echo "o accede vía la subruta correspondiente si está dentro del DocumentRoot general."
fi
echo "Revisa el log de cron en: $APP_DIR/cron/cron.log"
