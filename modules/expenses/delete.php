<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin');

require_post_with_csrf('list.php');

$id = (int) ($_POST['id'] ?? 0);

$conn->begin_transaction();
try {
    // Grab the full expense (plus readable category/user names) before it's gone.
    $stmt = $conn->prepare(
        'SELECT e.expense_id, e.expense_date, e.category_id, c.category_name, e.description, e.amount,
                e.recorded_by, u.full_name AS recorded_by_name
         FROM expenses e
         JOIN expense_categories c ON c.category_id = e.category_id
         JOIN users u ON u.user_id = e.recorded_by
         WHERE e.expense_id = ?'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $expense = $stmt->get_result()->fetch_assoc();

    if (!$expense) {
        $conn->rollback();
        flash('error', 'That expense record could not be found.');
        header('Location: list.php');
        exit;
    }

    $deletedBy = (int) currentUser()['user_id'];
    $deletedByName = currentUser()['full_name'];

    // Copy it into the archive first (types: expense_id=i, expense_date=s, category_id=i,
    // category_name=s, description=s, amount=d, recorded_by=i, recorded_by_name=s,
    // deleted_by=i, deleted_by_name=s -> "isissdisis").
    $stmt = $conn->prepare(
        'INSERT INTO deleted_expenses
            (expense_id, expense_date, category_id, category_name, description, amount,
             recorded_by, recorded_by_name, deleted_by, deleted_by_name)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'isissdisis',
        $expense['expense_id'],
        $expense['expense_date'],
        $expense['category_id'],
        $expense['category_name'],
        $expense['description'],
        $expense['amount'],
        $expense['recorded_by'],
        $expense['recorded_by_name'],
        $deletedBy,
        $deletedByName
    );
    $stmt->execute();

    // Only now remove the live record.
    $stmt = $conn->prepare('DELETE FROM expenses WHERE expense_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();

    $conn->commit();

    log_activity($conn, $deletedBy, 'EXPENSE_DELETED', "Deleted expense #$id (archived) - {$expense['description']}");
    flash('success', 'Expense deleted and moved to the archive.');
} catch (mysqli_sql_exception $e) {
    $conn->rollback();
    flash('error', friendly_db_error($e));
}

header('Location: list.php');
exit;
