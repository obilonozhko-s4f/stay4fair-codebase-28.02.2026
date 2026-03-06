<?php

declare(strict_types=1);

namespace StayFlow\Admin;

use WP_Error;

/**
 * Version: 1.2.2
 * RU: Профессиональная система защиты фронтенд-логина (перенесена из MU-plugins).
 * EN: Professional frontend login protection system (ported from MU-plugins).
 */
final class SecurityGuard
{
    private const OWNER_LOGIN_SLUG = 'owner-login';
    private const MAX_FAILS        = 5;
    private const LOCK_MINUTES     = 15;
    private const MIN_INTERVAL_SEC = 3;
    private const EMAIL_THROTTLE_MIN = 30;
    private const DEBUG_LOG        = false;

    public function register(): void
    {
        add_filter('authenticate', [$this, 'guardBeforeAuth'], 1, 3);
        add_action('wp_login_failed', [$this, 'onLoginFailed'], 10, 1);
        add_action('wp_login', [$this, 'onLoginSuccess'], 10, 2);
    }

    public function guardBeforeAuth($user, $username, $password)
    {
        if (!$this->isOwnerLoginRequest()) {
            return $user;
        }

        $ip = $this->getIp();
        if (!$ip) return $user;

        $username = is_string($username) ? trim($username) : '';
        $idLogin = $username !== '' ? $username : '__unknown__';

        // 1. Rate Limiting (Anti-Spam)
        $rlKey = $this->key('rl', $ip);
        $now   = time();
        $last  = (int) get_transient($rlKey);

        if ($last > 0 && ($now - $last) < self::MIN_INTERVAL_SEC) {
            $this->log('RATE_LIMIT', ['ip' => $ip, 'username' => $idLogin]);
            return new WP_Error('bsbt_rate_limited', 'Zu viele Versuche. Bitte warten Sie kurz.');
        }
        set_transient($rlKey, $now, self::MIN_INTERVAL_SEC + 2);

        // 2. Brute Force Lockout
        $lockKey = $this->key('lock', $ip . '|' . strtolower($idLogin));
        $lockedUntil = (int) get_transient($lockKey);

        if ($lockedUntil && $lockedUntil > $now) {
            $mins = (int) ceil(($lockedUntil - $now) / 60);
            $this->log('LOCKED_ACCESS_DENIED', ['ip' => $ip, 'username' => $idLogin]);
            return new WP_Error('bsbt_locked', 'Zu viele Fehlversuche. Bitte warten Sie ' . $mins . ' Min.');
        }

        return $user;
    }

    public function onLoginFailed($username): void
    {
        if (!$this->isOwnerLoginRequest()) return;

        $ip = $this->getIp();
        if (!$ip) return;

        $idLogin = is_string($username) ? strtolower(trim($username)) : '__unknown__';

        $failKey = $this->key('fails', $ip . '|' . $idLogin);
        $fails   = (int) get_transient($failKey);
        $fails++;

        set_transient($failKey, $fails, self::LOCK_MINUTES * 60);
        $this->log('LOGIN_FAIL', ['ip' => $ip, 'user' => $idLogin, 'count' => $fails]);

        if ($fails >= self::MAX_FAILS) {
            $lockKey = $this->key('lock', $ip . '|' . $idLogin);
            set_transient($lockKey, time() + (self::LOCK_MINUTES * 60), self::LOCK_MINUTES * 60);
            
            $this->log('LOCKOUT_TRIGGERED', ['ip' => $ip, 'user' => $idLogin]);
            $this->sendLockNotification($idLogin, $ip);
        }
    }

    public function onLoginSuccess($userLogin, $user): void
    {
        if (!$this->isOwnerLoginRequest()) return;

        $ip = $this->getIp();
        if (!$ip) return;

        $idLogin = strtolower(trim($userLogin));

        delete_transient($this->key('fails', $ip . '|' . $idLogin));
        delete_transient($this->key('lock',  $ip . '|' . $idLogin));
        
        $this->log('LOGIN_SUCCESS_CLEARED', ['ip' => $ip, 'user' => $idLogin]);
    }

    private function sendLockNotification(string $username, string $ip): void
    {
        $throttleKey = $this->key('email_sent', $username . '|' . $ip);
        if (get_transient($throttleKey)) {
            $this->log('EMAIL_THROTTLED', ['user' => $username]);
            return;
        }

        $user = get_user_by('login', $username);
        if (!$user) $user = get_user_by('email', $username);

        $to = [get_option('admin_email')];
        if ($user) $to[] = $user->user_email;

        $subject = 'Stay4Fair: Login-Sperre aktiv';
        $message = "Sicherheits-Benachrichtigung für Ihr Stay4Fair Partner-Portal.\n\n" .
                   "Konto: " . $username . "\n" .
                   "IP-Adresse: " . $ip . "\n" .
                   "Status: Gesperrt für " . self::LOCK_MINUTES . " Minuten.\n\n" .
                   "Falls Sie dies nicht waren, ändern Sie bitte Ihr Passwort.\n" .
                   "Ihr Stay4Fair Team";

        if (wp_mail($to, $subject, $message)) {
            set_transient($throttleKey, 1, self::EMAIL_THROTTLE_MIN * MINUTE_IN_SECONDS);
            $this->log('EMAIL_SENT', ['to' => $to]);
        }
    }

    private function isOwnerLoginRequest(): bool
    {
        if (is_admin()) return false;
        $uri = isset($_SERVER['REQUEST_URI']) ? strtolower((string)$_SERVER['REQUEST_URI']) : '';
        
        $isUri  = (strpos($uri, '/' . strtolower(self::OWNER_LOGIN_SLUG)) !== false);
        $isPost = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bsbt_login_submit']));

        return ($isUri || $isPost);
    }

    private function getIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return (strlen($ip) > 6 && strlen($ip) < 64) ? trim($ip) : '';
    }

    private function key(string $prefix, string $raw): string
    {
        return 'bsbt_sg_' . $prefix . '_' . substr(md5($raw), 0, 16);
    }

    private function log(string $event, array $ctx = []): void
    {
        if (!self::DEBUG_LOG) return;
        error_log('[BSBT_SG] ' . $event . ' ' . wp_json_encode($ctx));
    }
}