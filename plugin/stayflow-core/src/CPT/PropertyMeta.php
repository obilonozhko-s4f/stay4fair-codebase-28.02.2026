<?php
/**
 * File: /stay4fair.com/wp-content/plugins/stayflow-core/src/CPT/PropertyMeta.php
 * Version: 1.0.5
 * RU: Управление мета-полями для типа записи mphb_room_type (Квартиры).
 * EN: Meta fields management for mphb_room_type post type (Accommodations).
 */

declare(strict_types=1);

namespace StayFlow\CPT;

if (!defined('ABSPATH')) {
    exit;
}

final class PropertyMeta
{
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBoxes']);
        // RU: Возвращаем приоритет 99, так как кэш теперь чистится в shutdown
        add_action('save_post_mphb_room_type', [$this, 'saveMeta'], 99, 2);
    }

    public function addMetaBoxes(): void
    {
        add_meta_box(
            'stayflow_property_settings',
            'StayFlow: Настройки Объекта / Property Settings',
            [$this, 'renderMetabox'],
            'mphb_room_type',
            'normal',
            'high'
        );
    }

    public function renderMetabox(\WP_Post $post): void
    {
        wp_nonce_field('sf_property_meta_action', 'sf_property_meta_nonce');

        $communeId    = get_post_meta($post->ID, '_sf_commune_reg_id', true);
        $minStay      = get_post_meta($post->ID, '_sf_min_stay', true);
        $cancelPolicy = get_post_meta($post->ID, '_sf_cancellation_policy', true) ?: 'non_refundable';
        $cancelDays   = get_post_meta($post->ID, '_sf_cancellation_days', true);
        $fairReturn   = get_post_meta($post->ID, '_sf_fair_return', true);
        $ownerPrice   = get_post_meta($post->ID, '_sf_owner_price', true);
        
        // RU: Вернул iCal
        $icalExport   = get_post_meta($post->ID, '_sf_ical_export', true);
        $icalImport   = get_post_meta($post->ID, '_sf_ical_import', true);
        
        $businessModel = get_post_meta($post->ID, '_bsbt_business_model', true);
        $isModelA      = ($businessModel === 'model_a');

        ?>
        <style>
            .sf-meta-container { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
            .sf-meta-grid { display: grid; grid-template-columns: 1fr; gap: 16px; margin-bottom: 20px; }
            @media (min-width: 768px) { .sf-meta-grid { grid-template-columns: 1fr 1fr; } }
            .sf-meta-group { display: flex; flex-direction: column; width: 100%; box-sizing: border-box; }
            .sf-meta-group label { font-weight: 600; margin-bottom: 6px; color: #1e293b; }
            .sf-meta-group input[type="text"], .sf-meta-group input[type="number"], .sf-meta-group select { width: 100%; padding: 6px 8px; box-sizing: border-box; }
            .sf-meta-title { font-size: 14px; font-weight: 700; border-bottom: 1px solid #cbd5e1; padding-bottom: 6px; margin: 24px 0 12px; color: #0f172a; }
            .sf-meta-help { font-size: 12px; color: #64748b; margin-top: 4px; }
            .sf-checkbox-group { display: flex; align-items: flex-start; gap: 8px; margin-top: 8px; }
            .sf-checkbox-group input { margin-top: 2px; }
            .sf-checkbox-group label { font-weight: 400; color: #334155; }
            .sf-alert-model-a { background: #fef2f2; border-left: 4px solid #ef4444; padding: 12px; color: #991b1b; font-size: 13px; margin-bottom: 15px; }
        </style>

        <div class="sf-meta-container">
            
            <?php if ($isModelA): ?>
                <div class="sf-alert-model-a">
                    <strong>⚠️ Внимание (Model A):</strong> Этот объект работает по маржинальной модели. Автоматическая генерация тарифов отключена.
                </div>
            <?php endif; ?>

            <div class="sf-meta-grid">
                <div class="sf-meta-group">
                    <label for="sf_commune_reg_id">Wohnung ID (Amtliche Registrierung):</label>
                    <input type="text" id="sf_commune_reg_id" name="sf_commune_reg_id" value="<?php echo esc_attr($communeId); ?>">
                </div>

                <div class="sf-meta-group">
                    <label for="sf_owner_price">Базовая цена (Конечная) / Final Price (€):</label>
                    <?php if ($isModelA): ?>
                        <input type="text" value="Заблокировано (Model A)" disabled style="background:#f1f5f9;">
                    <?php else: ?>
                        <input type="text" id="sf_owner_price" name="sf_owner_price" value="<?php echo esc_attr($ownerPrice); ?>" placeholder="100.00 или 100,00" required>
                    <?php endif; ?>
                </div>

                <div class="sf-meta-group">
                    <label for="sf_min_stay">Минимум ночей / Minimum Stay:</label>
                    <input type="number" id="sf_min_stay" name="sf_min_stay" value="<?php echo esc_attr($minStay); ?>" min="1">
                </div>

                <div class="sf-meta-group">
                    <div class="sf-checkbox-group">
                        <input type="checkbox" id="sf_fair_return" name="sf_fair_return" value="1" <?php checked($fairReturn, '1'); ?>>
                        <label for="sf_fair_return">Участвовать в "Fair Return" (-10%)</label>
                    </div>
                </div>
            </div>

            <div class="sf-meta-title">Отмена бронирования / Cancellation Policy</div>
            <div class="sf-meta-grid">
                <div class="sf-meta-group">
                    <label for="sf_cancellation_policy">Тип правил:</label>
                    <select id="sf_cancellation_policy" name="sf_cancellation_policy">
                        <option value="non_refundable" <?php selected($cancelPolicy, 'non_refundable'); ?>>Безвозвратная</option>
                        <option value="free_cancellation" <?php selected($cancelPolicy, 'free_cancellation'); ?>>Бесплатная отмена</option>
                    </select>
                </div>
                
                <div class="sf-meta-group" id="sf_cancel_days_wrap" style="<?php echo $cancelPolicy === 'free_cancellation' ? 'display:flex;' : 'display:none;' ?>">
                    <label for="sf_cancellation_days">Дней до заезда:</label>
                    <input type="number" id="sf_cancellation_days" name="sf_cancellation_days" value="<?php echo esc_attr($cancelDays); ?>" min="1" max="365">
                </div>
            </div>

            <div class="sf-meta-title">Синхронизация / iCal Sync</div>
            <div class="sf-meta-grid">
                <div class="sf-meta-group">
                    <label for="sf_ical_export">iCal Export (Для Airbnb/Booking):</label>
                    <input type="text" id="sf_ical_export" name="sf_ical_export" value="<?php echo esc_attr($icalExport); ?>" readonly placeholder="Сгенерируется автоматически">
                </div>
                <div class="sf-meta-group">
                    <label for="sf_ical_import">iCal Import (От Airbnb/Booking):</label>
                    <input type="text" id="sf_ical_import" name="sf_ical_import" value="<?php echo esc_attr($icalImport); ?>" placeholder="https://...">
                </div>
            </div>
            
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var select = document.getElementById('sf_cancellation_policy');
                var wrap = document.getElementById('sf_cancel_days_wrap');
                if (select && wrap) {
                    select.addEventListener('change', function() {
                        wrap.style.display = (this.value === 'free_cancellation') ? 'flex' : 'none';
                    });
                }
            });
        </script>
        <?php
    }

    public function saveMeta(int $postId, \WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['sf_property_meta_nonce']) || !wp_verify_nonce($_POST['sf_property_meta_nonce'], 'sf_property_meta_action')) return;
        if (!current_user_can('edit_post', $postId)) return;

        $businessModel = get_post_meta($postId, '_bsbt_business_model', true);
        $isModelA      = ($businessModel === 'model_a');

        if (isset($_POST['sf_commune_reg_id'])) update_post_meta($postId, '_sf_commune_reg_id', sanitize_text_field(wp_unslash($_POST['sf_commune_reg_id'])));
        if (isset($_POST['sf_min_stay'])) update_post_meta($postId, '_sf_min_stay', absint($_POST['sf_min_stay']));
        update_post_meta($postId, '_sf_fair_return', isset($_POST['sf_fair_return']) ? '1' : '0');

        // RU: Вернул сохранение iCal
        if (isset($_POST['sf_ical_import'])) {
            update_post_meta($postId, '_sf_ical_import', esc_url_raw(wp_unslash($_POST['sf_ical_import'])));
        }

        if (isset($_POST['sf_cancellation_policy'])) {
            $policy = sanitize_text_field(wp_unslash($_POST['sf_cancellation_policy']));
            update_post_meta($postId, '_sf_cancellation_policy', $policy);
            if ($policy === 'free_cancellation' && !empty($_POST['sf_cancellation_days'])) {
                update_post_meta($postId, '_sf_cancellation_days', absint($_POST['sf_cancellation_days']));
            } else {
                delete_post_meta($postId, '_sf_cancellation_days');
            }
        }

        if (!$isModelA) {
            if (isset($_POST['sf_owner_price']) && $_POST['sf_owner_price'] !== '') {
                $priceStr = sanitize_text_field(wp_unslash($_POST['sf_owner_price']));
                $priceStr = str_replace(',', '.', $priceStr); 
                update_post_meta($postId, '_sf_owner_price', (float)$priceStr);
                update_post_meta($postId, '_bsbt_business_model', 'model_b');
            } else {
                delete_post_meta($postId, '_sf_owner_price');
            }
        }
    }
}