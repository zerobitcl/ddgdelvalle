<?php
session_start();

$USUARIO_VALIDO = 'alumno';
$CLAVE_VALIDA   = 'riego2026';
$error_login    = '';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $user = trim($_POST['usuario'] ?? '');
    $pass = trim($_POST['clave'] ?? '');
    if ($user === $USUARIO_VALIDO && $pass === $CLAVE_VALIDA) {
        $_SESSION['alumno_auth'] = true;
        $_SESSION['alumno_user'] = $user;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $error_login = 'Usuario o contraseña incorrectos.';
}

$autenticado = !empty($_SESSION['alumno_auth']);

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
  </style>
</head>
<body class="font-sans text-slate-800 antialiased">

<?php if (!$autenticado): ?>
  <!-- ============ LOGIN ============ -->
  <div class="min-h-screen flex items-center justify-center px-4 py-10">
    <div class="card w-full max-w-md p-8 sm:p-10">
      <div class="text-center mb-8">
        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-agua-500 to-agro-600 text-white shadow-lg shadow-agua-500/25">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c.5 2.5-1 5-3.5 6.5C6 11 4.5 13 4.5 15.5A7.5 7.5 0 0012 23a7.5 7.5 0 007.5-7.5C19.5 11 15 6 12 3z" />
          </svg>
        </div>
        <h1 class="font-display text-2xl font-bold text-slate-900">Portal de Estudio</h1>
        <p class="mt-1 text-sm text-slate-500">Módulo de riego · Acceso privado del alumno</p>
      </div>

      <?php if ($error_login): ?>
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          <?= htmlspecialchars($error_login) ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="" class="space-y-5" autocomplete="off">
        <div>
          <label for="usuario" class="mb-1.5 block text-sm font-medium text-slate-700">Usuario</label>
          <input type="text" id="usuario" name="usuario" required autofocus
                 class="field" placeholder="Ingresa tu usuario">
        </div>
        <div>
          <label for="clave" class="mb-1.5 block text-sm font-medium text-slate-700">Contraseña</label>
          <input type="password" id="clave" name="clave" required
                 class="field" placeholder="Ingresa tu contraseña">
        </div>
        <button type="submit" name="login" value="1"
                class="w-full rounded-xl bg-gradient-to-r from-agro-600 to-agua-600 px-4 py-3 text-sm font-semibold text-white shadow-md shadow-agro-600/20 transition hover:from-agro-700 hover:to-agua-700 focus:outline-none focus:ring-2 focus:ring-agro-500 focus:ring-offset-2">
          Ingresar al módulo
        </button>
      </form>
    </div>
  </div>

<?php else: ?>
  <!-- ============ PORTAL ============ -->
  <header class="no-print sticky top-0 z-40 border-b border-slate-200/80 bg-white/85 backdrop-blur-md">
    <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
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
      <a href="?logout=1"
         class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:border-red-200 hover:bg-red-50 hover:text-red-700">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
        </svg>
        Cerrar sesión
      </a>
    </div>
  </header>

  <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-10 space-y-8">

    <!-- Presentación -->
    <section class="card overflow-hidden">
      <div class="border-b border-slate-100 bg-gradient-to-r from-agua-50 to-agro-50 px-5 py-4 sm:px-6">
        <h2 class="font-display text-lg font-bold text-slate-900">Presentación del módulo</h2>
        <p class="mt-0.5 text-sm text-slate-500">Visualiza el material y descárgalo para estudiar offline.</p>
      </div>
      <div class="p-5 sm:p-6">
        <div class="ratio-16-9 overflow-hidden rounded-xl bg-slate-100 ring-1 ring-slate-200">
          <iframe
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
    <div class="mt-12 mb-6 border-b-2 border-slate-200 pb-2 no-print">
      <h2 class="font-display text-2xl font-bold text-slate-900">Módulo 2: Componentes de un sistema de riego presurizado</h2>
    </div>

    <section class="card overflow-hidden mb-8">
      <div class="border-b border-slate-100 bg-gradient-to-r from-agua-50 to-agro-50 px-5 py-4 sm:px-6">
        <h3 class="font-display text-lg font-bold text-slate-900">Presentación del módulo</h3>
        <p class="mt-0.5 text-sm text-slate-500">Visualiza el material y descárgalo para estudiar offline.</p>
      </div>
      <div class="p-5 sm:p-6">
        <div class="ratio-16-9 overflow-hidden rounded-xl bg-slate-100 ring-1 ring-slate-200">
          <iframe
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
    <div class="mt-12 mb-6 border-b-2 border-slate-200 pb-2 no-print">
      <h2 class="font-display text-2xl font-bold text-slate-900">Módulo 3: Mantención de Sistema de Riego</h2>
    </div>

    <section class="card overflow-hidden mb-8">
      <div class="border-b border-slate-100 bg-gradient-to-r from-agua-50 to-agro-50 px-5 py-4 sm:px-6">
        <h3 class="font-display text-lg font-bold text-slate-900">Presentación del módulo</h3>
        <p class="mt-0.5 text-sm text-slate-500">Visualiza el material y descárgalo para estudiar offline.</p>
      </div>
      <div class="p-5 sm:p-6">
        <div class="ratio-16-9 overflow-hidden rounded-xl bg-slate-100 ring-1 ring-slate-200">
          <iframe
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
    <div class="mt-12 mb-6 border-b-2 border-slate-200 pb-2 no-print">
      <h2 class="font-display text-2xl font-bold text-slate-900">Módulo 4: Implementación y Manejo de Sistemas de Riego Tecnificado Parte IV</h2>
    </div>

    <section class="card overflow-hidden mb-8">
      <div class="border-b border-slate-100 bg-gradient-to-r from-agua-50 to-agro-50 px-5 py-4 sm:px-6">
        <h3 class="font-display text-lg font-bold text-slate-900">Presentación del módulo</h3>
        <p class="mt-0.5 text-sm text-slate-500">Visualiza el material y descárgalo para estudiar offline.</p>
      </div>
      <div class="p-5 sm:p-6">
        <div class="ratio-16-9 overflow-hidden rounded-xl bg-slate-100 ring-1 ring-slate-200">
          <iframe
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
        <!-- Contenedor capturado por html2pdf (sin overflow-hidden ni botón) -->
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
          <p class="mt-2 text-xs text-slate-400">Se descargará el archivo y deberás enviarlo al profesor.</p>
        </div>
      </form>
    </section>

    <footer class="pb-8 text-center text-xs text-slate-400 no-print">
      Portal de estudio privado · Valle del Mataquito · Riego tecnificado
    </footer>
  </main>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
  <script>
  (function () {
    var formEval = document.getElementById('form-eval-final');
    if (!formEval) return;

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

    /** Construye un DOM plano (sin Tailwind) para que html2canvas no genere páginas en blanco */
    function construirDocumentoPdf() {
      var nombre = (document.getElementById('eval_nombre').value || '').trim();
      var fecha = document.getElementById('eval_fecha').value || '';
      var wrap = document.createElement('div');
      wrap.id = 'pdf-export-root';
      wrap.style.cssText = 'position:fixed;left:-10000px;top:0;width:720px;background:#fff;color:#0f172a;font-family:Arial,Helvetica,sans-serif;font-size:12.5px;line-height:1.45;padding:20px;box-sizing:border-box;';

      var html = '';
      html += '<div style="border-bottom:2px solid #15803d;padding-bottom:12px;margin-bottom:18px;">';
      html += '<div style="font-size:18px;font-weight:700;color:#14532d;">Evaluación Final · Riego Tecnificado</div>';
      html += '<div style="margin-top:8px;"><strong>Nombre:</strong> ' + esc(nombre) + '</div>';
      html += '<div><strong>Fecha:</strong> ' + esc(fecha) + '</div>';
      html += '</div>';

      html += '<div style="background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;margin-bottom:14px;">';
      html += '<div style="font-weight:700;font-size:14px;">Sección I · Selección múltiple</div>';
      html += '<div style="font-size:11px;color:#64748b;">2 pts c/u · 40 pts</div></div>';

      var origen = document.getElementById('eval-final-contenedor');
      var smGroups = origen.querySelectorAll('[role="group"][aria-labelledby^="sm_label_"]');
      smGroups.forEach(function (group, idx) {
        var labelEl = document.getElementById(group.getAttribute('aria-labelledby'));
        var pregunta = labelEl ? labelEl.textContent.trim() : ('Pregunta ' + (idx + 1));
        html += '<div style="margin-bottom:14px;page-break-inside:avoid;">';
        html += '<div style="font-weight:600;margin-bottom:6px;">' + esc(pregunta) + '</div>';
        group.querySelectorAll('label.eval-opcion').forEach(function (lab) {
          var radio = lab.querySelector('input[type="radio"]');
          var texto = (lab.querySelector('span') || lab).textContent.replace(/\s+/g, ' ').trim();
          var marcada = radio && radio.checked;
          var boxStyle = marcada
            ? 'background:#dcfce9;border:2px solid #16a34a;font-weight:700;color:#14532d;'
            : 'background:#fff;border:1px solid #cbd5e1;color:#334155;';
          var marca = marcada ? ' ✓' : '';
          html += '<div style="padding:7px 10px;margin:0 0 5px;border-radius:6px;' + boxStyle + '">' +
            esc(texto) + marca + '</div>';
        });
        html += '</div>';
      });

      html += '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 12px;margin:20px 0 14px;">';
      html += '<div style="font-weight:700;font-size:14px;">Sección II · Verdadero / Falso</div>';
      html += '<div style="font-size:11px;color:#64748b;">2 pts c/u · 20 pts</div></div>';

      var vfGroups = origen.querySelectorAll('[role="group"][aria-labelledby^="vf_label_"]');
      vfGroups.forEach(function (group, idx) {
        var labelEl = document.getElementById(group.getAttribute('aria-labelledby'));
        var pregunta = labelEl ? labelEl.textContent.trim() : ('Pregunta ' + (idx + 1));
        html += '<div style="margin-bottom:14px;page-break-inside:avoid;">';
        html += '<div style="font-weight:600;margin-bottom:6px;">' + esc(pregunta) + '</div>';
        group.querySelectorAll('label.eval-opcion').forEach(function (lab) {
          var radio = lab.querySelector('input[type="radio"]');
          var texto = (lab.querySelector('span') || lab).textContent.replace(/\s+/g, ' ').trim();
          var marcada = radio && radio.checked;
          var boxStyle = marcada
            ? 'background:#dcfce9;border:2px solid #16a34a;font-weight:700;color:#14532d;'
            : 'background:#fff;border:1px solid #cbd5e1;color:#334155;';
          var marca = marcada ? ' ✓' : '';
          html += '<div style="display:inline-block;min-width:120px;padding:7px 12px;margin:0 8px 5px 0;border-radius:6px;' + boxStyle + '">' +
            esc(texto) + marca + '</div>';
        });
        html += '</div>';
      });

      wrap.innerHTML = html;
      document.body.appendChild(wrap);
      return wrap;
    }

    formEval.addEventListener('submit', function (e) {
      e.preventDefault();

      if (typeof html2pdf !== 'function') {
        alert('No se pudo cargar la librería PDF. Revisa tu conexión e intenta de nuevo.');
        return;
      }

      var btn = document.getElementById('btn-generar-pdf');
      var filename = 'Prueba_Riego_' + slugNombre(document.getElementById('eval_nombre').value) + '.pdf';

      // Highlight también en pantalla (feedback al alumno)
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

      var exportRoot = null;
      try {
        exportRoot = construirDocumentoPdf();
      } catch (errBuild) {
        console.error(errBuild);
        restaurarUi(btn);
        alert('Error al preparar el contenido del PDF.');
        return;
      }

      var opt = {
        margin: [10, 10, 10, 10],
        filename: filename,
        image: { type: 'jpeg', quality: 0.95 },
        html2canvas: {
          scale: 1.5,
          useCORS: true,
          logging: false,
          backgroundColor: '#ffffff',
          scrollX: 0,
          scrollY: 0
        },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
        pagebreak: { mode: ['css', 'legacy'] }
      };

      // Visible para captura, detrás del contenido (opacity baja = PDF en blanco)
      exportRoot.style.left = '0';
      exportRoot.style.top = '0';
      exportRoot.style.opacity = '1';
      exportRoot.style.zIndex = '-1';
      exportRoot.style.pointerEvents = 'none';

      html2pdf()
        .set(opt)
        .from(exportRoot)
        .save()
        .then(
          function () {
            if (exportRoot && exportRoot.parentNode) exportRoot.parentNode.removeChild(exportRoot);
            alert('PDF generado. Por favor, envía este archivo al profesor');
            restaurarUi(btn);
          },
          function (err) {
            console.error('Error PDF:', err);
            if (exportRoot && exportRoot.parentNode) exportRoot.parentNode.removeChild(exportRoot);
            alert('No se pudo generar el PDF automáticamente. Se abrirá el diálogo de impresión: elige "Guardar como PDF".');
            restaurarUi(btn);
            window.print();
          }
        );
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

      if (correctos === total) {
        box.classList.add('bg-green-50', 'text-green-800', 'border', 'border-green-200');
        box.textContent = '¡Excelente! Todos los cálculos numéricos son correctos (' + correctos + '/' + total + ').';
      } else {
        box.classList.add('bg-red-50', 'text-red-800', 'border', 'border-red-200');
        box.textContent = 'Revisa tus cálculos: ' + correctos + ' de ' + total + ' respuestas numéricas correctas. Los campos en rojo necesitan corrección (tolerancia ±0,05).';
      }
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
<?php endif; ?>
</body>
</html>
