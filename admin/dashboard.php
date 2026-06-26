<?php
require_once __DIR__ . '/auth.php';
$db = Database::getInstance();

$year = isset($_GET['year']) ? intval($_GET['year']) : CURRENT_YEAR;
$month = isset($_GET['month']) ? intval($_GET['month']) : CURRENT_MONTH;

// 获取多套收费方案
$feePlans = $db->fetchAll(
    "SELECT * FROM fee_plans WHERE year = ? AND month = ? ORDER BY sort_order ASC, id ASC",
    [$year, $month]
);

// 统计概览
$totalStudents = 0;
$totalLessons = 0;
$gradeStats = [];

$grades = $db->fetchAll("SELECT * FROM grades ORDER BY sort_order");

foreach ($grades as $g) {
    $classes = $db->fetchAll("SELECT id FROM classes WHERE grade_id = ?", [$g['id']]);
    $classIds = array_column($classes, 'id');
    
    $students = 0;
    $lessons = 0;
    $maxStudents = 0; // 各班级最大出勤人数之和（估算实际学生数）
    
    if (!empty($classIds)) {
        $ids = implode(',', $classIds);
        $att = $db->fetchAll("SELECT SUM(student_count) as sc, SUM(lesson_hours) as lh FROM attendance WHERE class_id IN ({$ids}) AND year = ? AND month = ?", [$year, $month]);
        if ($att && $att[0]['sc']) {
            $students = intval($att[0]['sc']);
            $lessons = floatval($att[0]['lh']);
        }
        // 获取各班最大出勤人数（近似实际学生数），用于封顶计算
        $maxByClass = $db->fetchAll(
            "SELECT class_id, MAX(student_count) as max_sc FROM attendance WHERE class_id IN ({$ids}) AND year = ? AND month = ? GROUP BY class_id",
            [$year, $month]
        );
        $maxStudents = array_sum(array_column($maxByClass, 'max_sc'));
    }
    
    // 获取教师课时 (从 grade_settings 读取)
    $gs = $db->fetchOne("SELECT teacher_total_hours FROM grade_settings WHERE grade_id = ? AND year = ? AND month = ?", [$g['id'], $year, $month]);
    $teacherHours = floatval($gs['teacher_total_hours'] ?? 0);
    
    // 获取校外教师课时
    $ext = $db->fetchOne(
        "SELECT SUM(lesson_count) as total FROM teacher_lessons WHERE grade_id = ? AND year = ? AND month = ?",
        [$g['id'], $year, $month]
    );
    $extTeacherLessons = intval($ext['total'] ?? 0);
    
    $totalStudents += $students;
    $totalLessons += $lessons;
    
    $gradeStats[] = [
        'name' => $g['name'],
        'students' => $students,
        'lessons' => $lessons,
        'max_students' => $maxStudents,
        'teacher_hours' => $teacherHours,
        'ext_teacher_lessons' => $extTeacherLessons
    ];
}

// 计算全校总课时（教师课时 + 校外课时）
$totalTeacherHours = array_sum(array_column($gradeStats, 'teacher_hours'));
$totalExtTeacherLessons = array_sum(array_column($gradeStats, 'ext_teacher_lessons'));
$totalAllTeacherHours = $totalTeacherHours + $totalExtTeacherLessons;

// 获取特殊人员数据（领导/后勤/校医）
$specialStaffList = $db->fetchAll(
    "SELECT * FROM special_staff WHERE year = ? AND month = ? ORDER BY FIELD(staff_type, '领导','后勤','校医')",
    [$year, $month]
);
$specialStaffTotal = [];
foreach ($specialStaffList as $ss) {
    $specialStaffTotal[$ss['staff_type']] = [
        'hours' => floatval($ss['total_hours']),
        'unit_price' => floatval($ss['unit_price']),
        'expenditure' => floatval($ss['total_hours']) * floatval($ss['unit_price'])
    ];
}
$totalSpecialExpenditure = array_sum(array_column($specialStaffTotal, 'expenditure'));

adminHeader('预算概览');
?>

<!-- 月份选择 -->
<div class="month-selector" style="margin-bottom:20px;">
    <?php
    $py = $year; $pm = $month - 1;
    if ($pm < 1) { $pm = 12; $py--; }
    $ny = $year; $nm = $month + 1;
    if ($nm > 12) { $nm = 1; $ny++; }
    ?>
    <a href="?year=<?= $py ?>&month=<?= $pm ?>" class="month-nav">◀</a>
    <span class="month-display"><?= $year ?>年 <?= $month ?>月</span>
    <a href="?year=<?= $ny ?>&month=<?= $nm ?>" class="month-nav">▶</a>
</div>

<!-- ===== 各方案收支预算概览 ===== -->
<?php if (!empty($feePlans)): 
$planCards = [];
foreach ($feePlans as $fp):
    $unitPrice = floatval($fp['unit_price']);
    $capPrice = floatval($fp['cap_price']);
    $teacherPayRate = floatval($fp['teacher_pay_rate'] ?? 0);
    
    // 收入 = 按班级逐生计算（单价 × 上课节数，每人不超过封顶价）
    $totalIncome = 0;
    $incomeCalcRows = []; // 用于明细展示
    foreach ($gradeStats as $gs) {
        $personTimes = $gs['students'];
        $uniqueStudents = $gs['max_students'] > 0 ? $gs['max_students'] : $personTimes;
        // 每个学生：min(单价 × 上课节数, 封顶价)
        // 班级合计 ≈ min(总人次 × 单价, 实际学生数 × 封顶价)
        $rawTotal = $personTimes * $unitPrice;
        $capTotal = $uniqueStudents * $capPrice;
        $fee = ($capPrice > 0 && $uniqueStudents > 0) ? min($rawTotal, $capTotal) : $rawTotal;
        $totalIncome += $fee;
        $incomeCalcRows[] = [
            'name' => $gs['name'],
            'person_times' => $personTimes,
            'unique_students' => $uniqueStudents,
            'raw_total' => $rawTotal,
            'cap_total' => $capTotal,
            'fee' => $fee
        ];
    }
    
    // 支出 = 教师总课时 × 教师课时费 + 特殊人员支出
    $totalExpenditure = $totalAllTeacherHours * $teacherPayRate + $totalSpecialExpenditure;
    
    // 结余
    $balance = $totalIncome - $totalExpenditure;
?>
<!-- 方案预算卡片 -->
<div class="card" style="margin-bottom:16px;border-left:4px solid <?= $balance >= 0 ? '#10b981' : '#ef4444' ?>;">
    <div class="card-header">
        <h2>📊 <?= htmlspecialchars($fp['plan_name']) ?></h2>
        <span style="font-size:13px;color:var(--text-secondary);">
            学生 <?= number_format($unitPrice, 2) ?>元/节 · 封顶 <?= number_format($capPrice, 0) ?>元 · 教师 <?= number_format($teacherPayRate, 0) ?>元/节
        </span>
    </div>
    <div class="stat-grid" style="grid-template-columns:repeat(3,1fr);">
        <div class="stat-card" style="background:#f0f7ff;border-radius:12px;padding:16px;">
            <div class="stat-label" style="font-size:13px;">💰 预计收入</div>
            <div class="stat-value" style="font-size:28px;color:#3b82f6;">¥<?= number_format($totalIncome, 0) ?></div>
            <button class="calc-toggle" onclick="toggleCalc(this)" style="margin-top:6px;">
                ▶ 计算明细
            </button>
            <div class="calc-detail">
                <div style="font-weight:600;color:var(--text);margin-bottom:6px;">📐 收费规则（每位学生）</div>
                <div class="row"><span class="label">应收金额</span><span class="value">单价 × 上课节数</span></div>
                <div class="row"><span class="label">超过封顶价</span><span class="value">按封顶价 ¥<?= number_format($capPrice, 0) ?> 收取</span></div>
                <div class="row total" style="margin-bottom:8px;"><span class="label">每位学生</span><span class="value">min(<?= number_format($unitPrice, 2) ?>元/节 × 节数, ¥<?= number_format($capPrice, 0) ?>)</span></div>
                <div style="font-weight:600;color:var(--text);margin-bottom:6px;">📊 各年级预估收入</div>
                <?php foreach ($incomeCalcRows as $cr): ?>
                <div class="row">
                    <span class="label"><?= htmlspecialchars($cr['name']) ?></span>
                    <span class="value" style="font-size:12px;">
                        <?= number_format($cr['person_times']) ?>人次×¥<?= number_format($unitPrice, 2) ?>
                        <?php if ($cr['cap_total'] > 0 && $cr['fee'] < $cr['raw_total']): ?>
                        <span style="color:#ef4444;"> → 封顶 ¥<?= number_format($cr['fee'], 0) ?></span>
                        <?php else: ?>
                        = ¥<?= number_format($cr['fee'], 0) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endforeach; ?>
                <div class="row total income"><span class="label">全校预计收入</span><span class="value">¥<?= number_format($totalIncome, 0) ?></span></div>
            </div>
        </div>
        <div class="stat-card" style="background:#fef2f2;border-radius:12px;padding:16px;">
            <div class="stat-label" style="font-size:13px;">💸 预计支出</div>
            <div class="stat-value" style="font-size:28px;color:#ef4444;">¥<?= number_format($totalExpenditure, 0) ?></div>
            <button class="calc-toggle" onclick="toggleCalc(this)" style="margin-top:6px;">
                ▶ 计算明细
            </button>
            <div class="calc-detail">
                <div class="row"><span class="label">普通教师课时</span><span class="value"><?= number_format($totalAllTeacherHours, 1) ?></span></div>
                <div class="row"><span class="label">× 教师课时费</span><span class="value"><?= number_format($teacherPayRate, 0) ?>元</span></div>
                <div class="row"><span class="label">= 教师支出</span><span class="value">¥<?= number_format($totalAllTeacherHours * $teacherPayRate, 0) ?></span></div>
                <?php if (!empty($specialStaffTotal)): ?>
                <div class="sep"></div>
                <div style="color:var(--text-secondary);padding:2px 0;">+ 特殊人员支出</div>
                <?php foreach ($specialStaffTotal as $type => $ssd): ?>
                <div class="row"><span class="label">&nbsp;&nbsp;<?= $type ?></span><span class="value">¥<?= number_format($ssd['expenditure'], 0) ?></span></div>
                <?php endforeach; ?>
                <?php endif; ?>
                <div class="sep"></div>
                <div class="row total"><span class="label">总支出</span><span class="value">¥<?= number_format($totalExpenditure, 0) ?></span></div>
            </div>
        </div>
        <div class="stat-card" style="background:<?= $balance >= 0 ? '#f0fdf4' : '#fef2f2' ?>;border-radius:12px;padding:16px;">
            <div class="stat-label" style="font-size:13px;">📋 预算结余</div>
            <div class="stat-value" style="font-size:28px;color:<?= $balance >= 0 ? '#10b981' : '#ef4444' ?>;">
                ¥<?= number_format($balance, 0) ?>
            </div>
            <button class="calc-toggle" onclick="toggleCalc(this)" style="margin-top:6px;">
                ▶ 计算明细
            </button>
            <div class="calc-detail">
                <div class="row"><span class="label">收入</span><span class="value">¥<?= number_format($totalIncome, 0) ?></span></div>
                <div class="row"><span class="label">- 支出</span><span class="value">¥<?= number_format($totalExpenditure, 0) ?></span></div>
                <div class="row total <?= $balance >= 0 ? 'income' : '' ?>">
                    <span class="label">= 结余</span>
                    <span class="value" style="color:<?= $balance >= 0 ? '#10b981' : '#ef4444' ?>;">¥<?= number_format($balance, 0) ?></span>
                </div>
            </div>
        </div>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:12px;font-size:13px;color:var(--text-secondary);">
        <span>出勤：<strong><?= number_format($totalStudents) ?></strong>人次</span>
        <span>教师课时：<strong><?= number_format($totalAllTeacherHours, 1) ?></strong></span>
        <?php foreach ($specialStaffTotal as $type => $ssd): ?>
        <span><?= $type ?>：<strong><?= number_format($ssd['hours'], 1) ?></strong>课时 · ¥<?= number_format($ssd['expenditure'], 0) ?></span>
        <?php endforeach; ?>
        <span>收支比：<strong><?= $totalExpenditure > 0 ? number_format($totalIncome / $totalExpenditure, 2) : '-' ?></strong></span>
    </div>
</div>
<?php endforeach; ?>
<?php else: ?>
<div class="card" style="background:#fefce8;margin-bottom:16px;">
    <div class="empty-state">
        <div class="empty-icon">📊</div>
        <p>暂无收费方案</p>
        <p style="font-size:13px;margin-top:6px;">请先在"<a href="settings.php">月度设置</a>"中配置收费方案</p>
    </div>
</div>
<?php endif; ?>

<!-- ===== 各年级收支明细 ===== -->
<div class="card">
    <div class="card-header">
        <h2>各年级收支明细</h2>
        <span style="font-size:13px;color:var(--text-secondary);">按方案展示各年级的收入与支出</span>
    </div>
    
    <?php if (!empty($feePlans)): foreach ($feePlans as $fp):
        $unitPrice = floatval($fp['unit_price']);
        $capPrice = floatval($fp['cap_price']);
        $teacherPayRate = floatval($fp['teacher_pay_rate'] ?? 0);
    ?>
    <div style="margin-bottom:20px;">
        <h3 style="font-size:15px;margin-bottom:8px;padding:8px 0;border-bottom:2px solid var(--primary);">
            <?= htmlspecialchars($fp['plan_name']) ?>
        </h3>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>年级</th>
                        <th>出勤人次</th>
                        <th>预计收入</th>
                        <th>教师总课时</th>
                        <th>课时支出</th>
                        <th>年级结余</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $planTotalIncome = 0;
                    $planTotalExpenditure = 0;
                    foreach ($gradeStats as $gs):
                        $personTimes = $gs['students'];
                        $uniqueStudents = $gs['max_students'] > 0 ? $gs['max_students'] : $personTimes;
                        $rawTotal = $personTimes * $unitPrice;
                        $capTotal = $uniqueStudents * $capPrice;
                        $fee = ($capPrice > 0 && $uniqueStudents > 0) ? min($rawTotal, $capTotal) : $rawTotal;
                        $teacherTotal = $gs['teacher_hours'] + $gs['ext_teacher_lessons'];
                        $expenditure = $teacherTotal * $teacherPayRate;
                        $gBalance = $fee - $expenditure;
                        $planTotalIncome += $fee;
                        $planTotalExpenditure += $expenditure;
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($gs['name']) ?></strong></td>
                        <td><?= number_format($gs['students']) ?></td>
                        <td style="color:#3b82f6;font-weight:600;">¥<?= number_format($fee, 0) ?></td>
                        <td><?= number_format($teacherTotal, 1) ?>节</td>
                        <td style="color:#ef4444;">¥<?= number_format($expenditure, 0) ?></td>
                        <td style="color:<?= $gBalance >= 0 ? '#10b981' : '#ef4444' ?>;font-weight:600;">
                            ¥<?= number_format($gBalance, 0) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!empty($specialStaffTotal)): ?>
                    <tr style="background:#fefce8;">
                        <td><strong>👤 特殊人员</strong></td>
                        <td>-</td>
                        <td>-</td>
                        <td>
                            <?php 
                            $parts = [];
                            foreach ($specialStaffTotal as $type => $ssd) {
                                $parts[] = $type . ':' . number_format($ssd['hours'], 1);
                            }
                            echo implode(' ', $parts);
                            ?>
                        </td>
                        <td style="color:#ef4444;">¥<?= number_format($totalSpecialExpenditure, 0) ?></td>
                        <td style="color:#ef4444;font-weight:600;">¥-<?= number_format($totalSpecialExpenditure, 0) ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td><strong>合计</strong></td>
                        <td><?= number_format($totalStudents) ?></td>
                        <td style="color:#3b82f6;font-weight:600;">¥<?= number_format($planTotalIncome, 0) ?></td>
                        <td><?= number_format($totalAllTeacherHours, 1) ?>节</td>
                        <td style="color:#ef4444;">¥<?= number_format($planTotalExpenditure + $totalSpecialExpenditure, 0) ?></td>
                        <td style="color:<?= ($planTotalIncome - $planTotalExpenditure - $totalSpecialExpenditure) >= 0 ? '#10b981' : '#ef4444' ?>;font-weight:600;">
                            ¥<?= number_format($planTotalIncome - $planTotalExpenditure - $totalSpecialExpenditure, 0) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div style="margin-top:8px;text-align:right;">
            <button class="calc-toggle" onclick="toggleCalc(this)">
                ▶ 查看收入/支出计算规则
            </button>
            <div class="calc-detail" style="text-align:left;">
                <div style="font-weight:600;margin-bottom:4px;">📐 收入（按学生封顶）</div>
                <div class="row"><span class="label">每位学生</span><span class="value">min(单价×上课节数, 封顶价)</span></div>
                <div class="row"><span class="label">年级合计</span><span class="value">≈ min(总人次×单价, 实际学生数×封顶价)</span></div>
                <div style="font-weight:600;margin:8px 0 4px;">📉 支出</div>
                <div class="row"><span class="label">教师支出</span><span class="value">（教师课时+校外课时）× 教师课时费</span></div>
                <div class="row"><span class="label">特殊人员支出</span><span class="value">直接累加</span></div>
                <div class="row total"><span class="label">结余</span><span class="value">= 收入 - 支出</span></div>
            </div>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>

<!-- ===== 基础数据概览 ===== -->
<div class="card">
    <div class="card-header">
        <h2>📋 基础数据</h2>
    </div>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($totalStudents) ?></div>
            <div class="stat-label">出勤总人次</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($totalLessons, 1) ?></div>
            <div class="stat-label">总课时数</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($totalTeacherHours, 1) ?></div>
            <div class="stat-label">上课教师课时</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($totalExtTeacherLessons) ?></div>
            <div class="stat-label">校外教师课时</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($totalSpecialExpenditure) ?></div>
            <div class="stat-label">特殊人员支出</div>
        </div>
    </div>
</div>

<!-- 快捷操作 -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;">
    <div class="card">
        <div class="card-header"><h3>快捷操作</h3></div>
        <div style="display:flex;flex-direction:column;gap:10px;">
            <a href="settings.php" class="btn btn-primary btn-sm">⚙️ 月度参数设置</a>
            <a href="classes.php" class="btn btn-accent btn-sm">🏫 班级管理</a>
            <a href="statistics.php" class="btn btn-outline btn-sm">📈 查看完整统计报表</a>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3>💡 提示</h3></div>
        <div style="font-size:14px;color:var(--text-secondary);line-height:1.8;">
            <p>• 每位学生收费 = min(单价 × 上课节数, 封顶价)，超过封顶价按封顶价收取</p>
            <p>• 收入 = 各班逐生计算后累加</p>
            <p>• 支出 = 教师课时支出 + 特殊人员（领导/后勤/校医）支出</p>
            <p>• 普通教师课时由年级干事填写，特殊人员在"👤 特殊人员"页面设置</p>
            <p>• 可在"月度设置"中配置多套方案对比</p>
        </div>
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
<?php adminFooter(); ?>