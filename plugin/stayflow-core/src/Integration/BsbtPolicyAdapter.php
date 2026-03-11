<?php
/**
 * RU: Адаптер для вывода политик отмены бронирования (Шорткод).
 * EN: Adapter for rendering cancellation policies (Shortcode).
 */

declare(strict_types=1);

namespace StayFlow\Integration;

if (!defined('ABSPATH')) {
    exit;
}

final class BsbtPolicyAdapter
{
    /**
     * RU: Регистрация шорткода и стилей.
     * EN: Register shortcode and styles.
     */
    public function register(): void
    {
        add_shortcode('bsbt_cancellation_box', [$this, 'renderShortcode']);
        add_action('wp_head', [$this, 'injectStyles']);
    }

    /* =========================================================
       SECTION: RENDER LOGIC
       ========================================================= */

    /**
     * RU: Рендер шорткода [bsbt_cancellation_box id="..."]
     * EN: Render shortcode
     *
     * @param array|string $atts
     * @return string
     */
    public function renderShortcode(array|string $atts): string
    {
        $attributes = shortcode_atts(['id' => 0], is_array($atts) ? $atts : []);
        $roomId = (int) $attributes['id'];

        if ($roomId === 0) {
            $roomId = get_the_ID();
        }

        if (!$roomId || get_post_type($roomId) !== 'mphb_room_type') {
            return '';
        }

        // RU: Читаем новые динамические поля из базы
        // EN: Read new dynamic fields from database
        $policyType = get_post_meta($roomId, '_sf_cancellation_policy', true) ?: 'non_refundable';
        $cancelDays = (int) get_post_meta($roomId, '_sf_cancellation_days', true);

        $content  = $this->getPolicyText($policyType, $cancelDays);
        $boxClass = 'bsbt-cancel-box-' . esc_attr($policyType);

        $html  = '<div class="bsbt-cancel-box ' . $boxClass . '">';
        $html .= '<h3 class="bsbt-cancel-title">Cancellation Policy</h3>';
        $html .= '<div class="bsbt-cancel-content">' . $content . '</div>';
        $html .= '<p class="bsbt-cancel-link-note">';
        $html .= 'Full details can be found in our <a href="' . esc_url(home_url('/cancellation-policy/')) . '" target="_blank">Cancellation Policy</a> ';
        $html .= 'and <a href="' . esc_url(home_url('/terms-and-conditions/')) . '" target="_blank">Terms &amp; Conditions</a>.';
        $html .= '</p>';
        $html .= '</div>';

        return $html;
    }

    /* =========================================================
       SECTION: TEXT GENERATION
       ========================================================= */

    /**
     * RU: Генерация динамического текста на основе правил.
     * EN: Dynamic text generation based on rules.
     */
    private function getPolicyText(string $type, int $days): string
    {
        // RU: Если выбрана бесплатная отмена и указаны дни
        if ($type === 'free_cancellation' && $days > 0) {
            $penaltyDays = $days - 1;
            $text  = '<p><strong>Standard Flexible Cancellation Policy</strong></p>';
            $text .= '<ul>';
            $text .= '<li>Free cancellation up to <strong>' . $days . ' days before arrival</strong>.</li>';
            $text .= '<li>For cancellations made <strong>' . $penaltyDays . ' days or less</strong> before arrival, as well as in case of no-show, <strong>100% of the total booking amount</strong> will be charged.</li>';
            $text .= '<li>Date changes are subject to availability and must be confirmed by Stay4Fair.</li>';
            $text .= '</ul>';
            
            return $text;
        }

        // RU: По умолчанию - безвозвратный тариф (non_refundable)
        $text  = '<p><strong>✨ Non-Refundable – Better Price & Premium Support</strong></p>';
        $text .= '<p>This non-refundable option is usually offered at a more attractive price than flexible bookings.</p>';
        
        $text .= '<h4>🔐 1. Protected & Guaranteed Booking</h4>';
        $text .= '<ul>';
        $text .= '<li>Your booking price is <strong>locked and protected</strong>, even if market prices increase.</li>';
        $text .= '<li>If the apartment becomes unavailable due to a landlord cancellation, Stay4Fair will arrange an <strong>equivalent or superior accommodation at no extra cost</strong>.</li>';
        $text .= '<li>Priority assistance and relocation support.</li>';
        $text .= '</ul>';
        
        $text .= '<h4>🔄 2. Flexible Date Adjustment</h4>';
        $text .= '<ul>';
        $text .= '<li>You may <strong>adjust your travel dates</strong>, subject to availability.</li>';
        $text .= '<li>The <strong>total number of nights cannot be reduced</strong>.</li>';
        $text .= '</ul>';
        
        $text .= '<p><strong>⚠️ Important:</strong><br>';
        $text .= 'This booking <strong>cannot be cancelled or refunded</strong>. Full payment remains <strong>non-refundable</strong> after confirmation.</p>';

        return $text;
    }

    /* =========================================================
       SECTION: STYLES
       ========================================================= */

    /**
     * RU: Внедрение стилей в <head>.
     * EN: Inject styles into <head>.
     */
    public function injectStyles(): void
    {
        ?>
        <style>
            .bsbt-cancel-box { border-radius: 10px; border: 1px solid rgba(33, 47, 84, 0.10); padding: 18px 20px; margin: 24px 0; background: #ffffff; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08); font-family: "Manrope", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
            .bsbt-cancel-title { margin: 0 0 8px; font-size: 18px; color: #212F54; font-weight: 700; }
            .bsbt-cancel-content p, .bsbt-cancel-content ul { font-size: 14px; color: #212F54; }
            .bsbt-cancel-box-non_refundable { border-color: rgba(224, 184, 73, 0.6); background: #fffaf2; }
            .bsbt-cancel-box-free_cancellation { border-color: rgba(33, 47, 84, 0.25); }
        </style>
        <?php
    }
}