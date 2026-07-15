<?php
define( 'WP_CACHE', true );

/**
 * WordPress — plantilla de configuración.
 * Copia a wp-config.php y completa credenciales.
 * wp-config.php NO se versiona (.gitignore).
 */

define( 'DB_NAME', 'nombre_bd' );
define( 'DB_USER', 'usuario_bd' );
define( 'DB_PASSWORD', 'password_bd' );
define( 'DB_HOST', '127.0.0.1' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

/** Genera claves en https://api.wordpress.org/secret-key/1.1/salt/ */
define( 'AUTH_KEY',          'pon-una-frase-unica-aqui' );
define( 'SECURE_AUTH_KEY',   'pon-una-frase-unica-aqui' );
define( 'LOGGED_IN_KEY',     'pon-una-frase-unica-aqui' );
define( 'NONCE_KEY',         'pon-una-frase-unica-aqui' );
define( 'AUTH_SALT',         'pon-una-frase-unica-aqui' );
define( 'SECURE_AUTH_SALT',  'pon-una-frase-unica-aqui' );
define( 'LOGGED_IN_SALT',    'pon-una-frase-unica-aqui' );
define( 'NONCE_SALT',        'pon-una-frase-unica-aqui' );
define( 'WP_CACHE_KEY_SALT', 'pon-una-frase-unica-aqui' );

$table_prefix = 'wp_';

define( 'WP_DEBUG', false );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
