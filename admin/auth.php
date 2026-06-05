<?php
/**
 * 管理员验证辅助函数
 */
require_once __DIR__ . '/../db.php';

session_start();

function requireAdmin() {
    if (!isset($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

function getCurrentAdmin() {
    return $_SESSION['admin_username'] ?? '管理员';
}

// 公共头部
function adminHeader($title = '后台管理') {
    requireAdmin();
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <title><?= htmlspecialchars($title) ?> - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .sidebar { display: flex; flex-direction: column; }
        .sidebar-bottom { margin-top: auto; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 12px; }
    </style>
</head>
<body>
    <!-- 侧栏遮罩 -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- 侧栏 -->
    <aside class="sidebar" id="sidebar">
        <div class="admin-logo">
            📊 管理后台
            <small>v<?= APP_VERSION ?></small>
        </div>
        <a href="/admin/dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>">📊 概览</a>
        <a href="/admin/settings.php" class="<?= basename($_SERVER['PHP_SELF']) === 'settings.php' ? 'active' : '' ?>">⚙️ 月度设置</a>
        <a href="/admin/classes.php" class="<?= basename($_SERVER['PHP_SELF']) === 'classes.php' ? 'active' : '' ?>">🏫 班级管理</a>
        <a href="/admin/statistics.php" class="<?= basename($_SERVER['PHP_SELF']) === 'statistics.php' ? 'active' : '' ?>">📈 统计报表</a>
        <a href="/admin/teacher_lessons.php" class="<?= basename($_SERVER['PHP_SELF']) === 'teacher_lessons.php' ? 'active' : '' ?>">👩‍🏫 教师课时</a>
        <div class="sidebar-bottom">
            <a href="/admin/logout.php" style="color:rgba(255,255,255,0.5);">🚪 退出登录</a>
        </div>
    </aside>

    <!-- 主区域 -->
    <div class="main-area">
        <div class="admin-top-bar">
            <div style="display:flex;align-items:center;gap:10px;">
                <button class="hamburger" onclick="toggleSidebar()">
                    <span></span><span></span><span></span>
                </button>
                <span class="page-title"><?= htmlspecialchars($title) ?></span>
            </div>
            <div style="font-size:13px;color:var(--text-secondary);">
                <?= htmlspecialchars(getCurrentAdmin()) ?>
            </div>
        </div>
        <div class="admin-content">
    <?php
}

function adminFooter() {
    ?>
        </div><!-- .admin-content -->
    </div><!-- .main-area -->
    <script>
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('active');
    }
    </script>
</body>
</html>
    <?php
}