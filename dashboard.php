<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pageTitle = 'Dashboard';
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

// --- Today's summary via sp_get_daily_summary (IN date, OUT x4) ---
$totalSales = $totalExpenses = $netProfit = 0.0;
$profitStatus = 'No sales yet';

try {
    $stmt = $conn->prepare(
        'CALL sp_get_daily_summary(?, @total_sales, @total_expenses, @net_profit, @profit_status)'
    );
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $stmt->close();
    drain_multi_results($conn);

    $res = $conn->query(
        'SELECT @total_sales AS total_sales, @total_expenses AS total_expenses,
                @net_profit AS net_profit, @profit_status AS profit_status'
    );
    $row = $res->fetch_assoc();
    $totalSales    = (float) $row['total_sales'];
    $totalExpenses = (float) $row['total_expenses'];
    $netProfit     = (float) $row['net_profit'];
    $profitStatus  = $row['profit_status'];
} catch (mysqli_sql_exception $e) {
    error_log('Dashboard summary error: ' . $e->getMessage());
}

// --- Yesterday's totals, purely to compute the trend-delta pills ---
$yTotalSales = (float) $conn->query("SELECT COALESCE(SUM(total_amount),0) t FROM sales WHERE sale_date = '$yesterday'")->fetch_assoc()['t'];
$yTotalExpenses = (float) $conn->query("SELECT COALESCE(SUM(amount),0) t FROM expenses WHERE expense_date = '$yesterday'")->fetch_assoc()['t'];
$yNetProfit = $yTotalSales - $yTotalExpenses;

function trend_pill(float $today, float $yesterday, bool $lowerIsBetter = false): string
{
    if ($yesterday == 0.0) {
        if ($today == 0.0) return '<span class="trend-pill flat"><i class="bi bi-dash"></i> No change</span>';
        $cls = $lowerIsBetter ? 'down' : 'up';
        return '<span class="trend-pill ' . $cls . '"><i class="bi bi-arrow-up-short"></i> New today</span>';
    }
    $pct = (($today - $yesterday) / abs($yesterday)) * 100;
    $improving = $lowerIsBetter ? $pct < 0 : $pct > 0;
    if (abs($pct) < 0.5) return '<span class="trend-pill flat"><i class="bi bi-dash"></i> Flat vs yesterday</span>';
    $icon = $pct > 0 ? 'bi-arrow-up-short' : 'bi-arrow-down-short';
    $cls = $improving ? 'up' : 'down';
    return '<span class="trend-pill ' . $cls . '"><i class="bi ' . $icon . '"></i> ' . number_format(abs($pct), 1) . '% vs yesterday</span>';
}

$statusClass = [
    'Healthy'      => 'badge-status-healthy',
    'Low margin'   => 'badge-status-low',
    'Loss'         => 'badge-status-loss',
    'No sales yet' => 'badge-status-none',
][$profitStatus] ?? 'badge-status-none';

// --- Supporting counts ---
$salesTodayCount = (int) $conn->query("SELECT COUNT(*) c FROM sales WHERE sale_date = CURDATE()")->fetch_assoc()['c'];
$expensesTodayCount = (int) $conn->query("SELECT COUNT(*) c FROM expenses WHERE expense_date = CURDATE()")->fetch_assoc()['c'];
$monthSales = (float) $conn->query(
    "SELECT COALESCE(SUM(total_amount),0) t FROM sales WHERE YEAR(sale_date)=YEAR(CURDATE()) AND MONTH(sale_date)=MONTH(CURDATE())"
)->fetch_assoc()['t'];
$monthExpenses = (float) $conn->query(
    "SELECT COALESCE(SUM(amount),0) t FROM expenses WHERE YEAR(expense_date)=YEAR(CURDATE()) AND MONTH(expense_date)=MONTH(CURDATE())"
)->fetch_assoc()['t'];

// --- Last 14 days, sales vs expenses, for the analytics line chart ---
$chartLabels = [];
$chartSales = [];
$chartExpenses = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $chartLabels[] = date('M j', strtotime($d));
    $s = (float) $conn->query("SELECT COALESCE(SUM(total_amount),0) t FROM sales WHERE sale_date = '$d'")->fetch_assoc()['t'];
    $e = (float) $conn->query("SELECT COALESCE(SUM(amount),0) t FROM expenses WHERE expense_date = '$d'")->fetch_assoc()['t'];
    $chartSales[] = $s;
    $chartExpenses[] = $e;
}

// --- Recent activity feed ---
$activity = $conn->query(
    "SELECT a.action, a.details, a.created_at,
            COALESCE(u.full_name, a.actor_name, 'Deleted user') AS full_name
     FROM activity_logs a LEFT JOIN users u ON u.user_id = a.user_id
     ORDER BY a.created_at DESC LIMIT 8"
);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-end mb-4">
  <div>
    <h1 class="h4 mb-1">Good day, <?= htmlspecialchars(currentUser()['full_name']) ?></h1>
    <p class="text-muted mb-0"><?= date('l, F j, Y') ?></p>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="stat-card accent">
      <div class="stat-label"><i class="bi bi-receipt"></i> Today's sales</div>
      <div class="stat-value"><?= peso($totalSales) ?></div>
      <div class="stat-foot">
        <?= trend_pill($totalSales, $yTotalSales) ?>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card warn">
      <div class="stat-label"><i class="bi bi-wallet2"></i> Today's expenses</div>
      <div class="stat-value"><?= peso($totalExpenses) ?></div>
      <div class="stat-foot">
        <?= trend_pill($totalExpenses, $yTotalExpenses, true) ?>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card <?= $netProfit < 0 ? 'danger' : 'accent' ?>">
      <div class="stat-label"><i class="bi bi-graph-up-arrow"></i> Today's net profit</div>
      <div class="stat-value"><?= peso($netProfit) ?></div>
      <div class="stat-foot"><span class="badge <?= $statusClass ?>"><?= htmlspecialchars($profitStatus) ?></span></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card info">
      <div class="stat-label"><i class="bi bi-calendar3"></i> This month so far</div>
      <div class="stat-value"><?= peso($monthSales - $monthExpenses) ?></div>
      <div class="stat-foot">Sales <?= peso($monthSales) ?> &middot; Expenses <?= peso($monthExpenses) ?></div>
    </div>
  </div>
</div>

<div class="card chart-card mb-4">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div>
      <div class="panel-title">Sales vs. expenses — last 14 days</div>
      <div class="panel-sub">Daily totals, most recent on the right</div>
    </div>
    <div class="small">
      <span class="chart-legend-dot" style="background:#1F6F4A"></span>Sales
      <span class="chart-legend-dot ms-3" style="background:#B5651D"></span>Expenses
    </div>
  </div>
  <canvas id="trendChart" height="80"></canvas>
</div>

<div class="row g-3">
  <div class="col-md-7">
    <div class="card p-3">
      <div class="panel-title mb-2">Quick actions</div>
      <div class="d-flex gap-2 flex-wrap">
        <a href="<?= base_url('modules/sales/add.php') ?>" class="btn btn-brand btn-sm"><i class="bi bi-plus-lg"></i> Record a sale</a>
        <a href="<?= base_url('modules/expenses/add.php') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-plus-lg"></i> Record an expense</a>
        <a href="<?= base_url('modules/reports/daily_sales.php') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-text"></i> Daily report</a>
        <a href="<?= base_url('modules/reports/quarterly_performance.php') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-bar-chart-line"></i> Quarterly report</a>
        <?php if (isAdmin()): ?>
        <a href="<?= base_url('modules/categories/add.php') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-tag"></i> Add category</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-5">
    <div class="card p-3">
      <div class="panel-title mb-2">Recent activity</div>
      <ul class="list-unstyled small mb-0">
        <?php if ($activity->num_rows === 0): ?>
          <li class="text-muted">No activity yet.</li>
        <?php endif; ?>
        <?php while ($a = $activity->fetch_assoc()): ?>
          <li class="mb-2 pb-2 border-bottom">
            <strong><?= htmlspecialchars($a['full_name']) ?></strong> — <?= htmlspecialchars($a['details']) ?>
            <div class="text-muted"><?= date('M j, g:i A', strtotime($a['created_at'])) ?></div>
          </li>
        <?php endwhile; ?>
      </ul>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($chartLabels) ?>,
    datasets: [
      {
        label: 'Sales',
        data: <?= json_encode($chartSales) ?>,
        borderColor: '#1F6F4A',
        backgroundColor: 'rgba(31,111,74,0.08)',
        borderWidth: 2, tension: 0.35, fill: true, pointRadius: 2, pointHoverRadius: 5,
      },
      {
        label: 'Expenses',
        data: <?= json_encode($chartExpenses) ?>,
        borderColor: '#B5651D',
        backgroundColor: 'rgba(181,101,29,0.06)',
        borderWidth: 2, tension: 0.35, fill: true, pointRadius: 2, pointHoverRadius: 5,
      },
    ],
  },
  options: {
    responsive: true,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, grid: { color: '#EEF2EC' }, ticks: { callback: v => '₱' + v.toLocaleString() } },
      x: { grid: { display: false } },
    },
    interaction: { mode: 'index', intersect: false },
  },
});
</script>
