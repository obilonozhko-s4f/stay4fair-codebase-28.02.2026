<?php
/**
 * File: /stay4fair.com/wp-content/plugins/stayflow-core/src/BusinessModel/RateSyncService.php
 * Version: 1.1.6
 * RU: Служба синхронизации цены хоста с нативными тарифами (Rates) MotoPress.
 * EN: Service for syncing owner price with native MotoPress Rates.
 */

declare(strict_types=1);

namespace StayFlow\BusinessModel;

if (!defined('ABSPATH')) {
    exit;
}

final class RateSyncService
{
    public function register(): void
    {
        add_action('save_post_mphb_room_type', [$this, 'syncNativeMphbData'], 100, 2);
    }

    /* =========================================================
       SECTION: MAIN SYNC LOGIC
       ========================================================= */

    public function syncNativeMphbData(int $propertyId, \WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

        // RU: Предохранитель Model A
        $businessModel = get_post_meta($propertyId, '_bsbt_business_model', true);
        if ($businessModel === 'model_a') {
            return; 
        }

        $ownerPrice = get_post_meta($propertyId, '_sf_owner_price', true);
        $minStay    = get_post_meta($propertyId, '_sf_min_stay', true);

        if ($ownerPrice !== '' && is_numeric($ownerPrice)) {
            $this->syncRateAndSeason($propertyId, (float)$ownerPrice);
        }

        if ($minStay !== '' && is_numeric($minStay)) {
            $this->syncBookingRule($propertyId, (int)$minStay);
        }

        // Чистка кэша
        add_action('shutdown', [$this, 'hardFlushMotoPressCache']);
    }

    public function hardFlushMotoPressCache(): void
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mphb_%' OR option_name LIKE '_transient_timeout_mphb_%'");
        wp_cache_flush();
    }

    /* =========================================================
       SECTION: RATE & SEASON GENERATION
       ========================================================= */

    private function syncRateAndSeason(int $propertyId, float $price): void
    {
        $seasonId = $this->getOrCreateBaseSeason();

        $query = new \WP_Query([
            'post_type'      => 'mphb_rate',
            'meta_key'       => '_sf_auto_rate_for_room',
            'meta_value'     => $propertyId,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'post_status'    => 'publish'
        ]);

        if (!empty($query->posts)) {
            $rateId = (int)$query->posts[0];
        } else {
            $rateId = wp_insert_post([
                'post_type'   => 'mphb_rate',
                'post_title'  => 'Base Rate (StayFlow Auto)',
                'post_status' => 'publish'
            ]);
            update_post_meta($rateId, '_sf_auto_rate_for_room', $propertyId);
        }

        update_post_meta($rateId, 'mphb_room_type_id', (string)$propertyId);
        update_post_meta($rateId, 'mphb_is_multiprice', '0');

        // RU: ФИКС ИЗ ЛОГОВ! Идеальная структура массива для MotoPress.
        // EN: LOG FIX! Perfect array structure for MotoPress.
        $seasonPrices = [
            0 => [
                'season' => (string)$seasonId, // Тот самый ключ, которого не хватало!
                'price'  => [
                    'prices' => [ 0 => (string)$price ]
                ]
            ]
        ];
        
        update_post_meta($rateId, 'mphb_season_prices', $seasonPrices);

        // Обновляем пост, чтобы MotoPress пересчитал всё без ошибок
        wp_update_post(['ID' => $rateId]);
    }

    private function getOrCreateBaseSeason(): int
    {
        $query = new \WP_Query([
            'post_type'      => 'mphb_season',
            'meta_key'       => '_sf_is_global_base_season',
            'meta_value'     => 'yes',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'post_status'    => 'publish'
        ]);

        if (!empty($query->posts)) {
            return (int)$query->posts[0];
        }

        $seasonId = wp_insert_post([
            'post_type'   => 'mphb_season',
            'post_title'  => 'StayFlow Base Season (2024-2099)',
            'post_status' => 'publish'
        ]);

        update_post_meta($seasonId, '_sf_is_global_base_season', 'yes');
        update_post_meta($seasonId, 'mphb_start_date', '2024-01-01');
        update_post_meta($seasonId, 'mphb_end_date', '2099-12-31');
        
        $allDays = ['0', '1', '2', '3', '4', '5', '6'];
        update_post_meta($seasonId, 'mphb_days', $allDays);

        return $seasonId;
    }

    /* =========================================================
       SECTION: BOOKING RULES (MIN STAY) - WP_OPTIONS FIX
       ========================================================= */

    private function syncBookingRule(int $propertyId, int $minStay): void
    {
        // 1. Получаем наш глобальный сезон
        $seasonId = $this->getOrCreateBaseSeason();

        // 2. Достаем глобальный массив правил MotoPress
        $rules = get_option('mphb_min_stay_length', []);
        if (!is_array($rules)) {
            $rules = [];
        }

        // 3. Очистка: ищем и удаляем эту квартиру из старых правил, чтобы не было конфликтов
        foreach ($rules as $index => $rule) {
            if (isset($rule['room_type_ids']) && is_array($rule['room_type_ids'])) {
                $pos = array_search($propertyId, $rule['room_type_ids'], true); // Ищем как int
                if ($pos === false) {
                    $pos = array_search((string)$propertyId, $rule['room_type_ids'], true); // Ищем как string
                }
                
                if ($pos !== false) {
                    unset($rules[$index]['room_type_ids'][$pos]);
                    // Переиндексируем массив ID комнат
                    $rules[$index]['room_type_ids'] = array_values($rules[$index]['room_type_ids']);
                }
            }
            // Если после удаления правило осталось без комнат - удаляем правило целиком
            if (empty($rules[$index]['room_type_ids'])) {
                unset($rules[$index]);
            }
        }

        // 4. Добавляем наше новое, чистое правило
        $rules[] = [
            'min_stay_length' => $minStay,
            'room_type_ids'   => [$propertyId],
            'season_ids'      => [$seasonId]
        ];

        // Переиндексируем главный массив (MotoPress любит порядок 0, 1, 2...)
        $rules = array_values($rules);

        // 5. Сохраняем обратно в ядро MotoPress
        update_option('mphb_min_stay_length', $rules);
    }
}