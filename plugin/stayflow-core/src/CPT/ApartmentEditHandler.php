<?php

declare(strict_types=1);

namespace StayFlow\CPT;

/**
 * Version: 1.0.6
 * RU: Обработчик редактирования (Сброс кэша комнаты для iCal, правильный ключ sf_commune_reg_id).
 * EN: Edit handler (Clean post cache for iCal MotoPress sync, correct sf_commune_reg_id key).
 */
final class ApartmentEditHandler
{
    public function register(): void
    {
        add_action('admin_post_sf_process_edit_apartment', [$this, 'handleForm']);
    }

    public function handleForm(): void
    {
        if (!is_user_logged_in() || !isset($_POST['sf_edit_apt_nonce']) || !wp_verify_nonce($_POST['sf_edit_apt_nonce'], 'sf_edit_apt_action')) {
            wp_die('Sicherheit Check fehlgeschlagen.');
        }

        $userId = get_current_user_id();
        $apt_id = (int)$_POST['apt_id'];
        $post = get_post($apt_id);
        if (!$post || (int)$post->post_author !== $userId) wp_die('Zugriff verweigert.');

        // 1. Статус
        $req_status = $_POST['apt_status'] ?? 'online';
        $current_status = $post->post_status;
        $new_status = ($req_status === 'offline') ? 'draft' : (($current_status === 'draft') ? 'publish' : $current_status);

        // 2. Обновление Room Type
        $title = sanitize_text_field($_POST['apt_name'] ?? '');
        wp_update_post([
            'ID'           => $apt_id,
            'post_title'   => $title,
            'post_content' => wp_kses_post(wp_unslash($_POST['apt_description'] ?? '')),
            'post_status'  => $new_status
        ]);

        // 3. Синхронизация iCal (с очисткой кэша)
        $ical_url = esc_url_raw($_POST['apt_ical'] ?? '');
        
        update_post_meta($apt_id, '_sf_ical_import', $ical_url);
        update_post_meta($apt_id, 'sf_ical_import', $ical_url);

        $accoms = get_posts([
            'post_type'      => 'mphb_room',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'meta_query'     => [
                [
                    'key'   => 'mphb_room_type_id',
                    'value' => $apt_id
                ]
            ]
        ]);

        if (!empty($accoms)) {
            foreach ($accoms as $accom) {
                $room_id = $accom->ID;
                if (!empty($ical_url)) {
                    $sync_urls = array( 1 => array( 'url' => $ical_url ) );
                    update_post_meta($room_id, 'mphb_sync_urls', $sync_urls);
                } else {
                    delete_post_meta($room_id, 'mphb_sync_urls');
                }
                wp_update_post(['ID' => $room_id, 'post_status' => $new_status]);
                
                // ЖЕСТКИЙ СБРОС КЭША ДЛЯ MOTOPRESS
                clean_post_cache($room_id);
            }
        }

        // 4. Meta данные
        update_post_meta($apt_id, 'address', sanitize_text_field($_POST['apt_address'] ?? ''));
        update_post_meta($apt_id, 'doorbell_name', sanitize_text_field($_POST['apt_doorbell'] ?? ''));
        update_post_meta($apt_id, 'owner_phone', sanitize_text_field($_POST['apt_contact_phone'] ?? ''));
        
        // ПРАВИЛЬНЫЙ КЛЮЧ ДЛЯ REG_ID
        update_post_meta($apt_id, 'sf_commune_reg_id', sanitize_text_field($_POST['apt_reg_id'] ?? ''));
        
        update_post_meta($apt_id, 'mphb_adults_capacity', (int)($_POST['apt_adults'] ?? 2));
        update_post_meta($apt_id, 'mphb_children_capacity', (int)($_POST['apt_children'] ?? 0));
        
        update_post_meta($apt_id, '_sf_selling_price', (float)($_POST['apt_price'] ?? 0));
        update_post_meta($apt_id, 'sf_min_stay', (int)($_POST['apt_min_stay'] ?? 1));
        
        $cancel_pol = sanitize_text_field($_POST['apt_cancellation'] ?? 'flexible');
        update_post_meta($apt_id, 'sf_cancellation_policy', ($cancel_pol === 'flexible' ? 'free_cancellation' : 'non_refundable'));
        update_post_meta($apt_id, 'sf_cancellation_days', (int)($_POST['apt_flex_days'] ?? 14));
        update_post_meta($apt_id, '_sf_fair_return', isset($_POST['apt_loyalty']) ? 1 : 0);

        update_post_meta($apt_id, 'kontoinhaber', sanitize_text_field($_POST['apt_bank_name'] ?? ''));
        update_post_meta($apt_id, 'kontonummer', sanitize_text_field($_POST['apt_bank_iban'] ?? ''));
        update_post_meta($apt_id, 'steuernummer', sanitize_text_field($_POST['apt_tax_id'] ?? ''));

        // Таксономии
        if (!empty($_POST['apt_category'])) wp_set_object_terms($apt_id, [(int)$_POST['apt_category']], 'mphb_room_type_category');
        if (!empty($_POST['apt_attribute_type'])) {
            $attrId = (int)$_POST['apt_attribute_type'];
            wp_set_object_terms($apt_id, [$attrId], 'mphb_ra_apartment-type');
            update_post_meta($apt_id, 'mphb_attributes', ['apartment-type' => [$attrId]]);
        }
        if (isset($_POST['apt_amenities']) && is_array($_POST['apt_amenities'])) {
            wp_set_object_terms($apt_id, array_map('intval', $_POST['apt_amenities']), 'mphb_room_type_facility');
        } else {
            wp_set_object_terms($apt_id, [], 'mphb_room_type_facility'); 
        }

        // 5. Галерея
        $this->handlePhotoUpdates($apt_id);

        // 6. Уведомление админу
        $this->sendAdminNotification($apt_id, $title, $userId);

        // ЖЕСТКИЙ СБРОС КЭША ДЛЯ ШАБЛОНА
        clean_post_cache($apt_id);

        wp_safe_redirect(add_query_arg('apt_updated', '1', home_url('/owner-apartments/')));
        exit;
    }

    private function handlePhotoUpdates(int $apt_id): void
    {
        $deleted_str = sanitize_text_field($_POST['sf_deleted_images'] ?? '');
        $deleted_ids = array_filter(array_map('intval', explode(',', $deleted_str)));
        $order_str   = sanitize_text_field($_POST['sf_gallery_order'] ?? '');
        $order_ids   = array_filter(array_map('intval', explode(',', $order_str)));

        $final_gallery = [];
        foreach ($order_ids as $id) {
            if (!in_array($id, $deleted_ids) && $id > 0) $final_gallery[] = $id;
        }

        if (!empty($_FILES['apt_photos']['name'][0])) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');

            $files = $_FILES['apt_photos'];
            foreach ($files['name'] as $key => $value) {
                if ($files['error'][$key] === UPLOAD_ERR_OK) {
                    $_FILES['temp_up'] = [
                        'name' => $files['name'][$key], 'type' => $files['type'][$key],
                        'tmp_name' => $files['tmp_name'][$key], 'error' => $files['error'][$key], 'size' => $files['size'][$key]
                    ];
                    $attach_id = media_handle_upload('temp_up', $apt_id);
                    if (!is_wp_error($attach_id)) $final_gallery[] = $attach_id;
                }
            }
        }

        if (!empty($final_gallery)) {
            set_post_thumbnail($apt_id, $final_gallery[0]);
            update_post_meta($apt_id, 'mphb_gallery', implode(',', $final_gallery));
        } else {
            delete_post_thumbnail($apt_id);
            update_post_meta($apt_id, 'mphb_gallery', '');
        }
    }

    private function sendAdminNotification(int $postId, string $title, int $userId): void
    {
        // Если хочешь поменять почту, можешь заменить get_option на 'info@stay4fair.com'
        $adminEmail = get_option('admin_email');
        $ownerName  = get_userdata($userId)->display_name ?? 'Unbekannt';
        $editLink   = admin_url('post.php?post=' . $postId . '&action=edit');
        $subject    = '🔄 Apartment geändert: ' . $title;
        $message    = "Host <strong>{$ownerName}</strong> hat Änderungen an <strong>{$title}</strong> vorgenommen. Status: Aktiv (Soft-Mod). <a href='{$editLink}'>Prüfen</a>";
        wp_mail($adminEmail, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
    }
}