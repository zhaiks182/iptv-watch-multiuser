<?php
// Copia este archivo como config.php y completa tus credenciales reales.
// install.sh ya genera config.php automáticamente si usas el instalador.
return [
    'db_host' => 'localhost',
    'db_name' => 'iptv_watch',
    'db_user' => 'iptv_watch_user',
    'db_pass' => 'CAMBIA_ESTA_CLAVE',
    // Llave para cifrar la contraseña de sesión del panel XUI·ONE (ver
    // includes/Crypto.php). Genera la tuya con:
    //   php -r "echo base64_encode(random_bytes(32));"
    // y NO la reutilices entre instalaciones — si se pierde o cambia, las
    // contraseñas de panel ya guardadas quedan indescifrables (habría que
    // volver a guardarlas desde "Integración XUI·ONE").
    'xui_session_enc_key' => 'CAMBIA_ESTA_LLAVE_BASE64',
];
