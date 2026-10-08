<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle = 'Monthly performance';
$year  = (int) ($_GET['year']  ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('n'));
if ($month < 1 || $month > 12) { $month = (int) date('n'); }

// Daily sales totals for the month, with a ROLLUP grand-total row.
$stmt = $conn->prepare(
    "SELECT DAY(sale_date) AS d, SUM(total_amount) AS total
     FROM sales WHERE YEAR(sale_date)=? AND MONTH(sale_date)=?
     GROUP BY DAY(sale_date) WITH ROLLUP"
);
$stmt->bind_param('ii', $year, $month);
$stmt->execute();
$salesByDay = [];
$salesGrandTotal = 0.0;
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    if ($r['d'] === null) { $salesGrandTotal = (float) $r['total']; continue; }
    $salesByDay[(int) $r['d']] = (float) $r['total'];
}

// Daily expense totals for the month, with a ROLLUP grand-total row.
$stmt = $conn->prepare(
    "SELECT DAY(expense_date) AS d, SUM(amount) AS total
     FROM expenses WHERE YEAR(expense_date)=? AND MONTH(expense_date)=?
     GROUP BY DAY(expense_date) WITH ROLLUP"
);
$stmt->bind_param('ii', $year, $month);
$stmt->execute();
$expensesByDay = [];
$expensesGrandTotal = 0.0;
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    if ($r['d'] === null) { $expensesGrandTotal = (float) $r['total']; continue; }
    $expensesByDay[(int) $r['d']] = (float) $r['total'];
}

$daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
$monthName = date('F', mktime(0, 0, 0, $month, 1, $year));

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
  <h1 class="h4 mb-0"><i class="bi bi-calendar3 text-success-emphasis me-1"></i> Monthly financial performance</h1>
  <button onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer"></i> Print report</button>
</div>

<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-auto">
    <select name="month" class="form-select form-select-sm">
      <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
      <?php endfor; ?>
    </select>
  </div>
  <div class="col-auto">
    <input type="number" name="year" class="form-control form-control-sm" style="width:100px" value="<?= $year ?>">
  </div>
  <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">View month</button></div>
</form>

<div class="card p-4">
  <h2 class="h5 mb-1">Monthly Financial Performance</h2>
  <p class="text-muted mb-4"><?= $monthName . ' ' . $year ?></p>

  <div class="row g-3 mb-4">
    <div class="col-4"><div class="stat-card accent"><div class="stat-label"><i class="bi bi-receipt"></i> Total sales</div><div class="stat-value"><?= peso($salesGrandTotal) ?></div></div></div>
    <div class="col-4"><div class="stat-card warn"><div class="stat-label"><i class="bi bi-wallet2"></i> Total expenses</div><div class="stat-value"><?= peso($expensesGrandTotal) ?></div></div></div>
    <div class="col-4"><div class="stat-card <?= ($salesGrandTotal - $expensesGrandTotal) < 0 ? 'danger' : 'accent' ?>"><div class="stat-label"><i class="bi bi-graph-up-arrow"></i> Net profit</div><div class="stat-value"><?= peso($salesGrandTotal - $expensesGrandTotal) ?></div></div></div>
  </div>

  <table class="table">
    <thead><tr><th>Day</th><th class="text-end">Sales</th><th class="text-end">Expenses</th><th class="text-end">Net</th></tr></thead>
    <tbody>
      <?php for ($d = 1; $d <= $daysInMonth; $d++):
        $s = $salesByDay[$d] ?? 0.0;
        $e = $expensesByDay[$d] ?? 0.0;
        if ($s == 0 && $e == 0) continue; ?>
      <tr>
        <td><?= $monthName . ' ' . $d ?></td>
        <td class="text-end"><?= peso($s) ?></td>
        <td class="text-end"><?= peso($e) ?></td>
        <td class="text-end <?= ($s - $e) < 0 ? 'text-danger' : '' ?>"><?= peso($s - $e) ?></td>
      </tr>
      <?php endfor; ?>
      <?php if ($salesGrandTotal == 0 && $expensesGrandTotal == 0): ?>
        <tr><td colspan="4" class="text-muted text-center py-3">No activity recorded for this month.</td></tr>
      <?php endif; ?>
    </tbody>
    <tfoot>
      <tr class="fw-semibold">
        <td>Grand total</td>
        <td class="text-end"><?= peso($salesGrandTotal) ?></td>
        <td class="text-end"><?= peso($expensesGrandTotal) ?></td>
        <td class="text-end"><?= peso($salesGrandTotal - $expensesGrandTotal) ?></td>
      </tr>
    </tfoot>
  </table>

  <div class="d-flex justify-content-between align-items-center mb-2">
    <div>
      <div class="panel-title">Daily trend, <?= $monthName . ' ' . $year ?></div>
      <div class="panel-sub">Same figures as the table above, visualized</div>
    </div>
    <div class="small">
      <span class="chart-legend-dot" style="background:#1F6F4A"></span>Sales
      <span class="chart-legend-dot ms-3" style="background:#B5651D"></span>Expenses
    </div>
  </div>
  <canvas id="monthlyChart" height="80"></canvas>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
new Chart(document.getElementById('monthlyChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode(range(1, $daysInMonth)) ?>,
    datasets: [
      {
        label: 'Sales',
        data: <?= json_encode(array_map(fn($d) => round($salesByDay[$d] ?? 0, 2), range(1, $daysInMonth))) ?>,
        borderColor: '#1F6F4A', backgroundColor: 'rgba(31,111,74,0.08)',
        borderWidth: 2, tension: 0.3, fill: true, pointRadius: 2, pointHoverRadius: 5,
      },
      {
        label: 'Expenses',
        data: <?= json_encode(array_map(fn($d) => round($expensesByDay[$d] ?? 0, 2), range(1, $daysInMonth))) ?>,
        borderColor: '#B5651D', backgroundColor: 'rgba(181,101,29,0.06)',
        borderWidth: 2, tension: 0.3, fill: true, pointRadius: 2, pointHoverRadius: 5,
      },
    ],
  },
  options: {
    responsive: true,
    plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => c.dataset.label + ': ₱' + c.parsed.y.toLocaleString() } } },
    scales: {
      y: { beginAtZero: true, grid: { color: '#EEF2EC' }, ticks: { callback: v => '₱' + v.toLocaleString() } },
      x: { grid: { display: false }, title: { display: true, text: 'Day of month' } },
    },
    interaction: { mode: 'index', intersect: false },
  },
});
</script>
