<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle = 'Archived expenses';

$search = trim($_GET['q'] ?? '');
$sql = "SELECT * FROM deleted_expenses WHERE 1=1";
$params = [];
$types = '';
if ($search !== '') {
    $sql .= " AND (description LIKE CONCAT('%', ?, '%') OR category_name LIKE CONCAT('%', ?, '%'))";
    $params[] = $search;
    $params[] = $search;
    $types .= 'ss';
}
$sql .= " ORDER BY deleted_at DESC LIMIT 200";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$archived = $stmt->get_result();

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-2">
  <h1 class="h4 mb-0"><i class="bi bi-archive text-success-emphasis me-1"></i> Archived expenses</h1>
  <a href="list.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to expenses</a>
</div>
<p class="text-muted small mb-3">Deleted expenses are kept here instead of being permanently erased. This is a read-only historical record - nothing here can be edited or restored automatically.</p>

<form class="row g-2 mb-3" method="get">
  <div class="col-auto">
    <input type="text" name="q" class="form-control form-control-sm" placeholder="Search description or category"
           value="<?= htmlspecialchars($search) ?>">
  </div>
  <div class="col-auto">
    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-funnel"></i> Search</button>
    <?php if ($search !== ''): ?><a href="archive.php" class="btn btn-sm btn-link">Clear</a><?php endif; ?>
  </div>
</form>

<div class="card p-0">
  <table class="table mb-0 align-middle">
    <thead>
      <tr>
        <th>Original date</th><th>Category</th><th>Description</th><th>Amount</th>
        <th>Originally recorded by</th><th>Deleted by</th><th>Deleted on</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($archived->num_rows === 0): ?>
        <tr><td colspan="7" class="text-muted text-center py-4">No archived expenses yet.</td></tr>
      <?php endif; ?>
      <?php while ($a = $archived->fetch_assoc()): ?>
        <tr>
          <td><?= date('M j, Y', strtotime($a['expense_date'])) ?></td>
          <td><span class="pill pill-accent"><?= htmlspecialchars($a['category_name']) ?></span></td>
          <td><?= htmlspecialchars($a['description']) ?></td>
          <td class="fw-semibold"><?= peso($a['amount']) ?></td>
          <td class="text-muted"><?= htmlspecialchars($a['recorded_by_name']) ?></td>
          <td class="text-muted"><?= htmlspecialchars($a['deleted_by_name']) ?></td>
          <td class="text-muted"><?= date('M j, Y g:i A', strtotime($a['deleted_at'])) ?></td>
        </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
