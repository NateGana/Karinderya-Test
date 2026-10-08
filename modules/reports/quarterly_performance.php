<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle = 'Quarterly performance';
$year = (int) ($_GET['year'] ?? date('Y'));

// --- Sales & expenses grouped by quarter (QUARTER() = 1..4) ---
$salesByQuarter = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0];
$expensesByQuarter = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0];

$stmt = $conn->prepare("SELECT QUARTER(sale_date) q, SUM(total_amount) t FROM sales WHERE YEAR(sale_date) = ? GROUP BY q");
$stmt->bind_param('i', $year);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $salesByQuarter[(int) $r['q']] = (float) $r['t']; }

$stmt = $conn->prepare("SELECT QUARTER(expense_date) q, SUM(amount) t FROM expenses WHERE YEAR(expense_date) = ? GROUP BY q");
$stmt->bind_param('i', $year);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $expensesByQuarter[(int) $r['q']] = (float) $r['t']; }

$quarterLabels = [
    1 => ['Q1', 'Jan – Mar'],
    2 => ['Q2', 'Apr – Jun'],
    3 => ['Q3', 'Jul – Sep'],
    4 => ['Q4', 'Oct – Dec'],
];

$yearSales = array_sum($salesByQuarter);
$yearExpenses = array_sum($expensesByQuarter);

// --- Monthly breakdown for the line chart (12 points, grouped into quarters by color band) ---
$stmt = $conn->prepare("SELECT MONTH(sale_date) m, SUM(total_amount) t FROM sales WHERE YEAR(sale_date) = ? GROUP BY m");
$stmt->bind_param('i', $year);
$stmt->execute();
$monthSales = array_fill(1, 12, 0.0);
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $monthSales[(int) $r['m']] = (float) $r['t']; }

$stmt = $conn->prepare("SELECT MONTH(expense_date) m, SUM(amount) t FROM expenses WHERE YEAR(expense_date) = ? GROUP BY m");
$stmt->bind_param('i', $year);
$stmt->execute();
$monthExpenses = array_fill(1, 12, 0.0);
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $monthExpenses[(int) $r['m']] = (float) $r['t']; }

$monthLabels = [];
$monthSalesArr = [];
$monthExpensesArr = [];
$monthNetArr = [];
for ($m = 1; $m <= 12; $m++) {
    $monthLabels[] = date('M', mktime(0, 0, 0, $m, 1));
    $monthSalesArr[] = round($monthSales[$m], 2);
    $monthExpensesArr[] = round($monthExpenses[$m], 2);
    $monthNetArr[] = round($monthSales[$m] - $monthExpenses[$m], 2);
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
  <h1 class="h4 mb-0">Quarterly financial performance</h1>
  <button onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer"></i> Print report</button>
</div>

<form class="row g-2 mb-3 no-print" method="get">
  <div class="col-auto">
    <label class="form-label small mb-0">Year</label>
    <input type="number" name="year" class="form-control form-control-sm" style="width:110px" value="<?= $year ?>">
  </div>
  <div class="col-auto align-self-end"><button class="btn btn-sm btn-outline-secondary">View year</button></div>
</form>

<div class="card p-4">
  <h2 class="h5 mb-1">Quarterly Financial Performance</h2>
  <p class="text-muted mb-4">Calendar year <?= $year ?></p>

  <div class="row g-3 mb-4">
    <?php foreach ($quarterLabels as $q => [$label, $range]):
        $qSales = $salesByQuarter[$q];
        $qExpenses = $expensesByQuarter[$q];
        $qNet = $qSales - $qExpenses;
        $qStatus = $qSales == 0 && $qExpenses == 0 ? 'No activity' : ($qNet < 0 ? 'Loss' : 'Gain');
        $qStatusClass = $qStatus === 'Gain' ? 'badge-status-healthy' : ($qStatus === 'Loss' ? 'badge-status-loss' : 'badge-status-none');
        $cardClass = $qStatus === 'Loss' ? 'danger' : ($qStatus === 'No activity' ? '' : 'accent');
    ?>
    <div class="col-md-3">
      <div class="stat-card <?= $cardClass ?>">
        <div class="stat-label"><i class="bi bi-calendar-range"></i> <?= $label ?> — <?= $range ?></div>
        <div class="stat-value"><?= peso($qNet) ?></div>
        <div class="stat-foot">
          <span class="badge <?= $qStatusClass ?>"><?= $qStatus ?></span>
        </div>
        <div class="stat-foot text-tabular">Sales <?= peso($qSales) ?><br>Expenses <?= peso($qExpenses) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <table class="table mb-4">
    <thead><tr><th>Quarter</th><th>Months</th><th class="text-end">Sales</th><th class="text-end">Expenses</th><th class="text-end">Net gain / (loss)</th><th class="text-end">Status</th></tr></thead>
    <tbody>
      <?php foreach ($quarterLabels as $q => [$label, $range]):
          $qSales = $salesByQuarter[$q]; $qExpenses = $expensesByQuarter[$q]; $qNet = $qSales - $qExpenses;
          $qStatus = $qSales == 0 && $qExpenses == 0 ? 'No activity' : ($qNet < 0 ? 'Loss' : 'Gain');
          $qStatusClass = $qStatus === 'Gain' ? 'badge-status-healthy' : ($qStatus === 'Loss' ? 'badge-status-loss' : 'badge-status-none');
      ?>
      <tr>
        <td class="fw-semibold"><?= $label ?></td>
        <td class="text-muted"><?= $range ?></td>
        <td class="text-end"><?= peso($qSales) ?></td>
        <td class="text-end"><?= peso($qExpenses) ?></td>
        <td class="text-end <?= $qNet < 0 ? 'text-danger' : '' ?>"><?= peso($qNet) ?></td>
        <td class="text-end"><span class="badge <?= $qStatusClass ?>"><?= $qStatus ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="fw-semibold border-top">
        <td colspan="2">Full year total</td>
        <td class="text-end"><?= peso($yearSales) ?></td>
        <td class="text-end"><?= peso($yearExpenses) ?></td>
        <td class="text-end <?= ($yearSales - $yearExpenses) < 0 ? 'text-danger' : '' ?>"><?= peso($yearSales - $yearExpenses) ?></td>
        <td class="text-end"></td>
      </tr>
    </tfoot>
  </table>

  <div class="d-flex justify-content-between align-items-center mb-2">
    <div>
      <div class="panel-title">Monthly trend, <?= $year ?></div>
      <div class="panel-sub">Sales and expenses by month — the analytics view behind the quarterly totals above</div>
    </div>
    <div class="small">
      <span class="chart-legend-dot" style="background:#1F6F4A"></span>Sales
      <span class="chart-legend-dot ms-3" style="background:#B5651D"></span>Expenses
      <span class="chart-legend-dot ms-3" style="background:#2A5D8F"></span>Net
    </div>
  </div>
  <canvas id="quarterlyChart" height="90"></canvas>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
new Chart(document.getElementById('quarterlyChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($monthLabels) ?>,
    datasets: [
      {
        label: 'Sales',
        data: <?= json_encode($monthSalesArr) ?>,
        borderColor: '#1F6F4A', backgroundColor: 'rgba(31,111,74,0.08)',
        borderWidth: 2, tension: 0.3, fill: true, pointRadius: 3, pointHoverRadius: 6,
      },
      {
        label: 'Expenses',
        data: <?= json_encode($monthExpensesArr) ?>,
        borderColor: '#B5651D', backgroundColor: 'rgba(181,101,29,0.06)',
        borderWidth: 2, tension: 0.3, fill: true, pointRadius: 3, pointHoverRadius: 6,
      },
      {
        label: 'Net',
        data: <?= json_encode($monthNetArr) ?>,
        borderColor: '#2A5D8F', borderDash: [5, 4],
        borderWidth: 1.5, tension: 0.3, fill: false, pointRadius: 2, pointHoverRadius: 5,
      },
    ],
  },
  options: {
    responsive: true,
    plugins: {
      legend: { display: false },
      tooltip: { callbacks: { label: c => c.dataset.label + ': ₱' + c.parsed.y.toLocaleString() } },
    },
    scales: {
      y: { grid: { color: '#EEF2EC' }, ticks: { callback: v => '₱' + v.toLocaleString() } },
      x: { grid: { display: false } },
    },
    interaction: { mode: 'index', intersect: false },
  },
});
</script>
