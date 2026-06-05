<?php
require_once __DIR__ . '/auth.php';
$db = Database::getInstance();

$year = isset($_GET['year']) ? intval($_GET['year']) : CURRENT_YEAR;
$month = isset($_GET['month']) ? intval($_GET['month']) : CURRENT_MONTH;
$message = '';

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->getPdo()->beginTransaction();
        
        // 保存分年级上课节数
        if (isset($_POST['days']) && is_array($_POST['days'])) {
            foreach ($_POST['days'] as $gradeId => $days) {
                $gradeId = intval($gradeId);
                $days = intval($days);

                $existing = $db->fetchOne(
                    "SELECT id FROM grade_settings WHERE grade_id = ? AND year = ? AND month = ?",
                    [$gradeId, $year, $month]
                );
                if ($existing) {
                    $db->execute(
                        "UPDATE grade_settings SET teaching_days = ? WHERE id = ?",
                        [$days, $existing['id']]
                    );
                } else {
                    $db->execute(
                        "INSERT INTO grade_settings (grade_id, year, month, teaching_days) VALUES (?, ?, ?, ?)",
                        [$gradeId, $year, $month, $days]
                    );
                }
            }
        }

        // 保存全校统一收费标准
        $unitPrice = floatval($_POST['unit_price'] ?? 0);
        $capPrice = floatval($_POST['cap_price'] ?? 0);
        $existingFee = $db->fetchOne(
            "SELECT id FROM fee_settings WHERE year = ? AND month = ?",
            [$year, $month]
        );
        if ($existingFee) {
            $db->execute(
                "UPDATE fee_settings SET unit_price = ?, cap_price = ? WHERE id = ?",
                [$unitPrice, $capPrice, $existingFee['id']]
            );
        } else {
            $db->execute(
                "INSERT INTO fee_settings (year, month, unit_price, cap_price) VALUES (?, ?, ?, ?)",
                [$year, $month, $unitPrice, $capPrice]
            );
        }

        $db->getPdo()->commit();
        $message = '保存成功！';
    } catch (Exception $e) {
        $db->getPdo()->rollBack();
        $message = '保存失败：' . $e->getMessage();
    }
}

// 获取所有年级
$grades = $db->fetchAll("SELECT * FROM grades ORDER BY sort_order");
$settings = [];
foreach ($grades as $g) {
    $s = $db->fetchOne("SELECT * FROM grade_settings WHERE grade_id = ? AND year = ? AND month = ?", [$g['id'], $year, $month]);
    $settings[$g['id']] = $s ?: ['teaching_days' => 0];
}

// 获取全校统一收费标准
$feeSetting = $db->fetchOne("SELECT * FROM fee_settings WHERE year = ? AND month = ?", [$year, $month]);

adminHeader('月度参数设置');
?>

<?php if ($message): ?>
    <?php if (strpos($message, '成功') !== false): ?>
    <div class="toast toast-success show" style="position:fixed;top:20px;right:20px;z-index:9999;padding:14px 20px;border-radius:12px;color:#fff;font-size:14px;box-shadow:0 10px 30px rgba(0,0,0,0.15);">
        ✅ <?= htmlspecialchars($message) ?>
    </div>
    <?php else: ?>
    <div class="toast toast-error show" style="position:fixed;top:20px;right:20px;z-index:9999;padding:14px 20px;border-radius:12px;color:#fff;font-size:14px;box-shadow:0 10px 30px rgba(0,0,0,0.15);">
        ❌ <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>
    <script>
        setTimeout(() => {
            var t = document.querySelector('.toast');
            if (t) { t.style.transition = 'opacity 0.5s'; t.style.opacity = '0'; setTimeout(() => t.remove(), 500); }
        }, 2000);
    </script>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>月度参数设置</h2>
    </div>
    
    <!-- 月份选择 -->
    <div class="month-selector">
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

    <form method="post">
        <!-- 全校统一收费标准 -->
        <div class="card" style="margin-bottom:16px;background:#f0f4ff;">
            <div class="card-header">
                <h3>📌 全校统一收费标准</h3>
            </div>
            <div style="display:flex;gap:20px;flex-wrap:wrap;padding:0 0 16px 0;">
                <div class="form-group" style="margin-bottom:0;min-width:150px;">
                    <label style="font-size:13px;">课程单价（元/节）</label>
                    <input type="number" class="form-control" 
                           name="unit_price" 
                           value="<?= floatval($feeSetting['unit_price'] ?? 0) ?>" 
                           min="0" step="0.5" style="width:140px;">
                </div>
                <div class="form-group" style="margin-bottom:0;min-width:150px;">
                    <label style="font-size:13px;">封顶价格（元/人）</label>
                    <input type="number" class="form-control" 
                           name="cap_price" 
                           value="<?= floatval($feeSetting['cap_price'] ?? 0) ?>" 
                           min="0" step="1" style="width:140px;">
                </div>
            </div>
        </div>

        <!-- 分年级上课节数 -->
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>年级</th>
                        <th>上课节数</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grades as $g): 
                        $s = $settings[$g['id']];
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($g['name']) ?></strong></td>
                        <td>
                            <input type="number" class="form-control form-control-sm" 
                                   name="days[<?= $g['id'] ?>]" 
                                   value="<?= intval($s['teaching_days']) ?>" min="0" style="width:80px;">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:16px;text-align:right;">
            <button type="submit" class="btn btn-primary">💾 保存设置</button>
        </div>
    </form>
</div>

<div class="card" style="background:#f0f4ff;">
    <div style="font-size:14px;color:var(--text-secondary);line-height:1.8;">
        <strong>📌 说明：</strong><br>
        • <strong>上课节数</strong>：该年级本月实际上课节数，同一年级所有班级统一<br>
        • <strong>课程单价</strong>：每节课向学生收取的费用，全校统一（如：13元/节）<br>
        • <strong>封顶价格</strong>：每个学生每月最多收取的费用，全校统一（如：190元/人）<br>
        • 费用计算方式：人数 × 单价，但每个学生不超过封顶价
    </div>
</div>

<?php adminFooter(); ?>