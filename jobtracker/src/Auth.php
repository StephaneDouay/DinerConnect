<?php
declare(strict_types=1);

class Auth
{
    private static array $cfg = [];

    public static function init(array $cfg): void
    {
        self::$cfg = $cfg;
        if (session_status() === PHP_SESSION_NONE) {
            session_name('JT_SESSION');
            session_start();
        }
    }

    public static function check(): bool
    {
        return !empty($_SESSION['jt_auth']) && $_SESSION['jt_auth'] === true;
    }

    public static function login(string $password): bool
    {
        $hash = self::$cfg['app']['admin_password_hash'] ?? '';
        if ($hash === '') {
            // First login: show hash generation UI (handled by index.php)
            return false;
        }
        if (password_verify($password, $hash)) {
            session_regenerate_id(true);
            $_SESSION['jt_auth'] = true;
            return true;
        }
        return false;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function requireAuth(): void
    {
        if (!self::check()) {
            header('Location: index.php');
            exit;
        }
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
