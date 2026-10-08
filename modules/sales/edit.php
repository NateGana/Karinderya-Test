<?php
require_once __DIR__ . '/../../includes/auth.php';
// Same rule as deleting a sale: only the Administrator may change a recorded sale.
requireRole('admin');

$id = (int) ($_GET['id'] ?? 0);
$errors = [];
$validPaymentMethods = ['cash', 'gcash', 'card', 'bank_transfer'];

// ---- Load the sale being edited. It must exist (guards against ID tampering). ----
$sale = null;
$items = [];
if ($id > 0) {
    try {
        $stmt = $conn->prepare('SELECT sale_id, sale_date, payment_method, total_amount FROM sales WHERE sale_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $sale = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($sale) {
            $stmt = $conn->prepare('SELECT item_name, quantity, unit_price FROM sale_items WHERE sale_id = ? ORDER BY sale_item_id');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    } catch (mysqli_sql_exception $e) {
        error_log('Sale edit load failed: ' . $e->getMessage());
        $sale = null;
    }
}

if (!$sale) {
    flash('error', 'That sale could not be found.');
    header('Location: list.php');
    exit;
}

$pageTitle = 'Edit sale #' . $id;
$saleDate = $sale['sale_date'];
$paymentMethod = $sale['payment_method'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Read the submitted values safely (a forged request could send arrays/strings in the wrong place).
    $saleDate = is_string($_POST['sale_date'] ?? null) ? trim($_POST['sale_date']) : '';
    $paymentMethod = is_string($_POST['payment_method'] ?? null) ? trim($_POST['payment_method']) : '';
    $itemNames = is_array($_POST['item_name'] ?? null) ? $_POST['item_name'] : [];
    $itemQtys = is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : [];
    $itemPrices = is_array($_POST['unit_price'] ?? null) ? $_POST['unit_price'] : [];
    $cell = fn(array $arr, int $i): string => (isset($arr[$i]) && is_string($arr[$i])) ? trim($arr[$i]) : '';

    // ---- STEP 1: INPUT VALIDATION (same rules as recording a sale in add.php) ----
    $parsedDate = DateTime::createFromFormat('Y-m-d', $saleDate);
    if ($saleDate === '' || !$parsedDate || $parsedDate->format('Y-m-d') !== $saleDate) {
        $errors[] = 'Please provide a valid sale date.';
    } elseif ($saleDate > date('Y-m-d')) {
        $errors[] = 'Sale date cannot be in the future.';
    }
    if (!in_array($paymentMethod, $validPaymentMethods, true)) {
        $errors[] = 'Please select a valid payment method.';
    }

    $items = [];
    $rowCount = max(count($itemNames), count($itemQtys), count($itemPrices));
    for ($i = 0; $i < $rowCount; $i++) {
        $name = $cell($itemNames, $i);
        $qty = $cell($itemQtys, $i);
        $price = $cell($itemPrices, $i);

        // Skip fully blank rows (an extra row the person did not fill in).
        if ($name === '' && $qty === '' && $price === '') {
            continue;
        }

        $items[] = ['item_name' => $name, 'quantity' => $qty, 'unit_price' => $price];

        if ($name === '') {
            $errors[] = 'Item #' . ($i + 1) . ': item name is required.';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = 'Item #' . ($i + 1) . ': item name must be 100 characters or fewer.';
        }
        if ($qty === '' || !ctype_digit($qty) || (int) $qty <= 0) {
            $errors[] = 'Item #' . ($i + 1) . ': quantity must be a whole number greater than zero.';
        }
        if ($price === '' || !is_numeric($price) || (float) $price < 0) {
            $errors[] = 'Item #' . ($i + 1) . ': unit price must be zero or a positive number.';
        }
    }

    if (empty($items)) {
        $errors[] = 'Add at least one item to this sale.';
    }

    // ---- STEP 2 & 3: PROCESSING + DATABASE UPDATE (all-or-nothing transaction) ----
    // Validation is finished before anything is touched. Inside the transaction the old
    // items are only removed together with inserting the new ones; if anything fails,
    // rollback() restores the original sale exactly as it was.
    if (!$errors) {
        $userId = (int) currentUser()['user_id'];

        $conn->begin_transaction();
        try {
            // Lock the sale row and confirm it still exists (e.g. not deleted in another tab).
            $stmt = $conn->prepare('SELECT total_amount FROM sales WHERE sale_id = ? FOR UPDATE');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$current) {
                $conn->rollback();
                flash('error', 'That sale could not be found.');
                header('Location: list.php');
                exit;
            }
            $oldTotal = (float) $current['total_amount'];

            // Update the sale header (date and payment method). recorded_by and sale_time stay as recorded.
            $stmt = $conn->prepare('UPDATE sales SET sale_date = ?, payment_method = ? WHERE sale_id = ?');
            $stmt->bind_param('ssi', $saleDate, $paymentMethod, $id);
            $stmt->execute();
            $stmt->close();

            // Rebuild the line items: remove the old rows, then insert the edited ones.
            $stmt = $conn->prepare('DELETE FROM sale_items WHERE sale_id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            foreach ($items as $item) {
                $stmt = $conn->prepare('CALL sp_add_sale_item(?, ?, ?, ?)');
                $qty = (int) $item['quantity'];
                $price = (float) $item['unit_price'];
                $stmt->bind_param('isid', $id, $item['item_name'], $qty, $price);
                $stmt->execute();
                $stmt->close();
                drain_multi_results($conn);
            }

            // Recompute and store the header total from its (new) items.
            $stmt = $conn->prepare('CALL sp_finalize_sale(?, @final_total)');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            drain_multi_results($conn);
            $finalTotal = (float) $conn->query('SELECT @final_total AS t')->fetch_assoc()['t'];

            $conn->commit();

            // ---- STEP 4 & 5: CONFIRMATION + TRANSACTION HISTORY ----
            log_activity($conn, $userId, 'SALE_UPDATED', "Updated sale #$id - total " . peso($oldTotal) . ' to ' . peso($finalTotal));
            flash('success', "Sale #$id updated successfully - total " . peso($finalTotal) . '.');
            header('Location: view.php?id=' . $id);
            exit;
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            error_log('Sale update failed: ' . $e->getMessage());
            $errors[] = 'Unable to update this sale. Nothing was changed - please check the items and try again.';
        }
    }
}

// Always show at least one (blank) row so the table is usable.
if (empty($items)) {
    $items = [['item_name' => '', 'quantity' => '', 'unit_price' => '']];
}

$grandTotal = 0.0;
foreach ($items as $it) {
    $grandTotal += (is_numeric($it['quantity']) ? (float) $it['quantity'] : 0) * (is_numeric($it['unit_price']) ? (float) $it['unit_price'] : 0);
}

include __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-3">Edit sale #<?= $id ?></h1>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<div class="card p-4">
  <form method="post" id="saleForm" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">Sale date *</label>
        <input type="date" name="sale_date" class="form-control" required max="<?= date('Y-m-d') ?>"
               value="<?= htmlspecialchars($saleDate) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Payment method *</label>
        <select name="payment_method" class="form-select" required>
          <?php foreach ($validPaymentMethods as $m): ?>
            <option value="<?= $m ?>" <?= $paymentMethod === $m ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $m)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <h2 class="h6 mt-4 mb-2">Items</h2>
    <table class="table">
      <thead>
        <tr>
          <th>Item name</th>
          <th style="width: 120px;">Quantity</th>
          <th style="width: 160px;">Unit price (₱)</th>
          <th style="width: 140px;">Subtotal</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="itemsBody">
        <?php foreach ($items as $it):
            $rowQty = is_numeric($it['quantity']) ? (float) $it['quantity'] : 0;
            $rowPrice = is_numeric($it['unit_price']) ? (float) $it['unit_price'] : 0;
        ?>
        <tr>
          <td><input type="text" name="item_name[]" class="form-control" maxlength="100" value="<?= htmlspecialchars((string) $it['item_name']) ?>"></td>
          <td><input type="number" name="quantity[]" class="form-control" min="1" step="1" value="<?= htmlspecialchars((string) $it['quantity']) ?>"></td>
          <td><input type="number" name="unit_price[]" class="form-control" min="0" step="0.01" value="<?= htmlspecialchars((string) $it['unit_price']) ?>"></td>
          <td class="align-middle line-subtotal">₱<?= number_format($rowQty * $rowPrice, 2) ?></td>
          <td><button type="button" class="btn btn-sm btn-outline-danger remove-row">×</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="addRowBtn">+ Add item</button>
    <div class="text-end h5" id="grandTotal">Total: ₱<?= number_format($grandTotal, 2) ?></div>

    <button type="submit" class="btn btn-brand"><i class="bi bi-check-lg"></i> Save changes</button>
    <a href="view.php?id=<?= $id ?>" class="btn btn-link">Cancel</a>
  </form>
</div>

<!-- Blank row used by the "+ Add item" button -->
<template id="itemRowTemplate">
  <tr>
    <td><input type="text" name="item_name[]" class="form-control" maxlength="100" value=""></td>
    <td><input type="number" name="quantity[]" class="form-control" min="1" step="1" value=""></td>
    <td><input type="number" name="unit_price[]" class="form-control" min="0" step="0.01" value=""></td>
    <td class="align-middle line-subtotal">₱0.00</td>
    <td><button type="button" class="btn btn-sm btn-outline-danger remove-row">×</button></td>
  </tr>
</template>

<script>
  // Plain JavaScript (no extra libraries): add/remove rows and keep the subtotals and
  // total up to date while typing. The server still recomputes everything on save.
  (function () {
    var body = document.getElementById('itemsBody');
    var totalEl = document.getElementById('grandTotal');

    function money(n) { return '₱' + n.toFixed(2); }

    function recalc() {
      var grand = 0;
      body.querySelectorAll('tr').forEach(function (row) {
        var qty = parseFloat(row.querySelector('input[name="quantity[]"]').value) || 0;
        var price = parseFloat(row.querySelector('input[name="unit_price[]"]').value) || 0;
        var sub = qty * price;
        row.querySelector('.line-subtotal').textContent = money(sub);
        grand += sub;
      });
      totalEl.textContent = 'Total: ' + money(grand);
    }

    body.addEventListener('input', recalc);

    body.addEventListener('click', function (e) {
      var btn = e.target.closest('.remove-row');
      if (btn) {
        btn.closest('tr').remove();
        recalc();
      }
    });

    document.getElementById('addRowBtn').addEventListener('click', function () {
      var tpl = document.getElementById('itemRowTemplate');
      body.appendChild(tpl.content.cloneNode(true));
      recalc();
    });

    recalc();
  })();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
