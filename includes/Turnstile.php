<?php

/**
 * Verificación de Cloudflare Turnstile, compartida por api/register.php y
 * api/login.php — cada uno la exige de forma independiente según su propio
 * flag en app_settings (registration_captcha_enabled / login_captcha_enabled)
 * pero ambos verifican el token de la misma forma contra la misma API.
 */
function turnstile_verify(string $secretKey, string $token, string $ip): bool
{
    if ($secretKey === '' || $token === '') {
        return false;
    }

    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['secret' => $secretKey, 'response' => $token, 'remoteip' => $ip]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);

    $verify = $raw !== false ? json_decode($raw, true) : null;
    return is_array($verify) && !empty($verify['success']);
}
