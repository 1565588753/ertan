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
        
        if (isset($_POST['settings']) && is_array($_POST['settings'])) {
            foreach ($_POST['settings'] as $gradeId => $s) {
                $gradeId = intval($gradeId);
                $days = intval($s['days'] ?? 0);
                $price = floatval($s['price'] ?? 0);
                $cap = floatval($s['cap'] ?? 0);

                // UPSERT
                $existing = $db->fetchOne(
                    "SELECT id FROM grade_settings WHERE grade_id = ? AND year = ? AND month = ?",
                    [$gradeId, $year, $month]
                );
                if ($existing) {
                    $db->execute(
                        "UPDATE grade_settings SET teaching_days = ?, unit_price = ?, cap_price = ? WHERE id = ?",
                        [$days, $price, $cap, $existing['id']]
                    );
                } else {
                    $db->execute(
                        "INSERT INTO grade_settings (grade_id, year, month, teaching_days, unit_price, cap_price) VALUES (?, ?, ?, ?, ?, ?)",
                        [$gradeId, $year, $month, $days, $price, $cap]
                    );
                }
            }
        }

        $db->getPdo()->commit();
        $message = '保存成功！';
    } catch (Exception $e) {
        $db->getPdo()->rollBack();
        $message = '保存失败：' . $e->getMessage();
    }
}

// 获取所有年级的当前设置
$grades = $db->fetchAll("SELECT * FROM grades ORDER BY sort_order");
$settings = [];
foreach ($grades as $g) {
    $s = $db->fetchOne("SELECT * FROM grade_settings WHERE grade_id = ? AND year = ? AND month = ?", [$g['id'], $year, $month]);
    $settings[$g['id']] = $s ?: ['teaching_days' => 0, 'unit_price' => 0, 'cap_price' => 0];
}

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
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>年级</th>
                        <th>上课天数</th>
                        <th>课程单价（元/节）</th>
                        <th>封顶价格（元/人）</th>
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
                                   name="settings[<?= $g['id'] ?>][days]" 
                                   value="<?= intval($s['teaching_days']) ?>" min="0" style="width:80px;">
                        </td>
                        <td>
                            <input type="number" class="form-control form-control-sm" 
                                   name="settings[<?= $g['id'] ?>][price]" 
                                   value="<?= floatval($s['unit_price']) ?>" min="0" step="0.5" style="width:100px;">
                        </td>
                        <td>
                            <input type="number" class="form-control form-control-sm" 
                                   name="settings[<?= $g['id'] ?>][cap]" 
                                   value="<?= floatval($s['cap_price']) ?>" min="0" step="1" style="width:100px;">
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
        • <strong>上课天数</strong>：该年级本月实际上课的天数<br>
        • <strong>课程单价</strong>：每节课向学生收取的费用（如：13元/节）<br>
        • <strong>封顶价格</strong>：每个学生每月最多收取的费用（如：190元/人）<br>
        • 费用计算方式：人数 × 单价，但每个学生不超过封顶价
    </div>
</div>

<?php adminFooter(); ?>