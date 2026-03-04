<?php require_once __DIR__.'/auth.php'; require_once __DIR__.'/functions.php'; ?>
<!DOCTYPE html><html lang="zh"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>追踪系统</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="assets/style.css"></head>
<body>
<nav class="navbar navbar-dark bg-dark fixed-top navbar-expand-lg"><div class="container-fluid">
<a class="navbar-brand" href="index.php">追踪系统</a><button class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#nv"><span class="navbar-toggler-icon"></span></button>
<div class="collapse navbar-collapse" id="nv"><ul class="navbar-nav me-auto">
<?php if(current_user()):?><li class="nav-item"><a class="nav-link" href="dashboard.php">面板</a></li><?php if(is_admin()):?><li class="nav-item"><a class="nav-link" href="admin.php">后台</a></li><?php endif;?><li class="nav-item"><a class="nav-link" href="logout.php">退出</a></li>
<?php else: if(cfg('allow_register')=='1'):?><li class="nav-item"><a class="nav-link" href="register.php">注册</a></li><?php endif; endif;?>
</ul></div></div></nav><div class="container">
