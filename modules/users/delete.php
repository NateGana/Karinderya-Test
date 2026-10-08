<?php
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin');

require_post_with_csrf('list.php');

$id = (int) ($_POST['id'] ?? 0);
$isSelf = $id === (int) currentUser()['user_id'];

try {
    $stmt = $conn->prepare('SELECT username FROM users WHERE user_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();

    if (!$target) {
        flash('error', 'That user could not be found.');
        header('Location: list.php');
        exit;
    }

    $stmt = $conn->prepare('DELETE FROM users WHERE user_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();

    // Log this before the session is torn down below, in case it's the current user.
    // If you deleted your own account, your user_id no longer exists, so the row is saved
    // with a NULL user_id and your name in actor_name - the deletion is still recorded.
    $actor = currentUser();
    log_activity(
        $conn,
        $isSelf ? null : (int) $actor['user_id'],
        'USER_DELETED',
        "Deleted user account \"{$target['username']}\"",
        $actor['full_name']
    );

    if ($isSelf) {
        // You just deleted the account you're logged in as. Rather than a full
        // session_destroy() (which would also wipe the flash message we're
        // about to set - it has to survive into the next request so the
        // login page can show it), we clear only the auth-related session
        // keys and rotate the session ID. isLoggedIn() checks for
        // $_SESSION['user_id'], so this still fully logs you out.
        flash('success', 'Your account was deleted. You have been logged out.');
        unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['full_name'], $_SESSION['role']);
        session_regenerate_id(true);
        header('Location: ' . base_url('login.php'));
        exit;
    }

    flash('success', 'User account deleted.');
} catch (mysqli_sql_exception $e) {
    if ($e->getCode() === 1451) {
        flash('error', 'This user cannot be deleted because they have existing sales, expenses, or categories on record. Set their status to Inactive instead.');
    } else {
        flash('error', friendly_db_error($e));
    }
}

header('Location: list.php');
exit;
