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
$mode = isset($_GET['mode']) ? $_GET['mode'] : '';

// 是否为管理员
$isAdmin = isset($_SESSION['admin_id']);

// 获取管理员设置的年级上课节数
$setting = $db->fetchOne("SELECT * FROM grade_settings WHERE grade_id = ? AND year = ? AND month = ?", 
    [$gradeId, $year, $month]);
$teachingDays = intval($setting['teaching_days'] ?? 0);
if ($teachingDays < 1) $teachingDays = 10;

// 等级样式
$gradeColors = ['#ef4444','#f59e0b','#10b981','#3b82f6','#8b5cf6','#ec4899'];
$gradeColor = $gradeColors[$gradeId - 1] ?? '#3b82f6';

// 月份导航
$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

// ===== MODE: ATTENDANCE (收费统计 - 班主任填出勤人数) =====
if ($mode === 'attendance'):

// 获取班级列表
$classes = $db->fetchAll("SELECT * FROM classes WHERE grade_id = ? ORDER BY id ASC", [$gradeId]);

// 获取选中的班级ID
$selectedClassId = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

// 获取选中班级信息
$selectedClass = null;
if ($selectedClassId > 0) {
    $selectedClass = $db->fetchOne("SELECT * FROM classes WHERE id = ? AND grade_id = ?", [$selectedClassId, $gradeId]);
}

// 获取选中班级的出勤数据
$attendanceData = [];
if ($selectedClass) {
    $records = $db->fetchAll(
        "SELECT * FROM attendance WHERE class_id = ? AND year = ? AND month = ? ORDER BY lesson_number",
        [$selectedClassId, $year, $month]
    );
    foreach ($records as $r) {
        $attendanceData[$r['lesson_number']] = $r;
    }
}

// 获取所有班级的填写状态（用于班级选择页显示）
$classFillStatus = [];
foreach ($classes as $c) {
    $check = $db->fetchOne("SELECT id FROM attendance WHERE class_id = ? AND year = ? AND month = ? LIMIT 1", [$c['id'], $year, $month]);
    $classFillStatus[$c['id']] = !empty($check);
}

// 处理提交
$submitSuccess = false;
$submitError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedClassId > 0 && $selectedClass) {
    try {
        $db->getPdo()->beginTransaction();

        if (isset($_POST['attendance']) && is_array($_POST['attendance'])) {
            foreach ($_POST['attendance'] as $lessonNum => $studentCount) {
                $lessonNum = intval($lessonNum);
                $studentCount = intval($studentCount);
                if ($studentCount < 0) $studentCount = 0;
                $lessonHours = $studentCount;

                $existing = $db->fetchOne(
                    "SELECT id FROM attendance WHERE class_id = ? AND lesson_number = ? AND year = ? AND month = ?",
                    [$selectedClassId, $lessonNum, $year, $month]
                );
                if ($existing) {
                    $db->execute(
                        "UPDATE attendance SET student_count = ?, lesson_hours = ? WHERE id = ?",
                        [$studentCount, $lessonHours, $existing['id']]
                    );
                } else {
                    $db->execute(
                        "INSERT INTO attendance (class_id, lesson_number, student_count, lesson_hours, year, month) VALUES (?, ?, ?, ?, ?, ?)",
                        [$selectedClassId, $lessonNum, $studentCount, $lessonHours, $year, $month]
                    );
                }
            }
        }

        $db->getPdo()->commit();
        $submitSuccess = true;
    } catch (Exception $e) {
        $db->getPdo()->rollBack();
        $submitError = '保存失败：' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <title>收费统计 - <?= htmlspecialchars($grade['name']) ?> - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .grade-hero { background: linear-gradient(135deg, <?= $gradeColor ?>, <?= $gradeColor ?>dd); color: #fff; padding: 24px 16px; text-align: center; position: relative; }
        .grade-hero .back-link { position: absolute; left: 16px; top: 24px; color: #fff; font-size: 14px; text-decoration: none; opacity: 0.8; display: flex; align-items: center; gap: 4px; }
        .grade-hero .back-link:hover { opacity: 1; }
        .grade-hero h1 { font-size: 24px; font-weight: 800; }
        .grade-hero p { font-size: 13px; opacity: 0.85; margin-top: 4px; }
        .grade-hero .price-info { display: flex; justify-content: center; gap: 20px; margin-top: 12px; font-size: 13px; }
        .grade-hero .price-info span { background: rgba(255,255,255,0.2); padding: 3px 14px; border-radius: 12px; }
        .lesson-count-info { display: flex; align-items: center; gap: 8px; padding: 12px 16px; background: var(--bg-card); border-radius: var(--radius); margin-bottom: 12px; box-shadow: var(--shadow-sm); }
        .lesson-count-info label { font-size: 14px; font-weight: 600; color: var(--text); }
        .lesson-count-info .lesson-badge { padding: 6px 16px; background: var(--primary); color: #fff; border-radius: 8px; font-size: 15px; font-weight: 600; }
        .save-bar { position: sticky; bottom: 0; left: 0; right: 0; background: #fff; padding: 12px 16px; box-shadow: 0 -4px 20px rgba(0,0,0,0.1); z-index: 50; display: flex; gap: 10px; }
        .save-bar .btn { flex: 1; }
        .toast-success { background: var(--success); }
        .toast-error { background: var(--danger); }
        .class-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; margin-top: 16px; }
        .class-card {
            background: #fff; border-radius: 16px; padding: 20px 12px; text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06); transition: all 0.2s; cursor: pointer;
            border: 2px solid transparent; text-decoration: none; display: block; color: var(--text);
        }
        .class-card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,0.1); border-color: <?= $gradeColor ?>; }
        .class-card .class-icon { font-size: 32px; margin-bottom: 8px; }
        .class-card .class-title { font-size: 16px; font-weight: 700; }
        .class-card .class-status { font-size: 12px; color: var(--text-secondary); margin-top: 6px; }
        .class-card .class-status.has-data { color: var(--success); font-weight: 600; }
    </style>
</head>
<body>

<div class="grade-hero">
    <?php if ($selectedClass): ?>
    <a href="?g=<?= $gradeId ?>&mode=attendance&year=<?= $year ?>&month=<?= $month ?>" class="back-link">← 返回班级列表</a>
    <?php else: ?>
    <a href="?g=<?= $gradeId ?>&year=<?= $year ?>&month=<?= $month ?>" class="back-link">← 返回</a>
    <?php endif; ?>
    <h1>📋 收费统计</h1>
    <p><?= htmlspecialchars($grade['name']) ?> - <?= $year ?>年<?= $month ?>月</p>
    <?php if ($isAdmin): ?>
    <div class="price-info">
        <span>节数：<?= $teachingDays ?>节</span>
    </div>
    <?php endif; ?>
</div>

<div class="container page-content">
    <!-- 月份切换 -->
    <div class="month-selector">
        <?php $classParam = $selectedClass ? '&class_id='.$selectedClassId : ''; ?>
        <a href="?g=<?= $gradeId ?>&mode=attendance<?= $classParam ?>&year=<?= $prevYear ?>&month=<?= $prevMonth ?>" class="month-nav">◀</a>
        <div class="month-display"><?= $year ?>年 <?= $month ?>月</div>
        <?php if ($nextYear <= CURRENT_YEAR && $nextMonth <= CURRENT_MONTH): ?>
        <a href="?g=<?= $gradeId ?>&mode=attendance<?= $classParam ?>&year=<?= $nextYear ?>&month=<?= $nextMonth ?>" class="month-nav">▶</a>
        <?php else: ?>
        <span class="month-nav" style="opacity:0.3">▶</span>
        <?php endif; ?>
    </div>

    <?php if ($submitSuccess): ?>
    <div class="toast toast-success show" style="position:fixed;top:20px;right:20px;z-index:9999;padding:14px 20px;border-radius:12px;color:#fff;font-size:14px;box-shadow:0 10px 30px rgba(0,0,0,0.15);">✅ 数据保存成功！</div>
    <script>setTimeout(function(){var t=document.querySelector('.toast');if(t){t.style.transition='opacity 0.5s';t.style.opacity='0';setTimeout(function(){t.remove()},500);}},2000);</script>
    <?php endif; ?>
    <?php if ($submitError): ?>
    <div class="toast toast-error show" style="position:fixed;top:20px;right:20px;z-index:9999;padding:14px 20px;border-radius:12px;color:#fff;font-size:14px;box-shadow:0 10px 30px rgba(0,0,0,0.15);">❌ <?= htmlspecialchars($submitError) ?></div>
    <script>setTimeout(function(){var t=document.querySelector('.toast');if(t){t.style.transition='opacity 0.5s';t.style.opacity='0';setTimeout(function(){t.remove()},500);}},2000);</script>
    <?php endif; ?>

    <?php if (empty($classes)): ?>
    <div class="empty-state">
        <div class="empty-icon">📋</div>
        <p>该年级暂无班级</p>
        <p style="font-size:13px;margin-top:8px;">请联系管理员添加班级</p>
    </div>
    <?php elseif (!$selectedClass): ?>
    <!-- 班级选择页面 -->
    <div class="lesson-count-info">
        <label>📚 请选择要填写的班级</label>
    </div>
    <div class="class-grid">
        <?php foreach ($classes as $class): 
            $hasData = !empty($classFillStatus[$class['id']]);
        ?>
        <a href="?g=<?= $gradeId ?>&mode=attendance&year=<?= $year ?>&month=<?= $month ?>&class_id=<?= $class['id'] ?>" class="class-card">
            <div class="class-icon">🏫</div>
            <div class="class-title"><?= htmlspecialchars($class['name']) ?></div>
            <div class="class-status <?= $hasData ? 'has-data' : '' ?>"><?= $hasData ? '✓ 已填写' : '未填写' ?></div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <!-- 班级出勤填写页面 -->
    <div class="lesson-count-info">
        <label>📚 班级：</label>
        <span class="lesson-badge"><?= htmlspecialchars($grade['name']) ?> <?= htmlspecialchars($selectedClass['name']) ?></span>
        <span style="margin-left:auto;font-size:14px;color:var(--text-secondary);">共 <?= $teachingDays ?> 节</span>
    </div>

    <form method="post">
        <div class="attendance-card">
            <div class="class-header">
                <span class="class-name"><?= htmlspecialchars($grade['name']) ?> <?= htmlspecialchars($selectedClass['name']) ?></span>
                <span class="badge badge-primary" id="total_<?= $selectedClass['id'] ?>">合计：0 人</span>
            </div>
            <div class="class-body">
                <?php for ($l = 1; $l <= $teachingDays; $l++) {
                    $saved = isset($attendanceData[$l]) ? $attendanceData[$l] : null;
                    $count = $saved ? intval($saved['student_count']) : 0;
                ?>
                <div class="lesson-row">
                    <span class="lesson-label">上<?= $l ?>节</span>
                    <input type="number" class="lesson-input" 
                           name="attendance[<?= $l ?>]" 
                           value="<?= $count ?>" min="0" max="999" 
                           placeholder="人数" 
                           oninput="updateTotal(<?= $selectedClass['id'] ?>)" />
                    <span>人</span>
                </div>
                <?php } ?>
            </div>
        </div>

        <div class="save-bar">
            <button type="submit" class="btn btn-primary">💾 保存数据</button>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
function updateTotal(classId) {
    var inputs = document.querySelectorAll('input[name^="attendance["]');
    var total = 0;
    inputs.forEach(function(inp) { total += parseInt(inp.value) || 0; });
    var badge = document.getElementById('total_' + classId);
    if (badge) badge.textContent = '合计：' + total + ' 人';
}
document.addEventListener('DOMContentLoaded', function() {
    var inputs = document.querySelectorAll('input[name^="attendance["]');
    if (inputs.length > 0) {
        updateTotal(<?= $selectedClassId ?>);
    }
});
</script>

</body>
</html>

<?php
// ===== MODE: DISTRIBUTION (发放统计 - 年级干事填年级总课时) =====
elseif ($mode === 'distribution'):

// 获取已保存的年级总课时
$teacherTotalHours = floatval($setting['teacher_total_hours'] ?? 0);

$submitSuccess = false;
$submitError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedHours = floatval($_POST['teacher_total_hours'] ?? 0);
    if ($postedHours < 0) $postedHours = 0;

    try {
        if ($setting) {
            $db->execute(
                "UPDATE grade_settings SET teacher_total_hours = ? WHERE id = ?",
                [$postedHours, $setting['id']]
            );
        } else {
            $db->execute(
                "INSERT INTO grade_settings (grade_id, year, month, teaching_days, teacher_total_hours) VALUES (?, ?, ?, 0, ?)",
                [$gradeId, $year, $month, $postedHours]
            );
        }
        $submitSuccess = true;
    } catch (Exception $e) {
        $submitError = '保存失败：' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <title>发放统计 - <?= htmlspecialchars($grade['name']) ?> - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .grade-hero { background: linear-gradient(135deg, <?= $gradeColor ?>, <?= $gradeColor ?>dd); color: #fff; padding: 24px 16px; text-align: center; position: relative; }
        .grade-hero .back-link { position: absolute; left: 16px; top: 24px; color: #fff; font-size: 14px; text-decoration: none; opacity: 0.8; display: flex; align-items: center; gap: 4px; }
        .grade-hero .back-link:hover { opacity: 1; }
        .grade-hero h1 { font-size: 24px; font-weight: 800; }
        .grade-hero p { font-size: 13px; opacity: 0.85; margin-top: 4px; }
        .save-bar { position: sticky; bottom: 0; left: 0; right: 0; background: #fff; padding: 12px 16px; box-shadow: 0 -4px 20px rgba(0,0,0,0.1); z-index: 50; display: flex; gap: 10px; }
        .save-bar .btn { flex: 1; }
        .toast-success { background: var(--success); }
        .toast-error { background: var(--danger); }
        .total-input-card { background: #fff; border-radius: 16px; padding: 32px 24px; box-shadow: 0 2px 16px rgba(0,0,0,0.06); text-align: center; max-width: 400px; margin: 20px auto; }
        .total-input-card .label { font-size: 15px; color: var(--text-secondary); margin-bottom: 12px; }
        .total-input-card .hint { font-size: 13px; color: #f59e0b; background: #fffbeb; border-radius: 8px; padding: 10px 16px; margin-top: 16px; border-left: 3px solid #f59e0b; text-align: left; }
        .total-input { width: 200px; text-align: center; font-size: 32px; font-weight: 700; padding: 12px; border: 3px solid #e0e0e0; border-radius: 12px; color: var(--text); }
        .total-input:focus { border-color: var(--primary); outline: none; }
    </style>
</head>
<body>

<div class="grade-hero">
    <a href="?g=<?= $gradeId ?>&year=<?= $year ?>&month=<?= $month ?>" class="back-link">← 返回</a>
    <h1>💰 发放统计</h1>
    <p><?= htmlspecialchars($grade['name']) ?> - <?= $year ?>年<?= $month ?>月</p>
</div>

<div class="container page-content">
    <div class="month-selector">
        <a href="?g=<?= $gradeId ?>&mode=distribution&year=<?= $prevYear ?>&month=<?= $prevMonth ?>" class="month-nav">◀</a>
        <div class="month-display"><?= $year ?>年 <?= $month ?>月</div>
        <?php if ($nextYear <= CURRENT_YEAR && $nextMonth <= CURRENT_MONTH): ?>
        <a href="?g=<?= $gradeId ?>&mode=distribution&year=<?= $nextYear ?>&month=<?= $nextMonth ?>" class="month-nav">▶</a>
        <?php else: ?>
        <span class="month-nav" style="opacity:0.3">▶</span>
        <?php endif; ?>
    </div>

    <?php if ($submitSuccess): ?>
    <div class="toast toast-success show" style="position:fixed;top:20px;right:20px;z-index:9999;padding:14px 20px;border-radius:12px;color:#fff;font-size:14px;box-shadow:0 10px 30px rgba(0,0,0,0.15);">✅ 数据保存成功！</div>
    <script>setTimeout(function(){var t=document.querySelector('.toast');if(t){t.style.transition='opacity 0.5s';t.style.opacity='0';setTimeout(function(){t.remove()},500);}},2000);</script>
    <?php endif; ?>
    <?php if ($submitError): ?>
    <div class="toast toast-error show" style="position:fixed;top:20px;right:20px;z-index:9999;padding:14px 20px;border-radius:12px;color:#fff;font-size:14px;box-shadow:0 10px 30px rgba(0,0,0,0.15);">❌ <?= htmlspecialchars($submitError) ?></div>
    <script>setTimeout(function(){var t=document.querySelector('.toast');if(t){t.style.transition='opacity 0.5s';t.style.opacity='0';setTimeout(function(){t.remove()},500);}},2000);</script>
    <?php endif; ?>

    <form method="post">
        <div class="total-input-card">
            <div class="label">📚 本月 <?= htmlspecialchars($grade['name']) ?> 教师总课时数</div>
            <input type="number" name="teacher_total_hours" class="total-input"
                   value="<?= $teacherTotalHours ?>" min="0" step="0.5" placeholder="0">
            <div style="font-size:14px;color:var(--text-secondary);margin-top:8px;">课时</div>
            <div class="hint">
                📌 请填写本年级本月所有上课教师的总课时数，<br><strong>与上交纸质版保持一致</strong>
            </div>
        </div>

        <div class="save-bar" style="max-width:400px;margin:0 auto;">
            <button type="submit" class="btn btn-primary">💾 保存数据</button>
        </div>
    </form>
</div>

</body>
</html>

<?php
// ===== MODE: DEFAULT (年级入口页面) =====
else:

// 获取该年级本月已有数据概况
$classes = $db->fetchAll("SELECT * FROM classes WHERE grade_id = ? ORDER BY id ASC", [$gradeId]);

// 收费统计概况（出勤人数）
$totalAttendanceStudents = 0;
if (!empty($classes)) {
    $classIds = array_column($classes, 'id');
    $ids = implode(',', $classIds);
    $att = $db->fetchAll("SELECT SUM(student_count) as sc FROM attendance WHERE class_id IN ({$ids}) AND year = ? AND month = ?", [$year, $month]);
    $totalAttendanceStudents = intval($att[0]['sc'] ?? 0);
}

// 发放统计概况（教师课时数）
$totalTeacherHours = floatval($setting['teacher_total_hours'] ?? 0);

// 获取收费方案用于显示
$feePlans = $db->fetchAll(
    "SELECT * FROM fee_plans WHERE year = ? AND month = ? ORDER BY sort_order ASC, id ASC",
    [$year, $month]
);

// 计算特殊人员支出
$specialStaffList = $isAdmin ? $db->fetchAll(
    "SELECT * FROM special_staff WHERE year = ? AND month = ?",
    [$year, $month]
) : [];
$totalSpecialStaffExpenditure = 0;
foreach ($specialStaffList as $ss) {
    $hours = floatval($ss['total_hours'] ?? 0);
    $price = floatval($ss['unit_price'] ?? 0);
    $totalSpecialStaffExpenditure += $hours * $price;
}
// 取第一个方案的教师课时单价作为参考
$teacherPayRate = 0;
if (!empty($feePlans)) {
    $teacherPayRate = floatval($feePlans[0]['teacher_pay_rate'] ?? 0);
}
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
            color: #fff; padding: 24px 16px; text-align: center; position: relative;
        }
        .grade-hero .back-link { position: absolute; left: 16px; top: 24px; color: #fff; font-size: 14px; text-decoration: none; opacity: 0.8; }
        .grade-hero h1 { font-size: 24px; font-weight: 800; }
        .grade-hero p { font-size: 13px; opacity: 0.85; margin-top: 4px; }
        .entry-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 20px; }
        .entry-card {
            background: #fff; border-radius: 20px; padding: 28px 16px; text-align: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08); transition: all 0.3s; cursor: pointer;
            border: 2px solid transparent; text-decoration: none; display: block;
        }
        .entry-card:hover { transform: translateY(-4px); box-shadow: 0 8px 30px rgba(0,0,0,0.12); }
        .entry-card .icon { font-size: 48px; margin-bottom: 12px; }
        .entry-card .title { font-size: 18px; font-weight: 700; color: var(--text); margin-bottom: 6px; }
        .entry-card .desc { font-size: 13px; color: var(--text-secondary); line-height: 1.5; }
        .entry-card .stats { font-size: 14px; color: var(--primary); font-weight: 600; margin-top: 10px; }
        .entry-card.attendance { border-color: #3b82f6; background: #f0f7ff; }
        .entry-card.distribution { border-color: #10b981; background: #f0fdf4; }
        .summary-box {
            background: #fff; border-radius: 16px; padding: 16px; margin-top: 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .summary-box h3 { font-size: 14px; color: var(--text-secondary); margin-bottom: 8px; }
        .summary-table { width: 100%; font-size: 13px; }
        .summary-table td { padding: 4px 0; }
        .summary-table td:last-child { text-align: right; font-weight: 600; }
        .summary-table .plan-name { color: var(--text-secondary); }
        .summary-table .plan-value { color: var(--primary); }
    </style>
</head>
<body>

<div class="grade-hero">
    <a href="index.php" class="back-link">← 返回首页</a>
    <h1><?= htmlspecialchars($grade['name']) ?></h1>
    <p><?= $year ?>年<?= $month ?>月</p>
</div>

<div class="container page-content">
    <div class="month-selector">
        <a href="?g=<?= $gradeId ?>&year=<?= $prevYear ?>&month=<?= $prevMonth ?>" class="month-nav">◀</a>
        <div class="month-display"><?= $year ?>年 <?= $month ?>月</div>
        <?php if ($nextYear <= CURRENT_YEAR && $nextMonth <= CURRENT_MONTH): ?>
        <a href="?g=<?= $gradeId ?>&year=<?= $nextYear ?>&month=<?= $nextMonth ?>" class="month-nav">▶</a>
        <?php else: ?>
        <span class="month-nav" style="opacity:0.3">▶</span>
        <?php endif; ?>
    </div>

    <!-- 处理概览（仅管理员可见） -->
    <?php if ($isAdmin && !empty($feePlans)): ?>
    <div class="summary-box">
        <h3>💰 收费方案参考</h3>
        <table class="summary-table">
            <?php
            $totalStudents = $totalAttendanceStudents;
            foreach ($feePlans as $fp):
                $unitPrice = floatval($fp['unit_price']);
                $capPrice = floatval($fp['cap_price']);
                $estimatedFee = $totalStudents > 0 ? min($totalStudents * $unitPrice, $totalStudents * $capPrice) : 0;
                $rawIncome = $totalStudents * $unitPrice;
            ?>
            <tr>
                <td class="plan-name"><?= htmlspecialchars($fp['plan_name']) ?></td>
                <td class="plan-value">¥<?= number_format($unitPrice, 2) ?>/节</td>
                <td class="plan-value">封顶 ¥<?= number_format($capPrice, 0) ?></td>
                <td class="plan-value">估算 ¥<?= number_format($estimatedFee, 0) ?></td>
            </tr>
            <tr>
                <td colspan="4" style="padding:0;">
                    <button class="calc-toggle" onclick="toggleCalc(this)">▶ 计算明细</button>
                    <div class="calc-detail">
                        <div class="row">
                            <span class="label">出勤总人次</span>
                            <span class="value"><?= number_format($totalStudents) ?></span>
                        </div>
                        <div class="row">
                            <span class="label">× 学生单价</span>
                            <span class="value">¥<?= number_format($unitPrice, 2) ?></span>
                        </div>
                        <div class="row">
                            <span class="label">= 原始收入</span>
                            <span class="value">¥<?= number_format($rawIncome, 0) ?></span>
                        </div>
                        <div class="row">
                            <span class="label">封顶约束</span>
                            <span class="value">每人 ≤ ¥<?= number_format($capPrice, 0) ?></span>
                        </div>
                        <div class="row total income">
                            <span class="label">最终收入估算</span>
                            <span class="value">¥<?= number_format($estimatedFee, 0) ?></span>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <!-- 教师发放总课时（仅管理员可见） -->
    <?php if ($isAdmin): ?>
    <?php
    $teacherExpenditure = $totalTeacherHours * $teacherPayRate;
    $totalExpenditure = $teacherExpenditure + $totalSpecialStaffExpenditure;
    ?>
    <div class="summary-box">
        <h3>👩‍🏫 教师发放统计</h3>
        <table class="summary-table">
            <tr>
                <td>本月教师总课时</td>
                <td class="plan-value"><?= number_format($totalTeacherHours, 1) ?> 课时</td>
            </tr>
            <tr>
                <td>出勤总人次</td>
                <td class="plan-value"><?= number_format($totalAttendanceStudents) ?> 人次</td>
            </tr>
            <?php if ($teacherPayRate > 0 || $totalSpecialStaffExpenditure > 0): ?>
            <tr>
                <td colspan="2" style="padding:0;">
                    <button class="calc-toggle" onclick="toggleCalc(this)">▶ 支出明细</button>
                    <div class="calc-detail">
                        <?php if ($teacherPayRate > 0): ?>
                        <div class="row">
                            <span class="label">教师总课时</span>
                            <span class="value"><?= number_format($totalTeacherHours, 1) ?> 课时</span>
                        </div>
                        <div class="row">
                            <span class="label">× 教师课时单价</span>
                            <span class="value">¥<?= number_format($teacherPayRate, 2) ?></span>
                        </div>
                        <div class="row">
                            <span class="label">= 教师支出</span>
                            <span class="value">¥<?= number_format($teacherExpenditure, 0) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($specialStaffList)): ?>
                        <div style="margin-top:6px;font-weight:600;color:var(--text-secondary);font-size:12px;">特殊人员支出</div>
                        <?php foreach ($specialStaffList as $ss):
                            $hours = floatval($ss['total_hours'] ?? 0);
                            $price = floatval($ss['unit_price'] ?? 0);
                            $subtotal = $hours * $price;
                        ?>
                        <div class="row">
                            <span class="label"><?= htmlspecialchars($ss['staff_type']) ?></span>
                            <span class="value"><?= number_format($hours, 1) ?>h × ¥<?= number_format($price, 2) ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="row total">
                            <span class="label">总支出</span>
                            <span class="value">¥<?= number_format($totalExpenditure, 0) ?></span>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
        </table>
    </div>
    <?php endif; ?>

    <div class="entry-grid">
        <a href="?g=<?= $gradeId ?>&mode=attendance&year=<?= $year ?>&month=<?= $month ?>" class="entry-card attendance">
            <div class="icon">📋</div>
            <div class="title">收费统计</div>
            <div class="desc">班主任填写<br>各班每节课出勤人数</div>
            <?php if ($totalAttendanceStudents > 0): ?>
            <div class="stats">已填写 <?= number_format($totalAttendanceStudents) ?> 人次</div>
            <?php endif; ?>
        </a>
        <a href="?g=<?= $gradeId ?>&mode=distribution&year=<?= $year ?>&month=<?= $month ?>" class="entry-card distribution">
            <div class="icon">💰</div>
            <div class="title">发放统计</div>
            <div class="desc">课时干事填写<br>本月上课教师课时数</div>
            <?php if ($totalTeacherHours > 0): ?>
            <div class="stats">已录 <?= number_format($totalTeacherHours, 1) ?> 课时</div>
            <?php endif; ?>
        </a>
    </div>

    <div style="margin-top:20px;font-size:13px;color:var(--text-light);text-align:center;line-height:1.8;">
        💡 先由班主任填写"收费统计"（出勤人数），<br>
        再由课时干事填写"发放统计"（总课时需与上交纸质版一致）
    </div>
</div>

<script>
function toggleCalc(btn) {
    btn.classList.toggle('open');
    var detail = btn.nextElementSibling;
    if (detail) detail.classList.toggle('open');
    btn.innerHTML = btn.classList.contains('open') ? '▼ 收起明细' : '▶ 计算明细';
}
</script>

</body>
</html>

<?php endif; ?>