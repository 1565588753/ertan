<?php
session_start();
require_once __DIR__ . '/db.php';
$db = Database::getInstance();

$gradeId = isset($_GET['g']) ? intval($_GET['g']) : 0;
if ($gradeId < 1 || $gradeId > 6) {
    header('Location: index.php');
    exit;
}

// 获取年级信息
$grade = $db->fetchOne("SELECT * FROM grades WHERE id = ?", [$gradeId]);
if (!$grade) {
    header('Location: index.php');
    exit;
}

$year = isset($_GET['year']) ? intval($_GET['year']) : CURRENT_YEAR;
$month = isset($_GET['month']) ? intval($_GET['month']) : CURRENT_MONTH;

// 是否为管理员
$isAdmin = isset($_SESSION['admin_id']);

// 获取管理员设置的年级上课节数
$setting = $db->fetchOne("SELECT * FROM grade_settings WHERE grade_id = ? AND year = ? AND month = ?", 
    [$gradeId, $year, $month]);
$teachingDays = intval($setting['teaching_days'] ?? 0);
// 默认显示10节
if ($teachingDays < 1) $teachingDays = 10;

// 获取全校统一收费标准（仅管理员可见）
$feeSetting = $db->fetchOne("SELECT * FROM fee_settings WHERE year = ? AND month = ?", [$year, $month]);
$unitPrice = floatval($feeSetting['unit_price'] ?? 0);
$capPrice = floatval($feeSetting['cap_price'] ?? 0);

// 获取班级列表
$classes = $db->fetchAll("SELECT * FROM classes WHERE grade_id = ? ORDER BY id ASC", [$gradeId]);

// 获取已保存的出勤数据
$attendanceData = [];
if (!empty($classes)) {
    $classIds = array_column($classes, 'id');
    $placeholders = implode(',', array_fill(0, count($classIds), '?'));
    $records = $db->fetchAll(
        "SELECT * FROM attendance WHERE class_id IN ({$placeholders}) AND year = ? AND month = ? ORDER BY class_id, lesson_number",
        array_merge($classIds, [$year, $month])
    );
    foreach ($records as $r) {
        $attendanceData[$r['class_id']][$r['lesson_number']] = $r;
    }
}

// 处理提交
$submitSuccess = false;
$submitError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->getPdo()->beginTransaction();

        // 保存出勤数据
        if (isset($_POST['attendance']) && is_array($_POST['attendance'])) {
            foreach ($_POST['attendance'] as $classId => $lessons) {
                $classId = intval($classId);
                foreach ($lessons as $lessonNum => $studentCount) {
                    $lessonNum = intval($lessonNum);
                    $studentCount = intval($studentCount);
                    if ($studentCount < 0) $studentCount = 0;
                    $lessonHours = $studentCount; // 课时数 = 人数 × 1节

                    // UPSERT
                    $existing = $db->fetchOne(
                        "SELECT id FROM attendance WHERE class_id = ? AND lesson_number = ? AND year = ? AND month = ?",
                        [$classId, $lessonNum, $year, $month]
                    );
                    if ($existing) {
                        $db->execute(
                            "UPDATE attendance SET student_count = ?, lesson_hours = ? WHERE id = ?",
                            [$studentCount, $lessonHours, $existing['id']]
                        );
                    } else {
                        $db->execute(
                            "INSERT INTO attendance (class_id, lesson_number, student_count, lesson_hours, year, month) VALUES (?, ?, ?, ?, ?, ?)",
                            [$classId, $lessonNum, $studentCount, $lessonHours, $year, $month]
                        );
                    }
                }
            }
        }

        $db->getPdo()->commit();
        $submitSuccess = true;

        // 重新加载数据
        $classes = $db->fetchAll("SELECT * FROM classes WHERE grade_id = ? ORDER BY id ASC", [$gradeId]);
        $attendanceData = [];
        if (!empty($classes)) {
            $classIds = array_column($classes, 'id');
            $placeholders = implode(',', array_fill(0, count($classIds), '?'));
            $records = $db->fetchAll(
                "SELECT * FROM attendance WHERE class_id IN ({$placeholders}) AND year = ? AND month = ? ORDER BY class_id, lesson_number",
                array_merge($classIds, [$year, $month])
            );
            foreach ($records as $r) {
                $attendanceData[$r['class_id']][$r['lesson_number']] = $r;
            }
        }
    } catch (Exception $e) {
        $db->getPdo()->rollBack();
        $submitError = '保存失败：' . $e->getMessage();
    }
}

// 月份导航
$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

// 等级样式
$gradeColors = ['#ef4444','#f59e0b','#10b981','#3b82f6','#8b5cf6','#ec4899'];
$gradeColor = $gradeColors[$gradeId - 1] ?? '#3b82f6';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <title><?= htmlspecialchars($grade['name']) ?> - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .grade-hero {
            background: linear-gradient(135deg, <?= $gradeColor ?>, <?= $gradeColor ?>dd);
            color: #fff;
            padding: 24px 16px;
            text-align: center;
            position: relative;
        }
        .grade-hero .back-link {
            position: absolute;
            left: 16px;
            top: 24px;
            color: #fff;
            font-size: 14px;
            text-decoration: none;
            opacity: 0.8;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .grade-hero .back-link:hover { opacity: 1; }
        .grade-hero h1 { font-size: 24px; font-weight: 800; }
        .grade-hero p { font-size: 13px; opacity: 0.85; margin-top: 4px; }
        .grade-hero .price-info {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 12px;
            font-size: 13px;
        }
        .grade-hero .price-info span {
            background: rgba(255,255,255,0.2);
            padding: 3px 14px;
            border-radius: 12px;
        }
        .lesson-count-info {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 16px;
            background: var(--bg-card);
            border-radius: var(--radius);
            margin-bottom: 12px;
            box-shadow: var(--shadow-sm);
        }
        .lesson-count-info label {
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
        }
        .lesson-count-info .lesson-badge {
            padding: 6px 16px;
            background: var(--primary);
            color: #fff;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
        }
        .save-bar {
            position: sticky;
            bottom: 0;
            left: 0;
            right: 0;
            background: #fff;
            padding: 12px 16px;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.1);
            z-index: 50;
            display: flex;
            gap: 10px;
        }
        .save-bar .btn { flex: 1; }
        .toast-success { background: var(--success); }
        .toast-error { background: var(--danger); }
    </style>
</head>
<body>

<div class="grade-hero">
    <a href="index.php" class="back-link">← 返回</a>
    <h1><?= htmlspecialchars($grade['name']) ?></h1>
    <p><?= $year ?>年<?= $month ?>月 课后服务数据填写</p>
    <?php if ($isAdmin): ?>
    <div class="price-info">
        <span>单价：<?= number_format($unitPrice, 2) ?>元/节</span>
        <span>封顶：<?= number_format($capPrice, 2) ?>元/人</span>
        <span>节数：<?= $teachingDays ?>节</span>
    </div>
    <?php endif; ?>
</div>

<div class="container page-content">
    <!-- 月份切换 -->
    <div class="month-selector">
        <a href="?g=<?= $gradeId ?>&year=<?= $prevYear ?>&month=<?= $prevMonth ?>" class="month-nav">◀</a>
        <div class="month-display"><?= $year ?>年 <?= $month ?>月</div>
        <?php if ($nextYear <= CURRENT_YEAR && $nextMonth <= CURRENT_MONTH): ?>
        <a href="?g=<?= $gradeId ?>&year=<?= $nextYear ?>&month=<?= $nextMonth ?>" class="month-nav">▶</a>
        <?php else: ?>
        <span class="month-nav" style="opacity:0.3">▶</span>
        <?php endif; ?>
    </div>

    <?php if ($submitSuccess): ?>
    <div class="toast toast-success show" id="successToast" style="position:fixed;top:20px;right:20px;z-index:9999;padding:14px 20px;border-radius:12px;color:#fff;font-size:14px;box-shadow:0 10px 30px rgba(0,0,0,0.15);">
        ✅ 数据保存成功！
    </div>
    <script>
        setTimeout(() => {
            var t = document.getElementById('successToast');
            if (t) { t.style.transition = 'opacity 0.5s'; t.style.opacity = '0'; setTimeout(() => t.remove(), 500); }
        }, 2000);
    </script>
    <?php endif; ?>

    <?php if ($submitError): ?>
    <div class="toast toast-error show" style="position:fixed;top:20px;right:20px;z-index:9999;padding:14px 20px;border-radius:12px;color:#fff;font-size:14px;box-shadow:0 10px 30px rgba(0,0,0,0.15);">
        ❌ <?= htmlspecialchars($submitError) ?>
    </div>
    <?php endif; ?>

    <?php if (empty($classes)): ?>
    <div class="empty-state">
        <div class="empty-icon">📋</div>
        <p>该年级暂无班级</p>
        <p style="font-size:13px;margin-top:8px;">请联系管理员添加班级</p>
    </div>
    <?php else: ?>

    <!-- 课节数提示 -->
    <div class="lesson-count-info">
        <label>📚 每班课节数：</label>
        <span class="lesson-badge"><?= $teachingDays ?> 节</span>
    </div>

    <form method="post" id="mainForm">
        <!-- 班级列表 -->
        <?php foreach ($classes as $class): ?>
        <div class="attendance-card">
            <div class="class-header">
                <span class="class-name"><?= htmlspecialchars($grade['name']) ?> <?= htmlspecialchars($class['name']) ?></span>
                <span class="badge badge-primary" id="total_<?= $class['id'] ?>">合计：0 人</span>
            </div>
            <div class="class-body" id="lessons_<?= $class['id'] ?>">
                <?php for ($l = 1; $l <= $teachingDays; $l++) {
                    $saved = isset($attendanceData[$class['id']][$l]) ? $attendanceData[$class['id']][$l] : null;
                    $count = $saved ? intval($saved['student_count']) : 0;
                    $hours = $saved ? floatval($saved['lesson_hours']) : 0;
                ?>
                <div class="lesson-row">
                    <span class="lesson-label">上<?= $l ?>节</span>
                    <input type="number" class="lesson-input" 
                           name="attendance[<?= $class['id'] ?>][<?= $l ?>]" 
                           value="<?= $count ?>" min="0" max="999" 
                           placeholder="人数" 
                           oninput="updateTotal(<?= $class['id'] ?>)" />
                    <span>人</span>
                </div>
                <?php } ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- 底部保存栏 -->
        <div class="save-bar">
            <button type="submit" class="btn btn-primary">💾 保存数据</button>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
    // 更新合计
    function updateTotal(classId) {
        var inputs = document.querySelectorAll('input[name^="attendance[' + classId + ']"]');
        var total = 0;
        inputs.forEach(function(inp) {
            total += parseInt(inp.value) || 0;
        });
        var badge = document.getElementById('total_' + classId);
        if (badge) badge.textContent = '合计：' + total + ' 人';
    }

    // 初始化合计
    document.addEventListener('DOMContentLoaded', function() {
        var classIds = [];
        document.querySelectorAll('input[name^="attendance["]').forEach(function(inp) {
            var match = inp.name.match(/attendance\[(\d+)\]/);
            if (match) {
                var cid = parseInt(match[1]);
                if (!classIds.includes(cid)) classIds.push(cid);
            }
        });
        classIds.forEach(function(cid) { updateTotal(cid); });
    });
</script>

</body>
</html>