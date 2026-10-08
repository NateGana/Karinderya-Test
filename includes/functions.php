<?php

function base_url(string $path = ''): string
{
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

function clean(string $value): string
{
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

/** Queues a one-time message shown at the top of the next page load. */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Renders and clears any queued flash messages. Call this inside header.php. */
function render_flash(): void
{
    if (empty($_SESSION['flash'])) {
        return;
    }
    foreach ($_SESSION['flash'] as $f) {
        $type = $f['type'] === 'error' ? 'danger' : $f['type'];
        echo '<div class="alert alert-' . htmlspecialchars($type) . ' alert-dismissible fade show" role="alert">'
            . htmlspecialchars($f['message'])
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
    unset($_SESSION['flash']);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

/** Call at the top of every POST handler. Stops the request with a friendly message on mismatch. */
function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        flash('error', 'Your session has expired. Please try again.');
        header('Location: ' . base_url('login.php'));
        exit;
    }
}

/**
 * Guard for destructive actions (delete.php files). GET requests are refused
 * without touching any data; POST requests must carry a valid CSRF token.
 */
function require_post_with_csrf(string $redirectTo): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        flash('error', 'Invalid request. Please use the Delete button on the list page.');
        header('Location: ' . $redirectTo);
        exit;
    }
    verify_csrf();
}

function peso(?float $amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

/** Consistent color-coding for payment-method tags across Sales list/view/reports. */
function payment_pill_class(string $method): string
{
    return match ($method) {
        'cash' => 'pill-accent',
        'gcash' => 'pill-info',
        'card' => 'pill-warn',
        default => 'pill-neutral',
    };
}

/** Consistent color-coding for the admin/staff role tag. */
function role_pill_class(string $role): string
{
    return $role === 'admin' ? 'pill-accent' : 'pill-info';
}

/**
 * Writes one row to activity_logs. Never throws - a logging failure should not break the main action.
 *
 * $actorName is a snapshot of the person's name saved with the row, so the history stays
 * readable even after that user account is deleted (user_id is then set to NULL by the
 * database). If it is not supplied, it is looked up from the users table.
 * $userId may be NULL, e.g. when the acting account was just deleted.
 */
function log_activity(mysqli $conn, ?int $userId, string $action, string $details = '', ?string $actorName = null): void
{
    try {
        if ($actorName === null && $userId !== null) {
            $lookup = $conn->prepare('SELECT full_name FROM users WHERE user_id = ?');
            $lookup->bind_param('i', $userId);
            $lookup->execute();
            $row = $lookup->get_result()->fetch_assoc();
            $lookup->close();
            if ($row) {
                $actorName = $row['full_name'];
            } else {
                $userId = null; // account no longer exists - keep the log, avoid a foreign-key error
            }
        }

        $stmt = $conn->prepare('INSERT INTO activity_logs (user_id, actor_name, action, details) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('isss', $userId, $actorName, $action, $details);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        error_log('log_activity failed: ' . $e->getMessage());
    }
}

function friendly_db_error(mysqli_sql_exception $e): string
{
    error_log('DB error: ' . $e->getMessage());
    // Duplicate-entry errors are common and worth a specific message.
    if ($e->getCode() === 1062) {
        return 'That record already exists. Please check your entry and try again.';
    }
    return 'Unable to save the record. Please check the information and try again.';
}
