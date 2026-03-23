<?php

declare(strict_types=1);

namespace StayFlow\Integration;

use StayFlow\BusinessModel\BusinessModelEngine;
use StayFlow\Registry\ContentRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * File: /stayflow-core/src/Integration/ContractingPartyShortcode.php
 * Version: 1.1.0
 * RU: Шорткод [stayflow_contracting_party]. Выводит блок "Кто сдает жилье" с поддержкой локального аватара.
 * EN: Shortcode [stayflow_contracting_party]. Displays "Contracting Party" block with local avatar support.
 */
final class ContractingPartyShortcode
{
    public function register(): void
    {
        add_shortcode('stayflow_contracting_party', [$this, 'render']);
    }

    public function render($atts): string
    {
        // RU: Получаем ID текущего поста (ожидается комната)
        // EN: Get current post ID (expected room type)
        $room_id = get_the_ID();
        if (!$room_id || get_post_type($room_id) !== 'mphb_room_type') {
            return '';
        }

        $engine = BusinessModelEngine::instance();
        
        // RU: Защищенный вызов Registry (fallback на случай отсутствия ключей)
        // EN: Safe Registry call (fallback in case keys are missing)
        $texts = [];
        if (class_exists('\StayFlow\Registry\ContentRegistry')) {
            $contentRegistry = new ContentRegistry();
            $texts = $contentRegistry->getData();
        }

        // RU: Определяем бизнес-модель
        // EN: Resolve business model
        $model_meta = get_post_meta($room_id, '_bsbt_business_model', true) ?: 'model_a';
        $is_model_b = $engine->isModelB($model_meta);

        // RU: Данные автора (Владельца)
        // EN: Author data (Owner)
        $author_id = (int) get_post_field('post_author', $room_id);
        
        if ($is_model_b) {
            // RU: Имя: берем first_name, иначе display_name
            $name = get_user_meta($author_id, 'first_name', true) ?: get_the_author_meta('display_name', $author_id);
            
            // RU: Аватар: проверяем кастомный загруженный аватар, иначе Gravatar
            $custom_avatar_id = get_user_meta($author_id, 'stayflow_avatar_id', true);
            if ($custom_avatar_id && wp_get_attachment_image_url((int)$custom_avatar_id, 'thumbnail')) {
                $avatar = wp_get_attachment_image_url((int)$custom_avatar_id, 'thumbnail');
            } else {
                $avatar = get_avatar_url($author_id, ['size' => 120]);
            }

            $title   = 'Hosted by ' . esc_html($name);
            $desc    = $texts['contract_party_text_b'] ?? 'The contracting party for the accommodation is the respective property owner. Stay4Fair acts as an authorized intermediary.';
            $badge   = 'Verified Owner';
        } else {
            $name    = 'Stay4Fair.com';
            $avatar  = 'http://stay4fair.com/wp-content/uploads/2025/12/short-logo-color-3.png';
            $title   = 'Stay4Fair Partner';
            $desc    = $texts['contract_party_text_a'] ?? 'The contracting party is Stay4Fair.com. This property is managed by our professional partner.';
            $badge   = 'Managed Property';
        }

        ob_start();
        ?>
        <style>
            .sf-contract-card { display: flex; align-items: center; gap: 20px; padding: 24px; background: #ffffff; border-radius: 16px; border: 1px solid #eef2f6; box-shadow: 0 10px 30px -10px rgba(8, 37, 103, 0.08); font-family: 'Manrope', sans-serif; margin: 20px 0; transition: transform 0.3s ease; }
            .sf-contract-card:hover { transform: translateY(-2px); }
            .sf-contract-avatar-wrap { position: relative; flex-shrink: 0; }
            .sf-contract-avatar { width: 70px; height: 70px; border-radius: 50%; object-fit: cover; background: #f8fafc; border: 3px solid #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
            .sf-contract-info { flex-grow: 1; }
            .sf-contract-badge { display: inline-block; padding: 2px 8px; background: rgba(8, 37, 103, 0.05); color: #082567; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; border-radius: 4px; margin-bottom: 6px; }
            .sf-contract-name { display: block; font-size: 18px; font-weight: 700; color: #082567; margin-bottom: 4px; }
            .sf-contract-desc { font-size: 13px; color: #64748b; line-height: 1.5; margin: 0; }
            @media (max-width: 480px) { .sf-contract-card { flex-direction: column; text-align: center; } }
        </style>

        <div class="sf-contract-card">
            <div class="sf-contract-avatar-wrap">
                <img src="<?php echo esc_url($avatar); ?>" class="sf-contract-avatar" alt="<?php echo esc_attr($name); ?>">
            </div>
            <div class="sf-contract-info">
                <span class="sf-contract-badge"><?php echo esc_html($badge); ?></span>
                <strong class="sf-contract-name"><?php echo esc_html($title); ?></strong>
                <p class="sf-contract-desc"><?php echo esc_html($desc); ?></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
