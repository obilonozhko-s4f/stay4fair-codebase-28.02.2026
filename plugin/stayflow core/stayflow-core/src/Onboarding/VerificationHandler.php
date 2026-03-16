<?php

declare(strict_types=1);

namespace StayFlow\Onboarding;

/**
 * Version: 1.0.0
 * RU: Обработчик верификации email (активация аккаунта по ссылке из письма).
 * EN: Email verification handler (account activation via email link).
 */
final class VerificationHandler
{
    public function register(): void
    {
        // Вешаем на init, чтобы поймать запрос до того, как начнет грузиться страница
        add_action('init', [$this, 'handleVerification']);
    }

    public function handleVerification(): void
    {
        // Проверяем, есть ли наши параметры в URL
        if (!isset($_GET['sf_verify']) || !isset($_GET['sf_u'])) {
            return;
        }

        $token  = sanitize_text_field($_GET['sf_verify']);
        $userId = (int)$_GET['sf_u'];

        if ($userId <= 0 || empty($token)) {
            $this->redirectWithError();
        }

        // Достаем токен из базы
        $savedToken = get_user_meta($userId, '_sf_verify_token', true);

        // Если токена нет (уже использован) или он не совпадает — выкидываем ошибку
        if (empty($savedToken) || $savedToken !== $token) {
            $this->redirectWithError();
        }

        // 1. УСПЕШНАЯ ВЕРИФИКАЦИЯ
        delete_user_meta($userId, '_sf_verify_token'); // Делаем ссылку одноразовой
        update_user_meta($userId, '_sf_account_status', 'verified'); // Меняем статус

        // 2. АВТОМАТИЧЕСКАЯ АВТОРИЗАЦИЯ
        $user = get_userdata($userId);
        if ($user) {
            wp_set_current_user($userId, $user->user_login);
            wp_set_auth_cookie($userId, true); // true = запомнить меня
            do_action('wp_login', $user->user_login, $user);
        }

        // 3. РЕДИРЕКТ В ДАШБОРД (Туда, где будет плитка Apartments)
        wp_safe_redirect(home_url('/owner-dashboard/'));
        exit;
    }

    /**
     * RU: Если ссылка старая или битая, отправляем на страницу логина с ошибкой.
     */
    private function redirectWithError(): void
    {
        wp_safe_redirect(home_url('/owner-login/?sf_error=invalid_token'));
        exit;
    }
}