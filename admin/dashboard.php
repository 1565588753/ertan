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
    $classes = $db->fetchAll("SELECT id, name FROM classes WHERE grade_id = ?", [$g['id']]);
    $classIds = array_column($classes, 'id');
    
    $totalPersonTimes = 0; // 所有分组人数之和 = 实际学生总数
    $totalLessonHours = 0; // Σ(人数 × 节数)
    $attendanceGroups = []; // 各班分组明细
    
    if (!empty($classIds)) {
        $ids = implode(',', $classIds);
        // 每行：lesson_number = 上了X节课, student_count = 多少人
        $att = $db->fetchAll(
            "SELECT a.class_id, a.lesson_number, a.student_count, c.name as class_name
             FROM attendance a
             JOIN classes c ON c.id = a.class_id
             WHERE a.class_id IN ({$ids}) AND a.year = ? AND a.month = ?
             ORDER BY a.class_id, a.lesson_number",
            [$year, $month]
        );
        foreach ($att as $row) {
            $totalPersonTimes += intval($row['student_count']);
            $totalLessonHours += intval($row['student_count']) * intval($row['lesson_number']);
            $attendanceGroups[] = [
                'class_name' => $row['class_name'],
                'lesson_number' => intval($row['lesson_number']),
                'student_count' => intval($row['student_count'])
            ];
        }
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
    
    $totalStudents += $totalPersonTimes;
    $totalLessons += $totalLessonHours;
    
    $gradeStats[] = [
        'name' => $g['name'],
        'total_students' => $totalPersonTimes,
        'total_lesson_hours' => $totalLessonHours,
        'attendance_groups' => $attendanceGroups,
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
    
    // 收入 = 按分组逐生计算
    // 每组收费 = 人数 × min(单价 × 节数, 封顶价)
    // 如：上1节7人 = 7 × min(1×13,190) = 91；上18节10人 = 10 × min(18×13,190) = 10×190 = 1900
    $totalIncome = 0;
    $incomeCalcRows = [];
    foreach ($gradeStats as $gs) {
        $gradeIncome = 0;
        $detailRows = [];
        if (!empty($gs['attendance_groups'])) {
            foreach ($gs['attendance_groups'] as $ag) {
                $rawPerStudent = $ag['lesson_number'] * $unitPrice;
                $cappedPerStudent = min($rawPerStudent, $capPrice);
                $groupFee = $ag['student_count'] * $cappedPerStudent;
                $gradeIncome += $groupFee;
                $detailRows[] = [
                    'class_name' => $ag['class_name'],
                    'lessons' => $ag['lesson_number'],
                    'count' => $ag['student_count'],
                    'raw' => $rawPerStudent,
                    'capped' => $cappedPerStudent,
                    'fee' => $groupFee
                ];
            }
        }
        $totalIncome += $gradeIncome;
        $incomeCalcRows[] = [
            'name' => $gs['name'],
            'detail_rows' => $detailRows,
            'fee' => $gradeIncome
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
                <div style="font-weight:600;color:var(--text);margin-bottom:6px;">📐 收费规则（按分组计算）</div>
                <div class="row"><span class="label">表格中"上X节"</span><span class="value">本月上了X节课的学生人数</span></div>
                <div class="row"><span class="label">每组收费</span><span class="value">人数 × min(X节×单价, 封顶价)</span></div>
                <div class="row total" style="margin-bottom:8px;"><span class="label">全校合计</span><span class="value">Σ 所有分组收费</span></div>
                
                <div style="font-weight:600;color:var(--text);margin-bottom:6px;">📊 详细分组计算</div>
                <?php foreach ($incomeCalcRows as $cr): if (empty($cr['detail_rows'])) continue; ?>
                <div style="margin:4px 0;">
                    <strong style="font-size:13px;"><?= htmlspecialchars($cr['name']) ?></strong>
                </div>
                <?php foreach ($cr['detail_rows'] as $dr): ?>
                <div class="row" style="font-size:12px;">
                    <span class="label"><?= $dr['class_name'] ?> 上<?= $dr['lessons'] ?>节 × <?= $dr['count'] ?>人</span>
                    <span class="value">
                        <?php if ($dr['raw'] != $dr['capped']): ?>
                            <?= number_format($dr['count'], 0) ?> × min(<?= $dr['lessons'] ?>×¥<?= number_format($unitPrice, 2) ?>, ¥<?= number_format($capPrice, 0) ?>)
                            = <?= number_format($dr['count'], 0) ?> × ¥<?= number_format($dr['capped'], 2) ?>
                            = ¥<?= number_format($dr['fee'], 0) ?> <span style="color:#ef4444;">(封顶)</span>
                        <?php else: ?>
                            <?= number_format($dr['count'], 0) ?> × ¥<?= number_format($dr['capped'], 2) ?>
                            = ¥<?= number_format($dr['fee'], 0) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endforeach; ?>
                <div class="row" style="font-weight:600;">
                    <span class="label"><?= htmlspecialchars($cr['name']) ?> 合计</span>
                    <span class="value">¥<?= number_format($cr['fee'], 0) ?></span>
                </div>
                <?php endforeach; ?>
                <div class="row total income" style="margin-top:6px;">
                    <span class="label">全校预计收入</span>
                    <span class="value">¥<?= number_format($totalIncome, 0) ?></span>
                </div>
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
        <span>学生总人数：<strong><?= number_format($totalStudents) ?></strong></span>
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

<!-- ===== 各年级盈亏总览 ===== -->
<?php if (!empty($feePlans)): 
$planGradeBalances = [];
foreach ($feePlans as $fp):
    $unitPrice = floatval($fp['unit_price']);
    $capPrice = floatval($fp['cap_price']);
    $teacherPayRate = floatval($fp['teacher_pay_rate'] ?? 0);
    $gradeBalances = [];
    foreach ($gradeStats as $gs) {
        $gradeIncome = 0;
        if (!empty($gs['attendance_groups'])) {
            foreach ($gs['attendance_groups'] as $ag) {
                $gradeIncome += $ag['student_count'] * min($ag['lesson_number'] * $unitPrice, $capPrice);
            }
        }
        $teacherTotal = $gs['teacher_hours'] + $gs['ext_teacher_lessons'];
        $expenditure = $teacherTotal * $teacherPayRate;
        $balance = $gradeIncome - $expenditure;
        $gradeBalances[] = ['name' => $gs['name'], 'income' => $gradeIncome, 'expenditure' => $expenditure, 'balance' => $balance];
    }
    $planGradeBalances[] = ['plan_name' => $fp['plan_name'], 'grades' => $gradeBalances];
endforeach;
?>
<div class="card">
    <div class="card-header">
        <h2>📊 各年级盈亏总览</h2>
        <div style="display:flex;gap:8px;align-items:center;">
            <span style="font-size:13px;color:var(--text-secondary);">每套方案下各年级的盈亏状况</span>
            <a href="export.php?type=statistics&year=<?= $year ?>&month=<?= $month ?>" target="_blank" style="background:#17a2b8;color:#fff;text-decoration:none;padding:4px 10px;border-radius:6px;font-size:12px;">📥 导出Excel</a>
        </div>
    </div>
    <?php foreach ($planGradeBalances as $pgb): ?>
    <?php 
    $surplusCount = count(array_filter($pgb['grades'], fn($g) => $g['balance'] >= 0));
    $deficitCount = count($pgb['grades']) - $surplusCount;
    ?>
    <div style="margin-bottom:16px;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
            <h3 style="font-size:15px;margin:0;"><?= htmlspecialchars($pgb['plan_name']) ?></h3>
            <span class="badge" style="background:#f0fdf4;color:#16a34a;font-size:12px;">盈 <?= $surplusCount ?>个</span>
            <?php if ($deficitCount > 0): ?>
            <span class="badge" style="background:#fef2f2;color:#ef4444;font-size:12px;">亏 <?= $deficitCount ?>个</span>
            <?php endif; ?>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;">
            <?php foreach ($pgb['grades'] as $gb): 
                $ratio = $gb['expenditure'] > 0 ? ($gb['income'] / $gb['expenditure'] * 100) : 0;
            ?>
            <div style="background:#fff;border-radius:12px;padding:14px;border:1px solid <?= $gb['balance'] >= 0 ? '#bbf7d0' : '#fecaca' ?>;box-shadow:0 1px 4px rgba(0,0,0,0.04);<?= $gb['balance'] >= 0 ? '' : 'background:#fef2f2;' ?>">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <strong style="font-size:14px;"><?= htmlspecialchars($gb['name']) ?></strong>
                    <span style="font-size:11px;padding:2px 8px;border-radius:6px;font-weight:600;<?= $gb['balance'] >= 0 ? 'background:#f0fdf4;color:#16a34a;' : 'background:#fef2f2;color:#ef4444;' ?>">
                        <?= $gb['balance'] >= 0 ? '✓ 盈' : '✗ 亏' ?>
                    </span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-secondary);padding:2px 0;">
                    <span>收入</span>
                    <span style="color:#3b82f6;font-weight:600;">¥<?= number_format($gb['income'], 0) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-secondary);padding:2px 0;">
                    <span>支出</span>
                    <span style="color:#ef4444;font-weight:600;">¥<?= number_format($gb['expenditure'], 0) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:13px;padding:4px 0 0;margin-top:4px;border-top:1px solid #f0f0f0;">
                    <span style="font-weight:600;">结余</span>
                    <span style="font-weight:700;color:<?= $gb['balance'] >= 0 ? '#16a34a' : '#ef4444' ?>;">
                        <?= $gb['balance'] >= 0 ? '+' : '' ?>¥<?= number_format($gb['balance'], 0) ?>
                    </span>
                </div>
                <?php if ($ratio > 0): ?>
                <div style="margin-top:6px;">
                    <div style="font-size:11px;color:var(--text-light);margin-bottom:2px;">收支比 <?= number_format($ratio, 1) ?>%</div>
                    <div style="height:4px;background:#f0f0f0;border-radius:2px;overflow:hidden;">
                        <div style="height:100%;width:<?= min($ratio, 100) ?>%;background:<?= $ratio >= 100 ? '#16a34a' : '#ef4444' ?>;border-radius:2px;"></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
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
        <h3 style="font-size:15px;margin-bottom:4px;padding:8px 0 4px;border-bottom:2px solid var(--primary);">
            <?= htmlspecialchars($fp['plan_name']) ?>
        </h3>
        <div style="font-size:12px;color:var(--text-secondary);padding:2px 0 6px;border-bottom:2px solid var(--primary);margin-bottom:8px;">
            学生 ¥<?= number_format($unitPrice, 2) ?>/节 · 封顶 ¥<?= number_format($capPrice, 0) ?> · 教师 ¥<?= number_format($teacherPayRate, 0) ?>/节
        </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>年级</th>
                        <th>学生人数</th>
                        <th>预计收入</th>
                        <th>教师总课时</th>
                        <th>课时支出</th>
                        <th>年级结余</th>
                        <th>状态</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $planTotalIncome = 0;
                    $planTotalExpenditure = 0;
                    $planSurplus = 0; $planDeficit = 0;
                    foreach ($gradeStats as $gs):
                        $gradeIncome = 0;
                        if (!empty($gs['attendance_groups'])) {
                            foreach ($gs['attendance_groups'] as $ag) {
                                $gradeIncome += $ag['student_count'] * min($ag['lesson_number'] * $unitPrice, $capPrice);
                            }
                        }
                        $fee = $gradeIncome;
                        $teacherTotal = $gs['teacher_hours'] + $gs['ext_teacher_lessons'];
                        $expenditure = $teacherTotal * $teacherPayRate;
                        $gBalance = $fee - $expenditure;
                        $planTotalIncome += $fee;
                        $planTotalExpenditure += $expenditure;
                        if ($gBalance >= 0) $planSurplus++; else $planDeficit++;
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($gs['name']) ?></strong></td>
                        <td><?= number_format($gs['total_students']) ?>人</td>
                        <td style="color:#3b82f6;font-weight:600;">¥<?= number_format($fee, 0) ?></td>
                        <td><?= number_format($teacherTotal, 1) ?>节</td>
                        <td style="color:#ef4444;">¥<?= number_format($expenditure, 0) ?></td>
                        <td style="color:<?= $gBalance >= 0 ? '#10b981' : '#ef4444' ?>;font-weight:600;">
                            <?= $gBalance >= 0 ? '+' : '' ?>¥<?= number_format($gBalance, 0) ?>
                        </td>
                        <td>
                            <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600;<?= $gBalance >= 0 ? 'background:#f0fdf4;color:#16a34a;' : 'background:#fef2f2;color:#ef4444;' ?>">
                                <?= $gBalance >= 0 ? '✅ 盈余' : '⚠️ 亏损' ?>
                            </span>
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
                        <td style="color:#ef4444;font-weight:600;">-¥<?= number_format($totalSpecialExpenditure, 0) ?></td>
                        <td><span style="padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600;background:#fef2f2;color:#ef4444;">⚠️ 支出</span></td>
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
                            <?= ($planTotalIncome - $planTotalExpenditure - $totalSpecialExpenditure) >= 0 ? '+' : '' ?>¥<?= number_format($planTotalIncome - $planTotalExpenditure - $totalSpecialExpenditure, 0) ?>
                        </td>
                        <td>
                            <span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;">
                                <span style="background:#f0fdf4;color:#16a34a;padding:2px 6px;border-radius:4px;">盈<?= $planSurplus ?></span>
                                <?php if ($planDeficit > 0): ?>
                                <span style="background:#fef2f2;color:#ef4444;padding:2px 6px;border-radius:4px;">亏<?= $planDeficit ?></span>
                                <?php endif; ?>
                            </span>
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
                <div style="font-weight:600;margin-bottom:4px;">📐 收入（按分组计算）</div>
                <div class="row"><span class="label">每组学生</span><span class="value">人数 × min(节数×单价, 封顶价)</span></div>
                <div class="row"><span class="label">年级合计</span><span class="value">Σ 所有分组收费</span></div>
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
            <div class="stat-label">学生总人数</div>
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
            <p>• 收入 = 按"上X节"分组计算：每组收费 = 人数 × min(X节×单价, 封顶价)</p>
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