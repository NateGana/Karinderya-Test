<?php
$here = $_SERVER['SCRIPT_NAME'];
function navActive(string $needle, string $here): string
{
    return strpos($here, $needle) !== false ? 'active' : '';
}
?>
<aside class="app-sidebar" style="width:236px;">
  <nav class="nav flex-column p-2">
    <a class="nav-link <?= navActive('/dashboard.php', $here) ?>" href="<?= base_url('dashboard.php') ?>">
      <i class="bi bi-speedometer2"></i> Dashboard
    </a>

    <div class="nav-section">Transactions</div>
    <a class="nav-link <?= navActive('/modules/sales/', $here) ?>" href="<?= base_url('modules/sales/list.php') ?>">
      <i class="bi bi-receipt"></i> Sales
    </a>
    <a class="nav-link <?= navActive('/modules/expenses/list.php', $here) ?>" href="<?= base_url('modules/expenses/list.php') ?>">
      <i class="bi bi-wallet2"></i> Expenses
    </a>
    <a class="nav-link <?= navActive('/modules/expenses/archive.php', $here) ?>" href="<?= base_url('modules/expenses/archive.php') ?>">
      <i class="bi bi-archive"></i> Archived expenses
    </a>

    <?php if (isAdmin()): ?>
    <div class="nav-section">Setup</div>
    <a class="nav-link <?= navActive('/modules/categories/', $here) ?>" href="<?= base_url('modules/categories/list.php') ?>">
      <i class="bi bi-tags"></i> Expense categories
    </a>
    <a class="nav-link <?= navActive('/modules/users/', $here) ?>" href="<?= base_url('modules/users/list.php') ?>">
      <i class="bi bi-people"></i> User accounts
    </a>
    <?php endif; ?>

    <div class="nav-section">Reports</div>
    <a class="nav-link <?= navActive('daily_sales.php', $here) ?>" href="<?= base_url('modules/reports/daily_sales.php') ?>">
      <i class="bi bi-calendar-day"></i> Daily sales summary
    </a>
    <a class="nav-link <?= navActive('monthly_performance.php', $here) ?>" href="<?= base_url('modules/reports/monthly_performance.php') ?>">
      <i class="bi bi-calendar3"></i> Monthly performance
    </a>
    <a class="nav-link <?= navActive('quarterly_performance.php', $here) ?>" href="<?= base_url('modules/reports/quarterly_performance.php') ?>">
      <i class="bi bi-bar-chart-line"></i> Quarterly performance
    </a>
    <a class="nav-link <?= navActive('category_breakdown.php', $here) ?>" href="<?= base_url('modules/reports/category_breakdown.php') ?>">
      <i class="bi bi-pie-chart"></i> Category breakdown
    </a>
    <a class="nav-link <?= navActive('profit_statement.php', $here) ?>" href="<?= base_url('modules/reports/profit_statement.php') ?>">
      <i class="bi bi-graph-up"></i> Profit estimation
    </a>
  </nav>
</aside>
