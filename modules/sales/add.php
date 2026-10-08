<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle = 'Record a sale';
$errors = [];

$saleDate = date('Y-m-d');
$paymentMethod = 'cash';
$items = [['item_name' => '', 'quantity' => '', 'unit_price' => '']];

$validPaymentMethods = ['cash', 'gcash', 'card', 'bank_transfer'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $saleDate = trim($_POST['sale_date'] ?? '');
    $paymentMethod = trim($_POST['payment_method'] ?? '');
    $itemNames  = $_POST['item_name']   ?? [];
    $itemQtys   = $_POST['quantity']    ?? [];
    $itemPrices = $_POST['unit_price']  ?? [];

    // ---- STEP 1: INPUT VALIDATION (client already checks required/min via HTML,
    // this is the authoritative server-side check) ----
    if ($saleDate === '' || !DateTime::createFromFormat('Y-m-d', $saleDate)) {
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
        $name = trim($itemNames[$i] ?? '');
        $qty  = trim($itemQtys[$i] ?? '');
        $price = trim($itemPrices[$i] ?? '');

        // Skip fully blank rows (the person may have added an extra row and not filled it).
        if ($name === '' && $qty === '' && $price === '') {
            continue;
        }

        $items[] = ['item_name' => $name, 'quantity' => $qty, 'unit_price' => $price];

        if ($name === '') {
            $errors[] = 'Item #' . ($i + 1) . ': item name is required.';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = 'Item #' . ($i + 1) . ': item name must be 100 characters or fewer.';
        }
        if ($qty === '' || !ctype_digit((string) $qty) || (int) $qty <= 0) {
            $errors[] = 'Item #' . ($i + 1) . ': quantity must be a whole number greater than zero.';
        }
        if ($price === '' || !is_numeric($price) || (float) $price < 0) {
            $errors[] = 'Item #' . ($i + 1) . ': unit price must be zero or a positive number.';
        }
    }

    if (empty($items)) {
        $errors[] = 'Add at least one item to this sale.';
    }

    // ---- STEP 2 & 3: PROCESSING + DATABASE UPDATE (ACID transaction) ----
    if (!$errors) {
        $userId = (int) currentUser()['user_id'];
        $saleTime = date('H:i:s');

        $conn->begin_transaction();
        try {
            // Open the sale header, get the new sale_id back via an OUT parameter.
            $stmt = $conn->prepare('CALL sp_open_sale(?, ?, ?, ?, @new_sale_id)');
            $stmt->bind_param('sssi', $saleDate, $saleTime, $paymentMethod, $userId);
            $stmt->execute();
            $stmt->close();
            drain_multi_results($conn);

            $saleId = (int) $conn->query('SELECT @new_sale_id AS id')->fetch_assoc()['id'];

            // Insert every line item inside the same transaction.
            foreach ($items as $item) {
                $stmt = $conn->prepare('CALL sp_add_sale_item(?, ?, ?, ?)');
                $qty = (int) $item['quantity'];
                $price = (float) $item['unit_price'];
                $stmt->bind_param('isid', $saleId, $item['item_name'], $qty, $price);
                $stmt->execute();
                $stmt->close();
                drain_multi_results($conn);
            }

            // Recompute and store the header total from its items.
            $stmt = $conn->prepare('CALL sp_finalize_sale(?, @final_total)');
            $stmt->bind_param('i', $saleId);
            $stmt->execute();
            $stmt->close();
            drain_multi_results($conn);
            $finalTotal = (float) $conn->query('SELECT @final_total AS t')->fetch_assoc()['t'];

            $conn->commit();

            // ---- STEP 4 & 5: CONFIRMATION + TRANSACTION HISTORY ----
            log_activity($conn, $userId, 'SALE_RECORDED', "Recorded sale #$saleId - " . peso($finalTotal));
            flash('success', "Sale #$saleId recorded successfully - total " . peso($finalTotal) . '.');
            header('Location: view.php?id=' . $saleId);
            exit;
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            error_log('Sale transaction failed: ' . $e->getMessage());
            $errors[] = 'Unable to record this sale. Nothing was saved - please check the items and try again.';
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-3">Record a sale</h1>

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
            <option value="<?= $m ?>" <?= $paymentMethod === $m ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$m)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <h2 class="h6 mt-4 mb-2">Items</h2>
    <div id="react-items-root">
      <p class="text-muted small">Loading item table…</p>
    </div>

    <button type="submit" class="btn btn-brand"><i class="bi bi-check-lg"></i> Save sale</button>
    <a href="list.php" class="btn btn-link">Cancel</a>
  </form>
</div>

<script>
  // The PHP-validated item values (if this page is being redisplayed after a
  // validation error) are handed to React here so nothing the user typed is lost.
  window.__INITIAL_ITEMS__ = <?= json_encode(array_map(fn($it) => [
      'name'  => $it['item_name'],
      'qty'   => $it['quantity'],
      'price' => $it['unit_price'],
  ], $items)) ?>;
</script>

<!-- React itself, loaded as plain global scripts - no build tool needed yet. -->
<script src="https://unpkg.com/react@18/umd/react.development.js" crossorigin></script>
<script src="https://unpkg.com/react-dom@18/umd/react-dom.development.js" crossorigin></script>
<script src="https://unpkg.com/@babel/standalone@7/babel.min.js" crossorigin></script>

<script>
  // This replaces the old vanilla-JS addRow/removeRow/recalc block.
  // It renders the exact same input names (item_name[], quantity[], unit_price[])
  // your PHP backend already expects, so the form submits and validates exactly
  // as before - only how the rows are drawn and recalculated has changed.
  const salesItemsJSX = `
    function ItemsTable() {
      const initial = (window.__INITIAL_ITEMS__ && window.__INITIAL_ITEMS__.length > 0)
        ? window.__INITIAL_ITEMS__
        : [{ name: '', qty: '', price: '' }];

      const [items, setItems] = React.useState(initial);

      function updateItem(index, field, value) {
        const next = items.slice();
        next[index] = Object.assign({}, next[index], { [field]: value });
        setItems(next);
      }

      function addRow() {
        setItems(items.concat([{ name: '', qty: '', price: '' }]));
      }

      function removeRow(index) {
        setItems(items.filter((_, i) => i !== index));
      }

      let grandTotal = 0;
      items.forEach(item => {
        const qty = parseFloat(item.qty) || 0;
        const price = parseFloat(item.price) || 0;
        grandTotal += qty * price;
      });

      return (
        <div>
          <table className="table">
            <thead>
              <tr>
                <th>Item name</th>
                <th style={{ width: '120px' }}>Quantity</th>
                <th style={{ width: '160px' }}>Unit price (₱)</th>
                <th style={{ width: '140px' }}>Subtotal</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {items.map((item, index) => {
                const qty = parseFloat(item.qty) || 0;
                const price = parseFloat(item.price) || 0;
                const subtotal = qty * price;
                return (
                  <tr key={index}>
                    <td>
                      <input type="text" name="item_name[]" className="form-control" maxLength="100"
                        value={item.name} onChange={e => updateItem(index, 'name', e.target.value)} />
                    </td>
                    <td>
                      <input type="number" name="quantity[]" className="form-control" min="1" step="1"
                        value={item.qty} onChange={e => updateItem(index, 'qty', e.target.value)} />
                    </td>
                    <td>
                      <input type="number" name="unit_price[]" className="form-control" min="0" step="0.01"
                        value={item.price} onChange={e => updateItem(index, 'price', e.target.value)} />
                    </td>
                    <td className="align-middle">₱{subtotal.toFixed(2)}</td>
                    <td>
                      <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => removeRow(index)}>×</button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
          <button type="button" className="btn btn-sm btn-outline-secondary mb-3" onClick={addRow}>+ Add item</button>
          <div className="text-end h5">Total: ₱{grandTotal.toFixed(2)}</div>
        </div>
      );
    }

    const root = ReactDOM.createRoot(document.getElementById('react-items-root'));
    root.render(<ItemsTable />);
  `;

  try {
    // { runtime: 'classic' } is required - see react-test.php for why.
    const compiled = Babel.transform(salesItemsJSX, {
      presets: [['react', { runtime: 'classic' }]],
    }).code;
    eval(compiled);
  } catch (err) {
    document.getElementById('react-items-root').innerHTML =
      '<div class="alert alert-danger">The item table failed to load: ' + err.message + '</div>';
  }
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
