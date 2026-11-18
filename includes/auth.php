<?php
/**
 * Authentication, role-based authorization (RBAC), manager overrides,
 * brute-force protection and the audit log writer.
 */
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../config/db.php';

start_secure_session();

// ---------------------------------------------------------------------
// Roles & permissions
// ---------------------------------------------------------------------

/** Business roles of a retail shop. */
function role_definitions(): array
{
    return [
        'owner'           => ['label' => 'Owner',           'desc' => 'Full access to every module, staff accounts and settings.'],
        'manager'         => ['label' => 'Shop Manager',    'desc' => 'Runs daily operations: approvals, purchasing, finance and reports.'],
        'cashier'         => ['label' => 'Cashier',         'desc' => 'Processes sales, payments, customer returns and customer payments.'],
        'sales_assistant' => ['label' => 'Sales Assistant', 'desc' => 'Helps customers, checks stock and prepares carts for the cashier.'],
        'inventory'       => ['label' => 'Inventory / Store Staff', 'desc' => 'Receives goods, raises purchase orders, counts and adjusts stock (with approval).'],
    ];
}

function role_label(?string $role): string
{
    if ($role === 'admin') { $role = 'owner'; }
    return role_definitions()[$role]['label'] ?? ucfirst((string) $role);
}

/** Permission => roles that hold it. The owner implicitly holds every permission. */
function permission_matrix(): array
{
    $mgmt = ['manager'];
    return [
        'dashboard.view'        => $mgmt,
        'pos.access'            => ['manager', 'cashier', 'sales_assistant'],
        'pos.checkout'          => ['manager', 'cashier'],
        'pos.discount_override' => $mgmt,
        'sales.view_all'        => $mgmt,
        'sales.void'            => $mgmt,
        'returns.create'        => ['manager', 'cashier'],
        'returns.approve'       => $mgmt,
        'customers.view'        => ['manager', 'cashier', 'sales_assistant'],
        'customers.create'      => ['manager', 'cashier', 'sales_assistant'],
        'customers.edit'        => $mgmt,
        'customers.receive_payment' => ['manager', 'cashier'],
        'products.view'         => ['manager', 'cashier', 'sales_assistant', 'inventory'],
        'products.manage'       => $mgmt,
        'products.view_cost'    => ['manager', 'inventory'],
        'inventory.view'        => ['manager', 'sales_assistant', 'inventory'],
        'inventory.adjust'      => ['manager', 'inventory'],
        'inventory.approve'     => $mgmt,
        'inventory.count'       => ['manager', 'inventory'],
        'suppliers.view'        => ['manager', 'inventory'],
        'suppliers.manage'      => $mgmt,
        'suppliers.pay'         => $mgmt,
        'purchase.manage'       => ['manager', 'inventory'],
        'purchase.receive'      => ['manager', 'inventory'],
        'finance.view'          => $mgmt,
        'expenses.manage'       => $mgmt,
        'reports.view'          => $mgmt,
        'audit.view'            => $mgmt,
        'transfers.view'        => ['manager', 'inventory'],
        'transfers.manage'      => ['manager', 'inventory'],
        'users.manage'          => [],
        'settings.manage'       => [],
        'branches.manage'       => [],
    ];
}

function user_can(?array $user, string $permission): bool
{
    if (!$user || empty($user['role'])) { return false; }
    $role = $user['role'] === 'admin' ? 'owner' : $user['role'];
    if ($role === 'owner') { return true; }
    return in_array($role, permission_matrix()[$permission] ?? [], true);
}

/** Does the logged-in user hold the permission? */
function can(string $permission): bool
{
    return user_can(current_user(), $permission);
}

/** True if the user holds ANY of the given permissions. */
function can_any(array $permissions): bool
{
    foreach ($permissions as $p) { if (can($p)) { return true; } }
    return false;
}

function deny_access(): never
{
    http_response_code(403);
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'You do not have permission to do that.']);
        exit;
    }
    require __DIR__ . '/../403.php';
    exit;
}

/** Guard a page/endpoint: user must hold at least one of the permissions. */
function require_permission(string|array $permissions): void
{
    require_login();
    $permissions = (array) $permissions;
    if (!can_any($permissions)) { deny_access(); }
}

/** Legacy guard kept for compatibility; 'admin' means owner or manager. */
function require_role(array $roles): void
{
    require_login();
    $role = current_user()['role'];
    if (in_array('admin', $roles, true)) { $roles = array_merge($roles, ['owner', 'manager']); }
    if (!in_array($role, $roles, true)) { deny_access(); }
}

/** Where a user lands after login, based on what they can do. */
function home_path_for(?array $user): string
{
    if (user_can($user, 'dashboard.view')) { return '/index.php'; }
    if (user_can($user, 'pos.access')) { return '/pos/index.php'; }
    if (user_can($user, 'purchase.receive')) { return '/purchase/orders.php'; }
    return '/inventory/products.php';
}

/**
 * Supervisor override: a manager types their credentials on the cashier's
 * screen to authorize one restricted action (void, over-limit discount,
 * return approval). Returns the authorizing user row, or a string error.
 */
function authorize_override(?string $username, ?string $password, string $permission): array|string
{
    $username = trim((string) $username);
    $password = (string) $password;
    if ($username === '' || $password === '') {
        return 'Manager approval is required. Enter a manager\'s username and password.';
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (login_attempts_recent($username, $ip) >= LOGIN_MAX_ATTEMPTS) {
        return 'Too many failed approval attempts. Try again in ' . LOGIN_LOCKOUT_MINUTES . ' minutes.';
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE username = :u AND is_active = 1 LIMIT 1');
    $stmt->execute([':u' => $username]);
    $approver = $stmt->fetch();
    if (!$approver || !password_verify($password, $approver['password_hash'])) {
        record_login_attempt($username, $ip, false);
        return 'Approval failed: invalid manager credentials.';
    }
    unset($approver['password_hash']);
    if (!user_can($approver, $permission)) {
        return 'Approval failed: ' . $approver['full_name'] . ' is not allowed to authorize this action.';
    }
    return $approver;
}

// ---------------------------------------------------------------------
// Session / login
// ---------------------------------------------------------------------

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Your session has expired. Please sign in again.']);
            exit;
        }
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . app_url('/login.php?redirect=' . $redirect));
        exit;
    }
    // Keep role/active flag fresh so a demoted or disabled user loses access immediately.
    static $refreshed = false;
    if (!$refreshed) {
        $refreshed = true;
        $stmt = db()->prepare('SELECT id, username, full_name, email, role, is_active, branch_id, all_branches FROM users WHERE id = :id');
        $stmt->execute([':id' => $_SESSION['user']['id']]);
        $fresh = $stmt->fetch();
        if (!$fresh || (int) $fresh['is_active'] !== 1) {
            $_SESSION = [];
            header('Location: ' . app_url('/login.php'));
            exit;
        }
        $_SESSION['user'] = array_merge($_SESSION['user'], $fresh);
    }
}

function app_url(string $path = ''): string
{
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
}

function login_attempts_recent(string $username, string $ip): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE (username = :u OR ip_address = :ip) AND success = 0
           AND attempted_at > (NOW() - INTERVAL :mins MINUTE)'
    );
    $stmt->bindValue(':u', $username);
    $stmt->bindValue(':ip', $ip);
    $stmt->bindValue(':mins', LOGIN_LOCKOUT_MINUTES, PDO::PARAM_INT);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function record_login_attempt(string $username, string $ip, bool $success): void
{
    db()->prepare('INSERT INTO login_attempts (username, ip_address, success) VALUES (:u, :ip, :s)')
        ->execute([':u' => $username, ':ip' => $ip, ':s' => $success ? 1 : 0]);
}

function attempt_login(string $username, string $password): array|string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if (login_attempts_recent($username, $ip) >= LOGIN_MAX_ATTEMPTS) {
        return 'Too many failed attempts. Please try again in ' . LOGIN_LOCKOUT_MINUTES . ' minutes.';
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        record_login_attempt($username, $ip, false);
        return 'Invalid username or password.';
    }
    if ((int) $user['is_active'] !== 1) {
        record_login_attempt($username, $ip, false);
        return 'This account has been deactivated. Contact the shop owner.';
    }

    record_login_attempt($username, $ip, true);

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
            ->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['id']]);
    }
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')->execute([':id' => $user['id']]);

    session_regenerate_id(true);
    unset($user['password_hash']);
    $_SESSION['user'] = $user;

    log_activity('login', 'Signed in', 'user', (int) $user['id']);
    return $user;
}

function logout(): void
{
    log_activity('logout', 'Signed out');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/**
 * Audit trail writer. Every important business action calls this with a
 * human-readable description and (ideally) the entity it touched, so the
 * audit log can answer "who changed what, and when".
 */
function log_activity(string $action, string $details = '', ?string $entityType = null, ?int $entityId = null): void
{
    try {
        $branch = function_exists('current_branch_id') ? (current_branch_id() ?? (int) (current_user()['branch_id'] ?? 1)) : 1;
        db()->prepare(
            'INSERT INTO activity_log (user_id, branch_id, action, entity_type, entity_id, details, ip_address) VALUES (:u, :br, :a, :et, :eid, :d, :ip)'
        )->execute([
            ':br'  => $branch,
            ':u'   => current_user()['id'] ?? null,
            ':a'   => $action,
            ':et'  => $entityType,
            ':eid' => $entityId,
            ':d'   => $details,
            ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('Audit log write failed: ' . $e->getMessage());
    }
}
