<?php

declare(strict_types=1);

namespace VetBoard;

use PDO;

class LoginThrottle
{
    private const MAX_ATTEMPTS   = 5;
    private const WINDOW_MINUTES = 15;

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getPdo();
    }

    public function isLocked(string $email): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE email = :email AND success = false
               AND created_at > now() - interval '" . self::WINDOW_MINUTES . " minutes'"
        );
        $stmt->execute([':email' => strtolower(trim($email))]);

        return (int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    public function minutesUntilUnlock(string $email): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT MIN(created_at) FROM login_attempts
             WHERE email = :email AND success = false
               AND created_at > now() - interval '" . self::WINDOW_MINUTES . " minutes'"
        );
        $stmt->execute([':email' => strtolower(trim($email))]);
        $oldest = $stmt->fetchColumn();

        if (!$oldest) {
            return 0;
        }

        $unlockAt = strtotime((string) $oldest) + self::WINDOW_MINUTES * 60;

        return max(0, (int) ceil(($unlockAt - time()) / 60));
    }

    public function recordAttempt(string $email, bool $success): void
    {
        $email = strtolower(trim($email));

        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempts (email, ip_address, success) VALUES (:email, :ip, :success)'
        );
        $stmt->execute([
            ':email'   => $email,
            ':ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
            ':success' => $success,
        ]);

        // A successful login clears the account's failed-attempt history.
        if ($success) {
            $clear = $this->pdo->prepare('DELETE FROM login_attempts WHERE email = :email AND success = false');
            $clear->execute([':email' => $email]);
        }
    }
}
