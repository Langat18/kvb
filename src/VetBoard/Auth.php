<?php

declare(strict_types=1);

namespace VetBoard;

class Auth
{
    /**
     * Structural permission dependencies. If a role holds the key permission,
     * it automatically also holds each listed permission — regardless of
     * whether that permission is explicitly checked on the role's edit page.
     *
     * This exists because some permissions are sub-actions within a page
     * gated by a different permission (e.g. reviewing flagged attendance
     * happens from the event view page, so it requires view_events too).
     * Add a new line here whenever a new permission is introduced that only
     * makes sense to reach through another page.
     */
    private const PERMISSION_IMPLIES = [
        'create_events'              => ['view_events'],
        'manage_events'              => ['view_events'],
        'review_flagged_attendance'  => ['view_events'],
        'print_attendance_register'  => ['view_events'],
    ];

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function requireLogin(): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login.php');
            exit;
        }
    }

    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();
        $user = self::user();

        if (!in_array($user['role'], $roles, true)) {
            http_response_code(403);
            die('Access denied.');
        }
    }

    public static function requirePermission(string $permissionKey): void
    {
        self::requireLogin();

        if (!self::can($permissionKey)) {
            http_response_code(403);
            die('Access denied.');
        }
    }

    public static function can(string $permissionKey): bool
    {
        self::requireLogin();

        return in_array($permissionKey, $_SESSION['user_permissions'] ?? [], true);
    }

    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        return [
            'id'    => $_SESSION['user_id'],
            'name'  => $_SESSION['user_name']  ?? '',
            'email' => $_SESSION['user_email'] ?? '',
            'role'  => $_SESSION['user_role']  ?? '',
            'role_label' => $_SESSION['user_role_label'] ?? '',
        ];
    }

    public static function login(array $staff): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id']    = $staff['id'];
        $_SESSION['user_name']  = $staff['name'];
        $_SESSION['user_email'] = $staff['email'];
        $_SESSION['user_role']  = $staff['role'];
        $_SESSION['user_role_label'] = $staff['role_label'] ?? $staff['role'];

        $roleModel = new Role();
        $roleRow   = $roleModel->findByKey($staff['role']);
        $rawPermissions = $roleRow ? $roleModel->getPermissionKeys((int) $roleRow['id']) : [];
        $_SESSION['user_permissions'] = self::expandPermissions($rawPermissions);

        $pdo  = Database::getInstance()->getPdo();
        $stmt = $pdo->prepare('UPDATE staff SET last_login_at = now() WHERE id = :id');
        $stmt->execute([':id' => $staff['id']]);
    }

    public static function refreshPermissions(): void
    {
        if (empty($_SESSION['user_role'])) {
            return;
        }

        $roleModel = new Role();
        $roleRow   = $roleModel->findByKey($_SESSION['user_role']);
        $rawPermissions = $roleRow ? $roleModel->getPermissionKeys((int) $roleRow['id']) : [];
        $_SESSION['user_permissions'] = self::expandPermissions($rawPermissions);
    }

    /**
     * Expands a raw list of granted permission keys to also include any
     * permissions implied by them, per PERMISSION_IMPLIES. Runs until no
     * further expansion happens, so chains of implication resolve fully.
     */
    private static function expandPermissions(array $keys): array
    {
        $expanded = $keys;
        $changed  = true;

        while ($changed) {
            $changed = false;

            foreach ($expanded as $key) {
                foreach (self::PERMISSION_IMPLIES[$key] ?? [] as $implied) {
                    if (!in_array($implied, $expanded, true)) {
                        $expanded[]  = $implied;
                        $changed     = true;
                    }
                }
            }
        }

        return $expanded;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie('PHPSESSID', '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function validateCsrf(string $token): bool
    {
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }
}
