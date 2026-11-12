<?php
declare(strict_types=1);

/**
 * Conexión PDO del Aula Virtual.
 * Misma BD operativa Hostinger que asistencia/relatores.
 */
function aula_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host    = 'localhost';
    $db      = 'u739953844_DDGDELVALLE';
    $user    = 'u739953844_DDGADMIN';
    $pass    = 'dDGDELVALLE2026!!!';
    $charset = 'utf8mb4';

    $pdo = new PDO(
        "mysql:host={$host};dbname={$db};charset={$charset}",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    return $pdo;
}
