<?php

declare(strict_types=1);

namespace StayFlow\Settings;

/**
 * Version: 1.4.0
 * RU: Хранилище настроек.
 * - [NEW]: Добавлена настройка `platform_vat_rate_a` (НДС для Модели А).
 */
final class SettingsStore
{
    public const OPTION_KEY = 'stayflow_core_settings';

    public static function defaults(): array
    {
        return [
            'platform_country'    => 'DE',
            'base_currency'       => 'EUR',
            'platform_vat_rate'   => 19.0, // Для Модели B (Комиссия)
            'platform_vat_rate_a' => 7.0,  // Для Модели A (Прямая продажа)
            'commission_default'  => 0.15, // 15%
            'commission_min'      => 5.0,
            'commission_max'      => 100.0,
            'reverse_charge_mode' => 'pending',
            'enabled_models'      => ['A', 'B', 'C'],
            'onboarding' => [
                'verify_email_sub'   => 'Willkommen bei Stay4Fair – Bitte bestätigen Sie Ihre E-Mail-Adresse',
                'verify_email_body'  => "Hallo {name},\n\nvielen Dank für Ihre Registrierung! Bitte klicken Sie auf den Link unten, um Ihr Konto zu aktivieren:\n{verify_link}\n\nNach der Aktivierung können Sie direkt Ihr erstes Apartment im Dashboard hinzufügen.\n\nIhr Stay4Fair Team",
                'success_page_title' => 'Fast geschafft!',
                'success_page_text'  => 'Ihre Registrierung war erfolgreich. Wir haben Ihnen eine E-Mail zur Bestätigung gesendet. Bitte klicken Sie auf den Link in der Nachricht, um Ihr Konto zu aktivieren und Zugang zum Dashboard zu erhalten.',
            ],
        ];
    }

    public function register(): void
    {
        register_setting('stayflow_core_settings_group', self::OPTION_KEY, [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default'           => self::defaults(),
            'show_in_rest'      => false,
        ]);

        add_shortcode('sf_commission', [$this, 'renderCommissionShortcode']);
        add_shortcode('sf_vat', [$this, 'renderVatShortcode']);
    }

    public function renderCommissionShortcode(array|string $atts): string
    {
        $atts = is_array($atts) ? shortcode_atts(['format' => 'percent'], $atts) : ['format' => 'percent'];
        $val = $this->get('commission_default', 0.15);
        $num = (float)$val * 100;
        return $atts['format'] === 'number' ? (string)$num : $num . '%';
    }

    public function renderVatShortcode(array|string $atts): string
    {
        $atts = is_array($atts) ? shortcode_atts(['format' => 'percent'], $atts) : ['format' => 'percent'];
        $val = $this->get('platform_vat_rate', 19.0);
        $num = (float)$val;
        return $atts['format'] === 'number' ? (string)$num : $num . '%';
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        $settings = get_option(self::OPTION_KEY, self::defaults());
        return is_array($settings) ? ($settings[$key] ?? $fallback) : $fallback;
    }

    public function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $onboarding = self::defaults()['onboarding'];
        
        if (isset($input['onboarding']) && is_array($input['onboarding'])) {
            $onboarding['verify_email_sub']   = sanitize_text_field($input['onboarding']['verify_email_sub'] ?? '');
            $onboarding['verify_email_body']  = sanitize_textarea_field($input['onboarding']['verify_email_body'] ?? '');
            $onboarding['success_page_title'] = sanitize_text_field($input['onboarding']['success_page_title'] ?? '');
            $onboarding['success_page_text']  = sanitize_textarea_field($input['onboarding']['success_page_text'] ?? '');
        }

        return [
            'platform_country'    => sanitize_text_field((string)($input['platform_country'] ?? 'DE')),
            'base_currency'       => strtoupper(sanitize_text_field((string)($input['base_currency'] ?? 'EUR'))),
            'platform_vat_rate'   => (float)($input['platform_vat_rate'] ?? 19.0),
            'platform_vat_rate_a' => (float)($input['platform_vat_rate_a'] ?? 7.0),
            'commission_default'  => (float)($input['commission_default'] ?? 0.15),
            'onboarding'          => $onboarding,
            'enabled_models'      => ['A', 'B', 'C'],
        ];
    }
}
