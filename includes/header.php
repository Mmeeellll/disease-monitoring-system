<?php
/**
 * FILE: includes/header.php
 * PURPOSE: Persistent application shell header, navigation sidebar, and top status bar.
 * SYSTEM ROLE:
 *   1. Enforces authentication on every page render.
 *   2. Dynamically builds sidebar links and quick-action buttons based on user RBAC role.
 *   3. Displays live open-alert indicators, breadcrumbs, and dismissible flash messages.
 */

require_once __DIR__.'/../config.php';
require_login();

$page = $_GET['page'] ?? 'dashboard';
$nav = [
 'dashboard'=>['Dashboard','bi-grid-1x2-fill','Overview of your health unit'],
 'cases'=>['Disease Cases','bi-clipboard2-pulse-fill','Register and track cases'],
 'hotspots'=>['Hotspot Map','bi-geo-alt-fill','Geographic risk view'],
 'analytics'=>['Analytics','bi-graph-up-arrow','Trends and statistics'],
 'reports'=>['Reports','bi-file-earmark-bar-graph-fill','Generated summaries'],
 'barangays'=>['Barangays','bi-buildings-fill','Barangay records'],
 'alerts'=>['Alerts','bi-bell-fill','Active notifications'],
 'recommendations'=>['Recommendations','bi-lightbulb-fill','Action planning'],
 'users'=>['Users','bi-people-fill','Staff accounts'],
 'settings'=>['Settings','bi-sliders','System preferences'],
];

$pageTitle  = $page === 'view_import' ? 'Spreadsheet Net View' : ($nav[$page][0] ?? 'Dashboard');
$flash      = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$cityName   = db()->query("SELECT setting_value FROM settings WHERE setting_key='city_name'")->fetchColumn() ?: 'Butuan City';
$openAlerts = (int)db()->query("SELECT COUNT(*) FROM alerts WHERE status NOT IN ('Resolved', 'Completed')")->fetchColumn();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle) ?> | Disease Monitoring</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/style.css" rel="stylesheet">
<style>
/* ===================== INTERACTIVE UI EXTRAS ===================== */
:root {
    --sidebar-w: 260px;
    --accent: #2563eb;
    --accent-soft: #dbeafe;
    --accent-glow: rgba(37, 99, 235, .35);
}

/* Sidebar base & hover transitions */
.sidebar {
    transition: transform .3s cubic-bezier(.4,0,.2,1), box-shadow .3s;
    background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%) !important;
}
.sidebar .nav-link {
    position: relative;
    border-radius: 10px;
    margin: 2px 10px;
    padding: 10px 14px;
    transition: all .25s ease;
    overflow: hidden;
}
.sidebar .nav-link i {
    font-size: 1.05rem;
    transition: transform .25s ease, color .25s ease;
    width: 22px;
    text-align: center;
}
.sidebar .nav-link::before {
    content: '';
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 3px;
    background: var(--accent);
    transform: scaleY(0);
    transition: transform .25s ease;
    border-radius: 0 4px 4px 0;
}
.sidebar .nav-link:hover {
    background: rgba(255,255,255,.06);
    transform: translateX(3px);
}
.sidebar .nav-link:hover i {
    transform: scale(1.15) rotate(-4deg);
    color: #93c5fd;
}
.sidebar .nav-link:hover::before { transform: scaleY(1); }
.sidebar .nav-link.active {
    background: linear-gradient(90deg, rgba(37,99,235,.25), rgba(37,99,235,.08));
    box-shadow: inset 0 0 0 1px rgba(96,165,250,.25);
}
.sidebar .nav-link.active::before { transform: scaleY(1); }
.sidebar .nav-link.active i {
    color: #60a5fa;
    filter: drop-shadow(0 0 6px var(--accent-glow));
}

/* Staggered entrance animation for sidebar navigation links */
@keyframes slideInLeft {
    from { opacity: 0; transform: translateX(-12px); }
    to   { opacity: 1; transform: translateX(0); }
}
.sidebar nav .nav-link {
    animation: slideInLeft .35s ease backwards;
}
.sidebar nav .nav-link:nth-child(1) { animation-delay: .03s; }
.sidebar nav .nav-link:nth-child(2) { animation-delay: .06s; }
.sidebar nav .nav-link:nth-child(3) { animation-delay: .09s; }
.sidebar nav .nav-link:nth-child(4) { animation-delay: .12s; }
.sidebar nav .nav-link:nth-child(5) { animation-delay: .15s; }
.sidebar nav .nav-link:nth-child(6) { animation-delay: .18s; }
.sidebar nav .nav-link:nth-child(7) { animation-delay: .21s; }
.sidebar nav .nav-link:nth-child(8) { animation-delay: .24s; }
.sidebar nav .nav-link:nth-child(9) { animation-delay: .27s; }
.sidebar nav .nav-link:nth-child(10){ animation-delay: .30s; }

/* Brand icon pulse */
.brand-icon {
    animation: pulseGlow 2.4s ease-in-out infinite;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
@keyframes pulseGlow {
    0%, 100% { box-shadow: 0 0 0 0 rgba(96,165,250,.6); }
    50%      { box-shadow: 0 0 0 10px rgba(96,165,250,0); }
}

/* Nav count badge */
.nav-count {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: #fff;
    font-size: .65rem;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 999px;
    margin-left: auto;
    animation: pulseBadge 1.8s ease-in-out infinite;
    box-shadow: 0 0 8px rgba(239,68,68,.6);
}
@keyframes pulseBadge {
    0%, 100% { transform: scale(1);   }
    50%      { transform: scale(1.12); }
}

/* City chip hover */
.city-chip {
    transition: transform .25s ease, background .25s ease;
    border-radius: 10px;
    cursor: pointer;
}
.city-chip:hover {
    transform: translateY(-2px);
    background: rgba(255,255,255,.06);
}
.city-chip i {
    animation: floatPin 3s ease-in-out infinite;
}
@keyframes floatPin {
    0%, 100% { transform: translateY(0); }
    50%      { transform: translateY(-4px); }
}

/* Topbar & live status dot */
.topbar {
    backdrop-filter: blur(10px);
    background: rgba(255,255,255,.85) !important;
    border-bottom: 1px solid rgba(0,0,0,.05);
    transition: box-shadow .3s;
}
.topbar.scrolled {
    box-shadow: 0 4px 20px rgba(0,0,0,.06);
}
.live-status i {
    display: inline-block;
    width: 8px; height: 8px;
    border-radius: 50%;
    background: #22c55e;
    margin-right: 6px;
    animation: livePulse 1.6s ease-in-out infinite;
    box-shadow: 0 0 6px #22c55e;
}
@keyframes livePulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%      { opacity: .55; transform: scale(.85); }
}

/* Avatar */
.avatar {
    background: linear-gradient(135deg, #3b82f6, #8b5cf6);
    transition: transform .25s ease, box-shadow .25s ease;
    cursor: pointer;
}
.avatar:hover {
    transform: scale(1.08) rotate(3deg);
    box-shadow: 0 0 0 4px var(--accent-soft);
}

/* Breadcrumb hover */
.crumb i {
    transition: transform .25s ease;
}
.crumb:hover i {
    transform: translateX(3px);
}

/* Topbar quick action icons */
.quick-actions {
    display: flex;
    gap: 6px;
    align-items: center;
    margin-right: 12px;
}
.quick-btn {
    width: 38px; height: 38px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #f1f5f9;
    color: #475569;
    border: none;
    transition: all .25s ease;
    position: relative;
    text-decoration: none;
}
.quick-btn:hover {
    background: var(--accent);
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 6px 14px var(--accent-glow);
}
.quick-btn .badge-dot {
    position: absolute;
    top: 6px; right: 6px;
    width: 8px; height: 8px;
    border-radius: 50%;
    background: #ef4444;
    border: 2px solid #fff;
}

/* Content fade-in */
.content-area {
    animation: fadeUp .4s ease;
}
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* Menu toggle animation */
#menuToggle {
    transition: transform .25s ease;
}
#menuToggle:active {
    transform: scale(.92) rotate(90deg);
}

/* Mobile sidebar overlay */
.sidebar-overlay {
    position: fixed; inset: 0;
    background: rgba(15,23,42,.5);
    backdrop-filter: blur(2px);
    z-index: 1040;
    opacity: 0;
    pointer-events: none;
    transition: opacity .3s;
}
.sidebar-overlay.show {
    opacity: 1;
    pointer-events: auto;
}
@media (max-width: 991.98px) {
    .sidebar {
        transform: translateX(-100%);
    }
    .sidebar.show {
        transform: translateX(0);
        box-shadow: 0 0 40px rgba(0,0,0,.4);
    }
}
</style>
</head>
<body>

<div class="app-shell">

  <!-- LEFT NAVIGATION SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="index.php">
      <span class="brand-icon"><i class="bi bi-heart-pulse-fill"></i></span>
      <span style="color: white;"><b>DISEASE</b><small>MONITORING SYSTEM</small></span>
    </a>

    <div class="nav-label">WORKSPACE</div>
<nav>
  <?php
  $currentRole  = $_SESSION['role'] ?? 'Encoder';
  $isFullAccess = in_array($currentRole, ['Administrator', 'Health Officer'], true);
  ?>

  <a href="index.php?page=dashboard" class="nav-link <?= $page === 'dashboard' ? 'active' : '' ?>">
    <i class="bi bi-grid-1x2-fill"></i> Dashboard
  </a>

  <a href="index.php?page=cases" class="nav-link <?= $page === 'cases' ? 'active' : '' ?>">
    <i class="bi bi-clipboard2-pulse-fill"></i> Disease Cases
  </a>

  <?php if ($isFullAccess): ?>
    <a href="index.php?page=hotspots" class="nav-link <?= $page === 'hotspots' ? 'active' : '' ?>">
      <i class="bi bi-geo-alt-fill"></i> Hotspot Map
    </a>
    <a href="index.php?page=analytics" class="nav-link <?= $page === 'analytics' ? 'active' : '' ?>">
      <i class="bi bi-graph-up-arrow"></i> Analytics
    </a>
    <a href="index.php?page=reports" class="nav-link <?= $page === 'reports' ? 'active' : '' ?>">
      <i class="bi bi-file-earmark-bar-graph-fill"></i> Reports
    </a>
    <a href="index.php?page=barangays" class="nav-link <?= $page === 'barangays' ? 'active' : '' ?>">
      <i class="bi bi-buildings-fill"></i> Barangays
    </a>
 <?php endif; ?>
  <a href="index.php?page=alerts" class="nav-link <?= $page === 'alerts' ? 'active' : '' ?>">
    <i class="bi bi-bell-fill"></i> Alerts
    <?php if ($openAlerts): ?><span class="nav-count"><?= $openAlerts ?></span><?php endif; ?>
  </a>

  <a href="index.php?page=recommendations" class="nav-link <?= $page === 'recommendations' ? 'active' : '' ?>">
    <i class="bi bi-lightbulb-fill"></i> Recommendations
  </a>

    <a href="index.php?page=users" class="nav-link <?= $page === 'users' ? 'active' : '' ?>">
      <i class="bi bi-people-fill"></i> Users
    </a>

  <a href="index.php?page=settings" class="nav-link <?= $page === 'settings' ? 'active' : '' ?>">
    <i class="bi bi-sliders"></i> Settings
  </a>
</nav>

    <div class="sidebar-bottom">
      <div class="city-chip" title="Local Health Unit">
        <i class="bi bi-geo-alt-fill"></i>
        <div>
          <small>LOCAL HEALTH UNIT</small>
          <b><?= e($cityName) ?></b>
        </div>
      </div>
      <a class="nav-link" href="logout.php" onclick="return confirm('Sign out of the system?')">
        <i class="bi bi-box-arrow-left"></i>
        <span>Sign out</span>
      </a>
    </div>
  </aside>

  <!-- MOBILE BACKDROP OVERLAY -->
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <!-- MAIN WORKSPACE CONTAINER -->
  <main class="main-area">
    <header class="topbar" id="topbar">
      <button class="btn btn-light d-lg-none" id="menuToggle" aria-label="Toggle menu">
        <i class="bi bi-list"></i>
      </button>

      <div class="crumb">
        <i class="bi bi-house-door"></i>
        <span>Health Unit</span>
        <i class="bi bi-chevron-right"></i>
        <b><?= e($pageTitle) ?></b>
      </div>

      <div class="top-actions">
<div class="quick-actions">
  <a href="index.php?page=cases" class="quick-btn" title="New case">
    <i class="bi bi-plus-lg"></i>
  </a>
  <a href="index.php?page=alerts" class="quick-btn" title="Alerts">
    <i class="bi bi-bell"></i>
    <?php if($openAlerts): ?><span class="badge-dot"></span><?php endif; ?>
  </a>
  <?php if ($isFullAccess): ?>
    <a href="index.php?page=reports" class="quick-btn" title="Reports">
      <i class="bi bi-file-earmark-bar-graph"></i>
    </a>
    <a href="index.php?page=hotspots" class="quick-btn" title="Hotspot map">
      <i class="bi bi-geo-alt"></i>
    </a>
  <?php endif; ?>
</div>

        <span class="live-status"><i></i> System online</span>

        <div class="avatar" title="<?= e($_SESSION['name'] ?? 'Administrator') ?>">
          <?= e(strtoupper(substr($_SESSION['name'] ?? 'A',0,1))) ?>
        </div>
        <div class="user-meta">
          <b><?= e($_SESSION['name'] ?? 'Administrator') ?></b>
          <small><i class="bi bi-shield-check"></i> <?= e($_SESSION['role'] ?? 'Administrator') ?></small>
        </div>
      </div>
    </header>

    <section class="content-area">
      <!-- Dismissible One-Time Flash Notification Banner -->
      <?php if($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
          <i class="bi <?= $flash['type']==='danger'?'bi-exclamation-triangle-fill':'bi-check-circle-fill' ?> me-2"></i>
          <?= e($flash['message']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <!-- UI Interactions: Mobile drawer toggle, Escape key handler, sticky shadow, auto-hide alerts, ripple effect -->
      <script>
      document.addEventListener('DOMContentLoaded', function () {
          var sidebar = document.getElementById('sidebar');
          var toggle  = document.getElementById('menuToggle');
          var overlay = document.getElementById('sidebarOverlay');
          var topbar  = document.getElementById('topbar');

          function openSidebar() {
              sidebar.classList.add('show');
              overlay.classList.add('show');
          }
          function closeSidebar() {
              sidebar.classList.remove('show');
              overlay.classList.remove('show');
          }
          toggle?.addEventListener('click', function () {
              sidebar.classList.contains('show') ? closeSidebar() : openSidebar();
          });
          overlay?.addEventListener('click', closeSidebar);

          document.addEventListener('keydown', function (e) {
              if (e.key === 'Escape') closeSidebar();
          });

          window.addEventListener('scroll', function () {
              topbar.classList.toggle('scrolled', window.scrollY > 8);
          });

          // Automatically dismiss flash alerts after 5 seconds
          var flash = document.querySelector('.content-area .alert');
          if (flash) {
              setTimeout(function () {
                  if (flash.classList.contains('show')) {
                      bootstrap.Alert.getOrCreateInstance(flash).close();
                  }
              }, 5000);
          }

          // Click ripple animation on topbar quick-action buttons
          document.querySelectorAll('.quick-btn').forEach(function (btn) {
              btn.addEventListener('click', function (e) {
                  var ripple = document.createElement('span');
                  ripple.style.cssText = 'position:absolute;border-radius:50%;background:rgba(255,255,255,.6);transform:scale(0);animation:ripple .6s ease-out;pointer-events:none;';
                  var size = Math.max(btn.offsetWidth, btn.offsetHeight);
                  ripple.style.width = ripple.style.height = size + 'px';
                  ripple.style.left = (e.offsetX - size/2) + 'px';
                  ripple.style.top  = (e.offsetY - size/2) + 'px';
                  btn.style.overflow = 'hidden';
                  btn.appendChild(ripple);
                  setTimeout(function(){ ripple.remove(); }, 600);
              });
          });
      });
      </script>
      <style>
      @keyframes ripple {
          to { transform: scale(2.4); opacity: 0; }
      }
      </style>