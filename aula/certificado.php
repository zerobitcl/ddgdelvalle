<?php
declare(strict_types=1);

/**
 * Certificado imprimible. Solo si la persona terminó los módulos
 * o administración ya dejó constancia en aula_certificados.
 */
session_name('ddg_aula');
session_start();

require __DIR__ . '/db.php';

$u = $_SESSION['aula_user'] ?? null;
$cursoId = (int) ($_GET['curso_id'] ?? 0);

function negar(string $msg): never
{
    http_response_code(403);
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Certificado</title><p style="font-family:sans-serif">'
        . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

if (!$u || $cursoId < 1) {
    negar('Entra al aula y abre el certificado desde Mi Actividad.');
}

try {
    $pdo = aula_pdo();
    $st = $pdo->prepare('SELECT nombre FROM aula_cursos WHERE id = ? AND activo = 1');
    $st->execute([$cursoId]);
    $curso = (string) $st->fetchColumn();
    if ($curso === '') {
        negar('Curso no encontrado.');
    }

    $esAdmin = (($u['rol'] ?? '') === 'admin');
    $ins = $pdo->prepare('SELECT 1 FROM aula_inscripciones WHERE curso_id = ? AND usuario_id = ?');
    $ins->execute([$cursoId, (int) $u['id']]);
    if (!$esAdmin && !$ins->fetchColumn()) {
        negar('No estás inscrito en este curso.');
    }

    $emitido = $pdo->prepare('SELECT emitido_at FROM aula_certificados WHERE curso_id = ? AND usuario_id = ?');
    $emitido->execute([$cursoId, (int) $u['id']]);
    $fecha = $emitido->fetchColumn();

    $totalSt = $pdo->prepare('SELECT COUNT(*) FROM aula_modulos WHERE curso_id = ?');
    $totalSt->execute([$cursoId]);
    $total = (int) $totalSt->fetchColumn();
    $hechosSt = $pdo->prepare(
        'SELECT COUNT(*) FROM aula_progreso p
         JOIN aula_modulos m ON m.id = p.modulo_id
         WHERE m.curso_id = ? AND p.usuario_id = ?'
    );
    $hechosSt->execute([$cursoId, (int) $u['id']]);
    $hechos = (int) $hechosSt->fetchColumn();
    $completo = $total > 0 && $hechos >= $total;

    if (!$fecha && !$completo) {
        negar('El certificado se habilita cuando terminas todos los módulos.');
    }
    if (!$fecha) {
        $fecha = date('Y-m-d H:i:s');
    }
} catch (Throwable $e) {
    negar('No se pudo preparar el certificado.');
}

$nombre = htmlspecialchars((string) $u['nombre'], ENT_QUOTES, 'UTF-8');
$cursoH = htmlspecialchars($curso, ENT_QUOTES, 'UTF-8');
$meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$ts = strtotime((string) $fecha) ?: time();
$cuando = htmlspecialchars((int) date('j', $ts) . ' de ' . $meses[(int) date('n', $ts)] . ' de ' . date('Y', $ts), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es-CL">
<head>
  <meta charset="UTF-8">
  <title>Certificado · <?= $cursoH ?></title>
  <style>
    body { margin: 0; font-family: Georgia, "Times New Roman", serif; color: #1b2430; background: #eef3f6; }
    .sheet {
      width: min(900px, calc(100% - 32px));
      margin: 28px auto;
      background: #fff;
      padding: 56px 64px;
      border: 10px solid #3fbfc6;
      outline: 1px solid #1899a3;
      outline-offset: -18px;
      text-align: center;
    }
    img { width: 150px; }
    p { letter-spacing: 0.14em; text-transform: uppercase; font-family: "Segoe UI", sans-serif; font-size: 12px; color: #5d6b7a; }
    h1 { font-weight: 500; font-size: 42px; margin: 12px 0; }
    h2 { font-size: 28px; margin: 8px 0 28px; }
    .btn { font-family: "Segoe UI", sans-serif; border: 0; background: #243038; color: #fff; padding: 10px 16px; border-radius: 8px; cursor: pointer; }
    @media print { body { background: #fff; } .btn { display: none; } .sheet { margin: 0; width: auto; } }
  </style>
</head>
<body>
  <article class="sheet">
    <img src="https://ddgdelvalle.cl/wp-content/uploads/2026/03/LOGO-DDG_color.png" alt="DDG Del Valle">
    <p>Certificado de participación</p>
    <h1><?= $nombre ?></h1>
    <p>completó el curso</p>
    <h2><?= $cursoH ?></h2>
    <p><?= $cuando ?> · OTEC DDG Del Valle</p>
    <button class="btn" type="button" onclick="print()">Descargar / imprimir</button>
  </article>
</body>
</html>
