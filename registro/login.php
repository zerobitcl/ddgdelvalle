<?php
require_once __DIR__ . '/config.php';
ddg_session_start();

// Ya autenticado → ir al app
if (ddg_is_authenticated()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    // Comparaciones seguras contra timing-attacks
    $user_ok = hash_equals(DDG_USERNAME, $user);
    $pass_ok = hash_equals(DDG_PASSWORD, $pass);

    if ($user_ok && $pass_ok) {
        session_regenerate_id(true);
        $_SESSION['ddg_auth'] = true;
        $_SESSION['ddg_user'] = $user;
        header('Location: index.php');
        exit;
    } else {
        $error = 'Usuario o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Acceso — DDG Del Valle Capacitaciones</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer"/>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
  -webkit-font-smoothing: antialiased;
  min-height: 100vh;
  background: linear-gradient(145deg, #2C4855 0%, #1e343e 50%, #23424d 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
}

/* Subtle animated background grid */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  background-image:
    linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
  background-size: 48px 48px;
  pointer-events: none;
}

.login-card {
  background: white;
  border-radius: 24px;
  padding: 48px 44px 40px;
  width: 100%;
  max-width: 420px;
  box-shadow: 0 32px 80px rgba(0,0,0,0.4), 0 2px 8px rgba(0,0,0,0.2);
  position: relative;
  z-index: 1;
}

.brand-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  background: rgba(241, 234, 175, 0.25);
  border: 1px solid rgba(241, 234, 175, 0.4);
  border-radius: 16px;
  padding: 12px 20px;
  margin-bottom: 20px;
}
.brand-badge img {
  height: 52px;
  width: auto;
  max-width: 220px;
  display: block;
  object-fit: contain;
}

.login-title {
  font-size: 26px;
  font-weight: 800;
  color: #0f172a;
  letter-spacing: -0.6px;
  margin-bottom: 6px;
}

.login-sub {
  font-size: 14px;
  color: #64748b;
  margin-bottom: 32px;
}

.field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }

.field label {
  font-size: 11px;
  font-weight: 700;
  color: #374151;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.field input {
  width: 100%;
  padding: 12px 14px;
  background: #f8fafc;
  border: 1.5px solid #e2e8f0;
  border-radius: 10px;
  font-size: 14px;
  color: #0f172a;
  outline: none;
  transition: all 0.2s;
  font-family: inherit;
}
.field input:focus {
  border-color: #2C4855;
  box-shadow: 0 0 0 3px rgba(44, 72, 85, 0.12);
  background: white;
}
.field input.err {
  border-color: #f87171;
  background: #fff5f5;
}

.password-wrap { position: relative; }
.password-wrap input { padding-right: 44px; }
.toggle-pass {
  position: absolute;
  right: 12px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  cursor: pointer;
  color: #94a3b8;
  font-size: 14px;
  padding: 4px;
  transition: color 0.2s;
}
.toggle-pass:hover { color: #475569; }

.error-banner {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #dc2626;
  border-radius: 10px;
  padding: 10px 14px;
  font-size: 13px;
  font-weight: 500;
  margin-bottom: 20px;
}

.btn-login {
  width: 100%;
  padding: 13px;
  background: linear-gradient(135deg, #2C4855, #3d5f6e);
  color: white;
  font-size: 15px;
  font-weight: 700;
  border: none;
  border-radius: 10px;
  cursor: pointer;
  margin-top: 8px;
  font-family: inherit;
  transition: all 0.2s;
  box-shadow: 0 4px 16px rgba(44, 72, 85, 0.35);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.btn-login:hover {
  background: linear-gradient(135deg, #23404a, #2C4855);
  transform: translateY(-1px);
  box-shadow: 0 6px 22px rgba(44, 72, 85, 0.45);
}
.btn-login:active { transform: none; }

.login-footer {
  margin-top: 28px;
  padding-top: 20px;
  border-top: 1px solid #f1f5f9;
  text-align: center;
  font-size: 12px;
  color: #94a3b8;
}
</style>
</head>
<body>
<div class="login-card">

  <div style="text-align:center;margin-bottom:4px;">
    <div class="brand-badge">
      <img src="<?= htmlspecialchars(DDG_LOGO_URL, ENT_QUOTES, 'UTF-8') ?>" alt="DDG Del Valle Capacitaciones">
    </div>
    <h1 class="login-title">Bienvenido</h1>
    <p class="login-sub">Ingresa tus credenciales para acceder al sistema de informes</p>
  </div>

  <?php if ($error): ?>
  <div class="error-banner">
    <i class="fa-solid fa-circle-exclamation"></i>
    <?= htmlspecialchars($error) ?>
  </div>
  <?php endif; ?>

  <form method="POST" action="login.php" autocomplete="on">

    <div class="field">
      <label for="username">Usuario</label>
      <input
        type="text"
        id="username"
        name="username"
        placeholder="Ingresa tu usuario"
        value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
        autocomplete="username"
        class="<?= $error ? 'err' : '' ?>"
        required
        autofocus
      >
    </div>

    <div class="field">
      <label for="password">Contraseña</label>
      <div class="password-wrap">
        <input
          type="password"
          id="password"
          name="password"
          placeholder="••••••••••"
          autocomplete="current-password"
          class="<?= $error ? 'err' : '' ?>"
          required
        >
        <button type="button" class="toggle-pass" onclick="togglePassword()" title="Mostrar/ocultar contraseña">
          <i class="fa-solid fa-eye" id="eye-icon"></i>
        </button>
      </div>
    </div>

    <button type="submit" class="btn-login">
      <i class="fa-solid fa-right-to-bracket"></i>
      Ingresar al sistema
    </button>

  </form>

  <div class="login-footer">
    <i class="fa-solid fa-lock" style="margin-right:4px;"></i>
    Sistema de acceso restringido · DDG Del Valle Capacitaciones &copy; 2026
  </div>

</div>

<script>
function togglePassword() {
  const input = document.getElementById('password');
  const icon  = document.getElementById('eye-icon');
  if (input.type === 'password') {
    input.type = 'text';
    icon.className = 'fa-solid fa-eye-slash';
  } else {
    input.type = 'password';
    icon.className = 'fa-solid fa-eye';
  }
}
</script>
</body>
</html>
