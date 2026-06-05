<?php
require_once __DIR__ . '/../db.php';
session_start();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $db = Database::getInstance();
    $user = $db->fetchOne("SELECT * FROM admin_users WHERE username = ?", [$username]);

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['admin_id'] = $user['id'];
        $_SESSION['admin_username'] = $user['username'];
        header('Location: dashboard.php');
        exit;
    } else {
        $error = '用户名或密码错误';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>管理员登录 - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .login-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: linear-gradient(135deg, #1a3a5c 0%, #2a5a8c 50%, #1a3a5c 100%);
            position: relative;
            overflow: hidden;
        }
        .login-page::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle at 30% 50%, rgba(255,255,255,0.03) 0%, transparent 60%);
        }
        .login-box {
            background: rgba(255,255,255,0.98);
            border-radius: 24px;
            padding: 40px 32px;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 25px 80px rgba(0,0,0,0.3);
            position: relative;
            backdrop-filter: blur(10px);
        }
        .login-box .logo-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin: 0 auto 16px;
        }
        .login-box h1 { font-size: 22px; text-align: center; color: var(--primary); font-weight: 700; }
        .login-box .login-subtitle { text-align: center; color: var(--text-secondary); font-size: 14px; margin-bottom: 28px; }
        .login-box .form-group { margin-bottom: 18px; }
        .login-box label { font-size: 14px; font-weight: 600; color: var(--text); margin-bottom: 6px; display: block; }
        .login-box input {
            width: 100%;
            padding: 13px 16px;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 15px;
            outline: none;
            transition: all 0.3s;
            background: #fafafa;
        }
        .login-box input:focus { border-color: var(--primary-light); box-shadow: 0 0 0 3px rgba(42,90,140,0.1); background: #fff; }
        .login-box .error {
            background: #fff5f5;
            color: #e53e3e;
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #e53e3e;
        }
        .login-box .btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: #fff;
            transition: all 0.3s;
        }
        .login-box .btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(26,58,92,0.3); }
        .login-box .back-link {
            display: block;
            text-align: center;
            margin-top: 16px;
            color: var(--text-secondary);
            font-size: 13px;
            text-decoration: none;
        }
        .login-box .back-link:hover { color: var(--primary); }
    </style>
</head>
<body>
    <div class="login-page">
        <div class="login-box">
            <div class="logo-icon">🔑</div>
            <h1>管理员登录</h1>
            <p class="login-subtitle"><?= APP_NAME ?></p>

            <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <div class="form-group">
                    <label>用户名</label>
                    <input type="text" name="username" placeholder="请输入管理员用户名" required autocomplete="username">
                </div>
                <div class="form-group">
                    <label>密码</label>
                    <input type="password" name="password" placeholder="请输入密码" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn">登 录</button>
            </form>

            <a href="/index.php" class="back-link">← 返回首页</a>
        </div>
    </div>
</body>
</html>