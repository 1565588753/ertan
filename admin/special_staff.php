<?php
require_once __DIR__ . '/auth.php';
$db = Database::getInstance();

$year = isset($_GET['year']) ? intval($_GET['year']) : CURRENT_YEAR;
$month = isset($_GET['month']) ? intval($_GET['month']) : CURRENT_MONTH;
$message = '';

// 特殊人员类型
$staffTypes = ['领导', '后勤', '校医'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->getPdo()->beginTransaction();

        if (isset($_POST['staff']) && is_array($_POST['staff'])) {
            foreach ($_POST['staff'] as $type => $data) {
                $type = trim($type);
                $totalHours = floatval($data['total_hours'] ?? 0);
                $unitPrice = floatval($data['unit_price'] ?? 0);
                if ($totalHours < 0) $totalHours = 0;
                if ($unitPrice < 0) $unitPrice = 0;

                $existing = $db->fetchOne(
                    "SELECT id FROM special_staff WHERE year = ? AND month = ? AND staff_type = ?",
                    [$year, $month, $type]
                );
                if ($existing) {
                    $db->execute(
                        "UPDATE special_staff SET total_hours = ?, unit_price = ? WHERE id = ?",
                        [$totalHours, $unitPrice, $existing['id']]
                    );
                } else {
                    $db->execute(
                        "INSERT INTO special_staff (year, month, staff_type, total_hours, unit_price) VALUES (?, ?, ?, ?, ?)",
                        [$year, $month, $type, $totalHours, $unitPrice]
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

// 获取当前数据
$staffData = [];
foreach ($staffTypes as $st) {
    $s = $db->fetchOne(
        "SELECT * FROM special_staff WHERE year = ? AND month = ? AND staff_type = ?",
        [$year, $month, $st]
    );
    $staffData[$st] = $s ?: ['total_hours' => 0, 'unit_price' => 0];
}

adminHeader('特殊人员设置');
?>

<div class="card">
    <div class="card-header">
        <h2>👤 特殊人员课时设置</h2>
        <span style="font-size:13px;color:var(--text-secondary);">
            设置领导、后勤、校医等特殊岗位的本月总课时和单价，由管理员统一填写
        </span>
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

    <?php if ($message): ?>
    <div class="toast <?= strpos($message, '成功') !== false ? 'toast-success' : 'toast-error' ?> show" style="position:fixed;top:20px;right:20px;z-index:9999;padding:14px 20px;border-radius:12px;color:#fff;font-size:14px;box-shadow:0 10px 30px rgba(0,0,0,0.15);">
        <?= strpos($message, '成功') !== false ? '✅' : '❌' ?> <?= htmlspecialchars($message) ?>
    </div>
    <script>
        setTimeout(() => {
            var t = document.querySelector('.toast');
            if (t) { t.style.transition = 'opacity 0.5s'; t.style.opacity = '0'; setTimeout(() => t.remove(), 500); }
        }, 2000);
    </script>
    <?php endif; ?>

    <form method="post">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-top:16px;">
            <?php foreach ($staffTypes as $st):
                $sd = $staffData[$st];
                $expenditure = floatval($sd['total_hours']) * floatval($sd['unit_price']);
            ?>
            <div style="background:#fff;border-radius:16px;padding:20px;box-shadow:0 2px 10px rgba(0,0,0,0.06);border:1px solid #e0e0e0;">
                <div style="font-size:18px;font-weight:700;color:var(--text);margin-bottom:16px;">
                    <?php
                    $icons = ['领导' => '👤', '后勤' => '🔧', '校医' => '🏥'];
                    echo ($icons[$st] ?? '👤') . ' ' . $st;
                    ?>
                </div>
                <div style="display:flex;flex-direction:column;gap:12px;">
                    <div>
                        <label style="font-size:13px;color:var(--text-secondary);display:block;margin-bottom:4px;">总课时数</label>
                        <input type="number" name="staff[<?= $st ?>][total_hours]" 
                               value="<?= floatval($sd['total_hours']) ?>" min="0" step="0.5"
                               style="width:100%;padding:10px 12px;border:2px solid #e0e0e0;border-radius:8px;font-size:15px;"
                               placeholder="总课时">
                    </div>
                    <div>
                        <label style="font-size:13px;color:var(--text-secondary);display:block;margin-bottom:4px;">课时单价（元）</label>
                        <input type="number" name="staff[<?= $st ?>][unit_price]" 
                               value="<?= floatval($sd['unit_price']) ?>" min="0" step="1"
                               style="width:100%;padding:10px 12px;border:2px solid #e0e0e0;border-radius:8px;font-size:15px;background:#f0fdf4;"
                               placeholder="单价">
                    </div>
                    <?php if (floatval($sd['total_hours']) > 0 || floatval($sd['unit_price']) > 0): ?>
                    <div style="padding:8px 12px;background:#f0fdf4;border-radius:8px;font-size:14px;">
                        <span style="color:var(--text-secondary);">小计支出：</span>
                        <strong style="color:#ef4444;">¥<?= number_format($expenditure, 0) ?></strong>
                        <button class="calc-toggle" onclick="toggleCalc(this)" style="font-size:12px;margin-left:8px;">▶ 明细</button>
                        <div class="calc-detail" style="margin-top:6px;">
                            <div class="row">
                                <span class="label">总课时</span>
                                <span class="value"><?= number_format(floatval($sd['total_hours']), 1) ?> h</span>
                            </div>
                            <div class="row">
                                <span class="label">× 课时单价</span>
                                <span class="value">¥<?= number_format(floatval($sd['unit_price']), 2) ?></span>
                            </div>
                            <div class="row total">
                                <span class="label">小计</span>
                                <span class="value">¥<?= number_format($expenditure, 0) ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="margin-top:24px;text-align:right;">
            <button type="submit" class="btn btn-primary">💾 保存设置</button>
        </div>
    </form>
</div>

<div class="card" style="background:#f0f4ff;">
    <div style="font-size:14px;color:var(--text-secondary);line-height:1.8;">
        <strong>📌 说明：</strong><br>
        • 普通教师课时由各年级干事在"发放统计"中填写<br>
        • 特殊人员（领导、后勤、校医）由管理员在此统一设置<br>
        • 特殊人员支出 = 总课时 × 单价，自动计入每月预算
    </div>
</div>

<script>
function toggleCalc(btn) {
    btn.classList.toggle('open');
    var detail = btn.nextElementSibling;
    if (detail) detail.classList.toggle('open');
    btn.innerHTML = btn.classList.contains('open') ? '▼ 收起' : '▶ 明细';
}
</script>

<?php adminFooter(); ?>