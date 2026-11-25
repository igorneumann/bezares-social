<?php
/**
 * Copia este archivo como config.php y rellena los datos.
 * Las claves de ChatGPT y de las redes sociales se configuran desde el panel (Configuración).
 */
return array(
    // Base de datos MySQL / MariaDB (crea una base de datos vacía en tu hosting)
    'db_host'    => 'localhost',
    'db_name'    => 'bezares_social',
    'db_user'    => 'bezares_social',
    'db_pass'    => 'CAMBIA_ESTO',

    // URL pública de la aplicación (sin barra final). Se usa para la redirección OAuth de LinkedIn.
    'base_url'   => 'https://social.bezares.com',

    // Zona horaria para fechas y estadísticas
    'timezone'   => 'Europe/Madrid',

    // Clave que se pide una sola vez para crear el primer usuario (larga y aleatoria)
    'setup_key'  => 'CAMBIA_ESTO_POR_UNA_CLAVE_LARGA',

    // OBLIGATORIA. Clave para cifrar en la base de datos las claves de ChatGPT y de las redes sociales.
    // Aleatoria, de al menos 32 caracteres y distinta de setup_key. Sin ella no se pueden guardar claves.
    // Si la cambias, habrá que volver a introducir esas claves en Configuración.
    // Puedes generar una en PowerShell (generador criptográfico):
    //   $b = New-Object byte[] 36; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b); [Convert]::ToBase64String($b)
    'secret_key' => 'CAMBIA_ESTO_POR_OTRA_CLAVE_LARGA',

    // Redirigir siempre a HTTPS (desactívalo solo para pruebas en local)
    'force_https' => true,

    // Webs que pueden mostrar el panel dentro de un iframe (p. ej. Nextcloud → Sitios externos)
    'trusted_origins' => array(), // ej. array('https://cloud.bezares.com')
);
