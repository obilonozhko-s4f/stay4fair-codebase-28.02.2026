<?php

declare(strict_types=1);

namespace StayFlow\CPT;

/**
 * Version: 1.1.9
 * RU: Обработчик (Добавлено сохранение Описания, триггер Watermark, фикс спама писем).
 * EN: Handler (Added Description saving, Watermark trigger, email spam fix).
 */
final class ApartmentHandler
{
    /* ==========================================================================
     * REGISTER / РЕГИСТРАЦИЯ ХУКОВ
     * ========================================================================== */
    public function register(): void
    {
        add_action('admin_post_sf_process_add_apartment', [$this, 'handleForm']);
    }

    /* ==========================================================================
     * MAIN HANDLER / ГЛАВНЫЙ ОБРАБОТЧИК ФОРМЫ
     * ========================================================================== */
    public function handleForm(): void
    {
        if (!is_user_logged_in() || !isset($_POST['sf_add_apt_nonce']) || !wp_verify_nonce($_POST['sf_add_apt_nonce'], 'sf_add_apt_action')) {
            wp_die('Sicherheits-Check fehlgeschlagen.');
        }

        $userId = get_current_user_id();

        // 1. Создание поста "Тип жилья" (Draft)
        $title       = sanitize_text_field($_POST['apt_name']);
        // RU: Получаем описание из wp_editor и безопасно сохраняем HTML.
        $description = isset($_POST['apt_description']) ? wp_kses_post(wp_unslash($_POST['apt_description'])) : '';

        $postId = wp_insert_post([
            'post_type'    => 'mphb_room_type',
            'post_title'   => $title,
            'post_content' => $description, // <-- СОХРАНЕНИЕ ОПИСАНИЯ
            'post_status'  => 'draft',
            'post_author'  => $userId
        ]);

        if (is_wp_error($postId)) {
            wp_die('Fehler beim Erstellen des Apartments.');
        }

        // 2. Базовые данные
        update_post_meta($postId, 'bsbt_owner_id', $userId);
        update_post_meta($postId, 'address', sanitize_text_field($_POST['apt_address']));
        update_post_meta($postId, '_sf_reg_id', sanitize_text_field($_POST['apt_reg_id'] ?? ''));
        
        update_post_meta($postId, 'doorbell_name', sanitize_text_field($_POST['apt_doorbell']));
        update_post_meta($postId, '_doorbell_name', 'field_68fccdf3cdffe');

        update_post_meta($postId, 'owner_phone', sanitize_text_field($_POST['apt_contact_phone']));
        update_post_meta($postId, '_owner_phone', 'field_68fccdbacdffb');

        // iCal Import
        $icalUrl = esc_url_raw($_POST['apt_ical'] ?? '');
        if (!empty($icalUrl)) {
            update_post_meta($postId, '_sf_ical_import', $icalUrl);
            update_post_meta($postId, 'sf_ical_import', $icalUrl);
        }

        // 3. Вместимость 
        update_post_meta($postId, 'mphb_adults_capacity', (int)$_POST['apt_adults']);
        update_post_meta($postId, 'mphb_children_capacity', (int)$_POST['apt_children']);
        update_post_meta($postId, 'mphb_total_capacity', ''); 
        update_post_meta($postId, 'mphb_base_adults_capacity', '');
        update_post_meta($postId, 'mphb_base_children_capacity', '');

        // 4. Сохранение Категорий, Удобств и Атрибутов
        if (!empty($_POST['apt_category'])) {
            wp_set_post_terms($postId, [(int)$_POST['apt_category']], 'mphb_room_type_category');
        }
        
        if (!empty($_POST['apt_attribute_type'])) {
            $attrId = (int)$_POST['apt_attribute_type'];
            wp_set_post_terms($postId, [$attrId], 'mphb_ra_apartment-type');
            update_post_meta($postId, 'mphb_attributes', ['apartment-type' => [$attrId]]);
        }
        
        if (!empty($_POST['apt_amenities']) && is_array($_POST['apt_amenities'])) {
            $amenity_ids = array_map('intval', $_POST['apt_amenities']);
            wp_set_post_terms($postId, $amenity_ids, 'mphb_room_type_facility');
        }

        // 5. Бизнес-модель, Цена и Условия отмены 
        update_post_meta($postId, '_bsbt_business_model', 'model_b'); 
        
        $price = (float)$_POST['apt_price'];
        update_post_meta($postId, 'sf_owner_price', $price); 
        update_post_meta($postId, '_sf_owner_price', $price);
        update_post_meta($postId, '_sf_selling_price', $price);
        
        update_post_meta($postId, 'sf_min_stay', (int)$_POST['apt_min_stay']);
        update_post_meta($postId, '_sf_min_stay', (int)$_POST['apt_min_stay']);
        
        $cancelPolicy = sanitize_text_field($_POST['apt_cancellation']);
        if ($cancelPolicy === 'flexible') {
            update_post_meta($postId, 'sf_cancellation_policy', 'free_cancellation');
            update_post_meta($postId, '_sf_cancellation_policy', 'free_cancellation');
            update_post_meta($postId, 'bsbt_cancel_policy_type', 'standard'); 
            
            $flexDays = (int)($_POST['apt_flex_days'] ?? 14);
            update_post_meta($postId, 'sf_cancellation_days', $flexDays);
            update_post_meta($postId, '_sf_cancellation_days', $flexDays);
        } else {
            update_post_meta($postId, 'sf_cancellation_policy', 'non_refundable');
            update_post_meta($postId, '_sf_cancellation_policy', 'non_refundable');
            update_post_meta($postId, 'bsbt_cancel_policy_type', 'nonref'); 
        }

        $loyalty = isset($_POST['apt_loyalty']) ? 1 : 0;
        update_post_meta($postId, '_sf_fair_return', $loyalty);

        // 6. Финансы
        $ownerType = get_user_meta($userId, '_sf_owner_type', true);
        $bankName  = sanitize_text_field($_POST['apt_bank_name']);
        $bankIban  = sanitize_text_field($_POST['apt_bank_iban']);
        $taxId     = sanitize_text_field($_POST['apt_tax_id']);

        if ($ownerType === 'business') {
            update_user_meta($userId, 'sf_bank_kontoinhaber', $bankName);
            update_user_meta($userId, 'sf_bank_iban', $bankIban);
            update_user_meta($userId, 'sf_steuernummer', $taxId);
        } else {
            update_post_meta($postId, 'kontoinhaber', $bankName);
            update_post_meta($postId, '_kontoinhaber', 'field_691249ce204ee');
            update_post_meta($postId, 'kontonummer', $bankIban);
            update_post_meta($postId, '_kontonummer', 'field_691249bc204ec');
            update_post_meta($postId, 'steuernummer', $taxId);
            update_post_meta($postId, '_steuernummer', 'field_6995ad392c5e2');
        }

        // 7. СОЗДАНИЕ ФИЗИЧЕСКОЙ КВАРТИРЫ
        $accomId = wp_insert_post([
            'post_type'   => 'mphb_room',
            'post_title'  => $title . ' (Objekt 1)',
            'post_status' => 'publish', 
            'post_author' => $userId
        ]);
        if (!is_wp_error($accomId)) {
            update_post_meta($accomId, 'mphb_room_type_id', $postId);
            update_post_meta($accomId, '_mphb_room_type_id', $postId);
        }

        // 8. Фотографии (и активация вотермарка)
        $this->handlePhotoUploads($postId);

        // 9. Перезапуск хуков сохранения
        $post_obj = get_post($postId);
        do_action('save_post', $postId, $post_obj, true);
        do_action('save_post_mphb_room_type', $postId, $post_obj, true);

        // 10. Уведомление
        $this->sendAdminNotification($postId, $title, $userId);

        // 11. Редирект
        wp_safe_redirect(add_query_arg('apt_created', '1', home_url('/owner-dashboard/')));
        exit;
    }

    /* ==========================================================================
     * PHOTO UPLOADS
     * ========================================================================== */
    private function handlePhotoUploads(int $postId): void
    {
        if (empty($_FILES['apt_photos']['name'][0])) {
            return;
        }

        // RU: Отключаем сторонние оптимизаторы, чтобы избежать таймаутов
        if (!defined('BSBT_DISABLE_IMAGE_OPTIMIZER')) {
            define('BSBT_DISABLE_IMAGE_OPTIMIZER', true); 
        }

        // RU: Даем сигнал нашему ImageOptimizer.php НАЛОЖИТЬ WATERMARK
        if (!defined('SF_APPLY_WATERMARK')) {
            define('SF_APPLY_WATERMARK', true);
        }

        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $files = $_FILES['apt_photos'];
        $attachment_ids = [];
        $uploaded_count = 0;

        foreach ($files['name'] as $key => $value) {
            if ($uploaded_count >= 15) break;
            if ($files['error'][$key] !== UPLOAD_ERR_OK) continue;

            if (!empty($files['name'][$key])) {
                $file = [
                    'name'     => $files['name'][$key],
                    'type'     => $files['type'][$key],
                    'tmp_name' => $files['tmp_name'][$key],
                    'error'    => $files['error'][$key],
                    'size'     => $files['size'][$key]
                ];

                $_FILES['sf_custom_upload'] = $file;
                $attachment_id = media_handle_upload('sf_custom_upload', $postId);

                if (!is_wp_error($attachment_id)) {
                    $attachment_ids[] = $attachment_id;
                    $uploaded_count++;
                    
                    if (count($attachment_ids) === 1) {
                        set_post_thumbnail($postId, $attachment_id);
                    }
                }
                unset($_FILES['sf_custom_upload']);
            }
        }

        if (!empty($attachment_ids)) {
            $gallery_string = implode(',', $attachment_ids);
            update_post_meta($postId, 'mphb_gallery', $gallery_string);
        }
    }

    /* ==========================================================================
     * ADMIN NOTIFICATION
     * ========================================================================== */
    private function sendAdminNotification(int $postId, string $title, int $userId): void
    {
        $admins = get_users(['role' => 'administrator']);
        $adminEmails = [];
        
        foreach ($admins as $admin) {
            if (!empty($admin->user_email)) {
                $adminEmails[] = $admin->user_email;
            }
        }

        if (empty($adminEmails)) {
            $adminEmails[] = get_option('admin_email');
        }
        
        $user       = get_userdata($userId);
        $ownerName  = $user ? $user->display_name : 'Unbekannt';
        $ownerPhone = get_user_meta($userId, 'bsbt_phone', true) ?: 'Keine';
        
        $editLink   = admin_url('post.php?post=' . $postId . '&action=edit');
        $subject    = '🔔 Neues Apartment eingereicht: ' . $title;
        
        // RU: Оставляем только базовый заголовок, чтобы не триггерить спам-фильтры подменой ящика
        $headers = [
            'Content-Type: text/html; charset=UTF-8'
        ];

        ob_start();
        ?>
        <div style="font-family: Arial, sans-serif; background-color: #f1f5f9; padding: 20px;">
            <div style="max-width: 600px; margin: 0 auto; background: #fff; border-radius: 10px; padding: 30px; border: 1px solid #e2e8f0;">
                <h2 style="color: #082567; margin-top: 0;">Neues Apartment zur Prüfung</h2>
                <p style="color: #334155; font-size: 15px; line-height: 1.5;">
                    Ein Gastgeber hat ein neues Apartment hinzugefügt. Es wurde als <strong>Entwurf (Draft)</strong> gespeichert.
                </p>
                
                <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                    <tr>
                        <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; font-weight: bold; color: #082567; width: 120px;">Gastgeber:</td>
                        <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; color: #1e293b;"><?php echo esc_html($ownerName); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; font-weight: bold; color: #082567;">Telefon:</td>
                        <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; color: #1e293b;"><?php echo esc_html($ownerPhone); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; font-weight: bold; color: #082567;">Apartment:</td>
                        <td style="padding: 10px; border-bottom: 1px solid #e2e8f0; color: #1e293b;"><?php echo esc_html($title); ?></td>
                    </tr>
                </table>

                <a href="<?php echo esc_url($editLink); ?>" style="display: inline-block; background: #E0B849; color: #082567; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; margin-top: 10px;">
                    Im Admin-Bereich prüfen
                </a>
            </div>
        </div>
        <?php
        $message = ob_get_clean();

        wp_mail($adminEmails, $subject, $message, $headers);
    }
}