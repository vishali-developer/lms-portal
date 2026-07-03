<?php
// ============================================================
// auth.php — Session, Authentication & Helper Functions
// ============================================================

require_once __DIR__ . '/db.php';

// ── Session ──────────────────────────────────────────────────

function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => false,   // Set true when using HTTPS
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

// Require login; redirect to login page if not authenticated
function requireLogin(string $to = ''): void
{
    startSession();
    if (empty($_SESSION['user_id'])) {
        $redirect = $to ?: APP_URL . '/login.php';
        header('Location: ' . $redirect);
        exit;
    }

    // Validate the session's user still exists & is active.
    // Prevents null-property crashes / FK errors when an account is
    // deactivated or deleted while the user still has a live session.
    $stillValid = DB::fetchOne(
        "SELECT id FROM users WHERE id = ? AND status = 'active'",
        [$_SESSION['user_id']]
    );
    if (!$stillValid) {
        session_unset();
        session_destroy();
        setcookie(session_name(), '', time() - 3600, '/');
        $redirect = $to ?: APP_URL . '/login.php';
        header('Location: ' . $redirect);
        exit;
    }

    // Regenerate session id every 30 minutes to prevent fixation
    if (!isset($_SESSION['_regen']) || time() - $_SESSION['_regen'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['_regen'] = time();
    }
}

// Require one of the given roles; redirect to dashboard if denied
function requireRole(array $roles): void
{
    requireLogin();
    if (!in_array($_SESSION['user_role'] ?? '', $roles, true)) {
        setFlash('danger', 'Access denied.');
        redirect(APP_URL . '/dashboard.php');
    }
}

// Return the current logged-in user row, or null
function currentUser(): ?array
{
    startSession();
    if (empty($_SESSION['user_id'])) return null;
    return DB::fetchOne(
        "SELECT * FROM users WHERE id = ? AND status = 'active'",
        [$_SESSION['user_id']]
    );
}

// ── Authentication ───────────────────────────────────────────

// Verify credentials; returns user row or false
function attemptLogin(string $email, string $password): array|false
{
    $user = DB::fetchOne(
        "SELECT * FROM users WHERE email = ? AND status = 'active'",
        [$email]
    );
    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }
    DB::query("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);
    return $user;
}

// Populate session after successful login
function loginUser(array $user): void
{
    startSession();
    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['_regen']     = time();
    logActivity($user['id'], 'Login', 'User logged in successfully');
}

// Destroy session and log the action
function logoutUser(): void
{
    startSession();
    if (!empty($_SESSION['user_id'])) {
        logActivity($_SESSION['user_id'], 'Logout', 'User logged out');
    }
    session_unset();
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
}

// Hash a password with bcrypt
function hashPassword(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

// ── CSRF ─────────────────────────────────────────────────────

function csrfToken(): string
{
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): bool
{
    startSession();
    return !empty($_SESSION['csrf_token']) &&
           hash_equals($_SESSION['csrf_token'], $token);
}

// ── Flash messages ────────────────────────────────────────────

function setFlash(string $type, string $msg): void
{
    startSession();
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash(): ?array
{
    startSession();
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

// ── Notifications ─────────────────────────────────────────────

function addNotification(int $userId, string $title, string $message,
                          string $type = 'info', string $link = ''): void
{
    DB::query(
        "INSERT INTO notifications (user_id, title, message, type, link)
         VALUES (?, ?, ?, ?, ?)",
        [$userId, $title, $message, $type, $link]
    );

    // Send the notification to the user by using notification/push.php
    $postData = [
        'user_id' => $userId,
        'title'   => $title,
        'message' => $message,
        'type'    => $type,
        'link'    => $link
    ];

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => "http://localhost/lms/notification/push.php", // Change URL
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3
    ]);

    curl_exec($ch);
    curl_close($ch);
}
    


function unreadNotifCount(): int
{
    startSession();
    if (empty($_SESSION['user_id'])) return 0;
    $row = DB::fetchOne(
        "SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0",
        [$_SESSION['user_id']]
    );
    return (int)($row['cnt'] ?? 0);
}

// ── Activity logging ──────────────────────────────────────────

// $userId = 0 or null means unauthenticated/system action
function logActivity(int|null $userId, string $action, string $desc = ''): void
{
    // Foreign key requires a real user id or NULL — never 0
    $uid = ($userId !== null && $userId > 0) ? $userId : null;

    // Defensive guard: if the user row no longer exists (deleted account,
    // stale session), fall back to NULL instead of letting the INSERT
    // throw a foreign key constraint violation.
    if ($uid !== null) {
        $exists = DB::fetchOne("SELECT id FROM users WHERE id = ?", [$uid]);
        if (!$exists) {
            $uid = null;
        }
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // Normalise IPv6-mapped IPv4  e.g. ::ffff:127.0.0.1 → 127.0.0.1
    if (str_starts_with($ip, '::ffff:')) {
        $ip = substr($ip, 7);
    }
    DB::query(
        "INSERT INTO activity_logs (user_id, action, description, ip_address)
         VALUES (?, ?, ?, ?)",
        [$uid, $action, $desc, $ip]
    );
}

// ── Output / routing helpers ───────────────────────────────────

// Escape for safe HTML output (XSS prevention)
function e(mixed $val): string
{
    return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// HTTP redirect
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

// Return JSON and exit — for AJAX endpoints
function jsonOut(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Notify all admins & managers (excluding $exceptUserId)
function notifyAdmins(string $title, string $message,
                       string $type = 'info', string $link = '',
                       int $exceptUserId = 0): void
{
    $admins = DB::fetchAll(
        "SELECT id FROM users WHERE role IN ('admin','manager') AND status = 'active'"
    );
    foreach ($admins as $a) {
        if ($a['id'] !== $exceptUserId) {
            addNotification($a['id'], $title, $message, $type, $link);
        }
    }
}

// Format a MySQL datetime to a readable string
function fmtDate(string $datetime, string $format = 'd M Y, h:i A'): string
{
    return date($format, strtotime($datetime));
}

// Return a "time ago" string
function timeAgo(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60)    return 'Just now';
    if ($diff < 3600)  return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

// Sanitize a file name for safe storage
function safeFileName(string $name): string
{
    return preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
}

// Paginate: returns ['limit' => N, 'offset' => N, 'page' => N]
function paginate(int $total, int $perPage = 20): array
{
    $page   = max(1, (int)($_GET['p'] ?? 1));
    $pages  = max(1, (int)ceil($total / $perPage));
    $page   = min($page, $pages);
    return [
        'page'    => $page,
        'pages'   => $pages,
        'limit'   => $perPage,
        'offset'  => ($page - 1) * $perPage,
        'total'   => $total,
    ];
}