<?php require_once __DIR__.'/auth.php'; require_once __DIR__.'/functions.php'; ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>追踪系统</title>
  <link rel="stylesheet" href="assets/vendor/bootstrap.min.css">
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<nav class="navbar navbar-dark bg-dark fixed-top navbar-expand-lg shadow-sm">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="index.php">追踪系统</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nv" aria-controls="nv" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nv">
      <ul class="navbar-nav me-auto">
        <?php if(current_user()): ?>
          <li class="nav-item"><a class="nav-link" href="dashboard.php">面板</a></li>
          <?php if(is_admin()): ?><li class="nav-item"><a class="nav-link" href="admin.php">后台</a></li><?php endif; ?>
          <li class="nav-item"><a class="nav-link" href="logout.php">退出</a></li>
        <?php else: ?>
          <?php if(cfg('allow_register')=='1'): ?><li class="nav-item"><a class="nav-link" href="register.php">注册</a></li><?php endif; ?>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
<main class="container app-main">
