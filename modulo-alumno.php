<?php
session_start();

date_default_timezone_set('America/Santiago');
header('Cache-Control: no-store');

/* ---------- Parámetros normativos SENCE: única fuente de verdad (PHP y JS) ---------- */
const DDG_INACTIVIDAD_SEG = 3600; // cierre automático por inactividad
const DDG_EXIGENCIA_PCT   = 60;   // 60% de logro = nota 4.0
const DDG_AVANCE_MIN_PCT  = 75;   // avance curricular mínimo de aprobación
const DDG_NOTA_MIN        = 4.0;

function ddg_rut_limpio(string $v): string {
    return strtoupper(preg_replace('/[^0-9kK]/', '', $v));
}

/* Módulo 11 estándar chileno. Se calcula (no se tipea) para garantizar RUT válidos en datos de muestra. */
function ddg_rut_dv(int $n): string {
    $suma = 0; $mult = 2;
    foreach (array_reverse(str_split((string) $n)) as $d) {
        $suma += (int) $d * $mult;
        $mult = $mult === 7 ? 2 : $mult + 1;
    }
    $r = 11 - ($suma % 11);
    return $r === 11 ? '0' : ($r === 10 ? 'K' : (string) $r);
}

function ddg_rut_fmt(int $n): string {
    return number_format($n, 0, '', '.') . '-' . ddg_rut_dv($n);
}

/* Escala chilena 1.0–7.0 con exigencia al 60%: tramo lineal 1→4 (0–60%) y 4→7 (60–100%). */
function ddg_nota(float $pct): float {
    $pct = max(0.0, min(100.0, $pct));
    $e = (float) DDG_EXIGENCIA_PCT;
    $n = $pct >= $e ? 4 + 3 * ($pct - $e) / (100 - $e) : 1 + 3 * $pct / $e;
    return round($n, 1);
}

/* UTF-8 seguro sin depender de la extensión mbstring (no siempre está en hosting compartido) */
function ddg_cortar(string $s, int $max): string {
    return preg_match('/^.{0,' . $max . '}/su', $s, $m) ? $m[0] : '';
}

function ddg_largo(string $s): int {
    return (int) preg_match_all('/./su', $s);
}

function ddg_json(int $code, array $data): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function ddg_chk_modulo(int $n): string {
    return '<label class="no-print inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-agro-500">'
         . '<input type="checkbox" data-modulo="' . $n . '" class="h-4 w-4 accent-green-700"> Marcar módulo como completado</label>';
}

/* ---------- Perfiles de acceso ---------- */
/* 'ids' admite usuario o RUT (con o sin puntos/guion). La clave se compara con hash_equals. */
$PERFILES = [
    ['ids' => ['alumno'],                                 'clave' => 'riego2026',   'rol' => 'alumno',  'nombre' => 'Alumno Riego',        'rut' => '—'],
    ['ids' => ['154328765'],                              'clave' => 'Alumno2026*', 'rol' => 'alumno',  'nombre' => 'Participante Demo',   'rut' => '15.432.876-5'],
    ['ids' => ['111111111', 'auditor', 'auditor@sence.cl'], 'clave' => 'Sence2026*',  'rol' => 'auditor', 'nombre' => 'Fiscalizador SENCE',  'rut' => '11.111.111-1'],
];

function ddg_autenticar(array $perfiles, string $user, string $pass): ?array {
    $u  = trim($user);
    $k  = strtolower($u);
    $kr = preg_match('/^[0-9.\-]+[0-9kK]$/', $u) ? strtolower(ddg_rut_limpio($u)) : null;
    foreach ($perfiles as $p) {
        foreach ($p['ids'] as $id) {
            $id = strtolower($id);
            if (($k === $id || ($kr !== null && $kr === $id)) && hash_equals($p['clave'], $pass)) {
                return $p;
            }
        }
    }
    return null;
}

/* ---------- Clave de la evaluación final (se califica en servidor: no viaja al navegador) ---------- */
$DDG_CLAVE_SM = ['b','d','b','b','b','b','b','b','b','a','b','a','b','b','b','a','b','a','b','b'];
$DDG_CLAVE_VF = ['F','V','V','F','F','F','V','V','F','V'];

$error_login = '';
$aviso_login = '';

if (isset($_GET['logout'])) {
    if (($_GET['motivo'] ?? '') === 'inactividad') {
        setcookie('ddg_aviso', 'inactividad', time() + 60, '/');
    }
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $user = trim($_POST['usuario'] ?? '');
    $pass = trim($_POST['clave'] ?? '');
    $perfil = (($_SESSION['login_bloqueo'] ?? 0) > time()) ? null : ddg_autenticar($PERFILES, $user, $pass);
    if ($perfil) {
        session_regenerate_id(true); // evita fijación de sesión
        $_SESSION['intentos_fallidos'] = 0;
        $_SESSION['alumno_auth']   = true;
        $_SESSION['alumno_user']   = $user;
        $_SESSION['alumno_rol']    = $perfil['rol'];
        $_SESSION['alumno_nombre'] = $perfil['nombre'];
        $_SESSION['alumno_rut']    = $perfil['rut'];
        $_SESSION['login_ts']      = time();
        $_SESSION['login_ip']      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf']          = bin2hex(random_bytes(16));
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    if (($_SESSION['login_bloqueo'] ?? 0) > time()) {
        $error_login = 'Demasiados intentos fallidos. Espera 2 minutos e inténtalo nuevamente.';
    } else {
        $_SESSION['intentos_fallidos'] = ($_SESSION['intentos_fallidos'] ?? 0) + 1;
        if ($_SESSION['intentos_fallidos'] >= 8) {
            $_SESSION['login_bloqueo'] = time() + 120;
            $_SESSION['intentos_fallidos'] = 0;
        }
        $error_login = 'Usuario/RUT o contraseña incorrectos.';
    }
}

$autenticado = !empty($_SESSION['alumno_auth']);

/* Expiración server-side: el JS es solo UX; la normativa se hace cumplir aquí. */
if ($autenticado && (time() - (int) ($_SESSION['last_activity'] ?? time())) > DDG_INACTIVIDAD_SEG) {
    $_SESSION = [];
    session_destroy();
    $autenticado = false;
    $aviso_login = 'Tu sesión se cerró por inactividad (60 min, normativa SENCE). Ingresa nuevamente.';
} elseif ($autenticado) {
    $_SESSION['last_activity'] = time();
} elseif (($_COOKIE['ddg_aviso'] ?? '') === 'inactividad') {
    $aviso_login = 'Tu sesión se cerró por inactividad (60 min, normativa SENCE). Ingresa nuevamente.';
    setcookie('ddg_aviso', '', time() - 3600, '/');
}

/* Keep-alive: el cliente lo llama al detectar actividad real (lectura, clics, teclado). */
if ($autenticado && isset($_GET['ping'])) {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_GET['calificar']) || isset($_GET['foro']))) {
    if (!$autenticado) {
        ddg_json(401, ['error' => 'Sesión expirada. Ingresa nuevamente.']);
    }
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        ddg_json(403, ['error' => 'Token de seguridad inválido. Recarga la página.']);
    }

    if (isset($_GET['calificar'])) {
        $okSm = 0; $okVf = 0; $malSm = []; $malVf = [];
        foreach ($DDG_CLAVE_SM as $i => $c) {
            if (strtolower((string) ($_POST['sm_' . ($i + 1)] ?? '')) === $c) { $okSm++; } else { $malSm[] = $i + 1; }
        }
        foreach ($DDG_CLAVE_VF as $i => $c) {
            if (strtoupper((string) ($_POST['vf_' . ($i + 1)] ?? '')) === $c) { $okVf++; } else { $malVf[] = $i + 1; }
        }
        $total = (count($DDG_CLAVE_SM) + count($DDG_CLAVE_VF)) * 2; // 2 pts por ítem = 60 pts
        $pts   = ($okSm + $okVf) * 2;
        $pctRaw = $pts / $total * 100;
        $nota  = ddg_nota($pctRaw);
        $res = [
            'pts' => $pts, 'total' => $total, 'pct' => round($pctRaw, 1), 'nota' => $nota,
            'estado' => $nota >= DDG_NOTA_MIN ? 'APROBADO' : 'REPROBADO',
            'okSm' => $okSm, 'totSm' => count($DDG_CLAVE_SM), 'okVf' => $okVf, 'totVf' => count($DDG_CLAVE_VF),
            'malSm' => $malSm, 'malVf' => $malVf,
        ];
        $_SESSION['eval_final'] = $res + ['ts' => time()];
        ddg_json(200, $res);
    }

    /* Foro */
    $asunto = trim(ddg_cortar((string) ($_POST['asunto'] ?? ''), 120));
    $texto  = trim(ddg_cortar((string) ($_POST['consulta'] ?? ''), 1200));
    if ($asunto === '' || ddg_largo($texto) < 10) {
        ddg_json(422, ['error' => 'Indica un asunto y redacta una consulta de al menos 10 caracteres.']);
    }
    $item = [
        'autor'  => $_SESSION['alumno_nombre'] ?? 'Participante',
        'asunto' => $asunto,
        'texto'  => $texto,
        'fecha'  => date('d-m-Y H:i'),
    ];
    $_SESSION['foro'][] = $item;
    ddg_json(200, ['ok' => true, 'item' => $item]);
}

/* ---------- Datos de vista (solo si hay sesión) ---------- */
$rol           = $_SESSION['alumno_rol'] ?? 'alumno';
$esAuditor     = $autenticado && $rol === 'auditor';
$nombreUsuario = $_SESSION['alumno_nombre'] ?? 'Participante';
$rutUsuario    = $_SESSION['alumno_rut'] ?? '—';
$loginTs       = (int) ($_SESSION['login_ts'] ?? time());
$loginIp       = $_SESSION['login_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$csrf          = $_SESSION['csrf'] ?? '';
$userKey       = preg_replace('/[^a-z0-9]/', '', strtolower($_SESSION['alumno_user'] ?? 'anon'));

/* Datos de muestra del Gestor Intermedio (ficticios): se calculan para que el DV sea válido */
$SENCE_PARAMS = [
    'RutOtec'        => [ddg_rut_fmt(76123456), 'RUT del OTEC ejecutor (dato de demostración)'],
    'Token'          => [strtoupper(bin2hex(random_bytes(16))), 'Token de autenticación del OTEC (demostración)'],
    'CodSence'       => ['1237654321', 'Código SENCE del curso (demostración)'],
    'CodigoCurso'    => ['RIEGO-TEC-2026-01', 'Código interno del curso / cohorte'],
    'RutAlumno'      => [$rutUsuario !== '—' ? $rutUsuario : '15.432.876-5', 'RUN del participante que inicia sesión'],
    'IdSesionAlumno' => ['SES-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2))), 'Identificador único de la sesión de aprendizaje'],
    'UrlRetorno'     => [(isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : 'https://ddgdelvalle.cl') . strtok($_SERVER['REQUEST_URI'] ?? '/modulo-alumno.php', '?') . '?sence=ok', 'URL a la que SENCE retorna tras validar'],
    'UrlError'       => [(isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : 'https://ddgdelvalle.cl') . strtok($_SERVER['REQUEST_URI'] ?? '/modulo-alumno.php', '?') . '?sence=error', 'URL de retorno ante rechazo o error'],
];

$FORO_SEMILLA = [
    [
        'autor' => 'Participante M.F.', 'asunto' => 'Kc del cerezo en distintas etapas',
        'texto' => 'Si la ETo del mes es la misma para todos los cultivos, ¿por qué el Kc del cerezo cambia entre brotación y cosecha? ¿Qué valor debo usar para diciembre?',
        'fecha' => '13-10-2026 10:18',
        'resp_autor' => 'Relator del curso', 'resp_fecha' => '13-10-2026 16:40',
        'resp' => 'La ETo es la demanda atmosférica sobre un pasto de referencia; el Kc traduce esa demanda al cultivo según su cobertura y fenología. En brotación (poca superficie foliar) el Kc ronda 0,4–0,55; con plena cobertura y fructificación llega a ~0,9, valor usado en el ejercicio. Para diciembre en Mataquito: ETo diaria = 116,45 / 31 ≈ 3,76 mm/día, luego ETc = 3,76 × 0,9 ≈ 3,38 mm/día. Para pasar a litros/planta/día recuerda aplicar el marco de plantación y el porcentaje de cubrimiento (D.N.C).',
    ],
    [
        'autor' => 'Participante J.R.', 'asunto' => 'Por qué se divide por la eficiencia al calcular la D.B.C',
        'texto' => 'En el caso práctico dividimos la D.N.C por 0,90 para obtener la D.B.C. ¿Qué pasa si el coeficiente de uniformidad (CU) medido en mi huerto es 85%?',
        'fecha' => '14-10-2026 09:05',
        'resp_autor' => 'Relator del curso', 'resp_fecha' => '14-10-2026 15:12',
        'resp' => 'La D.B.C compensa las pérdidas del método: D.B.C = D.N.C / Ea. Con goteo (Ea = 90%) tu caso queda 31,30 / 0,90 ≈ 34,78 L/planta/día. Si en terreno mides un CU de 85%, el sistema aplica el agua de forma menos homogénea: usa 0,85 como eficiencia efectiva (31,30 / 0,85 ≈ 36,8 L/planta/día, T.R.D ≈ 4,6 h) y prioriza la mantención (limpieza de filtros y submatriz) para volver a CU > 90%, que es el objetivo de un sistema bien diseñado.',
    ],
];
$foro_todas = array_merge(array_reverse($_SESSION['foro'] ?? []), $FORO_SEMILLA);

/* Bitácora de auditoría. Filas precargadas = datos de demostración (RUT con DV calculado). */
$aud_filas = [];
if ($esAuditor) {
    $hoy = new DateTimeImmutable('today');
    $semilla = [
        // nombre, RUT base, días atrás, hora ingreso, minutos, IP, % avance, nota final
        ['María José Fuentes Araya',      16482317, 1, '08:42', 187, '190.45.112.38',  100, 6.2],
        ['Juan Pablo Rojas Tapia',        12905644, 1, '09:15', 142, '200.83.24.171',  100, 5.4],
        ['Carla Andrea Muñoz Díaz',       17236059, 2, '10:03', 231, '181.43.207.19',   75, 4.6],
        ['Luis Alberto Cortés Pizarro',    9874213, 2, '14:20', 118, '186.67.91.204',  100, 6.8],
        ['Camila Fernanda Soto Vera',     19351782, 3, '08:55', 264, '152.231.68.52',  100, 5.8],
        ['Pedro Antonio Herrera Castillo',13667490, 4, '15:10',  96, '190.160.33.117',  75, 4.2],
        ['Rosa Elena Campos Olivares',    10512936, 5, '09:40', 205, '201.215.140.9',  100, 5.0],
        ['Diego Ignacio Araya Pastén',    18049375, 6, '11:25',  73, '181.226.5.143',   50, 3.4],
    ];
    foreach ($semilla as $s) {
        $ing = $hoy->modify('-' . $s[2] . ' days')->setTime((int) substr($s[3], 0, 2), (int) substr($s[3], 3, 2), (int) (($s[4] * 7) % 60));
        $sal = $ing->modify('+' . $s[4] . ' minutes');
        $aud_filas[] = [
            'rut' => ddg_rut_fmt($s[1]), 'nombre' => $s[0],
            'ingreso' => $ing->format('d-m-Y H:i:s'), 'salida' => $sal->format('d-m-Y H:i:s'),
            'min' => $s[4], 'ip' => $s[5], 'avance' => $s[6], 'nota' => $s[7],
        ];
    }
}

/* URLs públicas absolutas: Office Online las descarga desde sus servidores */
$PPTX_URLS = [
    '1' => 'https://ddgdelvalle.cl/datos/Presentacion-Riego-p1.pptx',
    '2' => 'https://ddgdelvalle.cl/datos/riego_2.pptx',
    '3' => 'https://ddgdelvalle.cl/datos/riego_3.pptx',
    '4' => 'https://ddgdelvalle.cl/datos/riego_4.pptx',
];

/* Rutas locales del PPTX (lectura desde filesystem) */
$PPTX_LOCAL = [
    '1' => __DIR__ . '/datos/Presentacion-Riego-p1.pptx',
    '2' => __DIR__ . '/datos/riego_2.pptx',
    '3' => __DIR__ . '/datos/riego_3.pptx',
    '4' => __DIR__ . '/datos/riego_4.pptx',
];

$PPTX_EMBEDS = [];
foreach ($PPTX_URLS as $id => $url) {
    $PPTX_EMBEDS[$id] = 'https://view.officeapps.live.com/op/embed.aspx?src=' . rawurlencode($url);
}

/* Descarga autenticada sin exponer otros archivos de /datos/ (IDs 1|2|3|4) */
if ($autenticado && isset($_GET['download'])) {
    $dlId = (string) $_GET['download'];
    if (isset($PPTX_LOCAL[$dlId])) {
        $file = $PPTX_LOCAL[$dlId];
        if (!is_file($file)) {
            http_response_code(404);
            exit('Archivo no encontrado.');
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, no-store');
        readfile($file);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Módulo Alumno · Riego y Evapotranspiración</title>
  <meta name="robots" content="noindex,nofollow">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            agro: {
              50:  '#f0fdf6',
              100: '#dcfce9',
              500: '#16a34a',
              600: '#15803d',
              700: '#166534',
              800: '#14532d',
            },
            agua: {
              50:  '#f0f9ff',
              100: '#e0f2fe',
              500: '#0ea5e9',
              600: '#0284c7',
              700: '#0369a1',
            }
          },
          fontFamily: {
            sans: ['"DM Sans"', 'system-ui', 'sans-serif'],
            display: ['"Fraunces"', 'Georgia', 'serif'],
          }
        }
      }
    }
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Fraunces:opsz,wght@9..144,600;9..144,700&display=swap" rel="stylesheet">
  <style>
    body {
      background:
        radial-gradient(ellipse 80% 50% at 10% -10%, rgba(14, 165, 233, 0.12), transparent),
        radial-gradient(ellipse 60% 40% at 90% 0%, rgba(22, 163, 74, 0.1), transparent),
        linear-gradient(180deg, #f8fafc 0%, #f0fdf6 40%, #f0f9ff 100%);
      min-height: 100vh;
    }
    .card {
      background: #fff;
      border-radius: 1rem;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06), 0 8px 24px rgba(15, 23, 42, 0.04);
      border: 1px solid rgba(226, 232, 240, 0.9);
    }
    .field {
      width: 100%;
      border: 1.5px solid #cbd5e1;
      border-radius: 0.5rem;
      padding: 0.5rem 0.75rem;
      font-size: 0.9rem;
      transition: border-color .15s, box-shadow .15s;
      background: #fff;
    }
    .field:focus {
      outline: none;
      border-color: #0ea5e9;
      box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.15);
    }
    .field-inline {
      display: inline-block;
      width: auto;
      min-width: 5.5rem;
      max-width: 9rem;
      margin: 0 0.2rem;
      text-align: center;
      vertical-align: middle;
    }
    .field-ok { border-color: #16a34a !important; background: #f0fdf4; }
    .field-bad { border-color: #dc2626 !important; background: #fef2f2; }
    .ratio-16-9 { position: relative; width: 100%; padding-bottom: 56.25%; }
    .ratio-16-9 iframe {
      position: absolute; inset: 0; width: 100%; height: 100%; border: 0; border-radius: 0.75rem;
    }
    @media print {
      .no-print { display: none !important; }
      body { background: #fff; }
      .card { box-shadow: none; border: 1px solid #e2e8f0; break-inside: avoid; }
      .field { border: 1px solid #94a3b8; }
    }
    /* Respuestas marcadas: contraste alto para el PDF del profesor */
    .eval-opcion {
      display: flex;
      align-items: flex-start;
      gap: 0.6rem;
      padding: 0.65rem 0.85rem;
      border-radius: 0.65rem;
      border: 1.5px solid #e2e8f0;
      background: #fff;
      cursor: pointer;
      transition: border-color .15s, background .15s, box-shadow .15s;
    }
    .eval-opcion:hover { border-color: #94a3b8; background: #f8fafc; }
    .eval-opcion input { margin-top: 0.2rem; accent-color: #15803d; flex-shrink: 0; }
    .eval-opcion.respuesta-marcada {
      background: #dcfce9 !important;
      border-color: #16a34a !important;
      box-shadow: inset 0 0 0 2px #16a34a;
      font-weight: 600;
      color: #14532d;
    }
    .eval-pregunta { break-inside: avoid; page-break-inside: avoid; }

    /* ---------- Extensión E-learning SENCE ---------- */
    .tab-btn {
      display: inline-flex; align-items: center; gap: .4rem; white-space: nowrap;
      padding: .6rem .9rem; font-size: .8rem; font-weight: 600; color: #64748b;
      border-bottom: 2px solid transparent; transition: color .15s, border-color .15s, background .15s;
    }
    .tab-btn:hover { color: #0f172a; background: #f8fafc; }
    .tab-btn[aria-selected="true"] { color: #14532d; border-bottom-color: #15803d; background: #f0fdf6; }
    .tab-btn:focus-visible { outline: 2px solid #0ea5e9; outline-offset: -2px; }
    .modal-overlay { position: fixed; inset: 0; z-index: 60; display: none; align-items: flex-start; justify-content: center;
      overflow-y: auto; padding: 1.5rem 1rem; background: rgba(15, 23, 42, .6); backdrop-filter: blur(2px); }
    .modal-overlay.is-open { display: flex; }
    .tabla-bitacora th, .tabla-bitacora td { white-space: nowrap; }
    .print-only { display: none; }
    @media print {
      .print-only { display: block; }
      /* Informe de auditoría limpio: solo el panel, sin chrome de navegación */
      body.print-audit [data-tab-panel]:not(#tab-auditoria) { display: none !important; }
      body.print-audit #tab-auditoria { display: block !important; }
      body.print-audit footer { display: none !important; }
      body.print-audit .card { border: 0; }
      .tabla-bitacora { font-size: 8.5px; width: 100%; }
      .tabla-bitacora th, .tabla-bitacora td { padding: 3px 4px !important; white-space: normal; }
      .tabla-bitacora tr { break-inside: avoid; }
      .overflow-x-auto { overflow: visible !important; }
      @page { margin: 12mm; }
    }
  </style>
</head>
<body class="font-sans text-slate-800 antialiased">

<?php if (!$autenticado): ?>
  <!-- ============ LOGIN ============ -->
  <div class="min-h-screen flex items-center justify-center px-4 py-10">
    <div class="w-full max-w-md space-y-4">
    <div class="card w-full p-8 sm:p-10">
      <div class="text-center mb-8">
        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-agua-500 to-agro-600 text-white shadow-lg shadow-agua-500/25">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c.5 2.5-1 5-3.5 6.5C6 11 4.5 13 4.5 15.5A7.5 7.5 0 0012 23a7.5 7.5 0 007.5-7.5C19.5 11 15 6 12 3z" />
          </svg>
        </div>
        <h1 class="font-display text-2xl font-bold text-slate-900">Portal de Estudio</h1>
        <p class="mt-1 text-sm text-slate-500">Módulo de riego · Acceso privado de participantes y fiscalización</p>
      </div>

      <?php if ($error_login): ?>
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          <?= htmlspecialchars($error_login) ?>
        </div>
      <?php endif; ?>
      <?php if ($aviso_login): ?>
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" role="status">
          <?= htmlspecialchars($aviso_login) ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="" class="space-y-5" autocomplete="on">
        <div>
          <label for="usuario" class="mb-1.5 block text-sm font-medium text-slate-700">Usuario o RUT</label>
          <input type="text" id="usuario" name="usuario" required autofocus autocomplete="username"
                 class="field" placeholder="Usuario o RUT (ej: 12.345.678-5)">
        </div>
        <div>
          <label for="clave" class="mb-1.5 block text-sm font-medium text-slate-700">Contraseña</label>
          <input type="password" id="clave" name="clave" required autocomplete="current-password"
                 class="field" placeholder="Ingresa tu contraseña">
        </div>
        <button type="submit" name="login" value="1"
                class="w-full rounded-xl bg-gradient-to-r from-agro-600 to-agua-600 px-4 py-3 text-sm font-semibold text-white shadow-md shadow-agro-600/20 transition hover:from-agro-700 hover:to-agua-700 focus:outline-none focus:ring-2 focus:ring-agro-500 focus:ring-offset-2">
          Ingresar al módulo
        </button>
      </form>

      <div class="my-6 flex items-center gap-3 text-xs uppercase tracking-wide text-slate-400">
        <span class="h-px flex-1 bg-slate-200"></span> Asistencia normativa <span class="h-px flex-1 bg-slate-200"></span>
      </div>

      <button type="button" data-open="sence"
              class="flex w-full items-center justify-center gap-2.5 rounded-xl bg-[#0b3d91] px-4 py-3.5 text-sm font-bold text-white shadow-lg shadow-blue-900/25 ring-1 ring-blue-950/20 transition hover:bg-[#093178] focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.6-4.1A12 12 0 0112 2.9 12 12 0 013.4 6 12 12 0 003 9c0 5.6 3.8 10.3 9 11.6 5.2-1.3 9-6 9-11.6 0-1-.1-2-.4-3z" />
        </svg>
        Validar Asistencia con ClaveÚnica / SENCE
      </button>
    </div>

    <div class="card px-5 py-4 text-sm">
      <p class="font-semibold text-slate-900">Mesa de Ayuda y Soporte Técnico</p>
      <p class="mt-1 text-slate-600">
        <a class="font-medium text-agua-700 hover:underline" href="mailto:contacto@ddgdelvalle.cl">contacto@ddgdelvalle.cl</a>
        <span class="text-slate-300">|</span>
        WhatsApp/Tel: <a class="font-medium text-agua-700 hover:underline" href="https://wa.me/56963163859" rel="noopener">+56 9 6316 3859</a>
      </p>
      <p class="text-xs text-slate-500">Lun a Vie 09:00 a 18:00 hrs</p>
      <button type="button" data-open="reglamento" class="mt-3 text-xs font-semibold text-agro-700 underline decoration-dotted underline-offset-2 hover:text-agro-800">
        Reglamento Académico y Guía de Uso de Plataforma
      </button>
    </div>
    </div>
  </div>

<?php else: ?>
  <!-- ============ PORTAL ============ -->
  <header class="no-print relative z-40 border-b sm:sticky sm:top-0 border-slate-200/80 bg-white/90 backdrop-blur-md">
    <div class="mx-auto max-w-5xl px-4 sm:px-6">

      <!-- Fila 1: identidad, rol y salida -->
      <div class="flex flex-wrap items-center justify-between gap-3 py-2.5">
        <div class="flex items-center gap-3">
          <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-gradient-to-br from-agua-500 to-agro-600 text-white">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c.5 2.5-1 5-3.5 6.5C6 11 4.5 13 4.5 15.5A7.5 7.5 0 0012 23a7.5 7.5 0 007.5-7.5C19.5 11 15 6 12 3z" />
            </svg>
          </div>
          <div>
            <p class="font-display text-sm font-bold text-slate-900 leading-tight">Módulo Alumno</p>
            <p class="text-xs text-slate-500">Evapotranspiración y demanda de riego</p>
          </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <div class="flex items-center gap-2 pr-1">
            <span class="max-w-[12rem] truncate text-sm font-semibold text-slate-800" title="<?= htmlspecialchars($nombreUsuario . ' · ' . $rutUsuario) ?>"><?= htmlspecialchars($nombreUsuario) ?></span>
            <?php if ($esAuditor): ?>
              <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide text-amber-800 ring-1 ring-amber-200">Fiscalizador SENCE</span>
            <?php else: ?>
              <span class="rounded-full bg-agro-100 px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide text-agro-800 ring-1 ring-agro-500/30">Participante Activo</span>
            <?php endif; ?>
          </div>
          <?php if ($esAuditor): ?>
            <button type="button" data-tab-go="auditoria"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2">
              Panel de Auditoría
            </button>
          <?php endif; ?>
          <a href="?logout=1" id="btn-salida"
             class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:border-red-200 hover:bg-red-50 hover:text-red-700">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
            </svg>
            Registrar Salida SENCE / Cerrar Sesión
          </a>
        </div>
      </div>

      <!-- Fila 2: control de sesión normativo -->
      <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-slate-100 py-2 text-xs text-slate-600">
        <div class="flex items-center gap-1.5" title="Tiempo desde el inicio de sesión">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-agua-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          <span>Sesión activa</span>
          <span id="cronometro" class="rounded-md bg-slate-900 px-2 py-0.5 font-mono text-[13px] font-semibold tracking-wider text-white" aria-live="off">00:00:00</span>
        </div>

        <div class="flex items-center gap-1.5">
          <span id="inact-dot" class="inline-block h-2 w-2 rounded-full bg-agro-500"></span>
          <span>Control de inactividad activo: cierre automático a los 60 min (Normativa SENCE)</span>
          <span class="font-mono font-semibold text-slate-800" id="inact-restante" title="Tiempo restante hasta el cierre por inactividad">60:00</span>
        </div>

        <div class="flex items-center gap-2 sm:ml-auto">
          <label for="curso-activo" class="font-semibold text-slate-500">Curso activo</label>
          <select id="curso-activo" class="field !w-auto max-w-[19rem] !py-1 !text-xs">
            <option selected>Riego y Evapotranspiración (Riego Tecnificado - SENCE)</option>
            <option disabled>Aplicador de Plaguicidas (En proceso de habilitación de cohorte)</option>
            <option disabled>Estrategias de Inserción Laboral (En proceso de habilitación de cohorte)</option>
          </select>
        </div>
      </div>

      <!-- Pestañas -->
      <nav class="-mb-px flex gap-1 overflow-x-auto border-t border-slate-100" role="tablist" aria-label="Secciones del aula">
        <button type="button" role="tab" class="tab-btn" data-tab="modulos" aria-selected="true">Módulos, Clases (PPTX) y Evaluaciones</button>
        <button type="button" role="tab" class="tab-btn" data-tab="foro" aria-selected="false">Foro de Consultas al Relator</button>
        <button type="button" role="tab" class="tab-btn" data-tab="soporte" aria-selected="false">Soporte Técnico y Reglamento</button>
        <?php if ($esAuditor): ?>
          <button type="button" role="tab" class="tab-btn !text-amber-700" data-tab="auditoria" aria-selected="false">Panel Auditoría y Trazabilidad SENCE</button>
        <?php endif; ?>
      </nav>
    </div>
  </header>

  <!-- Aviso previo al cierre por inactividad -->
  <div id="aviso-inactividad" class="no-print fixed inset-x-0 bottom-4 z-50 mx-auto hidden w-[92%] max-w-lg rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 shadow-xl" role="alertdialog" aria-live="assertive">
    <p class="font-semibold">Tu sesión se cerrará por inactividad en <span id="aviso-restante" class="font-mono">05:00</span></p>
    <p class="mt-0.5 text-xs">Mueve el mouse o presiona una tecla para continuar. Normativa SENCE: cierre a los 60 min sin actividad.</p>
  </div>

  <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-10 space-y-8">

    <!-- ============ PESTAÑA 1 · MÓDULOS, CLASES Y EVALUACIONES ============ -->
    <div id="tab-modulos" data-tab-panel role="tabpanel" class="space-y-8">

    <!-- Progreso curricular -->
    <section class="card no-print p-5 sm:p-6" aria-labelledby="prog-titulo">
      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 id="prog-titulo" class="font-display text-lg font-bold text-slate-900">Progreso curricular</h2>
          <p class="text-xs text-slate-500">Se actualiza al marcar cada módulo como completado.</p>
        </div>
        <p class="font-display text-3xl font-bold text-agro-700"><span id="prog-pct">0</span>%</p>
      </div>
      <div class="relative mt-4">
        <div class="h-3 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="prog-barra-wrap" aria-labelledby="prog-titulo">
          <div id="prog-barra" class="h-full rounded-full bg-gradient-to-r from-agua-500 to-agro-600 transition-all duration-500" style="width:0%"></div>
        </div>
        <span class="absolute -top-1 h-5 w-0.5 bg-amber-500" style="left:75%" title="Mínimo SENCE 75%"></span>
      </div>
      <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
        <?php for ($m = 1; $m <= 4; $m++): ?>
          <a href="#modulo-<?= $m ?>" data-chip="<?= $m ?>" class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-agro-500">
            Módulo <?= $m ?> <span data-chip-estado class="text-slate-400">Pendiente</span>
          </a>
        <?php endfor; ?>
      </div>
      <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900 ring-1 ring-amber-200">
        Requisito mínimo de aprobación SENCE: 75% de avance curricular y nota mínima 4.0
      </p>
      <p id="prog-estado" class="mt-2 text-sm font-medium text-slate-700" role="status"></p>
    </section>

    <div id="modulo-1" class="mt-4 mb-2 flex flex-wrap items-center justify-between gap-3 border-b-2 border-slate-200 pb-2 no-print scroll-mt-48">
      <h2 class="font-display text-2xl font-bold text-slate-900">Módulo 1: Evapotranspiración y demanda de riego</h2>
      <?= ddg_chk_modulo(1) ?>
    </div>

    <!-- Presentación -->
    <section class="card overflow-hidden">
      <div class="border-b border-slate-100 bg-gradient-to-r from-agua-50 to-agro-50 px-5 py-4 sm:px-6">
        <h2 class="font-display text-lg font-bold text-slate-900">Presentación del módulo</h2>
        <p class="mt-0.5 text-sm text-slate-500">Visualiza el material y descárgalo para estudiar offline.</p>
      </div>
      <div class="p-5 sm:p-6">
        <div class="ratio-16-9 overflow-hidden rounded-xl bg-slate-100 ring-1 ring-slate-200">
          <iframe
            loading="lazy"
            src="<?= htmlspecialchars($PPTX_EMBEDS['1']) ?>"
            title="Presentación del módulo"
            allowfullscreen>
          </iframe>
        </div>
        <div class="mt-5 flex justify-center no-print">
          <a href="?download=1"
             class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-agua-600 to-agro-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-agua-600/25 transition hover:from-agua-700 hover:to-agro-700 hover:shadow-xl focus:outline-none focus:ring-2 focus:ring-agua-500 focus:ring-offset-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            Descargar Presentación
          </a>
        </div>
      </div>
    </section>

    <!-- Ejercicios -->
    <section class="space-y-6">
      <div class="px-1">
        <h2 class="font-display text-2xl font-bold text-slate-900">Ejemplo 1. Cálculo de Evapotranspiración de cultivo</h2>
        <p class="mt-2 text-sm leading-relaxed text-slate-600">
          En los distritos agroclimáticos del Valle del Mataquito, según la siguiente tabla complete las siguientes páginas.
        </p>
      </div>

      <!-- Tabla ETo -->
      <div class="card overflow-hidden">
        <div class="border-b border-slate-100 px-5 py-4 sm:px-6">
          <h3 class="font-semibold text-slate-900">Estación: Las Lomas</h3>
          <p class="text-xs text-slate-500">Red Agrometeorológica de INIA · ETo mensual (mm)</p>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[480px] text-left text-sm">
            <thead>
              <tr class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <th class="px-5 py-3 font-semibold sm:px-6">Mes</th>
                <th class="px-5 py-3 font-semibold sm:px-6">ETo (mm)</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100" id="tabla-eto">
              <tr><td class="px-5 py-2.5 sm:px-6">May-2025</td><td class="px-5 py-2.5 font-medium sm:px-6">19,43</td></tr>
              <tr class="bg-slate-50/50"><td class="px-5 py-2.5 sm:px-6">Jun-2025</td><td class="px-5 py-2.5 font-medium sm:px-6">10,83</td></tr>
              <tr><td class="px-5 py-2.5 sm:px-6">Jul-2025</td><td class="px-5 py-2.5 font-medium sm:px-6">14,07</td></tr>
              <tr class="bg-slate-50/50"><td class="px-5 py-2.5 sm:px-6">Ago-2025</td><td class="px-5 py-2.5 font-medium sm:px-6">24,08</td></tr>
              <tr><td class="px-5 py-2.5 sm:px-6">Sep-2025</td><td class="px-5 py-2.5 font-medium sm:px-6">43,48</td></tr>
              <tr class="bg-slate-50/50"><td class="px-5 py-2.5 sm:px-6">Oct-2025</td><td class="px-5 py-2.5 font-medium sm:px-6">72,26</td></tr>
              <tr><td class="px-5 py-2.5 sm:px-6">Nov-2025</td><td class="px-5 py-2.5 font-medium sm:px-6">102,57</td></tr>
              <tr class="bg-slate-50/50"><td class="px-5 py-2.5 sm:px-6">Dic-2025</td><td class="px-5 py-2.5 font-medium sm:px-6">116,45</td></tr>
              <tr class="bg-amber-50"><td class="px-5 py-2.5 font-semibold text-amber-900 sm:px-6">Ene-2026</td><td class="px-5 py-2.5 font-bold text-amber-800 sm:px-6">138,02</td></tr>
              <tr class="bg-slate-50/50"><td class="px-5 py-2.5 sm:px-6">Feb-2026</td><td class="px-5 py-2.5 font-medium sm:px-6">85,92</td></tr>
              <tr><td class="px-5 py-2.5 sm:px-6">Mar-2026</td><td class="px-5 py-2.5 font-medium sm:px-6">35,29</td></tr>
              <tr class="bg-slate-50/50"><td class="px-5 py-2.5 sm:px-6">Abr-2026</td><td class="px-5 py-2.5 font-medium sm:px-6">38,26</td></tr>
              <tr><td class="px-5 py-2.5 sm:px-6">May-2026</td><td class="px-5 py-2.5 font-medium sm:px-6">22,20</td></tr>
              <tr class="bg-slate-50/50"><td class="px-5 py-2.5 sm:px-6">Jun-2026</td><td class="px-5 py-2.5 font-medium sm:px-6">16,91</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Preguntas 1–4 -->
      <div class="card p-5 sm:p-6 space-y-6">
        <h3 class="font-display text-lg font-bold text-slate-900">Preguntas</h3>

        <div class="space-y-2">
          <p class="text-sm font-medium text-slate-700">1. Determine el mes de máxima ETo y su medición.</p>
          <div class="grid gap-3 sm:grid-cols-2">
            <div>
              <label class="mb-1 block text-xs text-slate-500">Mes</label>
              <input type="text" id="q1_mes" class="field" placeholder="Ej: Ene-2026" data-check="mes_max">
            </div>
            <div>
              <label class="mb-1 block text-xs text-slate-500">ETo (mm)</label>
              <input type="number" id="q1_eto" class="field" step="0.01" placeholder="mm" data-check="eto_max">
            </div>
          </div>
        </div>

        <div class="space-y-2">
          <p class="text-sm font-medium text-slate-700">2. ¿Cuánto es el registro de mm/día en el mes de máxima ETo? <span class="font-normal text-slate-500">(Asuma 31 días)</span></p>
          <div class="flex flex-wrap items-center gap-2 text-sm text-slate-600">
            <span>ETo diaria =</span>
            <input type="number" id="q2_mm_dia" class="field field-inline" step="0.01" data-check="eto_diaria">
            <span>mm/día</span>
          </div>
          <p class="text-xs text-slate-400">Fórmula: ETo mensual ÷ 31</p>
        </div>

        <div class="space-y-3">
          <p class="text-sm font-medium text-slate-700">3. Determine el ETc de cerezo (Kc = 0,9) y el ETc de frambuesa (Kc = 0,75).</p>
          <div class="grid gap-3 sm:grid-cols-2">
            <div class="rounded-xl bg-agro-50/60 p-4 ring-1 ring-agro-100">
              <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-agro-700">Cerezo · Kc 0,9</p>
              <div class="flex flex-wrap items-center gap-2 text-sm">
                <span>ETc =</span>
                <input type="number" id="q3_cerezo" class="field field-inline" step="0.01" data-check="etc_cerezo">
                <span>mm/día</span>
              </div>
            </div>
            <div class="rounded-xl bg-agua-50/60 p-4 ring-1 ring-agua-100">
              <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-agua-700">Frambuesa · Kc 0,75</p>
              <div class="flex flex-wrap items-center gap-2 text-sm">
                <span>ETc =</span>
                <input type="number" id="q3_frambuesa" class="field field-inline" step="0.01" data-check="etc_frambuesa">
                <span>mm/día</span>
              </div>
            </div>
          </div>
        </div>

        <div class="space-y-3">
          <p class="text-sm font-medium text-slate-700">4. Transforme el resultado del ejercicio 3 a m³/ha/día. <span class="font-normal text-slate-500">(1 mm = 10 m³/ha)</span></p>
          <div class="grid gap-3 sm:grid-cols-2">
            <div class="flex flex-wrap items-center gap-2 text-sm">
              <span>Cerezo:</span>
              <input type="number" id="q4_cerezo" class="field field-inline" step="0.01" data-check="m3_cerezo">
              <span>m³/ha/día</span>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-sm">
              <span>Frambuesa:</span>
              <input type="number" id="q4_frambuesa" class="field field-inline" step="0.01" data-check="m3_frambuesa">
              <span>m³/ha/día</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Caso práctico -->
      <div class="card p-5 sm:p-6 space-y-6">
        <h3 class="font-display text-lg font-bold text-slate-900">Caso práctico de cálculo</h3>
        <p class="text-sm leading-relaxed text-slate-600">
          En un sector de Mataquito, la máxima evaporación se registró en el mes de diciembre y alcanzó los
          <strong>150 mm/mes</strong>, equivalente a <strong>4,83 mm/día</strong>. Si estamos cultivando
          <strong>10 ha de cerezo</strong> con un marco de plantación de <strong>4 × 2</strong>
          (1.250 plantas/ha), la demanda del cultivo o ETc (con Kc: 0,9) será igual a:
        </p>

        <div class="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-100 space-y-3">
          <p class="text-sm font-medium text-slate-700">ETc = ETo × Kc</p>
          <div class="flex flex-wrap items-center gap-2 text-sm">
            <span>ETc:</span>
            <input type="number" id="caso_etc" class="field field-inline" step="0.01" data-check="caso_etc"
                   placeholder="resultado">
            <span>=</span>
            <input type="number" id="caso_etc_mm" class="field field-inline" step="0.01" data-check="caso_etc"
                   placeholder="mm/día">
            <span>mm/día</span>
          </div>
        </div>

        <div class="space-y-3">
          <p class="text-sm font-medium text-slate-700">¿Cómo transformar mm/día en litros/planta/día?</p>
          <p class="text-sm text-slate-600 font-mono bg-slate-50 rounded-lg px-3 py-2 ring-1 ring-slate-100">
            D.N.C = (ETc × M.P × P.C (%)) / 100
          </p>
          <p class="text-xs text-slate-500">
            M.P = área por planta = 4 × 2 = 8 m² · P.C (porcentaje de cubrimiento) = <strong>90%</strong>
          </p>
          <div class="space-y-2 text-sm">
            <div class="flex flex-wrap items-center gap-2">
              <span>D.N.C =</span>
              <input type="text" id="caso_dnc_formula" class="field" style="max-width:22rem" placeholder="(ETc × 8 × 90) / 100">
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <span>D.N.C =</span>
              <input type="number" id="caso_dnc" class="field field-inline" step="0.01" data-check="dnc">
              <span>Litros/planta/día</span>
            </div>
          </div>
        </div>

        <!-- Tabla eficiencia -->
        <div>
          <p class="mb-3 text-sm font-medium text-slate-700">
            Los métodos de riego no tienen 100% de eficiencia. Tabla de referencia:
          </p>
          <div class="overflow-x-auto rounded-xl ring-1 ring-slate-200">
            <table class="w-full min-w-[360px] text-sm">
              <thead>
                <tr class="bg-agua-50 text-xs uppercase tracking-wide text-agua-700">
                  <th class="px-4 py-2.5 text-left font-semibold">Método</th>
                  <th class="px-4 py-2.5 text-left font-semibold">Eficiencia</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr><td class="px-4 py-2">Tendido</td><td class="px-4 py-2">30%</td></tr>
                <tr class="bg-slate-50/50"><td class="px-4 py-2">Surcos</td><td class="px-4 py-2">45%</td></tr>
                <tr><td class="px-4 py-2">Aspersión</td><td class="px-4 py-2">75%</td></tr>
                <tr class="bg-slate-50/50"><td class="px-4 py-2">Micro-aspersión</td><td class="px-4 py-2">85%</td></tr>
                <tr class="bg-agro-50"><td class="px-4 py-2 font-semibold text-agro-800">Goteo</td><td class="px-4 py-2 font-semibold text-agro-800">90%</td></tr>
                <tr class="bg-slate-50/50"><td class="px-4 py-2">Cinta</td><td class="px-4 py-2">90%</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="space-y-2">
          <p class="text-sm font-medium text-slate-700">
            Calcule la Demanda Bruta o Real del Cultivo (D.B.C), usando riego por goteo (90%).
          </p>
          <p class="text-sm text-slate-600 font-mono bg-slate-50 rounded-lg px-3 py-2 ring-1 ring-slate-100">
            D.B.C = (D.N.C × 100) / 90
          </p>
          <div class="flex flex-wrap items-center gap-2 text-sm">
            <span>D.B.C =</span>
            <input type="number" id="caso_dbc" class="field field-inline" step="0.01" data-check="dbc">
            <span>Litros/planta/día</span>
          </div>
        </div>

        <div class="space-y-3 border-t border-slate-100 pt-5">
          <h4 class="font-semibold text-slate-900">Tiempo de Riego Diario (T.R.D)</h4>
          <p class="text-sm text-slate-600 font-mono bg-slate-50 rounded-lg px-3 py-2 ring-1 ring-slate-100">
            T.R.D = D.B.C / (n.g × Qg)
          </p>
          <p class="text-xs text-slate-500">
            n.g = 2 goteros/planta · Qg = 4 L/hr
          </p>
          <div class="flex flex-wrap items-center gap-2 text-sm">
            <span>T.R.D =</span>
            <input type="number" id="caso_trd" class="field field-inline" step="0.01" data-check="trd">
            <span>Horas/Día</span>
          </div>
        </div>

        <div class="space-y-4 border-t border-slate-100 pt-5">
          <p class="text-sm font-medium text-slate-700">
            ¿Cuántas horas/días dispongo para el riego del huerto?
            <span class="font-normal text-slate-500">(Pequeños agricultores: 12 h · Grandes: 18 h)</span>
          </p>

          <div class="rounded-xl bg-agro-50/50 p-4 ring-1 ring-agro-100 space-y-2">
            <p class="text-xs font-semibold uppercase tracking-wide text-agro-700">Pequeños agricultores</p>
            <div class="flex flex-wrap items-center gap-2 text-sm">
              <span>Nº sectores = 12 /</span>
              <input type="number" id="sect_peq_trd" class="field field-inline" step="0.01" data-check="trd" placeholder="T.R.D">
              <span>=</span>
              <input type="number" id="sect_peq" class="field field-inline" step="0.01" data-check="sectores_peq">
              <span>sectores</span>
            </div>
          </div>

          <div class="rounded-xl bg-agua-50/50 p-4 ring-1 ring-agua-100 space-y-2">
            <p class="text-xs font-semibold uppercase tracking-wide text-agua-700">Grandes agricultores</p>
            <div class="flex flex-wrap items-center gap-2 text-sm">
              <span>Nº sectores = 18 /</span>
              <input type="number" id="sect_gran_trd" class="field field-inline" step="0.01" data-check="trd" placeholder="T.R.D">
              <span>=</span>
              <input type="number" id="sect_gran" class="field field-inline" step="0.01" data-check="sectores_gran">
              <span>sectores</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Desarrollo cualitativo -->
      <div class="card p-5 sm:p-6 space-y-5">
        <h3 class="font-display text-lg font-bold text-slate-900">Desarrollo de preguntas</h3>

        <div>
          <label for="resp_21" class="mb-2 block text-sm font-medium text-slate-700">
            2.1.— Justifique brevemente cómo y/o por qué la implementación de un sistema de riego tecnificado puede ayudar en los periodos de escasez hídrica. Realice un análisis de su sistema productivo predial.
          </label>
          <textarea id="resp_21" rows="5" class="field" placeholder="Escriba su justificación aquí..."></textarea>
        </div>

        <div>
          <label for="resp_22" class="mb-2 block text-sm font-medium text-slate-700">
            2.2.— Según los datos de evapotranspiración, señale los tres meses de mayor demanda de agua para los cultivos y explique 3 factores que ocurren en esos meses a los cultivos de frutales.
          </label>
          <textarea id="resp_22" rows="5" class="field" placeholder="Meses de mayor demanda y factores..."></textarea>
        </div>
      </div>

      <!-- Acciones -->
      <div class="card p-5 sm:p-6 no-print">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h3 class="font-semibold text-slate-900">Evaluación e impresión</h3>
            <p class="text-xs text-slate-500">Valida tus cálculos numéricos o guarda tus respuestas en PDF.</p>
          </div>
          <div class="flex flex-col gap-2 sm:flex-row">
            <button type="button" id="btn-evaluar"
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-agro-600 to-agro-700 px-5 py-2.5 text-sm font-semibold text-white shadow-md shadow-agro-600/20 transition hover:from-agro-700 hover:to-agro-800 focus:outline-none focus:ring-2 focus:ring-agro-500 focus:ring-offset-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              Evaluar mis cálculos
            </button>
            <button type="button" id="btn-imprimir"
                    class="inline-flex items-center justify-center gap-2 rounded-xl border border-agua-200 bg-agua-50 px-5 py-2.5 text-sm font-semibold text-agua-700 transition hover:bg-agua-100 focus:outline-none focus:ring-2 focus:ring-agua-500 focus:ring-offset-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
              </svg>
              Imprimir mis respuestas
            </button>
          </div>
        </div>
        <div id="resultado-eval" class="mt-4 hidden rounded-xl px-4 py-3 text-sm font-medium" role="status"></div>
      </div>
    </section>

    <!-- ============ MÓDULO 2 ============ -->
    <div id="modulo-2" class="mt-12 mb-6 flex flex-wrap items-center justify-between gap-3 border-b-2 border-slate-200 pb-2 no-print scroll-mt-48">
      <h2 class="font-display text-2xl font-bold text-slate-900">Módulo 2: Componentes de un sistema de riego presurizado</h2>
      <?= ddg_chk_modulo(2) ?>
    </div>

    <section class="card overflow-hidden mb-8">
      <div class="border-b border-slate-100 bg-gradient-to-r from-agua-50 to-agro-50 px-5 py-4 sm:px-6">
        <h3 class="font-display text-lg font-bold text-slate-900">Presentación del módulo</h3>
        <p class="mt-0.5 text-sm text-slate-500">Visualiza el material y descárgalo para estudiar offline.</p>
      </div>
      <div class="p-5 sm:p-6">
        <div class="ratio-16-9 overflow-hidden rounded-xl bg-slate-100 ring-1 ring-slate-200">
          <iframe
            loading="lazy"
            src="<?= htmlspecialchars($PPTX_EMBEDS['2']) ?>"
            title="Presentación Módulo 2"
            allowfullscreen>
          </iframe>
        </div>
        <div class="mt-5 flex justify-center no-print">
          <a href="?download=2"
             class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-agua-600 to-agro-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-agua-600/25 transition hover:from-agua-700 hover:to-agro-700 hover:shadow-xl focus:outline-none focus:ring-2 focus:ring-agua-500 focus:ring-offset-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            Descargar Presentación
          </a>
        </div>
      </div>
    </section>

    <section class="card p-5 sm:p-6 space-y-6 mb-12">
      <h3 class="font-display text-lg font-bold text-slate-900">Preguntas</h3>

      <div>
        <label for="m2_q1" class="mb-2 block text-sm font-medium text-slate-700">
          2.1.- Mencione y comente brevemente 3 características de una bomba de inyección de fertilizantes.
        </label>
        <textarea id="m2_q1" class="field" rows="3" placeholder="Escriba su respuesta aquí..."></textarea>
      </div>

      <div>
        <label for="m2_q2" class="mb-2 block text-sm font-medium text-slate-700">
          2.2.- Realice un cuadro comparativo entre los siguientes goteros: gotero autocompensado, gotero incorporado, gotero en línea y microjet, en lo que respecta a: mecanismos de compensación, rango de presión, uniformidad de riego y sensibilidad a filtración del agua.
        </label>
        <textarea id="m2_q2" class="field" rows="3" placeholder="Escriba su cuadro comparativo aquí..."></textarea>
      </div>

      <div>
        <label for="m2_q3" class="mb-2 block text-sm font-medium text-slate-700">
          2.3.- Señale brevemente cómo funcionan los emisores autocompensados.
        </label>
        <textarea id="m2_q3" class="field" rows="3" placeholder="Escriba su respuesta aquí..."></textarea>
      </div>

      <div>
        <label for="m2_q4" class="mb-2 block text-sm font-medium text-slate-700">
          2.4.- Menciones los principales componentes de un cabezal de riego y explique brevemente la función de cada uno de ellos.
        </label>
        <textarea id="m2_q4" class="field" rows="3" placeholder="Escriba su respuesta aquí..."></textarea>
      </div>

      <div>
        <label for="m2_q5" class="mb-2 block text-sm font-medium text-slate-700">
          2.5.- En un proyecto de riego se debe instalar una matriz de PVC PN6 de 125 mm. Sin embargo, el distribuidor no tiene en stock tuberías de ese diámetro y le ofrece cambiarlas por Tuberías de 110 mm o de 140 mm PN6. Comente cuales serían las consecuencias técnicas de hacer uno u otro cambio.
        </label>
        <textarea id="m2_q5" class="field" rows="3" placeholder="Escriba su análisis técnico aquí..."></textarea>
      </div>
    </section>

    <!-- ============ MÓDULO 3 ============ -->
    <div id="modulo-3" class="mt-12 mb-6 flex flex-wrap items-center justify-between gap-3 border-b-2 border-slate-200 pb-2 no-print scroll-mt-48">
      <h2 class="font-display text-2xl font-bold text-slate-900">Módulo 3: Mantención de Sistema de Riego</h2>
      <?= ddg_chk_modulo(3) ?>
    </div>

    <section class="card overflow-hidden mb-8">
      <div class="border-b border-slate-100 bg-gradient-to-r from-agua-50 to-agro-50 px-5 py-4 sm:px-6">
        <h3 class="font-display text-lg font-bold text-slate-900">Presentación del módulo</h3>
        <p class="mt-0.5 text-sm text-slate-500">Visualiza el material y descárgalo para estudiar offline.</p>
      </div>
      <div class="p-5 sm:p-6">
        <div class="ratio-16-9 overflow-hidden rounded-xl bg-slate-100 ring-1 ring-slate-200">
          <iframe
            loading="lazy"
            src="<?= htmlspecialchars($PPTX_EMBEDS['3']) ?>"
            title="Presentación Módulo 3"
            allowfullscreen>
          </iframe>
        </div>
        <div class="mt-5 flex justify-center no-print">
          <a href="?download=3"
             class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-agua-600 to-agro-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-agua-600/25 transition hover:from-agua-700 hover:to-agro-700 hover:shadow-xl focus:outline-none focus:ring-2 focus:ring-agua-500 focus:ring-offset-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            Descargar Presentación
          </a>
        </div>
      </div>
    </section>

    <section class="card p-5 sm:p-6 space-y-6 mb-12">
      <h3 class="font-display text-lg font-bold text-slate-900">Preguntas</h3>

      <div>
        <label for="m3_q1" class="mb-2 block text-sm font-medium text-slate-700">
          3.1.- Cuáles son los principales inconvenientes que los agricultores deben enfrentar en las mantenciones de las fuentes de agua (tranques o estanques acumuladores) y señale cómo solucionarlo.
        </label>
        <textarea id="m3_q1" class="field" rows="3" placeholder="Escriba su respuesta aquí..."></textarea>
      </div>

      <div>
        <label for="m3_q2" class="mb-2 block text-sm font-medium text-slate-700">
          3.2.- Describa brevemente las causas que podrían producir alzas de temperatura en una bomba de riego, sus consecuencias y soluciones.
        </label>
        <textarea id="m3_q2" class="field" rows="3" placeholder="Escriba su respuesta aquí..."></textarea>
      </div>

      <div>
        <label for="m3_q3" class="mb-2 block text-sm font-medium text-slate-700">
          3.3.- Cuál es la importancia de realizar un “Lavado Mecánico” de las tuberías de riego y cuando recomendaría hacerlo.
        </label>
        <textarea id="m3_q3" class="field" rows="3" placeholder="Escriba su respuesta aquí..."></textarea>
      </div>

      <div>
        <p class="mb-4 text-sm font-medium text-slate-700">
          3.4.- Una auditoria a los sistemas de riego de La Agrícola San Jose de la región de Los Ríos señaló que tenían serios problemas de mantención de sus sistemas. Por ello, lo contrata como asesor para hacer un plan de trabajo de mantención. Señale cuáles son los elementos o componentes del sistema de riego que usted consideraría y cuál sería la programación para realizar la mantención. Complete el siguientes cuadro según los equipos que debiera revisar o chequear, complete el inicio (antes de usar el equipo), durante el funcionamiento y al termino cuando ya haya concluido el uso o el riego efectuado.
        </p>
        <div class="overflow-x-auto rounded-xl ring-1 ring-slate-200">
          <table class="w-full min-w-[640px] text-sm">
            <thead>
              <tr class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <th scope="col" class="px-3 py-3 text-left font-semibold sm:px-4">Equipos</th>
                <th scope="col" class="px-3 py-3 text-left font-semibold sm:px-4">Inicio</th>
                <th scope="col" class="px-3 py-3 text-left font-semibold sm:px-4">Durante</th>
                <th scope="col" class="px-3 py-3 text-left font-semibold sm:px-4">Termino</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr>
                <th scope="row" class="px-3 py-2.5 text-left font-medium text-slate-800 sm:px-4">Filtros de riego</th>
                <td class="px-2 py-2 sm:px-3"><input type="text" class="field text-xs px-2 py-1.5" placeholder="..." aria-label="Filtros de riego - Inicio"></td>
                <td class="px-2 py-2 sm:px-3"><input type="text" class="field text-xs px-2 py-1.5" placeholder="..." aria-label="Filtros de riego - Durante"></td>
                <td class="px-2 py-2 sm:px-3"><input type="text" class="field text-xs px-2 py-1.5" placeholder="..." aria-label="Filtros de riego - Termino"></td>
              </tr>
              <tr class="bg-slate-50/50">
                <th scope="row" class="px-3 py-2.5 text-left font-medium text-slate-800 sm:px-4">Electrobombas</th>
                <td class="px-2 py-2 sm:px-3"><input type="text" class="field text-xs px-2 py-1.5" placeholder="..." aria-label="Electrobombas - Inicio"></td>
                <td class="px-2 py-2 sm:px-3"><input type="text" class="field text-xs px-2 py-1.5" placeholder="..." aria-label="Electrobombas - Durante"></td>
                <td class="px-2 py-2 sm:px-3"><input type="text" class="field text-xs px-2 py-1.5" placeholder="..." aria-label="Electrobombas - Termino"></td>
              </tr>
              <tr>
                <th scope="row" class="px-3 py-2.5 text-left font-medium text-slate-800 sm:px-4">Válvulas</th>
                <td class="px-2 py-2 sm:px-3"><input type="text" class="field text-xs px-2 py-1.5" placeholder="..." aria-label="Válvulas - Inicio"></td>
                <td class="px-2 py-2 sm:px-3"><input type="text" class="field text-xs px-2 py-1.5" placeholder="..." aria-label="Válvulas - Durante"></td>
                <td class="px-2 py-2 sm:px-3"><input type="text" class="field text-xs px-2 py-1.5" placeholder="..." aria-label="Válvulas - Termino"></td>
              </tr>
              <tr class="bg-slate-50/50">
                <th scope="row" class="px-3 py-2.5 text-left font-medium text-slate-800 sm:px-4">Tablero eléctrico y programador</th>
                <td class="px-2 py-2 sm:px-3"><input type="text" class="field text-xs px-2 py-1.5" placeholder="..." aria-label="Tablero eléctrico y programador - Inicio"></td>
                <td class="px-2 py-2 sm:px-3"><input type="text" class="field text-xs px-2 py-1.5" placeholder="..." aria-label="Tablero eléctrico y programador - Durante"></td>
                <td class="px-2 py-2 sm:px-3"><input type="text" class="field text-xs px-2 py-1.5" placeholder="..." aria-label="Tablero eléctrico y programador - Termino"></td>
              </tr>
              <tr>
                <th scope="row" class="px-3 py-2.5 text-left font-medium text-slate-800 sm:px-4">Tuberías (matrices y sub matrices)</th>
                <td class="px-2 py-2 sm:px-3"><input type="text" class="field text-xs px-2 py-1.5" placeholder="..." aria-label="Tuberías - Inicio"></td>
                <td class="px-2 py-2 sm:px-3"><input type="text" class="field text-xs px-2 py-1.5" placeholder="..." aria-label="Tuberías - Durante"></td>
                <td class="px-2 py-2 sm:px-3"><input type="text" class="field text-xs px-2 py-1.5" placeholder="..." aria-label="Tuberías - Termino"></td>
              </tr>
              <tr class="bg-slate-50/50">
                <th scope="row" class="px-3 py-2.5 text-left font-medium text-slate-800 sm:px-4">Emisores (goteros)</th>
                <td class="px-2 py-2 sm:px-3"><input type="text" class="field text-xs px-2 py-1.5" placeholder="..." aria-label="Emisores - Inicio"></td>
                <td class="px-2 py-2 sm:px-3"><input type="text" class="field text-xs px-2 py-1.5" placeholder="..." aria-label="Emisores - Durante"></td>
                <td class="px-2 py-2 sm:px-3"><input type="text" class="field text-xs px-2 py-1.5" placeholder="..." aria-label="Emisores - Termino"></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- ============ MÓDULO 4 ============ -->
    <div id="modulo-4" class="mt-12 mb-6 flex flex-wrap items-center justify-between gap-3 border-b-2 border-slate-200 pb-2 no-print scroll-mt-48">
      <h2 class="font-display text-2xl font-bold text-slate-900">Módulo 4: Implementación y Manejo de Sistemas de Riego Tecnificado Parte IV</h2>
      <?= ddg_chk_modulo(4) ?>
    </div>

    <section class="card overflow-hidden mb-8">
      <div class="border-b border-slate-100 bg-gradient-to-r from-agua-50 to-agro-50 px-5 py-4 sm:px-6">
        <h3 class="font-display text-lg font-bold text-slate-900">Presentación del módulo</h3>
        <p class="mt-0.5 text-sm text-slate-500">Visualiza el material y descárgalo para estudiar offline.</p>
      </div>
      <div class="p-5 sm:p-6">
        <div class="ratio-16-9 overflow-hidden rounded-xl bg-slate-100 ring-1 ring-slate-200">
          <iframe
            loading="lazy"
            src="<?= htmlspecialchars($PPTX_EMBEDS['4']) ?>"
            title="Presentación Módulo 4"
            allowfullscreen>
          </iframe>
        </div>
        <div class="mt-5 flex justify-center no-print">
          <a href="?download=4"
             class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-agua-600 to-agro-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-agua-600/25 transition hover:from-agua-700 hover:to-agro-700 hover:shadow-xl focus:outline-none focus:ring-2 focus:ring-agua-500 focus:ring-offset-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            Descargar Presentación
          </a>
        </div>
      </div>
    </section>

    <section class="card p-5 sm:p-6 space-y-6 mb-12">
      <h3 class="font-display text-lg font-bold text-slate-900">Preguntas</h3>

      <div>
        <label for="m4_q1" class="mb-2 block text-sm font-medium text-slate-700">
          4.1.- Liste cronológicamente las labores que se deben realizar en una plantación de frambuesas.
        </label>
        <textarea id="m4_q1" class="field" rows="4" placeholder="Liste las labores en orden cronológico..."></textarea>
      </div>

      <div class="space-y-4">
        <p class="text-sm font-medium text-slate-700">
          4.2.- Cálculo del Coeficiente de Uniformidad (CU). A partir de los caudales medidos (lt/hr), calcule el caudal promedio, el caudal Q25% y el CU.
        </p>
        <div class="overflow-x-auto rounded-xl ring-1 ring-slate-200">
          <table class="w-full min-w-[320px] text-sm">
            <caption class="sr-only">Caudales medidos en lt/hr para cálculo de CU</caption>
            <thead>
              <tr class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <th scope="col" class="px-3 py-2 text-left font-semibold">#</th>
                <th scope="col" class="px-3 py-2 text-left font-semibold">Caudal (lt/hr)</th>
                <th scope="col" class="px-3 py-2 text-left font-semibold">#</th>
                <th scope="col" class="px-3 py-2 text-left font-semibold">Caudal (lt/hr)</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr>
                <th scope="row" class="px-3 py-1.5 text-left font-medium text-slate-500">1</th>
                <td class="px-3 py-1.5 font-medium">4,5</td>
                <th scope="row" class="px-3 py-1.5 text-left font-medium text-slate-500">9</th>
                <td class="px-3 py-1.5 font-medium">1,7</td>
              </tr>
              <tr class="bg-slate-50/50">
                <th scope="row" class="px-3 py-1.5 text-left font-medium text-slate-500">2</th>
                <td class="px-3 py-1.5 font-medium">2,8</td>
                <th scope="row" class="px-3 py-1.5 text-left font-medium text-slate-500">10</th>
                <td class="px-3 py-1.5 font-medium">4,5</td>
              </tr>
              <tr>
                <th scope="row" class="px-3 py-1.5 text-left font-medium text-slate-500">3</th>
                <td class="px-3 py-1.5 font-medium">4,2</td>
                <th scope="row" class="px-3 py-1.5 text-left font-medium text-slate-500">11</th>
                <td class="px-3 py-1.5 font-medium">3,3</td>
              </tr>
              <tr class="bg-slate-50/50">
                <th scope="row" class="px-3 py-1.5 text-left font-medium text-slate-500">4</th>
                <td class="px-3 py-1.5 font-medium">2,6</td>
                <th scope="row" class="px-3 py-1.5 text-left font-medium text-slate-500">12</th>
                <td class="px-3 py-1.5 font-medium">3,7</td>
              </tr>
              <tr>
                <th scope="row" class="px-3 py-1.5 text-left font-medium text-slate-500">5</th>
                <td class="px-3 py-1.5 font-medium">3,7</td>
                <th scope="row" class="px-3 py-1.5 text-left font-medium text-slate-500">13</th>
                <td class="px-3 py-1.5 font-medium">4,0</td>
              </tr>
              <tr class="bg-slate-50/50">
                <th scope="row" class="px-3 py-1.5 text-left font-medium text-slate-500">6</th>
                <td class="px-3 py-1.5 font-medium">2,5</td>
                <th scope="row" class="px-3 py-1.5 text-left font-medium text-slate-500">14</th>
                <td class="px-3 py-1.5 font-medium">3,1</td>
              </tr>
              <tr>
                <th scope="row" class="px-3 py-1.5 text-left font-medium text-slate-500">7</th>
                <td class="px-3 py-1.5 font-medium">5,1</td>
                <th scope="row" class="px-3 py-1.5 text-left font-medium text-slate-500">15</th>
                <td class="px-3 py-1.5 font-medium">1,8</td>
              </tr>
              <tr class="bg-slate-50/50">
                <th scope="row" class="px-3 py-1.5 text-left font-medium text-slate-500">8</th>
                <td class="px-3 py-1.5 font-medium">5,0</td>
                <th scope="row" class="px-3 py-1.5 text-left font-medium text-slate-500">16</th>
                <td class="px-3 py-1.5 font-medium">2,5</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="grid gap-3 sm:grid-cols-3">
          <div class="flex flex-wrap items-center gap-2 text-sm">
            <label for="m4_promedio" class="font-medium text-slate-700">Caudal Promedio:</label>
            <input type="number" id="m4_promedio" class="field field-inline" step="0.01" placeholder="lt/hr">
          </div>
          <div class="flex flex-wrap items-center gap-2 text-sm">
            <label for="m4_q25" class="font-medium text-slate-700">Caudal Q25%:</label>
            <input type="number" id="m4_q25" class="field field-inline" step="0.01" placeholder="lt/hr">
          </div>
          <div class="flex flex-wrap items-center gap-2 text-sm">
            <label for="m4_cu" class="font-medium text-slate-700">CU:</label>
            <input type="number" id="m4_cu" class="field field-inline" step="0.01" placeholder="%">
            <span class="text-slate-500">%</span>
          </div>
        </div>
        <div>
          <label for="m4_q2_comentario" class="mb-2 block text-sm font-medium text-slate-700">
            Comentario técnico: compare el caudal promedio obtenido con el emisor insertado de 3,8 lt/hr.
          </label>
          <textarea id="m4_q2_comentario" class="field" rows="3" placeholder="Escriba su análisis comparativo aquí..."></textarea>
        </div>
      </div>

      <div>
        <label for="m4_q3" class="mb-2 block text-sm font-medium text-slate-700">
          4.3.- Identifique las partículas que provocan taponamiento en los emisores y señale posibles soluciones.
        </label>
        <textarea id="m4_q3" class="field" rows="3" placeholder="Partículas de taponamiento y soluciones..."></textarea>
      </div>

      <div>
        <label for="m4_q4" class="mb-2 block text-sm font-medium text-slate-700">
          4.4.- Detalle los 4 puntos de Información Básica de terreno que se deben considerar.
        </label>
        <textarea id="m4_q4" class="field" rows="3" placeholder="Detalle los 4 puntos de información básica..."></textarea>
      </div>
    </section>

    <?php
    $eval_sm = [
      [
        'q' => '¿Qué representa la evapotranspiración de referencia (ETo)?',
        'opts' => [
          'a' => 'La cantidad de agua que pierde un cultivo específico bajo condiciones de estrés hídrico',
          'b' => 'La pérdida de agua por evaporación del suelo y transpiración de un cultivo de referencia (pasto), bajo condiciones estándar',
          'c' => 'El volumen de agua aplicado por el sistema de riego',
          'd' => 'La cantidad de agua retenida por el suelo a capacidad de campo',
        ],
      ],
      [
        'q' => '¿Cuál de los siguientes factores climáticos NO se utiliza en el método de Penman-Monteith para calcular la ETo de un cultivo?',
        'opts' => [
          'a' => 'Radiación solar',
          'b' => 'Temperatura del aire',
          'c' => 'Velocidad del viento',
          'd' => 'pH del suelo',
        ],
      ],
      [
        'q' => 'El coeficiente de cultivo (Kc) varía principalmente según:',
        'opts' => [
          'a' => 'El tipo de fuente de agua utilizada',
          'b' => 'La etapa fenológica del cultivo',
          'c' => 'El material del gotero',
          'd' => 'La presión de la red de riego',
        ],
      ],
      [
        'q' => 'En la etapa inicial de desarrollo de un cultivo, el valor de Kc generalmente es:',
        'opts' => [
          'a' => 'Muy alto',
          'b' => 'Bajo, debido a la escasa cobertura vegetal',
          'c' => 'El Kc es igual en toda la etapa de crecimiento del cultivo',
          'd' => 'Siempre igual a cero',
        ],
      ],
      [
        'q' => 'El cabezal de riego de un sistema tecnificado normalmente incluye:',
        'opts' => [
          'a' => 'Solo la fuente de captación de agua',
          'b' => 'Válvulas de aire, filtros, sistema de fertirriego, medidor de caudal y controlador',
          'c' => 'Únicamente las tuberías de conducción principal',
          'd' => 'Los goteros y la submatriz',
        ],
      ],
      [
        'q' => '¿Cuál es la función principal del cabezal de riego dentro de un sistema tecnificado?',
        'opts' => [
          'a' => 'Elevar el terreno para facilitar el drenaje',
          'b' => 'Controlar, filtrar, medir y dosificar el agua y los fertilizantes antes de su distribución',
          'c' => 'Almacenar agua de lluvia exclusivamente',
          'd' => 'Reemplazar la función de la electrobomba',
        ],
      ],
      [
        'q' => 'Una electrobomba superficial se caracteriza por:',
        'opts' => [
          'a' => 'Estar instalada sumergida dentro del pozo o fuente de agua',
          'b' => 'Ubicarse fuera del agua, succionando el fluido a través de una tubería de aspiración',
          'c' => 'No requerir cebado antes de su funcionamiento',
          'd' => 'Utilizarse exclusivamente en pozos profundos',
        ],
      ],
      [
        'q' => 'Un problema común en electrobombas superficiales relacionado con la altura de succión es:',
        'opts' => [
          'a' => 'La sobrepresión en la descarga',
          'b' => 'La cavitación, cuando se supera la altura máxima de succión permitida',
          'c' => 'El exceso de filtración de arena',
          'd' => 'La corrosión del rodete por exceso de cloro',
        ],
      ],
      [
        'q' => '¿Qué tipo de filtro es más recomendable cuando la fuente de agua contiene alto contenido de algas y materia orgánica?',
        'opts' => [
          'a' => 'Filtro de malla',
          'b' => 'Filtro de arena o grava (media filtrante)',
          'c' => 'Filtro de disco exclusivamente',
          'd' => 'Ningún filtro es necesario en ese caso',
        ],
      ],
      [
        'q' => 'La pérdida de carga en un filtro de riego debe monitorearse principalmente para:',
        'opts' => [
          'a' => 'Determinar cuándo se debe realizar el retrolavado o limpieza del filtro',
          'b' => 'Calcular el coeficiente de cultivo',
          'c' => 'Definir la dosis de fertilizante',
          'd' => 'Establecer la profundidad de plantación',
        ],
      ],
      [
        'q' => 'Un programador de riego (controlador) permite principalmente:',
        'opts' => [
          'a' => 'Medir la evapotranspiración del cultivo en tiempo real sin sensores',
          'b' => 'Automatizar el inicio, duración y frecuencia del riego según la programación establecida',
          'c' => 'Reemplazar la necesidad de un cabezal de riego',
          'd' => 'Filtrar el agua antes de su distribución',
        ],
      ],
      [
        'q' => 'Los programadores de riego más avanzados permiten integrar:',
        'opts' => [
          'a' => 'Sensores de humedad de suelo y datos climáticos para riego por demanda',
          'b' => 'Solo el encendido manual de válvulas',
          'c' => 'Un sistema de facturación eléctrica',
          'd' => 'La certificación de derechos de agua',
        ],
      ],
      [
        'q' => '¿Cuál de las siguientes acciones corresponde a una mantención preventiva típica en un sistema de riego tecnificado?',
        'opts' => [
          'a' => 'Esperar a que el sistema falle completamente antes de intervenir',
          'b' => 'Revisión periódica de presiones, limpieza de filtros y verificación de fugas',
          'c' => 'Aumentar la presión de la bomba sin diagnóstico previo',
          'd' => 'Eliminar los filtros para mejorar el caudal',
        ],
      ],
      [
        'q' => 'La falta de mantención adecuada de un sistema de riego tecnificado puede provocar principalmente:',
        'opts' => [
          'a' => 'Mejora en la uniformidad de riego',
          'b' => 'Obstrucción de emisores, pérdida de uniformidad y menor eficiencia del sistema',
          'c' => 'Reducción del consumo eléctrico de la electrobomba',
          'd' => 'Aumento de la vida útil de los goteros',
        ],
      ],
      [
        'q' => 'La limpieza periódica de la submatriz (líneas laterales) tiene como principal objetivo:',
        'opts' => [
          'a' => 'Aumentar la presión de trabajo del sistema',
          'b' => 'Eliminar sedimentos y partículas que puedan obstruir los goteros',
          'c' => 'Modificar el coeficiente de cultivo',
          'd' => 'Sustituir la necesidad de programadores',
        ],
      ],
      [
        'q' => 'Un método común para la limpieza de goteros y submatriz es:',
        'opts' => [
          'a' => 'La aplicación de ácido o cloro en dosis controladas mediante inyección, seguida de purga de las líneas',
          'b' => 'El uso exclusivo de agua a alta temperatura sin productos químicos',
          'c' => 'La eliminación de los filtros de la red',
          'd' => 'El aumento de la frecuencia de riego sin realizar purgas',
        ],
      ],
      [
        'q' => 'Para la implementación de un sistema de riego tecnificado, es importante evaluar principalmente:',
        'opts' => [
          'a' => 'Solo el color del suelo',
          'b' => 'La topografía, textura y estructura del suelo, y la calidad y disponibilidad de agua',
          'c' => 'Únicamente la cercanía a la red eléctrica',
          'd' => 'La presencia de malezas en el predio',
        ],
      ],
      [
        'q' => 'Un terreno con pendientes pronunciadas e irregulares generalmente requiere:',
        'opts' => [
          'a' => 'Un diseño hidráulico que considere sectores de riego adaptados a la topografía, o el uso de válvulas reguladoras de presión',
          'b' => 'Ningún ajuste especial respecto a un terreno plano',
          'c' => 'Eliminar el uso de filtros',
          'd' => 'Un único sector de riego para todo el predio, sin importar la pendiente',
        ],
      ],
      [
        'q' => 'El Coeficiente de Uniformidad (CU) de un sistema de riego tecnificado mide:',
        'opts' => [
          'a' => 'La cantidad total de agua aplicada durante toda la temporada',
          'b' => 'Qué tan homogénea es la distribución del caudal entregado por los emisores en el sector de riego',
          'c' => 'El costo de la energía eléctrica utilizada por la electrobomba',
          'd' => 'La cantidad de fertilizante aplicado por hectárea',
        ],
      ],
      [
        'q' => 'Un Coeficiente de Uniformidad (CU) considerado aceptable en un sistema de riego tecnificado bien diseñado y mantenido debe ser, en general:',
        'opts' => [
          'a' => 'Menor al 50%',
          'b' => 'Superior al 90%',
          'c' => 'Igual al 0%',
          'd' => 'No es un parámetro relevante para el riego tecnificado',
        ],
      ],
    ];

    $eval_vf = [
      'La evapotranspiración de referencia (ETo) representa la demanda hídrica de un cultivo específico en su etapa de máximo desarrollo.',
      'El coeficiente de cultivo (Kc) permite ajustar la ETo para obtener la evapotranspiración real de un cultivo específico (ETc).',
      'El cabezal de riego se ubica normalmente al inicio del sistema, antes de la red de distribución.',
      'Las electrobombas superficiales pueden funcionar correctamente sin importar la altura de succión desde la fuente de agua.',
      'Los filtros de malla son generalmente más adecuados para retener partículas orgánicas como algas que los filtros de arena.',
      'Los programadores de riego solo pueden operar de forma manual, sin posibilidad de automatización.',
      'La mantención preventiva de un sistema de riego tecnificado ayuda a prolongar la vida útil de los equipos y mantener la eficiencia del riego.',
      'La obstrucción de goteros puede deberse a la presencia de sedimentos, precipitados químicos o desarrollo biológico (algas/bacterias) en el agua de riego.',
      'Un suelo con muy baja capacidad de infiltración es igualmente adecuado para cualquier tipo de sistema de riego tecnificado, sin necesidad de ajustes de diseño.',
      'El coeficiente de uniformidad (CU) se ve afectado por variaciones de presión, obstrucción de emisores y el diseño hidráulico del sistema.',
    ];
    ?>

    <!-- ============ EVALUACIÓN FINAL ============ -->
    <div class="mt-12 mb-6 border-b-2 border-slate-200 pb-2">
      <h2 class="font-display text-2xl font-bold text-slate-900">Evaluación Final</h2>
      <p class="mt-1 text-sm text-slate-500">Curso: Implementación y Manejo de Sistemas de Riego Tecnificado · Puntaje: 60 pts</p>
    </div>

    <section class="card mb-12">
      <div class="border-b border-slate-100 bg-gradient-to-r from-agua-50 to-agro-50 px-5 py-4 sm:px-6">
        <h3 class="font-display text-lg font-bold text-slate-900">Prueba final del curso</h3>
        <p class="mt-0.5 text-sm text-slate-500">Complete todos los campos y genere el PDF para enviar al profesor.</p>
      </div>

      <form id="form-eval-final" class="p-5 sm:p-6 space-y-8" autocomplete="off">
        <!-- Contenido de la prueba (el PDF se genera vía API2PDF en servidor) -->
        <div id="eval-final-contenedor" class="space-y-8 bg-white">
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="eval_nombre" class="mb-1.5 block text-sm font-medium text-slate-700">Nombre <span class="text-red-500">*</span></label>
            <input type="text" id="eval_nombre" name="eval_nombre" required class="field" placeholder="Nombre completo del alumno">
          </div>
          <div>
            <label for="eval_fecha" class="mb-1.5 block text-sm font-medium text-slate-700">Fecha <span class="text-red-500">*</span></label>
            <input type="date" id="eval_fecha" name="eval_fecha" required class="field">
          </div>
        </div>

        <!-- SECCIÓN I -->
        <div class="space-y-5">
          <div class="rounded-xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200">
            <h4 class="font-display text-base font-bold text-slate-900">Sección I · Selección múltiple</h4>
            <p class="text-xs text-slate-500 mt-0.5">2 puntos c/u · 40 pts del ítem · Marque la alternativa correcta</p>
          </div>

          <?php foreach ($eval_sm as $i => $item):
            $n = $i + 1;
            $name = 'sm_' . $n;
          ?>
          <div class="eval-pregunta space-y-2" role="group" aria-labelledby="sm_label_<?= $n ?>">
            <p id="sm_label_<?= $n ?>" class="text-sm font-medium text-slate-700 mb-2">
              <?= $n ?>. <?= htmlspecialchars($item['q']) ?>
            </p>
            <div class="space-y-2">
              <?php foreach ($item['opts'] as $letra => $texto): ?>
              <label class="eval-opcion">
                <input type="radio" name="<?= $name ?>" value="<?= $letra ?>" required>
                <span class="text-sm text-slate-700">
                  <span class="font-semibold uppercase text-slate-500"><?= $letra ?>)</span>
                  <?= htmlspecialchars($texto) ?>
                </span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- SECCIÓN II -->
        <div class="space-y-5 border-t border-slate-100 pt-8">
          <div class="rounded-xl bg-agro-50/60 px-4 py-3 ring-1 ring-agro-100">
            <h4 class="font-display text-base font-bold text-slate-900">Sección II · Verdadero / Falso</h4>
            <p class="text-xs text-slate-500 mt-0.5">2 puntos c/u · 20 pts del ítem · Marque según corresponda</p>
          </div>

          <?php foreach ($eval_vf as $i => $enunciado):
            $n = $i + 1;
            $name = 'vf_' . $n;
          ?>
          <div class="eval-pregunta space-y-2" role="group" aria-labelledby="vf_label_<?= $n ?>">
            <p id="vf_label_<?= $n ?>" class="text-sm font-medium text-slate-700 mb-2">
              <?= $n ?>. <?= htmlspecialchars($enunciado) ?>
            </p>
            <div class="grid gap-2 sm:grid-cols-2 max-w-md">
              <label class="eval-opcion">
                <input type="radio" name="<?= $name ?>" value="V" required>
                <span class="text-sm font-semibold text-slate-700">Verdadero</span>
              </label>
              <label class="eval-opcion">
                <input type="radio" name="<?= $name ?>" value="F">
                <span class="text-sm font-semibold text-slate-700">Falso</span>
              </label>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        </div><!-- /#eval-final-contenedor -->

        <div class="border-t border-slate-100 pt-6">
          <button type="submit" id="btn-generar-pdf"
                  class="inline-flex w-full sm:w-auto items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-agro-600 to-agua-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-agro-600/25 transition hover:from-agro-700 hover:to-agua-700 focus:outline-none focus:ring-2 focus:ring-agro-500 focus:ring-offset-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            Generar PDF y descargar
          </button>
          <button type="button" id="btn-calcular-nota"
                  class="mt-2 inline-flex w-full sm:mt-0 sm:ml-3 sm:w-auto items-center justify-center gap-2 rounded-xl border border-agua-200 bg-agua-50 px-6 py-3 text-sm font-semibold text-agua-700 transition hover:bg-agua-100 focus:outline-none focus:ring-2 focus:ring-agua-500 focus:ring-offset-2">
            Calcular mi nota (sin generar PDF)
          </button>
          <p class="mt-2 text-xs text-slate-400">Se descargará el archivo y deberás enviarlo al profesor.</p>
          <div id="resultado-final" class="hidden" role="status"></div>
        </div>
      </form>
    </section>

    </div><!-- /#tab-modulos -->

    <!-- ============ PESTAÑA 2 · FORO DE CONSULTAS ============ -->
    <div id="tab-foro" data-tab-panel role="tabpanel" hidden class="space-y-6 no-print">
      <section class="card overflow-hidden">
        <div class="border-b border-slate-100 bg-gradient-to-r from-agua-50 to-agro-50 px-5 py-4 sm:px-6">
          <h2 class="font-display text-lg font-bold text-slate-900">Foro de Consultas al Relator</h2>
          <p class="mt-0.5 text-sm text-slate-500">Consultas técnicas del curso. Tiempo de respuesta del relator: menos de 24 hrs hábiles.</p>
        </div>
        <div id="foro-lista" class="divide-y divide-slate-100">
          <?php foreach ($foro_todas as $c): ?>
            <article class="space-y-3 p-5 sm:p-6">
              <header class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="font-semibold text-slate-900"><?= htmlspecialchars($c['asunto']) ?></h3>
                <?php if (!empty($c['resp'])): ?>
                  <span class="rounded-full bg-agro-100 px-2.5 py-0.5 text-[11px] font-bold uppercase text-agro-800">Respondida</span>
                <?php else: ?>
                  <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-[11px] font-bold uppercase text-amber-800">Pendiente de respuesta</span>
                <?php endif; ?>
              </header>
              <div class="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-100">
                <p class="text-xs text-slate-500"><strong class="text-slate-700"><?= htmlspecialchars($c['autor']) ?></strong> · <?= htmlspecialchars($c['fecha']) ?> hrs</p>
                <p class="mt-1.5 text-sm leading-relaxed text-slate-700"><?= nl2br(htmlspecialchars($c['texto'])) ?></p>
              </div>
              <?php if (!empty($c['resp'])): ?>
                <div class="rounded-xl bg-agro-50/70 p-4 ring-1 ring-agro-100 sm:ml-6">
                  <p class="text-xs text-agro-800"><strong><?= htmlspecialchars($c['resp_autor']) ?></strong> · <?= htmlspecialchars($c['resp_fecha']) ?> hrs</p>
                  <p class="mt-1.5 text-sm leading-relaxed text-slate-700"><?= nl2br(htmlspecialchars($c['resp'])) ?></p>
                </div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="card p-5 sm:p-6">
        <h3 class="font-display text-lg font-bold text-slate-900">Nueva consulta al relator</h3>
        <form id="form-foro" class="mt-4 space-y-4" autocomplete="off">
          <div>
            <label for="foro_asunto" class="mb-1.5 block text-sm font-medium text-slate-700">Asunto</label>
            <input type="text" id="foro_asunto" name="asunto" maxlength="120" required class="field" placeholder="Ej: Duda sobre el cálculo del T.R.D">
          </div>
          <div>
            <label for="foro_consulta" class="mb-1.5 block text-sm font-medium text-slate-700">Consulta</label>
            <textarea id="foro_consulta" name="consulta" rows="4" maxlength="1200" required minlength="10" class="field" placeholder="Describe tu duda técnica con el mayor detalle posible..."></textarea>
          </div>
          <div class="flex flex-wrap items-center gap-3">
            <button type="submit" id="foro-enviar" class="rounded-xl bg-gradient-to-r from-agro-600 to-agua-600 px-5 py-2.5 text-sm font-semibold text-white shadow-md transition hover:from-agro-700 hover:to-agua-700 focus:outline-none focus:ring-2 focus:ring-agro-500 focus:ring-offset-2">Enviar consulta</button>
            <p id="foro-msg" class="text-sm" role="status"></p>
          </div>
        </form>
      </section>
    </div>

    <!-- ============ PESTAÑA 3 · SOPORTE Y REGLAMENTO ============ -->
    <div id="tab-soporte" data-tab-panel role="tabpanel" hidden class="space-y-6 no-print">
      <section class="card overflow-hidden">
        <div class="border-b border-slate-100 bg-gradient-to-r from-agua-50 to-agro-50 px-5 py-4 sm:px-6">
          <h2 class="font-display text-lg font-bold text-slate-900">Soporte Técnico y Reglamento</h2>
        </div>
        <div class="grid gap-5 p-5 sm:grid-cols-2 sm:p-6">
          <div class="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-100">
            <h3 class="text-sm font-bold text-slate-900">Coordinación E-learning</h3>
            <dl class="mt-2 space-y-1.5 text-sm text-slate-700">
              <div><dt class="inline text-slate-500">Correo: </dt><dd class="inline"><a class="font-medium text-agua-700 hover:underline" href="mailto:contacto@ddgdelvalle.cl">contacto@ddgdelvalle.cl</a></dd></div>
              <div><dt class="inline text-slate-500">WhatsApp/Tel: </dt><dd class="inline"><a class="font-medium text-agua-700 hover:underline" href="https://wa.me/56963163859" rel="noopener">+56 9 6316 3859</a></dd></div>
              <div><dt class="inline text-slate-500">Horario: </dt><dd class="inline">Lun a Vie 09:00 a 18:00 hrs</dd></div>
            </dl>
          </div>
          <div class="rounded-xl bg-agro-50/60 p-4 ring-1 ring-agro-100">
            <h3 class="text-sm font-bold text-slate-900">Tiempos de respuesta</h3>
            <ul class="mt-2 space-y-1.5 text-sm text-slate-700">
              <li><strong>Soporte técnico y acceso:</strong> menos de 24 hrs hábiles.</li>
              <li><strong>Consultas académicas al relator:</strong> menos de 24 hrs hábiles.</li>
              <li><strong>Incidencias con asistencia SENCE:</strong> prioridad el mismo día hábil.</li>
            </ul>
          </div>
          <div class="rounded-xl bg-agua-50/60 p-4 ring-1 ring-agua-100 sm:col-span-2">
            <h3 class="text-sm font-bold text-slate-900">Requisitos técnicos de conexión</h3>
            <ul class="mt-2 grid gap-1.5 text-sm text-slate-700 sm:grid-cols-2">
              <li>Navegador actualizado: Chrome, Edge, Firefox o Safari.</li>
              <li>Conexión estable de al menos 5 Mbps (mínimo 3 Mbps).</li>
              <li>JavaScript y cookies habilitados (necesarios para el control de sesión).</li>
              <li>Acceso a <span class="font-mono text-xs">view.officeapps.live.com</span> para visualizar las presentaciones.</li>
              <li>Permitir descargas para guardar la evaluación en PDF.</li>
              <li>Pantalla de 360 px o más; recomendado computador para los ejercicios de cálculo.</li>
            </ul>
          </div>
        </div>
        <div class="border-t border-slate-100 px-5 py-4 sm:px-6">
          <button type="button" data-open="reglamento" class="inline-flex items-center gap-2 rounded-xl border border-agro-200 bg-agro-50 px-4 py-2 text-sm font-semibold text-agro-800 transition hover:bg-agro-100">
            Ver Reglamento Académico y Guía de Uso de Plataforma
          </button>
        </div>
      </section>
    </div>

<?php if ($esAuditor): ?>
    <!-- ============ PESTAÑA 4 · PANEL AUDITORÍA Y TRAZABILIDAD SENCE ============ -->
    <div id="tab-auditoria" data-tab-panel role="tabpanel" hidden class="space-y-6">

      <div class="print-only border-b-2 border-slate-800 pb-3">
        <p class="text-lg font-bold">Informe de Auditoría y Trazabilidad SENCE</p>
        <p class="text-xs">Curso: Riego y Evapotranspiración (Riego Tecnificado - SENCE) · Fiscalizador: <?= htmlspecialchars($nombreUsuario . ' · ' . $rutUsuario) ?> · Generado: <span id="print-fecha"></span></p>
      </div>

      <section class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Resumen de auditoría">
        <div class="card p-4"><p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Curso auditado</p><p class="mt-1 text-sm font-bold text-slate-900">Riego y Evapotranspiración</p></div>
        <div class="card p-4"><p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Estado API SENCE</p><p class="mt-1 flex items-center gap-1.5 text-sm font-bold text-agro-700"><span class="inline-block h-2 w-2 rounded-full bg-agro-500"></span>Conectado - Gestor Intermedio SIC</p></div>
        <div class="card p-4"><p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Escala Evaluación</p><p class="mt-1 text-sm font-bold text-slate-900">1.0 a 7.0 / 60% exigencia</p></div>
        <div class="card p-4"><p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Asistencia Mínima</p><p class="mt-1 text-sm font-bold text-slate-900">75%</p></div>
      </section>

      <section class="card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 bg-gradient-to-r from-amber-50 to-white px-5 py-4 sm:px-6">
          <div>
            <h2 class="font-display text-lg font-bold text-slate-900">Bitácora de Conectividad, Trazabilidad IP y Avance Académico</h2>
            <p class="mt-0.5 text-xs text-slate-500">Fila 1: sesión en vivo del usuario conectado (IP y hora reales). Filas 2–9: registros precargados de demostración.</p>
          </div>
          <div class="no-print flex flex-wrap gap-2">
            <button type="button" id="btn-csv" class="inline-flex items-center gap-2 rounded-xl bg-agro-600 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-agro-700 focus:outline-none focus:ring-2 focus:ring-agro-500 focus:ring-offset-2">Descargar Bitácora SENCE (CSV / Excel)</button>
            <button type="button" id="btn-print-audit" class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2">Imprimir / Exportar Informe de Auditoría (PDF)</button>
          </div>
        </div>
        <div class="overflow-x-auto">
          <table id="tabla-bitacora" class="tabla-bitacora w-full text-left text-xs">
            <thead>
              <tr class="bg-slate-50 uppercase tracking-wide text-slate-500">
                <th class="px-3 py-2.5 font-semibold">RUT</th>
                <th class="px-3 py-2.5 font-semibold">Nombre completo</th>
                <th class="px-3 py-2.5 font-semibold">Ingreso</th>
                <th class="px-3 py-2.5 font-semibold">Salida</th>
                <th class="px-3 py-2.5 font-semibold">Min. conectado</th>
                <th class="px-3 py-2.5 font-semibold">Dirección IP</th>
                <th class="px-3 py-2.5 font-semibold">% Avance (4 módulos)</th>
                <th class="px-3 py-2.5 font-semibold">Nota final (1.0–7.0)</th>
                <th class="px-3 py-2.5 font-semibold">Estado</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr id="fila-live" class="bg-amber-50/70 font-medium">
                <td class="px-3 py-2"><?= htmlspecialchars($rutUsuario) ?></td>
                <td class="px-3 py-2"><?= htmlspecialchars($nombreUsuario) ?> (sesión actual)</td>
                <td class="px-3 py-2"><?= date('d-m-Y H:i:s', $loginTs) ?></td>
                <td class="px-3 py-2">En sesión</td>
                <td class="px-3 py-2" id="live-min">0</td>
                <td class="px-3 py-2"><?= htmlspecialchars($loginIp) ?></td>
                <td class="px-3 py-2" id="live-avance">0%</td>
                <td class="px-3 py-2" id="live-nota">—</td>
                <td class="px-3 py-2"><span class="rounded-full bg-agro-100 px-2 py-0.5 font-bold text-agro-800">Sesión en vivo</span></td>
              </tr>
              <?php foreach ($aud_filas as $f): ?>
              <tr>
                <td class="px-3 py-2"><?= htmlspecialchars($f['rut']) ?></td>
                <td class="px-3 py-2"><?= htmlspecialchars($f['nombre']) ?></td>
                <td class="px-3 py-2"><?= $f['ingreso'] ?></td>
                <td class="px-3 py-2"><?= $f['salida'] ?></td>
                <td class="px-3 py-2"><?= (int) $f['min'] ?></td>
                <td class="px-3 py-2"><?= htmlspecialchars($f['ip']) ?></td>
                <td class="px-3 py-2"><?= (int) $f['avance'] ?>%</td>
                <td class="px-3 py-2 font-semibold <?= $f['nota'] >= DDG_NOTA_MIN ? 'text-agro-700' : 'text-red-600' ?>"><?= number_format($f['nota'], 1, '.', '') ?></td>
                <td class="px-3 py-2"><span class="rounded-full bg-agua-100 px-2 py-0.5 font-bold text-agua-700">Validado ClaveÚnica</span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    </div>
<?php endif; ?>

    <footer class="pb-8 text-center text-xs text-slate-400 no-print">
      Portal de estudio privado · Valle del Mataquito · Riego tecnificado
    </footer>
  </main>

  <script>
  (function () {
    var formEval = document.getElementById('form-eval-final');
    if (!formEval) return;

    var PDF_ENDPOINT = 'generar-pdf-evaluacion.php';

    function esc(str) {
      return String(str == null ? '' : str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    function slugNombre(raw) {
      return (raw || '')
        .trim()
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-zA-Z0-9]+/g, '_')
        .replace(/^_|_$/g, '') || 'Alumno';
    }

    function restaurarUi(btn) {
      if (!btn) return;
      btn.disabled = false;
      btn.classList.remove('opacity-70', 'cursor-wait');
      btn.innerHTML =
        '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">' +
        '<path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />' +
        '</svg> Generar PDF y descargar';
    }

    /** HTML limpio con respuestas resaltadas (API2PDF/Chrome lo renderiza perfecto) */
    function construirHtmlPdf() {
      var nombre = (document.getElementById('eval_nombre').value || '').trim();
      var fecha = document.getElementById('eval_fecha').value || '';
      var origen = document.getElementById('eval-final-contenedor');
      var html = '';

      html += '<div style="border-bottom:2px solid #15803d;padding-bottom:12px;margin-bottom:18px;">';
      html += '<div style="font-size:20px;font-weight:700;color:#14532d;">Evaluación Final · Riego Tecnificado</div>';
      html += '<div style="margin-top:10px;font-size:13px;"><strong>Nombre:</strong> ' + esc(nombre) + '</div>';
      html += '<div style="font-size:13px;"><strong>Fecha:</strong> ' + esc(fecha) + '</div>';
      var cal = window.DDG_ULTIMA;
      if (cal) {
        html += '<div style="margin-top:6px;font-size:13px;"><strong>Resultado:</strong> ' + cal.pts + '/' + cal.total + ' pts · ' + cal.pct.toFixed(1) +
          '% de logro · <strong>Nota ' + cal.nota.toFixed(1) + '</strong> (escala 1.0–7.0, exigencia 60%) · ' + esc(cal.estado) + '</div>';
      }
      html += '</div>';

      html += '<div style="background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;margin-bottom:14px;">';
      html += '<div style="font-weight:700;font-size:14px;color:#0f172a;">Sección I · Selección múltiple</div>';
      html += '<div style="font-size:11px;color:#64748b;">2 pts c/u · 40 pts</div></div>';

      origen.querySelectorAll('[role="group"][aria-labelledby^="sm_label_"]').forEach(function (group, idx) {
        var labelEl = document.getElementById(group.getAttribute('aria-labelledby'));
        var pregunta = labelEl ? labelEl.textContent.trim() : ('Pregunta ' + (idx + 1));
        html += '<div class="pdf-q">';
        html += '<div style="font-weight:600;margin-bottom:6px;color:#0f172a;">' + esc(pregunta) + '</div>';
        group.querySelectorAll('label.eval-opcion').forEach(function (lab) {
          var radio = lab.querySelector('input[type="radio"]');
          var texto = (lab.querySelector('span') || lab).textContent.replace(/\s+/g, ' ').trim();
          var marcada = !!(radio && radio.checked);
          var boxStyle = marcada
            ? 'background:#dcfce9;border:2px solid #16a34a;font-weight:700;color:#14532d;'
            : 'background:#fff;border:1px solid #cbd5e1;color:#334155;';
          html += '<div style="padding:8px 10px;margin:0 0 5px;border-radius:6px;' + boxStyle + '">' +
            esc(texto) + (marcada ? ' ✓' : '') + '</div>';
        });
        html += '</div>';
      });

      html += '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 12px;margin:20px 0 14px;">';
      html += '<div style="font-weight:700;font-size:14px;color:#0f172a;">Sección II · Verdadero / Falso</div>';
      html += '<div style="font-size:11px;color:#64748b;">2 pts c/u · 20 pts</div></div>';

      origen.querySelectorAll('[role="group"][aria-labelledby^="vf_label_"]').forEach(function (group, idx) {
        var labelEl = document.getElementById(group.getAttribute('aria-labelledby'));
        var pregunta = labelEl ? labelEl.textContent.trim() : ('Pregunta ' + (idx + 1));
        html += '<div class="pdf-q">';
        html += '<div style="font-weight:600;margin-bottom:6px;color:#0f172a;">' + esc(pregunta) + '</div>';
        group.querySelectorAll('label.eval-opcion').forEach(function (lab) {
          var radio = lab.querySelector('input[type="radio"]');
          var texto = (lab.querySelector('span') || lab).textContent.replace(/\s+/g, ' ').trim();
          var marcada = !!(radio && radio.checked);
          var boxStyle = marcada
            ? 'background:#dcfce9;border:2px solid #16a34a;font-weight:700;color:#14532d;'
            : 'background:#fff;border:1px solid #cbd5e1;color:#334155;';
          html += '<div style="display:inline-block;min-width:130px;padding:8px 12px;margin:0 8px 5px 0;border-radius:6px;' + boxStyle + '">' +
            esc(texto) + (marcada ? ' ✓' : '') + '</div>';
        });
        html += '</div>';
      });

      return html;
    }

    function descargarBlob(blob, filename) {
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 2500);
    }

    formEval.addEventListener('submit', function (e) {
      e.preventDefault();

      /* Califica en servidor antes de armar el PDF para que la nota quede impresa en el documento */
      if (!window.__ddgNotaLista) {
        var pasar = function () { window.__ddgNotaLista = true; formEval.requestSubmit(); window.__ddgNotaLista = false; };
        window.DDGCalificar().then(pasar, pasar);
        return;
      }

      var btn = document.getElementById('btn-generar-pdf');
      var filename = 'Prueba_Riego_' + slugNombre(document.getElementById('eval_nombre').value) + '.pdf';
      var pantalla = document.getElementById('eval-final-contenedor');

      pantalla.querySelectorAll('.respuesta-marcada').forEach(function (el) {
        el.classList.remove('respuesta-marcada');
      });
      pantalla.querySelectorAll('input[type="radio"]:checked').forEach(function (radio) {
        var label = radio.closest('label');
        if (label) label.classList.add('respuesta-marcada');
      });

      if (btn) {
        btn.disabled = true;
        btn.classList.add('opacity-70', 'cursor-wait');
        btn.textContent = 'Generando PDF…';
      }

      var html;
      try {
        html = construirHtmlPdf();
      } catch (errBuild) {
        console.error(errBuild);
        restaurarUi(btn);
        alert('Error al preparar el contenido del PDF.');
        return;
      }

      fetch(PDF_ENDPOINT, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/pdf, application/json' },
        body: JSON.stringify({ html: html, filename: filename })
      })
        .then(function (res) {
          var ctype = (res.headers.get('Content-Type') || '').toLowerCase();
          if (!res.ok || ctype.indexOf('application/pdf') === -1) {
            return res.text().then(function (txt) {
              var msg = 'No se pudo generar el PDF.';
              try {
                var j = JSON.parse(txt);
                if (j && j.error) msg = j.error;
              } catch (ignore) {}
              throw new Error(msg);
            });
          }
          return res.blob();
        })
        .then(function (blob) {
          descargarBlob(blob, filename);
          alert('PDF generado. Por favor, envía este archivo al profesor');
          restaurarUi(btn);
        })
        .catch(function (err) {
          console.error(err);
          alert(err.message || 'Error al generar el PDF.');
          restaurarUi(btn);
        });
    });
  })();
  </script>

  <script>
  (function () {
    /* —— Valores esperados (fórmulas del enunciado) —— */
    var ETO_MAX = 138.02;
    var DIAS = 31;
    var ETO_DIARIA = ETO_MAX / DIAS;           // ≈ 4.4523
    var ETC_CEREZO = ETO_DIARIA * 0.9;         // ≈ 4.007
    var ETC_FRAMB = ETO_DIARIA * 0.75;         // ≈ 3.339
    var M3_CEREZO = ETC_CEREZO * 10;           // ≈ 40.07
    var M3_FRAMB = ETC_FRAMB * 10;             // ≈ 33.39

    var CASO_ETO = 4.83;
    var CASO_ETC = CASO_ETO * 0.9;             // 4.347
    var MP = 8;                                // 4 × 2 m²
    var PC = 90;
    var DNC = (CASO_ETC * MP * PC) / 100;      // ≈ 31.298
    var DBC = (DNC * 100) / 90;                // ≈ 34.776
    var NG = 2;
    var QG = 4;
    var TRD = DBC / (NG * QG);                 // ≈ 4.347
    var SECT_PEQ = 12 / TRD;                   // ≈ 2.76
    var SECT_GRAN = 18 / TRD;                  // ≈ 4.14

    /* —— Módulo 4 · Ejercicio 4.2 (CU) —— */
    var M4_PROMEDIO = 3.375;
    var M4_Q25 = 2.125;                        // promedio de los 4 menores: 1.7, 1.8, 2.5, 2.5
    var M4_CU = (M4_Q25 / M4_PROMEDIO) * 100;  // ≈ 62.96%

    var TOL = 0.05; // tolerancia de redondeo

    function num(id) {
      var el = document.getElementById(id);
      if (!el) return NaN;
      var v = String(el.value).trim().replace(',', '.');
      if (v === '') return NaN;
      return parseFloat(v);
    }

    function approx(a, b) {
      return !isNaN(a) && Math.abs(a - b) <= TOL;
    }

    function mark(id, ok) {
      var el = document.getElementById(id);
      if (!el) return;
      el.classList.remove('field-ok', 'field-bad');
      if (ok === null) return;
      el.classList.add(ok ? 'field-ok' : 'field-bad');
    }

    function mesOk(val) {
      if (!val) return false;
      var n = val.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
      return (
        n.indexOf('ene') !== -1 ||
        n.indexOf('enero') !== -1 ||
        n.indexOf('jan') !== -1
      ) && (n.indexOf('2026') !== -1 || n.indexOf('26') !== -1 || true);
    }

    function evaluar() {
      var checks = [];
      var mesVal = (document.getElementById('q1_mes').value || '').trim();
      var okMes = mesOk(mesVal);
      mark('q1_mes', okMes);
      checks.push(okMes);

      var okEto = approx(num('q1_eto'), ETO_MAX);
      mark('q1_eto', okEto);
      checks.push(okEto);

      var okDia = approx(num('q2_mm_dia'), ETO_DIARIA);
      mark('q2_mm_dia', okDia);
      checks.push(okDia);

      var okCer = approx(num('q3_cerezo'), ETC_CEREZO);
      mark('q3_cerezo', okCer);
      checks.push(okCer);

      var okFra = approx(num('q3_frambuesa'), ETC_FRAMB);
      mark('q3_frambuesa', okFra);
      checks.push(okFra);

      var okM3c = approx(num('q4_cerezo'), M3_CEREZO);
      mark('q4_cerezo', okM3c);
      checks.push(okM3c);

      var okM3f = approx(num('q4_frambuesa'), M3_FRAMB);
      mark('q4_frambuesa', okM3f);
      checks.push(okM3f);

      var etc1 = approx(num('caso_etc'), CASO_ETC);
      var etc2 = approx(num('caso_etc_mm'), CASO_ETC);
      mark('caso_etc', etc1);
      mark('caso_etc_mm', etc2);
      checks.push(etc1, etc2);

      var okDnc = approx(num('caso_dnc'), DNC);
      mark('caso_dnc', okDnc);
      checks.push(okDnc);

      var okDbc = approx(num('caso_dbc'), DBC);
      mark('caso_dbc', okDbc);
      checks.push(okDbc);

      var okTrd = approx(num('caso_trd'), TRD);
      mark('caso_trd', okTrd);
      checks.push(okTrd);

      var okTrdP = approx(num('sect_peq_trd'), TRD);
      var okTrdG = approx(num('sect_gran_trd'), TRD);
      mark('sect_peq_trd', okTrdP);
      mark('sect_gran_trd', okTrdG);
      checks.push(okTrdP, okTrdG);

      var okSp = approx(num('sect_peq'), SECT_PEQ);
      var okSg = approx(num('sect_gran'), SECT_GRAN);
      mark('sect_peq', okSp);
      mark('sect_gran', okSg);
      checks.push(okSp, okSg);

      /* Módulo 4 · Cálculo de CU */
      var okM4Prom = approx(num('m4_promedio'), M4_PROMEDIO);
      var okM4Q25 = approx(num('m4_q25'), M4_Q25);
      var okM4Cu = approx(num('m4_cu'), M4_CU);
      mark('m4_promedio', okM4Prom);
      mark('m4_q25', okM4Q25);
      mark('m4_cu', okM4Cu);
      checks.push(okM4Prom, okM4Q25, okM4Cu);

      var correctos = checks.filter(Boolean).length;
      var total = checks.length;
      var box = document.getElementById('resultado-eval');
      box.classList.remove('hidden', 'bg-green-50', 'text-green-800', 'border-green-200', 'bg-red-50', 'text-red-800', 'border-red-200', 'border');

      var logro = Math.round(correctos / total * 1000) / 10;
      var nota = window.DDGNota(logro);
      var aprobado = nota >= 4.0;
      box.classList.add('border', aprobado ? 'bg-green-50' : 'bg-red-50', aprobado ? 'text-green-800' : 'text-red-800', aprobado ? 'border-green-200' : 'border-red-200');
      box.textContent = (correctos === total ? '¡Excelente! Todos los cálculos son correctos. ' : 'Revisa los campos en rojo (tolerancia ±0,05). ') +
        'Ejercicios: ' + correctos + '/' + total + ' · Logro ' + logro.toFixed(1) + '% · Nota ' + nota.toFixed(1) + ' (escala 1.0–7.0, exigencia 60%) · ' + (aprobado ? 'APROBADO' : 'REPROBADO');
      if (window.DDGGuardarEjercicios) window.DDGGuardarEjercicios(logro, nota);
      box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    document.getElementById('btn-evaluar').addEventListener('click', evaluar);
    document.getElementById('btn-imprimir').addEventListener('click', function () {
      window.print();
    });

    /* Sync T.R.D helpers: al completar caso_trd, sugerir en sectores */
    document.getElementById('caso_trd').addEventListener('change', function () {
      var v = this.value;
      var p = document.getElementById('sect_peq_trd');
      var g = document.getElementById('sect_gran_trd');
      if (v && !p.value) p.value = v;
      if (v && !g.value) g.value = v;
    });
  })();
  </script>

  <script>
  window.DDG = <?= json_encode([
      'csrf'       => $csrf,
      'rol'        => $rol,
      'userKey'    => $userKey,
      'loginTs'    => $loginTs * 1000,
      'serverNow'  => time() * 1000,
      'inactSeg'   => DDG_INACTIVIDAD_SEG,
      'avanceMin'  => DDG_AVANCE_MIN_PCT,
      'notaMin'    => DDG_NOTA_MIN,
      'exigencia'  => DDG_EXIGENCIA_PCT,
  ], JSON_UNESCAPED_UNICODE) ?>;
  </script>

  <script>
  (function () {
    var C = window.DDG;
    var $ = function (id) { return document.getElementById(id); };
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    /* El reloj del servidor manda: evita que un reloj de PC desfasado distorsione la bitácora */
    var offset = C.serverNow - Date.now();
    var ahora = function () { return Date.now() + offset; };

    /* ============ Nota chilena (misma fórmula que el servidor) ============ */
    window.DDGNota = function (pct) {
      pct = Math.max(0, Math.min(100, pct));
      var e = C.exigencia;
      var n = pct >= e ? 4 + 3 * (pct - e) / (100 - e) : 1 + 3 * pct / e;
      return Math.round(n * 10) / 10;
    };

    /* ============ Estado persistente (localStorage, por usuario) ============ */
    var KEY = 'ddg_riego_v1_' + C.userKey;
    var st = { mods: [false, false, false, false], ejercicios: null, final: null };
    try {
      var raw = JSON.parse(localStorage.getItem(KEY) || 'null');
      if (raw && Array.isArray(raw.mods) && raw.mods.length === 4) st = raw;
    } catch (e) {}
    function guardar() { try { localStorage.setItem(KEY, JSON.stringify(st)); } catch (e) {} }

    window.DDGGuardarEjercicios = function (pct, nota) {
      st.ejercicios = { pct: pct, nota: nota }; guardar(); render();
    };

    /* ============ Pestañas ============ */
    var paneles = [].slice.call(document.querySelectorAll('[data-tab-panel]'));
    var tabs = [].slice.call(document.querySelectorAll('.tab-btn[data-tab]'));
    function setTab(id, noScroll) {
      if (!$('tab-' + id)) id = 'modulos';
      paneles.forEach(function (p) { p.hidden = p.id !== 'tab-' + id; });
      tabs.forEach(function (b) { b.setAttribute('aria-selected', String(b.dataset.tab === id)); });
      try { history.replaceState(null, '', '#' + id); } catch (e) {}
      if (!noScroll) window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    tabs.forEach(function (b) { b.addEventListener('click', function () { setTab(b.dataset.tab); }); });
    [].forEach.call(document.querySelectorAll('[data-tab-go]'), function (b) {
      b.addEventListener('click', function () { setTab(b.dataset.tabGo); });
    });
    var inicial = (location.hash || '').replace('#', '');
    if (!$('tab-' + inicial)) inicial = C.rol === 'auditor' ? 'auditoria' : 'modulos';
    setTab(inicial, true);

    /* ============ Cronómetro de sesión ============ */
    function hms(ms) {
      var s = Math.max(0, Math.floor(ms / 1000));
      return pad(Math.floor(s / 3600)) + ':' + pad(Math.floor(s % 3600 / 60)) + ':' + pad(s % 60);
    }
    function mmss(s) { s = Math.max(0, s); return pad(Math.floor(s / 60)) + ':' + pad(s % 60); }

    /* ============ Control de inactividad (60 min) ============ */
    var ultima = Date.now(), ultimoPing = Date.now();
    var AVISO_SEG = 300;
    function actividad() {
      ultima = Date.now();
      if (ultima - ultimoPing > 240000) { // mantiene viva la sesión del servidor sin saturarlo
        ultimoPing = ultima;
        fetch('?ping=1', { credentials: 'same-origin' }).catch(function () {});
      }
    }
    ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(function (ev) {
      window.addEventListener(ev, actividad, { passive: true });
    });

    function tick() {
      $('cronometro').textContent = hms(ahora() - C.loginTs);

      /* Los iframes de Office Online absorben los eventos: si el foco está en uno, el alumno está leyendo */
      var ae = document.activeElement;
      if (ae && ae.tagName === 'IFRAME') ultima = Date.now();

      var restante = C.inactSeg - Math.floor((Date.now() - ultima) / 1000);
      $('inact-restante').textContent = mmss(restante);
      var critico = restante <= AVISO_SEG;
      $('inact-dot').className = 'inline-block h-2 w-2 rounded-full ' + (critico ? 'bg-amber-500 animate-pulse' : 'bg-agro-500');
      $('aviso-inactividad').classList.toggle('hidden', !critico);
      if (critico) $('aviso-restante').textContent = mmss(restante);
      if (restante <= 0) { location.href = '?logout=1&motivo=inactividad'; }
      renderLive();
    }
    setInterval(tick, 1000);

    /* ============ Progreso curricular ============ */
    var CHECKS = [].slice.call(document.querySelectorAll('input[data-modulo]'));
    CHECKS.forEach(function (cb) {
      cb.addEventListener('change', function () {
        st.mods[Number(cb.dataset.modulo) - 1] = cb.checked; guardar(); render();
      });
    });

    function avance() { return st.mods.filter(Boolean).length * 25; }

    function render() {
      var pct = avance();
      $('prog-pct').textContent = pct;
      $('prog-barra').style.width = pct + '%';
      $('prog-barra-wrap').setAttribute('aria-valuenow', pct);
      CHECKS.forEach(function (cb) { cb.checked = !!st.mods[Number(cb.dataset.modulo) - 1]; });
      [].forEach.call(document.querySelectorAll('[data-chip]'), function (chip) {
        var ok = !!st.mods[Number(chip.dataset.chip) - 1];
        var s = chip.querySelector('[data-chip-estado]');
        s.textContent = ok ? 'Completado' : 'Pendiente';
        s.className = ok ? 'text-agro-700' : 'text-slate-400';
        chip.classList.toggle('border-agro-500', ok);
        chip.classList.toggle('bg-agro-50', ok);
      });

      var nota = st.final ? st.final.nota : null;
      var okAv = pct >= C.avanceMin, okNota = nota !== null && nota >= C.notaMin;
      var el = $('prog-estado');
      if (okAv && okNota) {
        el.className = 'mt-2 text-sm font-semibold text-agro-700';
        el.textContent = 'CUMPLE requisitos de aprobación SENCE · Avance ' + pct + '% · Nota final ' + nota.toFixed(1);
      } else {
        el.className = 'mt-2 text-sm font-medium text-slate-700';
        var falta = [];
        if (!okAv) falta.push('avance ≥ ' + C.avanceMin + '% (actual ' + pct + '%)');
        if (!okNota) falta.push('nota final ≥ ' + C.notaMin.toFixed(1) + ' (actual ' + (nota === null ? 'sin rendir' : nota.toFixed(1)) + ')');
        el.textContent = 'En curso · Pendiente: ' + falta.join(' y ') + '.';
      }
      renderLive();
    }

    /* ============ Evaluación final: calificación en servidor ============ */
    function mostrarFinal(r) {
      var ok = r.estado === 'APROBADO';
      var box = $('resultado-final');
      box.className = 'mt-4 rounded-xl border px-4 py-4 text-sm ' + (ok ? 'border-green-200 bg-green-50 text-green-900' : 'border-red-200 bg-red-50 text-red-900');
      box.innerHTML = '';
      var fila = document.createElement('div');
      fila.className = 'flex flex-wrap items-center gap-x-6 gap-y-2';
      [
        ['Puntaje', r.pts + ' / ' + r.total + ' pts'],
        ['% de logro', r.pct.toFixed(1) + '%'],
        ['Nota (1.0–7.0)', r.nota.toFixed(1)],
        ['Estado', r.estado]
      ].forEach(function (p) {
        var d = document.createElement('div');
        var a = document.createElement('p'); a.className = 'text-[11px] font-semibold uppercase tracking-wide opacity-70'; a.textContent = p[0];
        var b = document.createElement('p'); b.className = 'text-lg font-bold'; b.textContent = p[1];
        d.appendChild(a); d.appendChild(b); fila.appendChild(d);
      });
      box.appendChild(fila);
      var det = document.createElement('p');
      det.className = 'mt-3 text-xs';
      var mal = [];
      if (r.malSm.length) mal.push('Selección múltiple: ' + r.malSm.join(', '));
      if (r.malVf.length) mal.push('V/F: ' + r.malVf.join(', '));
      det.textContent = 'Exigencia ' + C.exigencia + '% para nota 4.0 · Selección múltiple ' + r.okSm + '/' + r.totSm + ' · V/F ' + r.okVf + '/' + r.totVf +
        (mal.length ? ' · Reforzar preguntas → ' + mal.join(' | ') : '');
      box.appendChild(det);
    }

    window.DDGCalificar = function () {
      var f = $('form-eval-final');
      return fetch('?calificar=1', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'X-CSRF-Token': C.csrf, 'Accept': 'application/json' },
        body: new FormData(f)
      }).then(function (res) {
        return res.json().then(function (j) {
          if (!res.ok) throw new Error(j.error || 'No se pudo calificar la evaluación.');
          return j;
        });
      }).then(function (r) {
        window.DDG_ULTIMA = r;
        st.final = { pct: r.pct, nota: r.nota, estado: r.estado };
        guardar(); mostrarFinal(r); render();
        return r;
      });
    };

    $('btn-calcular-nota').addEventListener('click', function () {
      var btn = this; btn.disabled = true;
      window.DDGCalificar().catch(function (e) { alert(e.message); }).then(function () { btn.disabled = false; });
    });

    /* ============ Foro ============ */
    var formForo = $('form-foro');
    formForo.addEventListener('submit', function (e) {
      e.preventDefault();
      var msg = $('foro-msg'), btn = $('foro-enviar');
      btn.disabled = true; msg.className = 'text-sm text-slate-500'; msg.textContent = 'Enviando…';
      fetch('?foro=1', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'X-CSRF-Token': C.csrf, 'Accept': 'application/json' },
        body: new FormData(formForo)
      }).then(function (res) {
        return res.json().then(function (j) { if (!res.ok) throw new Error(j.error || 'No se pudo enviar.'); return j; });
      }).then(function (j) {
        var it = j.item;
        var art = document.createElement('article'); art.className = 'space-y-3 p-5 sm:p-6';
        var h = document.createElement('div'); h.className = 'flex flex-wrap items-center justify-between gap-2';
        var t = document.createElement('h3'); t.className = 'font-semibold text-slate-900'; t.textContent = it.asunto;
        var s = document.createElement('span'); s.className = 'rounded-full bg-amber-100 px-2.5 py-0.5 text-[11px] font-bold uppercase text-amber-800'; s.textContent = 'Pendiente de respuesta';
        h.appendChild(t); h.appendChild(s);
        var q = document.createElement('div'); q.className = 'rounded-xl bg-slate-50 p-4 ring-1 ring-slate-100';
        var m = document.createElement('p'); m.className = 'text-xs text-slate-500';
        var au = document.createElement('strong'); au.className = 'text-slate-700'; au.textContent = it.autor;
        m.appendChild(au); m.appendChild(document.createTextNode(' · ' + it.fecha + ' hrs'));
        var tx = document.createElement('p'); tx.className = 'mt-1.5 whitespace-pre-line text-sm leading-relaxed text-slate-700'; tx.textContent = it.texto;
        q.appendChild(m); q.appendChild(tx);
        art.appendChild(h); art.appendChild(q);
        $('foro-lista').insertBefore(art, $('foro-lista').firstChild);
        formForo.reset();
        msg.className = 'text-sm font-medium text-agro-700'; msg.textContent = 'Consulta enviada. El relator responderá en menos de 24 hrs hábiles.';
      }).catch(function (err) {
        msg.className = 'text-sm font-medium text-red-600'; msg.textContent = err.message;
      }).then(function () { btn.disabled = false; });
    });

    /* ============ Auditoría ============ */
    function renderLive() {
      var fila = $('fila-live');
      if (!fila) return;
      $('live-min').textContent = Math.floor((ahora() - C.loginTs) / 60000);
      $('live-avance').textContent = avance() + '%';
      $('live-nota').textContent = st.final ? st.final.nota.toFixed(1) : '—';
    }

    function descargar(blob, nombre) {
      var url = URL.createObjectURL(blob), a = document.createElement('a');
      a.href = url; a.download = nombre; document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 2500);
    }

    var btnCsv = $('btn-csv');
    if (btnCsv) {
      btnCsv.addEventListener('click', function () {
        renderLive();
        var celda = function (td) {
          var v = td.textContent.replace(/\s+/g, ' ').trim();
          if (/^\d+\.\d$/.test(v)) v = v.replace('.', ',');   // Excel en configuración regional es-CL
          if (/^[=+@]/.test(v)) v = "'" + v;                  // evita inyección de fórmulas al abrir en Excel
          return '"' + v.replace(/"/g, '""') + '"';
        };
        var lineas = [].map.call(document.querySelectorAll('#tabla-bitacora tr'), function (tr) {
          return [].map.call(tr.children, celda).join(';');   // ';' = separador por defecto de Excel es-CL
        });
        var d = new Date();
        var sello = d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + '_' + pad(d.getHours()) + pad(d.getMinutes());
        descargar(new Blob(['\uFEFF' + lineas.join('\r\n')], { type: 'text/csv;charset=utf-8' }), 'Bitacora_SENCE_Riego_' + sello + '.csv');
      });
    }

    var btnPrint = $('btn-print-audit');
    if (btnPrint) {
      btnPrint.addEventListener('click', function () {
        renderLive();
        setTab('auditoria', true);
        $('print-fecha').textContent = new Date().toLocaleString('es-CL');
        document.body.classList.add('print-audit');
        window.print();
      });
      window.addEventListener('afterprint', function () { document.body.classList.remove('print-audit'); });
    }

    render();
    tick();
  })();
  </script>
<?php endif; ?>

  <!-- ============ MODAL · GESTOR INTERMEDIO SENCE (simulación) ============ -->
  <div id="modal-sence" class="modal-overlay no-print" role="dialog" aria-modal="true" aria-labelledby="sence-titulo">
    <div class="card relative my-auto w-full max-w-2xl overflow-hidden">
      <div class="flex items-start justify-between gap-4 bg-[#0b3d91] px-5 py-4 text-white sm:px-6">
        <div>
          <h2 id="sence-titulo" class="font-display text-lg font-bold">Validar Asistencia con ClaveÚnica / SENCE</h2>
          <p class="mt-0.5 text-xs text-blue-100">Envío POST al Gestor Intermedio de SENCE · entorno de simulación</p>
        </div>
        <button type="button" data-close class="rounded-lg p-1 text-blue-100 hover:bg-white/10 hover:text-white focus:outline-none focus:ring-2 focus:ring-white" aria-label="Cerrar">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
      </div>
      <div class="space-y-4 p-5 sm:p-6">
        <p class="text-sm text-slate-600">Al iniciar la sesión de aprendizaje, la plataforma envía los siguientes parámetros al Gestor Intermedio, que autentica al participante con ClaveÚnica y registra la asistencia.</p>
        <div class="overflow-x-auto rounded-xl ring-1 ring-slate-200">
          <table class="w-full text-left text-xs">
            <thead><tr class="bg-slate-50 uppercase tracking-wide text-slate-500"><th class="px-3 py-2 font-semibold">Parámetro</th><th class="px-3 py-2 font-semibold">Valor</th><th class="hidden px-3 py-2 font-semibold sm:table-cell">Descripción</th></tr></thead>
            <tbody class="divide-y divide-slate-100" id="sence-params">
              <?php foreach ($SENCE_PARAMS as $k => $p): ?>
                <tr>
                  <th scope="row" class="px-3 py-2 font-mono font-semibold text-slate-800"><?= htmlspecialchars($k) ?></th>
                  <td class="break-all px-3 py-2 font-mono text-slate-700" data-param="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($p[0]) ?></td>
                  <td class="hidden px-3 py-2 text-slate-500 sm:table-cell"><?= htmlspecialchars($p[1]) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <button type="button" id="sence-simular" class="rounded-xl bg-[#0b3d91] px-5 py-2.5 text-sm font-bold text-white shadow-md transition hover:bg-[#093178] focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2">Simular envío POST</button>
          <p class="text-xs text-slate-500">No se transmite ningún dato a SENCE. Valores de demostración hasta configurar las credenciales reales del OTEC.</p>
        </div>
        <pre id="sence-salida" class="hidden max-h-64 overflow-auto rounded-xl bg-slate-900 p-4 text-[11px] leading-relaxed text-green-300" aria-live="polite"></pre>
      </div>
    </div>
  </div>

  <!-- ============ MODAL · REGLAMENTO ============ -->
  <div id="modal-reglamento" class="modal-overlay no-print" role="dialog" aria-modal="true" aria-labelledby="reg-titulo">
    <div class="card relative my-auto w-full max-w-2xl overflow-hidden">
      <div class="flex items-start justify-between gap-4 border-b border-slate-100 bg-gradient-to-r from-agua-50 to-agro-50 px-5 py-4 sm:px-6">
        <h2 id="reg-titulo" class="font-display text-lg font-bold text-slate-900">Reglamento Académico y Guía de Uso de Plataforma</h2>
        <button type="button" data-close class="rounded-lg p-1 text-slate-500 hover:bg-white hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-agua-500" aria-label="Cerrar">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
      </div>
      <div class="max-h-[70vh] space-y-4 overflow-y-auto p-5 text-sm leading-relaxed text-slate-700 sm:p-6">
        <section><h3 class="font-bold text-slate-900">1. Acceso y credenciales</h3>
          <p>El acceso es personal e intransferible (usuario o RUT y contraseña). El participante es responsable del uso de sus credenciales. La asistencia se valida con ClaveÚnica a través del Gestor Intermedio de SENCE.</p></section>
        <section><h3 class="font-bold text-slate-900">2. Control de sesión y trazabilidad</h3>
          <p>La plataforma registra fecha y hora de ingreso y salida, minutos conectados y dirección IP de cada sesión. La sesión se cierra automáticamente tras 60 minutos sin actividad. Al terminar, utiliza «Registrar Salida SENCE / Cerrar Sesión».</p></section>
        <section><h3 class="font-bold text-slate-900">3. Requisitos de aprobación</h3>
          <ul class="list-disc space-y-1 pl-5"><li>Avance curricular mínimo: 75% (módulos completados).</li><li>Nota mínima de aprobación: 4.0 en escala 1.0 a 7.0, con 60% de exigencia.</li><li>Evaluación final: 20 preguntas de selección múltiple y 10 de verdadero/falso (60 puntos).</li></ul></section>
        <section><h3 class="font-bold text-slate-900">4. Evaluaciones y probidad académica</h3>
          <p>Las evaluaciones son individuales. Está prohibido compartir respuestas, suplantar a otro participante o intentar vulnerar el sistema. El incumplimiento puede implicar la invalidación de la evaluación.</p></section>
        <section><h3 class="font-bold text-slate-900">5. Guía rápida de uso</h3>
          <ol class="list-decimal space-y-1 pl-5"><li>Revisa la presentación de cada módulo y descárgala si lo necesitas.</li><li>Resuelve los ejercicios y marca el módulo como completado.</li><li>Rinde la evaluación final y genera tu PDF para el relator.</li><li>Plantea dudas en el Foro de Consultas (respuesta en menos de 24 hrs hábiles).</li></ol></section>
        <section><h3 class="font-bold text-slate-900">6. Protección de datos</h3>
          <p>Los datos personales se usan exclusivamente para la ejecución y fiscalización del curso, conforme a la normativa chilena de protección de datos personales.</p></section>
        <section><h3 class="font-bold text-slate-900">7. Soporte</h3>
          <p>contacto@ddgdelvalle.cl · WhatsApp/Tel: +56 9 6316 3859 · Lun a Vie 09:00 a 18:00 hrs.</p></section>
      </div>
    </div>
  </div>

  <script>
  (function () {
    var abierto = null;
    function abrir(id) {
      var m = document.getElementById('modal-' + id);
      if (!m) return;
      abierto = m; m.classList.add('is-open'); document.body.style.overflow = 'hidden';
      if (id === 'sence') prepararSence();
      var c = m.querySelector('[data-close]'); if (c) c.focus();
    }
    function cerrar() {
      if (!abierto) return;
      abierto.classList.remove('is-open'); abierto = null; document.body.style.overflow = '';
    }
    document.addEventListener('click', function (e) {
      var o = e.target.closest('[data-open]');
      if (o) { abrir(o.dataset.open); return; }
      if (e.target.closest('[data-close]') || e.target.classList.contains('modal-overlay')) cerrar();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrar(); });

    /* RUT → formato 12.345.678-5 (no valida DV: solo presenta el dato tecleado) */
    function fmtRut(v) {
      v = v.replace(/[^0-9kK]/g, '').toUpperCase();
      if (v.length < 2) return v;
      return v.slice(0, -1).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + '-' + v.slice(-1);
    }

    function prepararSence() {
      var u = document.getElementById('usuario');
      var celda = document.querySelector('[data-param="RutAlumno"]');
      if (u && celda && /^[0-9.\-]+[0-9kK]$/.test(u.value.trim())) celda.textContent = fmtRut(u.value.trim());
      document.getElementById('sence-salida').classList.add('hidden');
    }

    document.getElementById('sence-simular').addEventListener('click', function () {
      var p = {};
      [].forEach.call(document.querySelectorAll('#sence-params [data-param]'), function (td) { p[td.dataset.param] = td.textContent.trim(); });
      var body = Object.keys(p).map(function (k) { return k + '=' + encodeURIComponent(p[k]); }).join('&');
      var ts = new Date();
      var out =
        '→ POST https://sistemas.sence.cl/…/IniciarSesion   [SIMULADO]\n' +
        '  Content-Type: application/x-www-form-urlencoded\n\n  ' + body.replace(/&/g, '\n  &') + '\n\n' +
        '← 200 OK   [SIMULADO · ' + ts.toLocaleString('es-CL') + ']\n' +
        '  RunAlumno=' + p.RutAlumno + '\n  CodSence=' + p.CodSence + '\n  CodigoCurso=' + p.CodigoCurso +
        '\n  IdSesionAlumno=' + p.IdSesionAlumno + '\n  GlosaError=0   (autenticación ClaveÚnica simulada: OK)\n  → retorno a ' + p.UrlRetorno;
      var pre = document.getElementById('sence-salida');
      pre.textContent = out; pre.classList.remove('hidden');
    });
  })();
  </script>
</body>
</html>
