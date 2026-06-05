<?php
require_once __DIR__ . '/db.php';
$db = Database::getInstance();

// 检查数据库是否可用
$dbReady = false;
$grades = [];
try {
    $grades = $db->fetchAll("SELECT * FROM grades ORDER BY sort_order ASC");
    $dbReady = true;
} catch (Exception $e) {
    // 数据库未安装，跳转到安装页
    header('Location: install.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <title><?= APP_NAME ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .hero-section {
            padding: 40px 16px 24px;
            text-align: center;
        }
        .hero-section h1 {
            font-size: 26px;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: 2px;
        }
        .hero-section p {
            color: var(--text-secondary);
            font-size: 14px;
            margin-top: 6px;
        }
        .hero-section .current-month {
            display: inline-block;
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: #fff;
            padding: 4px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-top: 12px;
        }
        .grade-section-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
            padding: 0 4px 12px;
        }
        .admin-entry {
            text-align: center;
            padding: 24px 0 32px;
        }
        .admin-entry a {
            color: var(--text-secondary);
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 20px;
            border: 1px solid var(--border);
            transition: all 0.3s;
        }
        .admin-entry a:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        .footer-text {
            text-align: center;
            color: var(--text-light);
            font-size: 12px;
            padding: 16px 0 24px;
        }
        .quick-info {
            background: linear-gradient(135deg, #eef2ff, #e0e7ff);
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-around;
            gap: 8px;
        }
        .quick-info-item {
            text-align: center;
            flex: 1;
        }
        .quick-info-item .qi-value {
            font-size: 20px;
            font-weight: 800;
            color: var(--primary);
        }
        .quick-info-item .qi-label {
            font-size: 12px;
            color: var(--text-secondary);
        }
    </style>
</head>
<body>

<div class="page-header" style="padding: 32px 16px 24px;">
    <h1>🏫 课后服务</h1>
    <p class="subtitle">预算管理系统</p>
</div>

<div class="container">
    <div class="hero-section">
        <h1>选择年级</h1>
        <p>请选择需要填写数据的年级</p>
        <div class="current-month"><?= CURRENT_YEAR ?>年 <?= CURRENT_MONTH ?>月</div>
    </div>

    <div class="grade-grid">
        <?php foreach ($grades as $g): 
            $icon = ['❶','❷','❸','❹','❺','❻'];
            $colors = ['g1','g2','g3','g4','g5','g6'];
            $idx = $g['sort_order'] - 1;
        ?>
        <a href="grade.php?g=<?= $g['id'] ?>" class="grade-card <?= $colors[$idx] ?? '' ?>">
            <div class="grade-num"><?= $icon[$idx] ?? $g['sort_order'] ?></div>
            <div class="grade-label"><?= htmlspecialchars($g['name']) ?></div>
            <div class="grade-badge">点击进入</div>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="admin-entry">
        <a href="admin/login.php">🔑 管理员登录</a>
    </div>

    <div class="footer-text">
        <?= APP_NAME ?> v<?= APP_VERSION ?>
    </div>
</div>

</body>
</html>