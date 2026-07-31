<?php
/**
 * Cifrado en reposo para secretos que la app necesita poder RECUPERAR en
 * texto plano (a diferencia de las contraseñas de usuarios, que se guardan
 * con password_hash y nunca se descifran). Caso de uso: la contraseña del
 * panel XUI·ONE para el login por sesión (includes/XuiSession.php) — se
 * necesita el valor real para reenviarlo al formulario de login del panel,
 * así que un hash de un solo sentido no sirve.
 *
 * La llave de cifrado vive en config.php (fuera de la base de datos, no
 * accesible por HTTP), no en la tabla xui_panels. Así, robarse solo la base
 * de datos (un backup, un dump, una inyección SQL) no alcanza para leer la
 * contraseña real del panel — también se necesitaría el archivo de
 * configuración del servidor.
 *
 * Formato de xui_encrypt(): base64( IV(12 bytes) . TAG(16 bytes) . CIPHERTEXT )
 * usando AES-256-GCM (autenticado: cualquier manipulación del texto cifrado
 * hace fallar el descifrado en vez de devolver basura silenciosamente).
 */

function xui_crypto_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $config = require __DIR__ . '/../config.php';
    $b64 = $config['xui_session_enc_key'] ?? '';
    if ($b64 === '') {
        throw new RuntimeException('Falta "xui_session_enc_key" en config.php. Genera una con: php -r "echo base64_encode(random_bytes(32));"');
    }
    $key = base64_decode($b64, true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('"xui_session_enc_key" en config.php debe ser 32 bytes en base64.');
    }
    return $key;
}

function xui_encrypt(string $plaintext): string
{
    $key = xui_crypto_key();
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) {
        throw new RuntimeException('Falló el cifrado.');
    }
    return base64_encode($iv . $tag . $ciphertext);
}

function xui_decrypt(?string $encoded): ?string
{
    if ($encoded === null || $encoded === '') {
        return null;
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 28) {
        return null;
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $key = xui_crypto_key();
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plaintext === false ? null : $plaintext;
}
