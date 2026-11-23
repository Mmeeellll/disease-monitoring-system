<?php
/**
 * FILE: login.php
 * PURPOSE: Public entry point and user authentication screen.
 * SYSTEM ROLE: Validates staff credentials against bcrypt password hashes,
 *              regenerates session IDs against session fixation, and initializes
 *              user role metadata in $_SESSION.
 */

require_once 'config.php';

// Redirect already authenticated users straight to the dashboard
if (logged_in()) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf(); // Validate form security token
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        $stmt = db()->prepare('SELECT id,name,email,password_hash,role,active FROM users WHERE email=? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Verify user exists, is active, and password matches stored bcrypt hash
        if ($user && $user['active'] && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true); // Prevent session fixation attacks
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name']    = $user['name'];
            $_SESSION['email']   = $user['email'];
            $_SESSION['role']    = $user['role'];
            $_SESSION['user_id']     = (int)$user['id'];
            $_SESSION['barangay_id'] = !empty($user['barangay_id']) ? (int)$user['barangay_id'] : null;

           // $_SESSION['barangay_id'] = $user['barangay_id'] ?? null;
           
            header('Location: index.php');
            exit;
        }
        $error = 'Invalid email or password, or the account is inactive.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in | Disease Monitoring</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/style.css" rel="stylesheet">
</head>
<body class="login-body">
  <div class="login-wrap">
    <!-- Left Promotional Panel -->
    <div class="login-promo">
      <div class="promo-brand"><span class="brand-icon"><i class="bi bi-heart-pulse-fill"></i></span><span><b>DISEASE</b><small>MONITORING</small></span></div>
      <div class="promo-content">
        <div class="eyebrow"><span></span> HEALTH INTELLIGENCE PLATFORM</div>
        <h1>Disease surveillance.<br><em>Smarter decisions.</em></h1>
        <p>Empowering local health units with data-driven insights for rapid response and effective disease control.</p>
        <div class="promo-stats">
          <div><i class="bi bi-activity"></i><span><b>Real-time</b><small>Case monitoring</small></span></div>
          <div><i class="bi bi-map"></i><span><b>Barangay-level</b><small>Risk mapping</small></span></div>
        </div>
      </div>
      <div class="promo-footer">HEALTH UNIT MANAGEMENT SYSTEM <span>•</span> <?= date('Y') ?></div>
    </div>

    <!-- Right Sign-In Form Panel -->
    <div class="login-panel">
      <div class="login-mobile-brand"><span class="brand-icon"><i class="bi bi-heart-pulse-fill"></i></span> DISEASE MONITORING</div>
      <div class="login-form">
        <div class="login-symbol"><i class="bi bi-shield-lock"></i></div>
        <h2>Welcome back</h2>
        <p class="muted">Sign in to access your disease monitoring dashboard.</p>
        <?php if($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <label class="form-label" for="email">Email address</label>
          <div class="input-icon"><i class="bi bi-envelope"></i><input class="form-control" type="email" id="email" name="email" placeholder="you@healthunit.gov.ph" autocomplete="username" required></div>
          <div class="password-label"><label class="form-label" for="password">Password</label></div>
          <div class="input-icon"><i class="bi bi-lock"></i><input class="form-control" type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required><button type="button" class="password-toggle" id="togglePassword" aria-label="Show password"><i class="bi bi-eye"></i></button></div>
          <button class="btn btn-primary w-100 sign-in-btn" type="submit">Sign in to dashboard <i class="bi bi-arrow-right"></i></button>
        </form>

        <!-- Seeded Role Credentials Reference -->
        <div class="demo-note">
          <b>Demo account</b><br><br>
          Email: admin@disease.local<br>Password: password<br><br>
          Email: officer@disease.local<br>Password: Officer@2026<br><br>
          Email: Maonencoder@disease.local<br>Password: Encoder@2026<br><br>
          Email: Libertadencoder@disease.local<br>Password: Encoder@2026
        </div>
      </div>
      <div class="login-copyright">© <?= date('Y') ?> Disease Monitoring System</div>
    </div>
  </div>
  <!-- Toggle password visibility between masked dots and plain text -->
  <script>document.getElementById('togglePassword').addEventListener('click',function(){const p=document.getElementById('password');p.type=p.type==='password'?'text':'password';this.innerHTML=p.type==='password'?'<i class="bi bi-eye"></i>':'<i class="bi bi-eye-slash"></i>'});</script>
</body>
</html>