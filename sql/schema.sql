-- IPTV·WATCH — esquema de base de datos (multi-usuario)
-- Ejecutar dentro de la base de datos ya creada (ver install.sh)

SET NAMES utf8mb4;

-- Usuarios del panel (autenticación local). Cada usuario administra sus
-- propios proveedores/categorías/canales/conexión XUI·ONE/Telegram —
-- ver "user_id" en las tablas de abajo.
--
-- "role": 'admin' puede aprobar/deshabilitar cuentas desde el módulo de
-- administración, pero NUNCA ve los datos privados (proveedores, canales,
-- etc.) de otro usuario — solo la lista de cuentas y su estado.
-- "status": una cuenta nueva (api/register.php) nace en 'pending' y no
-- puede iniciar sesión hasta que un admin la pasa a 'approved'.
-- 'disabled' bloquea el login sin borrar los datos del usuario.
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','user') NOT NULL DEFAULT 'user',
    status ENUM('pending','approved','disabled') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS providers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    m3u_url TEXT NOT NULL,
    check_interval_minutes INT UNSIGNED NOT NULL DEFAULT 60,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_checked_at DATETIME NULL,
    next_check_at DATETIME NULL,
    last_sync_status ENUM('ok','error','running') NULL,
    last_error TEXT NULL,
    -- Reintentos seguidos fallidos desde el último éxito (includes/Sync.php).
    -- Se resetea a 0 en cada sincronización exitosa; cada fallo suma 1 y
    -- alarga el próximo reintento (backoff progresivo con techo, no siempre
    -- los mismos 5 minutos fijos para un proveedor que lleva días caído).
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (user_id),
    CONSTRAINT fk_providers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El nombre de categoría ya no es único de forma global — cada usuario
-- puede tener su propia categoría "Deportes" sin chocar con la de otro.
CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    icon VARCHAR(10) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_category (user_id, name),
    CONSTRAINT fk_categories_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nota: "Sin clasificar" ya NO se pre-inserta aquí. Se crea sola, de forma
-- perezosa, la primera vez que un canal sin group-title lo necesita
-- (ver Sync::getOrCreateCategory), ya con el user_id del proveedor dueño.

-- Identidad del canal:
--   - Si el proveedor entrega tvg-id no vacío, identity_hash = sha256("tvgid:<tvg-id>")
--   - Si no, identity_hash = sha256("url:<stream_url>")
-- Esto permite que un canal cuyo tvg-id es estable pueda cambiar de nombre,
-- categoría, logo o incluso de URL de transmisión sin perder su identidad
-- (se reporta como "modificado" en vez de eliminado + agregado). Cuando el
-- proveedor no entrega tvg-id, la identidad sigue dependiendo de la URL
-- (limitación conocida: un cambio de URL en ese caso se ve como baja + alta).
-- "user_id" queda denormalizado del proveedor dueño (facilita filtrar por
-- usuario sin tener que unir con providers en cada consulta).
CREATE TABLE IF NOT EXISTS channels (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    provider_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    tvg_id VARCHAR(255) NULL,
    logo_url TEXT NULL,
    -- logo_manual=1: el logo se asignó a mano (ver api/logo_search.php) y
    -- Sync.php debe dejarlo en paz en vez de pisarlo con lo que traiga el
    -- M3U del proveedor en la próxima sincronización.
    logo_manual TINYINT(1) NOT NULL DEFAULT 0,
    stream_url TEXT NOT NULL,
    identity_hash CHAR(64) NOT NULL,
    status ENUM('active','removed') NOT NULL DEFAULT 'active',
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_provider_identity (provider_id, identity_hash),
    KEY idx_provider_status (provider_id, status),
    KEY idx_category (category_id),
    KEY idx_user (user_id),
    CONSTRAINT fk_channels_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
    CONSTRAINT fk_channels_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_channels_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- type:
--   added            -> canal nuevo (primera vez que se ve esa identidad)
--   modified         -> el canal ya existía y cambió nombre/categoría/logo/URL
--   removed          -> el canal ya no aparece en la lista del proveedor
--   category_added   -> se detectó una categoría (group-title) nunca antes vista
CREATE TABLE IF NOT EXISTS channel_changes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    provider_id INT UNSIGNED NOT NULL,
    channel_id INT UNSIGNED NULL,
    type ENUM('added','removed','modified','category_added') NOT NULL,
    old_name VARCHAR(255) NULL,
    new_name VARCHAR(255) NULL,
    old_category_id INT UNSIGNED NULL,
    new_category_id INT UNSIGNED NULL,
    detail VARCHAR(500) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_provider (provider_id),
    KEY idx_channel (channel_id),
    KEY idx_read (is_read),
    KEY idx_created (created_at),
    KEY idx_user (user_id),
    CONSTRAINT fk_changes_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
    CONSTRAINT fk_changes_channel FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE SET NULL,
    CONSTRAINT fk_changes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conexiones guardadas a paneles XUI·ONE (integración de solo lectura/importación,
-- ver api/xui_panels.php y api/xui_test.php). Solo una puede estar activa a la
-- vez POR USUARIO (antes era una sola para toda la app).
--
-- "session_access_code" es DISTINTO de "access_code": se comprobó en pruebas
-- que el access_code de la API de administración (api_key) y el access_code
-- de la URL del panel web (login de usuario/contraseña, usado por
-- includes/XuiSession.php para asignar servidor/on-demand) pueden ser
-- valores diferentes en el mismo panel — no asumir que son iguales.
-- "panel_username"/"panel_password_enc" son las credenciales de ESE login
-- web (no el api_key). La contraseña se guarda cifrada (ver
-- includes/Crypto.php) con una llave que vive en config.php, fuera de la
-- base de datos — así un dump/backup de la BD por sí solo no expone la
-- contraseña real del panel.
CREATE TABLE IF NOT EXISTS xui_panels (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    panel_url VARCHAR(255) NOT NULL,
    access_code VARCHAR(120) NOT NULL,
    session_access_code VARCHAR(120) NULL,
    api_key VARCHAR(255) NOT NULL,
    panel_username VARCHAR(120) NULL,
    panel_password_enc TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_user (user_id),
    CONSTRAINT fk_panels_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Caché local de datos de canales creados vía api/xui_channels.php. Se
-- verificó que "edit_stream" de XUI·ONE NO es un update parcial: si al
-- editar (ej. renombrar) no se reenvían stream_source/category_id/
-- stream_all, el panel los BORRA (los deja vacíos/0) en vez de conservar
-- el valor existente — solo stream_icon sobrevive a una edición parcial.
-- Esta tabla guarda lo necesario para poder reenviar esos campos al
-- renombrar/editar un canal sin perder su URL de origen o categoría.
-- "name" además permite a api/xui_bulk_import.php detectar canales ya
-- importados por su URL y, si el nombre local cambió (ej. el proveedor
-- renombró "TCM" a "TCM !!"), actualizarlo en XUI·ONE en vez de solo
-- omitirlo como duplicado o crear uno nuevo. "provider_id" (solo lo llena
-- xui_bulk_import.php) evita que, al reimportar la categoría de UN
-- proveedor, se borren en XUI canales de OTRO proveedor que comparta el
-- mismo nombre de categoría (ej. dos proveedores con "Cine"). "user_id"
-- queda denormalizado del panel dueño (xui_panel_id), para filtrar directo
-- sin tener que unir con xui_panels.
-- "bouquet_ids" es CRÍTICO: se verificó que "bouquets" en edit_stream no es
-- aditivo, es "membresía exacta" — si se edita un canal (ej. al renombrarlo)
-- sin mandar "bouquets", el panel lo saca de TODOS los bouquets en los que
-- estaba. Por eso hay que reenviarlo siempre que se llame edit_stream.
CREATE TABLE IF NOT EXISTS xui_channel_cache (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    xui_panel_id INT UNSIGNED NOT NULL,
    provider_id INT UNSIGNED NULL,
    stream_id INT UNSIGNED NOT NULL,
    name VARCHAR(255) NULL,
    source_url TEXT NOT NULL,
    logo TEXT NULL,
    category_ids VARCHAR(500) NULL,
    bouquet_ids VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_panel_stream (xui_panel_id, stream_id),
    KEY idx_user (user_id),
    CONSTRAINT fk_channel_cache_panel FOREIGN KEY (xui_panel_id) REFERENCES xui_panels(id) ON DELETE CASCADE,
    CONSTRAINT fk_channel_cache_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    provider_id INT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    status ENUM('running','ok','error') NOT NULL DEFAULT 'running',
    channels_added INT UNSIGNED NOT NULL DEFAULT 0,
    channels_removed INT UNSIGNED NOT NULL DEFAULT 0,
    channels_modified INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    KEY idx_provider (provider_id),
    KEY idx_user (user_id),
    CONSTRAINT fk_runs_provider FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
    CONSTRAINT fk_runs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configuración de notificaciones por Telegram (ver includes/Telegram.php y
-- api/telegram_settings.php). Una fila POR USUARIO ("user_id" es la propia
-- llave primaria, relación 1:1 — antes era una fila única global con id=1).
-- "bot_token_enc" se guarda cifrado (includes/Crypto.php, misma llave que
-- la sesión de XUI·ONE) porque quien lo tenga puede enviar mensajes como
-- ese bot. Solo se notifica cuando un proveedor YA sincronizado antes
-- detecta cambios reales (ver Sync::syncProvider() / "was_first_sync") —
-- la primera sincronización de un proveedor recién agregado nunca dispara
-- Telegram, para no mandar un aviso con cientos de canales "nuevos" de una
-- sola vez.
CREATE TABLE IF NOT EXISTS telegram_settings (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    bot_token_enc TEXT NOT NULL,
    chat_id VARCHAR(120) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_telegram_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configuración global de la app (fila única, id=1 fijo — no es por usuario,
-- la controla cualquier admin desde 🛡️ Administración de usuarios). Por
-- ahora solo cubre el captcha de Cloudflare Turnstile: no tiene interruptor
-- de encendido/apagado, se exige automáticamente en registro y login en
-- cuanto ambas llaves están guardadas (y se desactiva borrando el Site Key).
-- "turnstile_site_key" es pública (viaja al HTML de register.html/login.html
-- tal cual); "turnstile_secret_key_enc" se guarda cifrada
-- (includes/Crypto.php) porque se usa para verificar contra la API de
-- Cloudflare del lado del servidor.
CREATE TABLE IF NOT EXISTS app_settings (
    id INT UNSIGNED NOT NULL PRIMARY KEY,
    turnstile_site_key VARCHAR(255) NULL,
    turnstile_secret_key_enc TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro de intentos de registro por IP (api/register.php), para limitar
-- cuántas cuentas puede crear la misma IP por hora — independiente del
-- captcha (siempre activo, no tiene toggle: es invisible y sin costo para
-- alguien real, así que no hace falta poder desactivarlo). Se limpia sola:
-- register.php borra las filas de más de 24h en cada llamada.
CREATE TABLE IF NOT EXISTS registration_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ip_created (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Intentos de login fallidos por USUARIO (api/login.php), para bloquear
-- fuerza bruta contra una cuenta puntual — independiente de si esa cuenta
-- existe de verdad (se guarda el string tal cual se envió, no un user_id,
-- para no tener que revelar si el usuario existe con una consulta aparte).
-- Solo cuentan los fallos por credenciales inválidas, no los rechazos por
-- estado 'pending'/'disabled' (ver includes/auth.php::auth_login()) — esos
-- no son intentos de adivinar una contraseña. Se limpia sola: login.php
-- borra las filas de más de 1 día en cada llamada, y un login exitoso borra
-- de inmediato las de ese usuario.
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_username_created (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
