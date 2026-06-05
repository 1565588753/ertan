<?php
require_once __DIR__ . '/config.php';

$step = isset($_GET['step']) ? intval($_GET['step']) : 1;
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = intval($_POST['step']);
}

// 第一步：数据库配置
if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['host'] ?? DB_HOST);
    $port = trim($_POST['port'] ?? DB_PORT);
    $dbname = trim($_POST['dbname'] ?? DB_NAME);
    $user = trim($_POST['user'] ?? DB_USER);
    $pass = $_POST['pass'] ?? DB_PASS;

    try {
        // 先连接MySQL（不指定数据库）
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        // 创建数据库
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$dbname}`");

        // 创建表
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `grades` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(20) NOT NULL COMMENT '年级名称',
                `sort_order` INT NOT NULL DEFAULT 0 COMMENT '排序',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `classes` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `grade_id` INT NOT NULL COMMENT '所属年级',
                `name` VARCHAR(50) NOT NULL COMMENT '班级名称',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`grade_id`) REFERENCES `grades`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `grade_settings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `grade_id` INT NOT NULL,
                `year` INT NOT NULL,
                `month` INT NOT NULL,
                `teaching_days` INT NOT NULL DEFAULT 0 COMMENT '上课节数',
                FOREIGN KEY (`grade_id`) REFERENCES `grades`(`id`) ON DELETE CASCADE,
                UNIQUE KEY `uk_grade_month` (`grade_id`, `year`, `month`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `fee_settings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `year` INT NOT NULL,
                `month` INT NOT NULL,
                `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '每节课单价(元)',
                `cap_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '每人封顶价(元)',
                UNIQUE KEY `uk_month` (`year`, `month`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `attendance` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `class_id` INT NOT NULL,
                `lesson_number` INT NOT NULL COMMENT '第几节课',
                `student_count` INT NOT NULL DEFAULT 0 COMMENT '上课人数',
                `lesson_hours` DECIMAL(10,1) NOT NULL DEFAULT 0.0 COMMENT '课时数',
                `year` INT NOT NULL,
                `month` INT NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE CASCADE,
                UNIQUE KEY `uk_class_lesson` (`class_id`, `lesson_number`, `year`, `month`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `teacher_lessons` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `grade_id` INT NOT NULL,
                `teacher_name` VARCHAR(50) NOT NULL COMMENT '教师姓名',
                `lesson_count` INT NOT NULL DEFAULT 0 COMMENT '上课节数',
                `year` INT NOT NULL,
                `month` INT NOT NULL,
                FOREIGN KEY (`grade_id`) REFERENCES `grades`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `admin_users` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `username` VARCHAR(50) NOT NULL UNIQUE,
                `password_hash` VARCHAR(255) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // 初始数据
        $count = $pdo->query("SELECT COUNT(*) FROM `grades`")->fetchColumn();
        if ($count == 0) {
            $pdo->exec("INSERT INTO `grades` (`name`, `sort_order`) VALUES 
                ('一年级', 1), ('二年级', 2), ('三年级', 3), 
                ('四年级', 4), ('五年级', 5), ('六年级', 6)");
        }

        $adminCount = $pdo->query("SELECT COUNT(*) FROM `admin_users`")->fetchColumn();
        if ($adminCount == 0) {
            $adminPassword = trim($_POST['admin_pass'] ?? 'admin123');
            $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO `admin_users` (`username`, `password_hash`) VALUES (?, ?)");
            $stmt->execute(['admin', $hash]);
        }

        // 写入配置文件
        $configContent = "<?php\n";
        $configContent .= "// 数据库配置\n";
        $configContent .= "define('DB_HOST', '{$host}');\n";
        $configContent .= "define('DB_PORT', '{$port}');\n";
        $configContent .= "define('DB_NAME', '{$dbname}');\n";
        $configContent .= "define('DB_USER', '{$user}');\n";
        $configContent .= "define('DB_PASS', '{$pass}');\n";
        $configContent .= "define('DB_CHARSET', 'utf8mb4');\n";
        $configContent .= "\n";
        $configContent .= "// 应用配置\n";
        $configContent .= "define('APP_NAME', '课后服务预算管理系统');\n";
        $configContent .= "define('APP_VERSION', '1.0.0');\n";
        $configContent .= "define('CURRENT_YEAR', date('Y'));\n";
        $configContent .= "define('CURRENT_MONTH', intval(date('m')));\n";
        $configContent .= "\n";
        $configContent .= "// 年级列表\n";
        $configContent .= "\$GRADES = [\n";
        $configContent .= "    1 => '一年级',\n";
        $configContent .= "    2 => '二年级',\n";
        $configContent .= "    3 => '三年级',\n";
        $configContent .= "    4 => '四年级',\n";
        $configContent .= "    5 => '五年级',\n";
        $configContent .= "    6 => '六年级'\n";
        $configContent .= "];\n";
        $configContent .= "\n";
        $configContent .= "// 错误报告\n";
        $configContent .= "error_reporting(E_ALL);\n";
        $configContent .= "ini_set('display_errors', 0);\n";
        $configContent .= "ini_set('log_errors', 1);\n";

        file_put_contents(__DIR__ . '/config.php', $configContent);

        $success = '安装成功！默认管理员账号：admin，密码：' . htmlspecialchars($adminPassword);
        $step = 3;
    } catch (PDOException $e) {
        $error = '安装失败：' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>安装 - 课后服务预算管理系统</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", "Helvetica Neue", sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .install-box {
            background: #fff;
            border-radius: 20px;
            padding: 40px 35px;
            width: 100%;
            max-width: 520px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 { font-size: 24px; color: #1a237e; text-align: center; margin-bottom: 8px; }
        .subtitle { text-align: center; color: #666; font-size: 14px; margin-bottom: 30px; }
        .form-group { margin-bottom: 18px; }
        label { display: block; font-size: 14px; color: #333; margin-bottom: 6px; font-weight: 500; }
        input, select {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            transition: border-color 0.3s;
            outline: none;
            background: #fafafa;
        }
        input:focus { border-color: #667eea; background: #fff; }
        .btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: #fff;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(102,126,234,0.4); }
        .error { background: #fff5f5; color: #e53e3e; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; border-left: 4px solid #e53e3e; }
        .success { background: #f0fff4; color: #38a169; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; border-left: 4px solid #38a169; }
        .info-box { background: #f0f4ff; padding: 16px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; color: #4a5568; line-height: 1.8; }
        .info-box strong { color: #2d3748; }
        .step-indicator { display: flex; justify-content: center; gap: 10px; margin-bottom: 25px; }
        .step-dot { width: 35px; height: 35px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 600; background: #e0e0e0; color: #999; }
        .step-dot.active { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; }
        .step-dot.done { background: #38a169; color: #fff; }
        .success-box { text-align: center; }
        .success-box .icon { font-size: 64px; margin-bottom: 15px; }
        .success-box h2 { color: #38a169; margin-bottom: 10px; }
        .success-box p { color: #666; margin-bottom: 8px; font-size: 14px; line-height: 1.6; }
        .success-box .btn { margin-top: 20px; }
        .row2 { display: flex; gap: 12px; }
        .row2 .form-group { flex: 1; }
    </style>
</head>
<body>
    <div class="install-box">
        <h1>课后服务预算管理系统</h1>
        <p class="subtitle">系统安装向导</p>

        <?php if ($error): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?= $success ?></div>
        <?php endif; ?>

        <div class="step-indicator">
            <div class="step-dot <?= $step >= 1 ? 'active' : '' ?>">1</div>
            <div class="step-dot <?= $step >= 2 ? 'active' : '' ?>">2</div>
            <div class="step-dot <?= $step >= 3 ? 'done' : '' ?>">✓</div>
        </div>

        <?php if ($step === 3): ?>
            <div class="success-box">
                <div class="icon">🎉</div>
                <h2>安装完成！</h2>
                <p>系统已成功安装并配置完成。</p>
                <p>管理员账号：<strong>admin</strong></p>
                <a href="index.php" class="btn">进入系统</a>
            </div>
        <?php else: ?>
            <div class="info-box">
                <strong>📌 安装说明：</strong><br>
                请确保已创建MySQL数据库，并填写正确的数据库连接信息。<br>
                系统将自动创建所需的数据库表。
            </div>

            <form method="post">
                <input type="hidden" name="step" value="1">

                <div class="form-group">
                    <label>数据库主机</label>
                    <input type="text" name="host" value="localhost" placeholder="默认: localhost">
                </div>

                <div class="row2">
                    <div class="form-group">
                        <label>端口</label>
                        <input type="text" name="port" value="3306" placeholder="3306">
                    </div>
                    <div class="form-group">
                        <label>数据库名</label>
                        <input type="text" name="dbname" value="afterschool_budget" placeholder="数据库名称">
                    </div>
                </div>

                <div class="form-group">
                    <label>数据库用户名</label>
                    <input type="text" name="user" value="root" placeholder="数据库用户名">
                </div>

                <div class="form-group">
                    <label>数据库密码</label>
                    <input type="password" name="pass" value="" placeholder="数据库密码">
                </div>

                <div style="border-top: 1px solid #e0e0e0; margin: 20px 0; padding-top: 20px;">
                    <div class="form-group">
                        <label>设置管理员密码（默认: admin123）</label>
                        <input type="text" name="admin_pass" value="admin123" placeholder="管理员密码">
                    </div>
                </div>

                <button type="submit" class="btn">开始安装</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>