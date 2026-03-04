<?php require_once __DIR__.'/auth.php'; require_once __DIR__.'/functions.php';
$_currentPage = basename($_SERVER['PHP_SELF']);
$_siteName = cfg('site_name') ?: '追踪系统';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($_siteName) ?></title>
  <link rel="stylesheet" href="assets/vendor/bootstrap.min.css">
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<nav class="navbar navbar-dark fixed-top navbar-expand-lg">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php">
      <span class="brand-icon">&#9993;</span>
      <?= htmlspecialchars($_siteName) ?>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nv" aria-controls="nv" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nv">
      <ul class="navbar-nav me-auto">
        <?php if(current_user()): ?>
          <li class="nav-item"><a class="nav-link <?= $_currentPage==='dashboard.php'?'active':'' ?>" href="dashboard.php">&#9776; 面板</a></li>
          <?php if(is_admin()): ?><li class="nav-item"><a class="nav-link <?= $_currentPage==='admin.php'?'active':'' ?>" href="admin.php">&#9881; 后台</a></li><?php endif; ?>
        <?php endif; ?>
      </ul>
      <?php if(current_user()): ?>
      <ul class="navbar-nav">
        <li class="nav-item"><a class="nav-link <?= $_currentPage==='profile.php'?'active':'' ?>" href="profile.php">&#9998; <?= htmlspecialchars(current_user()['username']) ?></a></li>
        <li class="nav-item"><a class="nav-link nav-link-danger" href="logout.php">退出</a></li>
      </ul>
      <?php endif; ?>
    </div>
  </div>
</nav>
<main class="container app-main">
