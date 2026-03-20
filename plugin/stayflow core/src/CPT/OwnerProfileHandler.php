<?php

declare(strict_types=1);

namespace StayFlow\CPT;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.1.0
 * RU: Обработчик формы профиля владельца.
 * - [FIX]: Исправлены ключи записи (bsbt_iban, bsbt_account_holder, bsbt_tax_number, bsbt_alt_phone).
 */
final class OwnerProfileHandler
{
    public function register(): void
    {
        add_action('admin_post_sf_process_owner_profile', [$this, 'handleForm']);
    }

    public function handleForm(): void
    {
        if (!is_user_logged_in() || !isset($_POST['sf_profile_csrf']) || !wp_verify_nonce((string)$_POST['sf_profile_csrf'], 'sf_owner_profile_nonce')) {
            wp_die('Sicherheit Check fehlgeschlagen.');
        }

        $userId = get_current_user_id();
        $action = sanitize_text_field((string)($_POST['profile_action'] ?? ''));

        if ($action === 'save_profile') {
            $this->saveProfile($userId);
        } elseif ($action === 'save_security') {
            $this->saveSecurity($userId);
        } elseif ($action === 'soft_delete') {
            $this->softDelete($userId);
        } else {
            wp_die('Unbekannte Aktion.');
        }
    }

    // === 1. Сохранение Базовых Данных ===
    private function saveProfile(int $userId): void
    {
        // Личные
        update_user_meta($userId, 'first_name', sanitize_text_field($_POST['first_name'] ?? ''));
        update_user_meta($userId, 'last_name', sanitize_text_field($_POST['last_name'] ?? ''));
        update_user_meta($userId, 'billing_phone', sanitize_text_field($_POST['phone'] ?? ''));
        
        // RU: Правильные ключи для админки
        update_user_meta($userId, 'bsbt_alt_phone', sanitize_text_field($_POST['alt_phone'] ?? ''));
        update_user_meta($userId, 'bsbt_account_holder', sanitize_text_field($_POST['bank_name'] ?? ''));
        update_user_meta($userId, 'bsbt_iban', sanitize_text_field($_POST['iban'] ?? ''));
        update_user_meta($userId, 'bsbt_tax_number', sanitize_text_field($_POST['steuernummer'] ?? ''));
        
        // Адрес
        update_user_meta($userId, 'billing_address_1', sanitize_text_field($_POST['address'] ?? ''));
        update_user_meta($userId, 'billing_postcode', sanitize_text_field($_POST['postcode'] ?? ''));
        update_user_meta($userId, 'billing_city', sanitize_text_field($_POST['city'] ?? ''));

        // Коммерческие
        if (isset($_POST['company_name'])) update_user_meta($userId, 'sf_company_name', sanitize_text_field($_POST['company_name']));
        if (isset($_POST['vat_id'])) update_user_meta($userId, 'sf_vat_id', sanitize_text_field($_POST['vat_id']));
        if (isset($_POST['company_reg'])) update_user_meta($userId, 'sf_company_reg', sanitize_text_field($_POST['company_reg']));

        wp_safe_redirect(add_query_arg('updated', '1', wp_get_referer()));
        exit;
    }

    // === 2. Смена Пароля и E-mail ===
    private function saveSecurity(int $userId): void
    {
        $user = get_user_by('id', $userId);
        $currentPass = (string)($_POST['current_pass'] ?? '');
        
        if (!wp_check_password($currentPass, $user->user_pass, $userId)) {
            wp_safe_redirect(add_query_arg('security_error', '1', wp_get_referer()));
            exit;
        }

        $newEmail = sanitize_email($_POST['user_email'] ?? '');
        $newPass  = (string)($_POST['new_pass'] ?? '');
        $updateData = ['ID' => $userId];
        $changed = false;

        if (!empty($newEmail) && $newEmail !== $user->user_email) {
            $updateData['user_email'] = $newEmail;
            $changed = true;
        }

        if (!empty($newPass)) {
            $updateData['user_pass'] = $newPass;
            $changed = true;
        }

        if ($changed) {
            wp_update_user($updateData);
            clean_user_cache($userId);
            wp_clear_auth_cookie();
            wp_set_authenticated_user_cookie($userId, true);
        }

        wp_safe_redirect(add_query_arg('security_updated', '1', wp_get_referer()));
        exit;
    }

    // === 3. Soft Delete ===
    private function softDelete(int $userId): void
    {
        global $wpdb;

        $apt_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT p.ID 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'bsbt_owner_id'
            WHERE p.post_type = 'mphb_room_type' 
            AND p.post_status != 'trash'
            AND (p.post_author = %d OR pm.meta_value = %d)
        ", $userId, $userId));

        foreach ($apt_ids as $aid) {
            wp_update_post(['ID' => $aid, 'post_status' => 'draft']);
        }

        $hasFutureBookings = false;
        if (!empty($apt_ids) && function_exists('MPHB')) {
            $room_ids = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'mphb_room_type_id' AND meta_value IN (" . implode(',', array_map('intval', $apt_ids)) . ")");
            
            if (!empty($room_ids)) {
                $today = current_time('Y-m-d');
                $bookings = \MPHB()->getBookingRepository()->findAll(['rooms' => $room_ids, 'date_from' => $today]);

                foreach ($bookings as $booking) {
                    if (in_array($booking->getStatus(), ['confirmed', 'pending_user', 'pending_payment'], true)) {
                        $hasFutureBookings = true;
                        break;
                    }
                }
            }
        }

        if ($hasFutureBookings) {
            wp_safe_redirect(add_query_arg('delete_error', 'active_bookings', wp_get_referer()));
            exit;
        } else {
            update_user_meta($userId, 'sf_account_status', 'deleted');
            wp_logout();
            wp_safe_redirect(home_url('/?deleted=1'));
            exit;
        }
    }
}