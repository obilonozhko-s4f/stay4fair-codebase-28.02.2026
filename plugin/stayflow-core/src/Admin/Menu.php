<?php

declare(strict_types=1);

namespace StayFlow\Admin;

use StayFlow\Registry\ModuleRegistry;
use StayFlow\Settings\SettingsStore;

/**
 * Version: 1.4.0
 * RU: Управление меню и страницами админки.
 * - [NEW]: Добавлено поле `platform_vat_rate_a` для управления НДС Модели А.
 */
final class Menu
{
    public function register(): void
    {
        add_menu_page(
            'StayFlow',
            'StayFlow',
            'manage_options',
            'stayflow-core',
            [$this, 'renderDashboard'],
            'dashicons-admin-generic',
            58
        );

        add_submenu_page(
            'stayflow-core',
            'Settings',
            'Settings',
            'manage_options',
            'stayflow-core-settings',
            [$this, 'renderSettings']
        );

        add_submenu_page(
            'stayflow-core',
            'Content Registry',
            'Content Registry',
            'manage_options',
            'stayflow-core-content-registry',
            [$this, 'renderContentRegistry']
        );

        add_submenu_page(
            'stayflow-core',
            'Policies',
            'Policies',
            'manage_options',
            'stayflow-core-policies',
            [$this, 'renderPolicies']
        );

        add_submenu_page(
            'stayflow-core',
            'Owners',
            'Owners',
            'manage_options',
            'edit.php?post_type=stayflow_owner'
        );
    }

    // =========================================================================
    // 1. ДАШБОРД (Твои красивые плитки)
    // =========================================================================
    public function renderDashboard(): void
    {
        $modules = ModuleRegistry::all();

        ?>
        <div class="wrap stayflow-dashboard">

            <div class="sf-hero">
                <div>
                    <h1>StayFlow Control Center</h1>
                    <p>SaaS-ready enterprise architecture core</p>
                </div>
                <span class="sf-version">v<?php echo esc_html(STAYFLOW_CORE_VERSION); ?></span>
            </div>

            <div class="sf-kpi-grid">
                <?php $this->kpi('Modules', count($modules)); ?>
                <?php $this->kpi('Active', $this->countByStatus($modules, 'active')); ?>
                <?php $this->kpi('Pending', $this->countByStatus($modules, 'pending')); ?>
                <?php $this->kpi('Coming Soon', $this->countByStatus($modules, 'coming')); ?>
            </div>

            <div class="sf-grid">
                <?php foreach ($modules as $module) {
                    $this->card($module);
                } ?>
            </div>

        </div>

        <style>
            .stayflow-dashboard { max-width: 1200px; }
            .stayflow-dashboard .notice { display: none; }
            .sf-hero { background: #212F54; color: white; padding: 30px; border-radius: 16px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
            .sf-hero h1 { margin: 0 0 6px; font-size: 26px; color: #ffffff !important; }
            .sf-hero p { margin: 0; opacity: 0.85; }
            .sf-version { background: #E0B849; color: #111; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
            .sf-kpi-grid, .sf-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
            .sf-kpi { background: white; padding: 20px; border-radius: 16px; box-shadow: 0 6px 18px rgba(0,0,0,0.06); }
            .sf-kpi-value { font-size: 22px; font-weight: 600; }
            .sf-kpi-label { font-size: 13px; color: #6b7280; }
            .sf-card { display: block; background: #ffffff; border-radius: 16px; padding: 24px; box-shadow: 0 8px 24px rgba(0,0,0,0.06); transition: all 0.2s ease; text-decoration: none; color: inherit; }
            .sf-card:hover { transform: translateY(-4px); box-shadow: 0 14px 36px rgba(0,0,0,0.12); }
            .sf-card.sf-disabled { opacity: 0.6; cursor: not-allowed; pointer-events: none; }
            .sf-icon { font-size: 26px; margin-bottom: 12px; }
            .sf-card h3 { margin: 0 0 6px; font-size: 16px; color: #111827; }
            .sf-card p { margin: 0; font-size: 13px; color: #6b7280; }
            .sf-badge { display: inline-block; margin-top: 10px; padding: 4px 10px; font-size: 11px; border-radius: 20px; font-weight: 600; }
            .badge-active { background: #e6f4ea; color: #1e7e34; }
            .badge-pending { background: #fff3cd; color: #856404; }
            .badge-coming { background: #e2e3e5; color: #6c757d; }
            @media (max-width: 782px) { .sf-hero { flex-direction: column; align-items: flex-start; gap: 12px; } }
        </style>
        <?php
    }

    private function kpi(string $label, int $value): void
    {
        echo '<div class="sf-kpi"><div class="sf-kpi-value">' . esc_html((string)$value) . '</div><div class="sf-kpi-label">' . esc_html($label) . '</div></div>';
    }

    private function card(array $module): void
    {
        $isClickable = $module['link'] !== '#';
        $tagStart = $isClickable ? '<a href="' . esc_url(admin_url($module['link'])) . '" class="sf-card">' : '<div class="sf-card sf-disabled">';
        $tagEnd = $isClickable ? '</a>' : '</div>';

        echo $tagStart;
        echo '<div class="sf-icon">' . esc_html($module['icon']) . '</div>';
        echo '<h3>' . esc_html($module['title']) . '</h3>';
        echo '<p>' . esc_html($module['desc']) . '</p>';
        echo '<span class="sf-badge badge-' . esc_attr($module['status']) . '">' . esc_html(ucfirst($module['status'])) . '</span>';
        echo $tagEnd;
    }

    // =========================================================================
    // 2. НАСТРОЙКИ (Settings)
    // =========================================================================
    public function renderSettings(): void
    {
        // RU: Глубокое слияние (Deep Merge). 
        // Гарантирует подтягивание дефолтных текстов, даже если база была пустой.
        $defaults = SettingsStore::defaults();
        $saved = get_option(SettingsStore::OPTION_KEY, []);
        $options = array_replace_recursive($defaults, is_array($saved) ? $saved : []);
        
        $optKey  = SettingsStore::OPTION_KEY;
        ?>
        <div class="wrap stayflow-admin-wrap">
            <h1 class="sf-page-title">⚙️ StayFlow Settings</h1>
            <p style="color: #64748b; margin-bottom: 30px;">Zentrale Konfiguration für Finanzen, Onboarding und Plattform-Standards.</p>

            <?php settings_errors('stayflow_core_settings_group'); ?>

            <form method="post" action="options.php">
                <?php settings_fields('stayflow_core_settings_group'); ?>

                <div class="sf-settings-grid">
                    
                    <div class="sf-settings-card">
                        <h3>💳 Finanz- und Steuer-Standards</h3>
                        <p class="sf-hint">Grundeinstellungen für Buchungen und Berechnungen.</p>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label>Plattform-Land (ISO)</label></th>
                                <td>
                                    <input type="text" name="<?php echo $optKey; ?>[platform_country]" value="<?php echo esc_attr((string)($options['platform_country'] ?? 'DE')); ?>" class="regular-text" style="width: 80px;">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label>Basiswährung</label></th>
                                <td>
                                    <input type="text" name="<?php echo $optKey; ?>[base_currency]" value="<?php echo esc_attr((string)($options['base_currency'] ?? 'EUR')); ?>" class="regular-text" style="width: 80px;">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label>Standard Provision (%)</label></th>
                                <td>
                                    <input type="number" step="0.1" name="<?php echo $optKey; ?>[commission_default]" value="<?php echo esc_attr((string)($options['commission_default'] ?? 15.0)); ?>" class="regular-text" style="width: 100px;">
                                    <p class="description">Als Prozentwert (z.B. <strong>15</strong> für 15%).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label>MwSt-Satz Modell B (%)</label></th>
                                <td>
                                    <input type="number" step="0.1" name="<?php echo $optKey; ?>[platform_vat_rate]" value="<?php echo esc_attr((string)($options['platform_vat_rate'] ?? 19.0)); ?>" class="regular-text" style="width: 100px;">
                                    <p class="description">Wird auf die Vermittlungsprovision angewendet (Standard: 19.0).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label>MwSt-Satz Modell A (%)</label></th>
                                <td>
                                    <input type="number" step="0.1" name="<?php echo $optKey; ?>[platform_vat_rate_a]" value="<?php echo esc_attr((string)($options['platform_vat_rate_a'] ?? 7.0)); ?>" class="regular-text" style="width: 100px;">
                                    <p class="description">Reduzierter Satz für Übernachtungen (Standard: 7.0).</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="sf-settings-card">
                        <h3>✉️ Onboarding: E-Mails & Bestätigung</h3>
                        <p class="sf-hint">Texte für den Registrierungsprozess neuer Vermieter.</p>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label>Betreff (E-Mail)</label></th>
                                <td>
                                    <input type="text" name="<?php echo $optKey; ?>[onboarding][verify_email_sub]" value="<?php echo esc_attr((string)($options['onboarding']['verify_email_sub'] ?? '')); ?>" class="large-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label>Nachricht (E-Mail)</label></th>
                                <td>
                                    <textarea name="<?php echo $optKey; ?>[onboarding][verify_email_body]" rows="6" class="large-text"><?php echo esc_textarea((string)($options['onboarding']['verify_email_body'] ?? '')); ?></textarea>
                                    <p class="description">Verfügbare Variablen: <code>{name}</code>, <code>{verify_link}</code></p>
                                </td>
                            </tr>
                        </table>

                        <h4 style="margin-top: 20px; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px;">Erfolgsseite (Nach Formular-Versand)</h4>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label>Titel</label></th>
                                <td>
                                    <input type="text" name="<?php echo $optKey; ?>[onboarding][success_page_title]" value="<?php echo esc_attr((string)($options['onboarding']['success_page_title'] ?? '')); ?>" class="large-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label>Text</label></th>
                                <td>
                                    <textarea name="<?php echo $optKey; ?>[onboarding][success_page_text]" rows="4" class="large-text"><?php echo esc_textarea((string)($options['onboarding']['success_page_text'] ?? '')); ?></textarea>
                                </td>
                            </tr>
                        </table>
                    </div>

                </div>

                <?php submit_button('Einstellungen speichern', 'primary', 'submit', true, ['style' => 'background: #082567; border-color: #082567; color: #E0B849; padding: 5px 25px; border-radius: 8px; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.1);']); ?>
            </form>
        </div>

        <style>
            .stayflow-admin-wrap { max-width: 1200px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
            .sf-page-title { color: #082567; font-weight: 800; margin-bottom: 5px; }
            .sf-settings-grid { display: grid; grid-template-columns: 1fr; gap: 30px; margin-bottom: 30px; }
            .sf-settings-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 25px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); }
            .sf-settings-card h3 { margin: 0 0 5px 0; color: #082567; font-size: 18px; }
            .sf-hint { color: #64748b; font-size: 13px; margin: 0 0 20px 0; }
            .form-table th { font-weight: 600; color: #1e293b; padding-left: 0; }
            .form-table td { padding-left: 0; }
            .regular-text, .large-text { border-radius: 6px; border: 1px solid #cbd5e1; padding: 6px 10px; width: 100%; box-sizing: border-box; }
            .regular-text:focus, .large-text:focus { border-color: #082567; box-shadow: 0 0 0 1px #082567; }
        </style>
        <?php
    }

    public function renderContentRegistry(): void
    {
        echo '<div class="wrap"><h1>Content Registry</h1><p>Hier kommen bald Vorlagen und Verträge hin.</p></div>';
    }

    public function renderPolicies(): void
    {
        echo '<div class="wrap"><h1>Policies</h1><p>Regeln und Stornierungsbedingungen zentral verwalten.</p></div>';
    }
}
