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

        // 1. 保存分年级上课节数
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

        // 2. 保存多套收费方案
        if (isset($_POST['plans']) && is_array($_POST['plans'])) {
            // 获取已有方案的ID列表
            $existingPlans = $db->fetchAll(
                "SELECT id, plan_name FROM fee_plans WHERE year = ? AND month = ?",
                [$year, $month]
            );
            $existingIds = [];
            $processedIds = [];

            foreach ($_POST['plans'] as $planId => $planData) {
                $planName = trim($planData['name'] ?? '');
                $unitPrice = floatval($planData['unit_price'] ?? 0);
                $capPrice = floatval($planData['cap_price'] ?? 0);
                $sortOrder = intval($planData['sort_order'] ?? 0);

                if (empty($planName)) continue;

                // 判断是新增还是更新
                if (strpos($planId, 'new_') === 0) {
                    // 新增方案
                    $db->execute(
                        "INSERT INTO fee_plans (year, month, plan_name, unit_price, cap_price, sort_order) VALUES (?, ?, ?, ?, ?, ?)",
                        [$year, $month, $planName, $unitPrice, $capPrice, $sortOrder]
                    );
                    $processedIds[] = $db->lastInsertId();
                } else {
                    $pid = intval($planId);
                    if ($pid > 0) {
                        $db->execute(
                            "UPDATE fee_plans SET plan_name = ?, unit_price = ?, cap_price = ?, sort_order = ? WHERE id = ? AND year = ? AND month = ?",
                            [$planName, $unitPrice, $capPrice, $sortOrder, $pid, $year, $month]
                        );
                        $processedIds[] = $pid;
                    }
                }
            }

            // 删除未被提交的方案（用户删除了）
            foreach ($existingPlans as $ep) {
                if (!in_array($ep['id'], $processedIds)) {
                    $db->execute("DELETE FROM fee_plans WHERE id = ?", [$ep['id']]);
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

// 获取所有年级
$grades = $db->fetchAll("SELECT * FROM grades ORDER BY sort_order");
$settings = [];
foreach ($grades as $g) {
    $s = $db->fetchOne("SELECT * FROM grade_settings WHERE grade_id = ? AND year = ? AND month = ?", [$g['id'], $year, $month]);
    $settings[$g['id']] = $s ?: ['teaching_days' => 0];
}

// 获取多套收费方案
$feePlans = $db->fetchAll(
    "SELECT * FROM fee_plans WHERE year = ? AND month = ? ORDER BY sort_order ASC, id ASC",
    [$year, $month]
);

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

    <form method="post" id="settingsForm">
        <!-- 多套收费方案 -->
        <div class="card" style="margin-bottom:16px;background:#fefce8;">
            <div class="card-header">
                <h3>📊 收费方案（可设置多套供参考）</h3>
            </div>
            <div id="plansContainer">
                <?php if (empty($feePlans)): ?>
                <div class="plan-row" data-index="0" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);">
                    <input type="text" name="plans[new_0][name]" value="方案A" placeholder="方案名称" style="width:100px;padding:8px 10px;border:2px solid #e0e0e0;border-radius:8px;font-size:14px;">
                    <input type="number" name="plans[new_0][unit_price]" value="13" min="0" step="0.5" placeholder="单价" style="width:100px;padding:8px 10px;border:2px solid #e0e0e0;border-radius:8px;font-size:14px;">
                    <span style="font-size:13px;color:#666;">元/节</span>
                    <input type="number" name="plans[new_0][cap_price]" value="190" min="0" step="1" placeholder="封顶" style="width:100px;padding:8px 10px;border:2px solid #e0e0e0;border-radius:8px;font-size:14px;">
                    <span style="font-size:13px;color:#666;">元/人封顶</span>
                    <input type="hidden" name="plans[new_0][sort_order]" value="1">
                    <button type="button" class="btn btn-danger btn-sm" onclick="this.closest('.plan-row').remove()" style="padding:6px 12px;font-size:13px;">✕ 删除</button>
                </div>
                <?php else: ?>
                <?php $planIdx = 0; foreach ($feePlans as $fp): $planIdx++; ?>
                <div class="plan-row" data-index="<?= $fp['id'] ?>" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);">
                    <input type="text" name="plans[<?= $fp['id'] ?>][name]" value="<?= htmlspecialchars($fp['plan_name']) ?>" placeholder="方案名称" style="width:100px;padding:8px 10px;border:2px solid #e0e0e0;border-radius:8px;font-size:14px;">
                    <input type="number" name="plans[<?= $fp['id'] ?>][unit_price]" value="<?= floatval($fp['unit_price']) ?>" min="0" step="0.5" placeholder="单价" style="width:100px;padding:8px 10px;border:2px solid #e0e0e0;border-radius:8px;font-size:14px;">
                    <span style="font-size:13px;color:#666;">元/节</span>
                    <input type="number" name="plans[<?= $fp['id'] ?>][cap_price]" value="<?= floatval($fp['cap_price']) ?>" min="0" step="1" placeholder="封顶" style="width:100px;padding:8px 10px;border:2px solid #e0e0e0;border-radius:8px;font-size:14px;">
                    <span style="font-size:13px;color:#666;">元/人封顶</span>
                    <input type="hidden" name="plans[<?= $fp['id'] ?>][sort_order]" value="<?= $planIdx ?>">
                    <button type="button" class="btn btn-danger btn-sm" onclick="this.closest('.plan-row').remove()" style="padding:6px 12px;font-size:13px;">✕ 删除</button>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div style="margin-top:10px;">
                <button type="button" class="btn btn-outline btn-sm" onclick="addPlan()">➕ 添加方案</button>
            </div>
            <div style="margin-top:10px;font-size:13px;color:var(--text-secondary);line-height:1.6;">
                <strong>💡 说明：</strong>可设置多套收费方案供领导参考（如方案A:13元/节、方案B:15元/节等），<br>
                概览页和统计页将同时显示所有方案的计算结果。
            </div>
        </div>

        <!-- 分年级上课节数 -->
        <h3 style="margin-bottom:12px;font-size:16px;">📚 各年级上课节数</h3>
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

<script>
let planCounter = <?= max(count($feePlans), 1) ?>;

function addPlan() {
    planCounter++;
    const names = ['方案A', '方案B', '方案C', '方案D', '方案E'];
    const name = names[planCounter - 1] || '方案' + String.fromCharCode(64 + planCounter);
    const container = document.getElementById('plansContainer');
    const div = document.createElement('div');
    div.className = 'plan-row';
    div.style.cssText = 'display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);';
    div.innerHTML = `
        <input type="text" name="plans[new_${planCounter}][name]" value="${name}" placeholder="方案名称" style="width:100px;padding:8px 10px;border:2px solid #e0e0e0;border-radius:8px;font-size:14px;">
        <input type="number" name="plans[new_${planCounter}][unit_price]" value="13" min="0" step="0.5" placeholder="单价" style="width:100px;padding:8px 10px;border:2px solid #e0e0e0;border-radius:8px;font-size:14px;">
        <span style="font-size:13px;color:#666;">元/节</span>
        <input type="number" name="plans[new_${planCounter}][cap_price]" value="190" min="0" step="1" placeholder="封顶" style="width:100px;padding:8px 10px;border:2px solid #e0e0e0;border-radius:8px;font-size:14px;">
        <span style="font-size:13px;color:#666;">元/人封顶</span>
        <input type="hidden" name="plans[new_${planCounter}][sort_order]" value="${planCounter}">
        <button type="button" class="btn btn-danger btn-sm" onclick="this.closest('.plan-row').remove()" style="padding:6px 12px;font-size:13px;">✕ 删除</button>
    `;
    container.appendChild(div);
}
</script>

<div class="card" style="background:#f0f4ff;">
    <div style="font-size:14px;color:var(--text-secondary);line-height:1.8;">
        <strong>📌 说明：</strong><br>
        • <strong>上课节数</strong>：该年级本月实际上课节数，同一年级所有班级统一<br>
        • <strong>收费方案</strong>：可设置多套方案，每套包含单价和封顶价，概览和统计页同时展示<br>
        • 费用计算方式：人数 × 单价，但每个学生不超过封顶价
    </div>
</div>

<?php adminFooter(); ?>