<?php

declare(strict_types=1);

namespace VetBoard;

class Auth
{
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
        ];
    }

    public static function login(array $staff): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id']    = $staff['id'];
        $_SESSION['user_name']  = $staff['name'];
        $_SESSION['user_email'] = $staff['email'];
        $_SESSION['user_role']  = $staff['role'];

        $pdo  = Database::getInstance()->getPdo();
        $stmt = $pdo->prepare('UPDATE staff SET last_login_at = now() WHERE id = :id');
        $stmt->execute([':id' => $staff['id']]);
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
