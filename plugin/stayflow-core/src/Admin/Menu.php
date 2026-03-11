<?php

declare(strict_types=1);

namespace StayFlow\Admin;

use StayFlow\Registry\ModuleRegistry;
use StayFlow\Settings\SettingsStore;

/**
 * Version: 2.1.0
 * RU: Управление меню. Добавлен wp_editor (визуальный редактор) для Policies и Content Registry.
 */
final class Menu
{
    public function register(): void
    {
        add_menu_page('StayFlow', 'StayFlow', 'manage_options', 'stayflow-core', [$this, 'renderDashboard'], 'dashicons-admin-generic', 58);
        add_submenu_page('stayflow-core', 'Settings', 'Settings', 'manage_options', 'stayflow-core-settings', [$this, 'renderSettings']);
        add_submenu_page('stayflow-core', 'Content Registry', 'Content Registry', 'manage_options', 'stayflow-core-content-registry', [$this, 'renderContentRegistry']);
        add_submenu_page('stayflow-core', 'Policies', 'Policies', 'manage_options', 'stayflow-core-policies', [$this, 'renderPolicies']);
        add_submenu_page('stayflow-core', 'Owners', 'Owners', 'manage_options', 'edit.php?post_type=stayflow_owner');

        add_action('admin_init', function() {
            register_setting('stayflow_policies_group', 'stayflow_registry_policies');
            register_setting('stayflow_content_group', 'stayflow_registry_content');
        });
    }

    // =========================================================================
    // 1. ДАШБОРД
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
                <?php foreach ($modules as $module) { $this->card($module); } ?>
            </div>
        </div>
        <?php $this->adminDashboardStyles(); ?>
        <?php
    }
    
    private function kpi(string $label, int $value): void { echo '<div class="sf-kpi"><div class="sf-kpi-value">' . esc_html((string)$value) . '</div><div class="sf-kpi-label">' . esc_html($label) . '</div></div>'; }
    private function countByStatus(array $modules, string $status): int { return count(array_filter($modules, fn($m) => $m['status'] === $status)); }
    private function card(array $module): void {
        $isClickable = $module['link'] !== '#';
        $tagStart = $isClickable ? '<a href="' . esc_url(admin_url($module['link'])) . '" class="sf-card">' : '<div class="sf-card sf-disabled">';
        echo $tagStart . '<div class="sf-icon">' . esc_html($module['icon']) . '</div><h3>' . esc_html($module['title']) . '</h3><p>' . esc_html($module['desc']) . '</p><span class="sf-badge badge-' . esc_attr($module['status']) . '">' . esc_html(ucfirst($module['status'])) . '</span>' . ($isClickable ? '</a>' : '</div>');
    }

    // =========================================================================
    // 2. СЕТТИНГИ
    // =========================================================================
    public function renderSettings(): void
    {
        $defaults = SettingsStore::defaults();
        $saved = get_option(SettingsStore::OPTION_KEY, []);
        $options = array_replace_recursive($defaults, is_array($saved) ? $saved : []);
        $optKey  = SettingsStore::OPTION_KEY;
        ?>
        <div class="wrap stayflow-admin-wrap">
            <h1 class="sf-page-title">⚙️ StayFlow Settings</h1>
            <?php settings_errors('stayflow_core_settings_group'); ?>
            <form method="post" action="options.php">
                <?php settings_fields('stayflow_core_settings_group'); ?>
                <div class="sf-settings-grid">
                    
                    <div class="sf-settings-card">
                        <h3>💳 Finanz- und Steuer-Standards</h3>
                        <table class="form-table">
                            <tr><th scope="row"><label>Plattform-Land</label></th><td><input type="text" name="<?php echo $optKey; ?>[platform_country]" value="<?php echo esc_attr((string)$options['platform_country']); ?>" class="regular-text" style="width: 80px;"></td></tr>
                            <tr><th scope="row"><label>Basiswährung</label></th><td><input type="text" name="<?php echo $optKey; ?>[base_currency]" value="<?php echo esc_attr((string)$options['base_currency']); ?>" class="regular-text" style="width: 80px;"></td></tr>
                            <tr><th scope="row"><label>Standard Provision (%)</label></th><td><input type="number" step="0.1" name="<?php echo $optKey; ?>[commission_default]" value="<?php echo esc_attr((string)$options['commission_default']); ?>" class="regular-text" style="width: 100px;"><p class="description">Als Prozentwert (z.B. 15 für 15%).</p></td></tr>
                            <tr><th scope="row"><label>MwSt-Satz Modell B (%)</label></th><td><input type="number" step="0.1" name="<?php echo $optKey; ?>[platform_vat_rate]" value="<?php echo esc_attr((string)$options['platform_vat_rate']); ?>" class="regular-text" style="width: 100px;"></td></tr>
                            <tr><th scope="row"><label>MwSt-Satz Modell A (%)</label></th><td><input type="number" step="0.1" name="<?php echo $optKey; ?>[platform_vat_rate_a]" value="<?php echo esc_attr((string)$options['platform_vat_rate_a']); ?>" class="regular-text" style="width: 100px;"></td></tr>
                        </table>
                    </div>

                    <div class="sf-settings-card">
                        <h3>✉️ Dokument: Onboarding | Abschnitt: E-Mails & Bestätigung</h3>
                        <table class="form-table">
                            <tr><th scope="row"><label>Betreff (E-Mail)</label></th><td><input type="text" name="<?php echo $optKey; ?>[onboarding][verify_email_sub]" value="<?php echo esc_attr((string)$options['onboarding']['verify_email_sub']); ?>" class="large-text"></td></tr>
                            <tr><th scope="row"><label>Nachricht (E-Mail)</label></th><td><textarea name="<?php echo $optKey; ?>[onboarding][verify_email_body]" rows="5" class="large-text"><?php echo esc_textarea((string)$options['onboarding']['verify_email_body']); ?></textarea></td></tr>
                        </table>
                    </div>

                    <div class="sf-settings-card">
                        <h3>📄 Dokument: Owner E-Mail | Abschnitt: PDF Anhänge</h3>
                        <p class="sf-hint">Texte für E-Mails, an die das Owner-PDF (Abrechnung/Bestätigung) angehängt wird.</p>
                        <table class="form-table">
                            <tr><th scope="row"><label>Betreff (E-Mail)</label></th><td><input type="text" name="<?php echo $optKey; ?>[owner_pdf][email_subject]" value="<?php echo esc_attr((string)$options['owner_pdf']['email_subject']); ?>" class="large-text"><p class="description">Variable: <code>{booking_id}</code></p></td></tr>
                            <tr><th scope="row"><label>Nachricht (E-Mail)</label></th><td><textarea name="<?php echo $optKey; ?>[owner_pdf][email_body]" rows="5" class="large-text"><?php echo esc_textarea((string)$options['owner_pdf']['email_body']); ?></textarea></td></tr>
                        </table>
                    </div>

                </div>
                <?php submit_button('Einstellungen speichern', 'primary', 'submit', true, ['style' => 'background: #082567; border-color: #082567; color: #E0B849; padding: 5px 25px; border-radius: 8px;']); ?>
            </form>
        </div>
        <?php $this->adminStyles(); ?>
        <?php
    }

    // =========================================================================
    // 3. ПОЛИТИКИ ОТМЕНЫ (Policies) - VISUAL EDITOR
    // =========================================================================
    public function renderPolicies(): void
    {
        $optKey = 'stayflow_registry_policies';
        $options = get_option($optKey, []);
        
        $def_flex = "<p><strong>Standard Flexible Cancellation Policy</strong></p>\n<ul>\n<li>Free cancellation up to <strong>{days} days before arrival</strong>.</li>\n<li>For cancellations made <strong>{penalty_days} days or less</strong> before arrival, as well as in case of no-show, <strong>100% of the total booking amount</strong> will be charged.</li>\n<li>Date changes are subject to availability and must be confirmed by Stay4Fair.</li>\n</ul>";
        $def_non_ref = "<p><strong>✨ Non-Refundable – Better Price & Premium Support</strong></p>\n<p>This non-refundable option is usually offered at a more attractive price than flexible bookings.</p>\n<h4>🔐 1. Protected & Guaranteed Booking</h4>\n<ul>\n<li>Your booking price is <strong>locked and protected</strong>.</li>\n<li>If the apartment becomes unavailable due to a landlord cancellation, Stay4Fair will arrange an <strong>equivalent or superior accommodation at no extra cost</strong>.</li>\n</ul>\n<h4>🔄 2. Flexible Date Adjustment</h4>\n<ul>\n<li>You may <strong>adjust your travel dates</strong>, subject to availability.</li>\n<li>The <strong>total number of nights cannot be reduced</strong>.</li>\n</ul>\n<p><strong>⚠️ Important:</strong><br>\nThis booking <strong>cannot be cancelled or refunded</strong>. Full payment remains <strong>non-refundable</strong> after confirmation.</p>";

        // RU: Берем из базы, если пусто — подставляем дефолт
        $flex = !empty($options['free_cancellation']) ? $options['free_cancellation'] : $def_flex;
        $non_ref = !empty($options['non_refundable']) ? $options['non_refundable'] : $def_non_ref;
        ?>
        <div class="wrap stayflow-admin-wrap">
            <h1 class="sf-page-title">🛡️ Cancellation Policies</h1>
            <p style="color: #64748b; margin-bottom: 30px;">Zentrale Verwaltung der Stornierungsbedingungen. Nutzen Sie den Editor für Text, Emojis oder HTML.</p>
            <?php settings_errors('stayflow_policies_group'); ?>
            <form method="post" action="options.php">
                <?php settings_fields('stayflow_policies_group'); ?>
                <div class="sf-settings-grid">
                    
                    <div class="sf-settings-card">
                        <h3>🏨 Modul: Apartment-Seite & Checkout | Abschnitt: Flexible Stornierung</h3>
                        <div class="sf-hint" style="margin-bottom: 15px; padding: 10px; background: #f8fafc; border-left: 3px solid #082567;">
                            Wird verwendet, wenn der Host eine flexible Stornierung anbietet.<br>
                            <strong>Dynamische Variablen (Shortcodes):</strong><br>
                            <code>{days}</code> — Zeigt die Anzahl der Tage für kostenlose Stornierung (z.B. "14").<br>
                            <code>{penalty_days}</code> — Zeigt die Tage, ab denen die Strafe anfällt (z.B. "13").
                        </div>
                        <?php 
                        wp_editor($flex, 'free_cancellation_editor', [
                            'textarea_name' => $optKey . '[free_cancellation]',
                            'media_buttons' => false,
                            'textarea_rows' => 10,
                            'tinymce' => true,
                        ]); 
                        ?>
                    </div>

                    <div class="sf-settings-card">
                        <h3>🏨 Modul: Apartment-Seite & Checkout | Abschnitt: Nicht erstattbar (Non-Refundable)</h3>
                        <div class="sf-hint" style="margin-bottom: 15px; padding: 10px; background: #f8fafc; border-left: 3px solid #E0B849;">
                            Standard-Text für die nicht erstattbare Rate. Emojis 🎉 und Formatierungen werden unterstützt.
                        </div>
                        <?php 
                        wp_editor($non_ref, 'non_refundable_editor', [
                            'textarea_name' => $optKey . '[non_refundable]',
                            'media_buttons' => false,
                            'textarea_rows' => 12,
                            'tinymce' => true,
                        ]); 
                        ?>
                    </div>

                </div>
                <div style="margin-top: 20px;">
                    <?php submit_button('Policies speichern', 'primary', 'submit', false, ['style' => 'background: #082567; border-color: #082567; color: #E0B849; padding: 5px 25px; border-radius: 8px;']); ?>
                </div>
            </form>
        </div>
        <?php $this->adminStyles(); ?>
        <?php
    }

    // =========================================================================
    // 4. РЕЕСТР КОНТЕНТА (Content Registry - Vouchers) - VISUAL EDITOR
    // =========================================================================
    public function renderContentRegistry(): void
    {
        $optKey = 'stayflow_registry_content';
        $options = get_option($optKey, []);
        
        $def_voucher = "<strong>Check-in:</strong> ab 15:00 Uhr<br /><strong>Check-out:</strong> bis 11:00 Uhr<br /><br />Bitte kontaktieren Sie Ihren Gastgeber vorab bezüglich der Schlüsselübergabe.";
        
        // RU: Берем из базы, если пусто — подставляем дефолт
        $voucher_text = !empty($options['voucher_instructions']) ? $options['voucher_instructions'] : $def_voucher;
        ?>
        <div class="wrap stayflow-admin-wrap">
            <h1 class="sf-page-title">📝 Content Registry</h1>
            <p style="color: #64748b; margin-bottom: 30px;">Zentrale Verwaltung für dynamische Textbausteine.</p>
            <?php settings_errors('stayflow_content_group'); ?>
            <form method="post" action="options.php">
                <?php settings_fields('stayflow_content_group'); ?>
                <div class="sf-settings-grid">
                    
                    <div class="sf-settings-card">
                        <h3>📄 Dokument: Gast-Voucher (PDF) | Abschnitt: Check-in / Check-out Anweisungen</h3>
                        <div class="sf-hint" style="margin-bottom: 15px; padding: 10px; background: #f8fafc; border-left: 3px solid #082567;">
                            Dieser Text wird auf dem generierten Gast-Voucher (PDF) angezeigt.<br>
                            Nutzen Sie den Reiter <strong>"Visual"</strong> für normalen Text/Emojis oder <strong>"Text"</strong> für HTML-Eingaben.
                        </div>
                        <?php 
                        wp_editor($voucher_text, 'voucher_instructions_editor', [
                            'textarea_name' => $optKey . '[voucher_instructions]',
                            'media_buttons' => false,
                            'textarea_rows' => 8,
                            'tinymce' => true,
                        ]); 
                        ?>
                    </div>

                </div>
                <div style="margin-top: 20px;">
                    <?php submit_button('Content speichern', 'primary', 'submit', false, ['style' => 'background: #082567; border-color: #082567; color: #E0B849; padding: 5px 25px; border-radius: 8px;']); ?>
                </div>
            </form>
        </div>
        <?php $this->adminStyles(); ?>
        <?php
    }

    private function adminStyles(): void
    {
        ?>
        <style>
            .stayflow-admin-wrap { max-width: 1000px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
            .sf-page-title { color: #082567; font-weight: 800; margin-bottom: 5px; }
            .sf-settings-grid { display: grid; grid-template-columns: 1fr; gap: 30px; margin-bottom: 20px; }
            .sf-settings-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
            .sf-settings-card h3 { margin: 0 0 10px 0; color: #082567; font-size: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; }
            .sf-hint { color: #475569; font-size: 13px; line-height: 1.5; }
            .form-table th { font-weight: 600; color: #1e293b; padding-left: 0; }
            .form-table td { padding-left: 0; }
            .regular-text, .large-text { border-radius: 6px; border: 1px solid #cbd5e1; padding: 6px 10px; width: 100%; box-sizing: border-box; }
            .regular-text:focus, .large-text:focus { border-color: #082567; box-shadow: 0 0 0 1px #082567; }
        </style>
        <?php
    }

    private function adminDashboardStyles(): void
    {
        ?>
        <style>
            .stayflow-dashboard { max-width: 1200px; } .stayflow-dashboard .notice { display: none; }
            .sf-hero { background: #212F54; color: white; padding: 30px; border-radius: 16px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
            .sf-hero h1 { margin: 0 0 6px; font-size: 26px; color: #ffffff !important; } .sf-hero p { margin: 0; opacity: 0.85; }
            .sf-version { background: #E0B849; color: #111; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
            .sf-kpi-grid, .sf-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
            .sf-kpi { background: white; padding: 20px; border-radius: 16px; box-shadow: 0 6px 18px rgba(0,0,0,0.06); }
            .sf-kpi-value { font-size: 22px; font-weight: 600; } .sf-kpi-label { font-size: 13px; color: #6b7280; }
            .sf-card { display: block; background: #ffffff; border-radius: 16px; padding: 24px; box-shadow: 0 8px 24px rgba(0,0,0,0.06); transition: all 0.2s ease; text-decoration: none; color: inherit; }
            .sf-card:hover { transform: translateY(-4px); box-shadow: 0 14px 36px rgba(0,0,0,0.12); }
            .sf-card.sf-disabled { opacity: 0.6; cursor: not-allowed; pointer-events: none; }
            .sf-icon { font-size: 26px; margin-bottom: 12px; } .sf-card h3 { margin: 0 0 6px; font-size: 16px; color: #111827; } .sf-card p { margin: 0; font-size: 13px; color: #6b7280; }
            .sf-badge { display: inline-block; margin-top: 10px; padding: 4px 10px; font-size: 11px; border-radius: 20px; font-weight: 600; }
            .badge-active { background: #e6f4ea; color: #1e7e34; } .badge-pending { background: #fff3cd; color: #856404; } .badge-coming { background: #e2e3e5; color: #6c757d; }
        </style>
        <?php
    }
}
