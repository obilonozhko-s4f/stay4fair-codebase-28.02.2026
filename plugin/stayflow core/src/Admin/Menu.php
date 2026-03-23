<?php

declare(strict_types=1);

namespace StayFlow\Admin;

use StayFlow\Registry\ModuleRegistry;
use StayFlow\Settings\SettingsStore;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 2.14.0
 * RU: Управление меню. Добавлены поля для редактирования Contracting Party (Модели A/B).
 * EN: Menu management. Added fields for Contracting Party (Models A/B).
 */
final class Menu
{
    public function register(): void
    {
        // RU: Регистрация страниц меню / EN: Register menu pages
        add_menu_page('StayFlow', 'StayFlow', 'manage_options', 'stayflow-core', [$this, 'renderDashboard'], 'dashicons-admin-generic', 58);
        add_submenu_page('stayflow-core', 'Settings', 'Settings', 'manage_options', 'stayflow-core-settings', [$this, 'renderSettings']);
        add_submenu_page('stayflow-core', 'Content Registry', 'Content Registry', 'manage_options', 'stayflow-core-content-registry', [$this, 'renderContentRegistry']);
        add_submenu_page('stayflow-core', 'Policies', 'Policies', 'manage_options', 'stayflow-core-policies', [$this, 'renderPolicies']);
        add_submenu_page('stayflow-core', 'Owners', 'Owners', 'manage_options', 'stayflow-owners', [$this, 'renderOwnersTable']);
        add_submenu_page('stayflow-core', 'Finance', 'Finance Hub', 'manage_options', 'stayflow-finance', [$this, 'renderFinanceHub']);

        add_action('admin_init', function() {
            register_setting('stayflow_policies_group', 'stayflow_registry_policies');
            register_setting('stayflow_content_group', 'stayflow_registry_content');
            
            // Фикс прав для User Switching
            if (current_user_can('manage_options') && !current_user_can('switch_users')) {
                $role = get_role('administrator');
                if ($role) {
                    $role->add_cap('switch_users');
                }
            }
        });
    }

    public function renderOwnersTable(): void
    {
        if (class_exists('\\StayFlow\\Admin\\OwnersTable')) {
            (new OwnersTable())->render();
        }
    }

    public function renderFinanceHub(): void
    {
        if (class_exists('\\StayFlow\\Admin\\FinanceHub')) {
            (new FinanceHub())->render();
        }
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
    
    private function kpi(string $label, int $value): void { 
        echo '<div class="sf-kpi"><div class="sf-kpi-value">' . esc_html((string)$value) . '</div><div class="sf-kpi-label">' . esc_html($label) . '</div></div>'; 
    }
    
    private function countByStatus(array $modules, string $status): int { 
        return count(array_filter($modules, fn($m) => $m['status'] === $status)); 
    }
    
    private function card(array $module): void {
        $isClickable = $module['link'] !== '#';
        $link = ($module['key'] === 'owners') ? 'admin.php?page=stayflow-owners' : (($module['key'] === 'finance') ? 'admin.php?page=stayflow-finance' : $module['link']);
        $url = $isClickable ? admin_url($link) : '#';
        
        // RU: Спец-дизайн заголовка для Finance
        $titleHtml = esc_html($module['title']);
        if ($module['key'] === 'finance') {
            $titleHtml = '<span style="font-weight:800; color:#000;">Finance</span><span style="background:#ff9000; color:#000; padding:2px 6px; border-radius:4px; margin-left:5px; font-weight:900;">Taxes</span>';
        }
        
        $tagStart = $isClickable ? '<a href="' . esc_url($url) . '" class="sf-card">' : '<div class="sf-card sf-disabled">';
        echo $tagStart . '<div class="sf-icon">' . esc_html($module['icon']) . '</div><h3 style="margin-bottom:10px;">' . $titleHtml . '</h3><p>' . esc_html($module['desc']) . '</p><span class="sf-badge badge-' . esc_attr($module['status']) . '">' . esc_html(ucfirst($module['status'])) . '</span>' . ($isClickable ? '</a>' : '</div>');
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
    // 3. ПОЛИТИКИ ОТМЕНЫ
    // =========================================================================
    public function renderPolicies(): void
    {
        $optKey = 'stayflow_registry_policies';
        $options = get_option($optKey, []);
        
        $def_flex = "<p><strong>Standard Flexible Cancellation Policy</strong></p>\n<ul>\n<li>Free cancellation up to <strong>{days} days before arrival</strong>.</li>\n<li>For cancellations made <strong>{penalty_days} days or less</strong> before arrival, as well as in case of no-show, <strong>100% of the total booking amount</strong> will be charged.</li>\n<li>Date changes are subject to availability and must be confirmed by Stay4Fair.</li>\n</ul>";
        $def_non_ref = "<p><strong>✨ Non-Refundable – Better Price & Premium Support</strong></p>\n<p>This non-refundable option is usually offered at a more attractive price than flexible bookings.</p>\n<h4>🔐 1. Protected & Guaranteed Booking</h4>\n<ul>\n<li>Your booking price is <strong>locked and protected</strong>.</li>\n<li>If the apartment becomes unavailable due to a landlord cancellation, Stay4Fair will arrange an <strong>equivalent or superior accommodation at no extra cost</strong>.</li>\n</ul>\n<h4>🔄 2. Flexible Date Adjustment</h4>\n<ul>\n<li>You may <strong>adjust your travel dates</strong>, subject to availability.</li>\n<li>The <strong>total number of nights cannot be reduced</strong>.</li>\n</ul>\n<p><strong>⚠️ Important:</strong><br>\nThis booking <strong>cannot be cancelled or refunded</strong>. Full payment remains <strong>non-refundable</strong> after confirmation.</p>";

        $flex = !empty($options['free_cancellation']) ? $options['free_cancellation'] : $def_flex;
        $non_ref = !empty($options['non_refundable']) ? $options['non_refundable'] : $def_non_ref;
        ?>
        <div class="wrap stayflow-admin-wrap">
            <h1 class="sf-page-title">🛡️ Cancellation Policies</h1>
            <?php settings_errors('stayflow_policies_group'); ?>
            <form method="post" action="options.php">
                <?php settings_fields('stayflow_policies_group'); ?>
                <div class="sf-settings-grid">
                    <div class="sf-settings-card">
                        <h3>🏨 Modul: Apartment-Seite & Checkout | Abschnitt: Flexible Stornierung</h3>
                        <div class="sf-hint" style="margin-bottom: 15px; padding: 10px; background: #f8fafc; border-left: 3px solid #082567;">Variablen: <code>{days}</code>, <code>{penalty_days}</code></div>
                        <?php wp_editor($flex, 'free_cancellation_editor', ['textarea_name' => $optKey . '[free_cancellation]', 'media_buttons' => false, 'textarea_rows' => 10, 'tinymce' => true]); ?>
                    </div>
                    <div class="sf-settings-card">
                        <h3>🏨 Modul: Apartment-Seite & Checkout | Abschnitt: Nicht erstattbar (Non-Refundable)</h3>
                        <?php wp_editor($non_ref, 'non_refundable_editor', ['textarea_name' => $optKey . '[non_refundable]', 'media_buttons' => false, 'textarea_rows' => 12, 'tinymce' => true]); ?>
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
    // 4. CONTENT REGISTRY
    // =========================================================================
    public function renderContentRegistry(): void
    {
        $optKey = 'stayflow_registry_content';
        $options = get_option($optKey, []);
        
        $def_voucher = "<strong>Check-in:</strong> ab 15:00 Uhr<br /><strong>Check-out:</strong> bis 11:00 Uhr<br /><br />Bitte kontaktieren Sie Ihren Gastgeber vorab bezüglich der Schlüsselübergabe.";
        $def_tax_single = "<p>Die Auszahlung erfolgt in der Regel innerhalb von 3–7 Werktagen nach Abreise des Gastes.</p>\n<p>Wir freuen uns über Ihre erfolgreichen Buchungen! Bitte beachten Sie, dass die erzielten Einkünfte aus der kurzfristigen Vermietung steuerpflichtig sind. Die Verantwortung für die korrekte Versteuerung sowie die Einhaltung aller steuerlichen Meldepflichten liegt gemäß den gesetzlichen Vorgaben beim Vermieter.</p>\n<p>Ein besonderer Hinweis zum Beherbergungsteuer (City Tax): Bitte prüfen Sie eigenständig die lokalen Satzungen Ihrer Stadt. In vielen Regionen sind Vermieter verpflichtet, diese Steuer ordnungsgemäß zu erfassen und abzuführen. Da die Handhabung je nach Aufenthaltszweck (geschäftlich oder privat) variieren kann, liegt die finale Prüfung und Abwicklung ausschließlich in Ihrer Hand.</p>\n<p>Stay4Fair unterstützt Sie mit der Bereitstellung der Buchungsdaten, übernimmt jedoch keine steuerliche Beratung oder Haftung.</p>";
        $def_tax_monthly = "<p>Die Auszahlung erfolgt in der Regel innerhalb von 3–7 Werktagen nach Abreise des Gastes.</p>\n\n<p><strong>Für Buchungen nach Modell A (Direkt):</strong> Die Abführung der Beherbergungsteuer (City-Tax) für diese Buchungen wurde von Stay4Fair übernommen. Für die Versteuerung Ihrer Einkünfte sind Sie selbst verantwortlich.</p>\n\n<p><strong>Für Buchungen nach Modell B (Vermittlung):</strong> Bitte beachten Sie, dass die erzielten Einkünfte aus der kurzfristigen Vermietung steuerpflichtig sind. Die Verantwortung für die korrekte Versteuerung sowie die Einhaltung aller steuerlichen Meldepflichten liegt beim Vermieter. Bitte prüfen Sie eigenständig die lokalen Satzungen bezüglich Beherbergungssteuer (City Tax).</p>\n\n<p><strong>Stay4Fair unterstützt Sie mit der Bereitstellung der Buchungsdaten, übernimmt jedoch keine steuerliche Beratung oder Haftung.</strong></p>";
        
        // RU: Новые дефолтные тексты для шорткода Contracting Party
        $def_cp_a = "The contracting party is Stay4Fair.com. This property is managed by our professional partner.";
        $def_cp_b = "The contracting party for the accommodation is the respective property owner. Stay4Fair acts as an authorized intermediary.";

        $voucher_text = !empty($options['voucher_instructions']) ? $options['voucher_instructions'] : $def_voucher;
        $tax_single   = !empty($options['tax_notice_single']) ? $options['tax_notice_single'] : $def_tax_single;
        $tax_monthly  = !empty($options['tax_notice_monthly']) ? $options['tax_notice_monthly'] : $def_tax_monthly;
        
        $cp_a = !empty($options['contract_party_text_a']) ? $options['contract_party_text_a'] : $def_cp_a;
        $cp_b = !empty($options['contract_party_text_b']) ? $options['contract_party_text_b'] : $def_cp_b;

        ?>
        <div class="wrap stayflow-admin-wrap">
            <h1 class="sf-page-title">📝 Content Registry</h1>
            <p style="color: #64748b; margin-bottom: 30px;">Zentrale Verwaltung für dynamische Textbausteine.</p>
            <?php settings_errors('stayflow_content_group'); ?>
            <form method="post" action="options.php">
                <?php settings_fields('stayflow_content_group'); ?>
                <div class="sf-settings-grid">
                    
                    <div class="sf-settings-card">
                        <h3>🤝 Contracting Party: Modell A (Stay4Fair)</h3>
                        <div class="sf-hint" style="margin-bottom: 15px; padding: 10px; background: #f8fafc; border-left: 3px solid #082567;">Dieser Text wird auf der Apartment-Seite im Block "Contracting Party" (bei Stay4Fair/Modell A Apartments) angezeigt.</div>
                        <?php wp_editor($cp_a, 'cp_text_a_editor', ['textarea_name' => $optKey . '[contract_party_text_a]', 'media_buttons' => false, 'textarea_rows' => 4, 'tinymce' => true]); ?>
                    </div>

                    <div class="sf-settings-card">
                        <h3>🤝 Contracting Party: Modell B (Owner)</h3>
                        <div class="sf-hint" style="margin-bottom: 15px; padding: 10px; background: #f8fafc; border-left: 3px solid #E0B849;">Dieser Text wird auf der Apartment-Seite im Block "Contracting Party" (bei Owner/Modell B Apartments) angezeigt.</div>
                        <?php wp_editor($cp_b, 'cp_text_b_editor', ['textarea_name' => $optKey . '[contract_party_text_b]', 'media_buttons' => false, 'textarea_rows' => 4, 'tinymce' => true]); ?>
                    </div>

                    <div class="sf-settings-card">
                        <h3>📄 Dokument: Gast-Voucher (PDF) | Abschnitt: Check-in / Check-out Anweisungen</h3>
                        <div class="sf-hint" style="margin-bottom: 15px; padding: 10px; background: #f8fafc; border-left: 3px solid #082567;">Dieser Text wird auf dem generierten Gast-Voucher (PDF) angezeigt.</div>
                        <?php wp_editor($voucher_text, 'voucher_instructions_editor', ['textarea_name' => $optKey . '[voucher_instructions]', 'media_buttons' => false, 'textarea_rows' => 8, 'tinymce' => true]); ?>
                    </div>

                    <div class="sf-settings-card">
                        <h3>📄 Dokument: Owner Buchungsbestätigung (Einzel-PDF) | Abschnitt: Auszahlung & Steuerliche Hinweise</h3>
                        <div class="sf-hint" style="margin-bottom: 15px; padding: 10px; background: #f8fafc; border-left: 3px solid #E0B849;">Dieser Text erscheint am Ende der <strong>einzelnen PDF-Buchungsbestätigung</strong> für den Vermieter.</div>
                        <?php wp_editor($tax_single, 'tax_notice_single_editor', ['textarea_name' => $optKey . '[tax_notice_single]', 'media_buttons' => false, 'textarea_rows' => 12, 'tinymce' => true]); ?>
                    </div>

                    <div class="sf-settings-card">
                        <h3>📄 Dokument: Owner Monatsabrechnung (PDF) | Abschnitt: Auszahlung & Steuerliche Hinweise</h3>
                        <div class="sf-hint" style="margin-bottom: 15px; padding: 10px; background: #f8fafc; border-left: 3px solid #10b981;">Dieser Text erscheint am Ende der gebündelten <strong>Monatsabrechnung</strong>. Er enthält spezifische Hinweise für Modell A und Modell B.</div>
                        <?php wp_editor($tax_monthly, 'tax_notice_monthly_editor', ['textarea_name' => $optKey . '[tax_notice_monthly]', 'media_buttons' => false, 'textarea_rows' => 12, 'tinymce' => true]); ?>
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
            .sf-card { display: block; background: #ffffff; border-radius: 16px; padding: 24px; box-shadow: 0 8px 24px rgba(0,0,0,0.06); transition: all 0.2s ease; text-decoration: none; color: inherit; border: 1px solid #e2e8f0; }
            .sf-card:hover { transform: translateY(-4px); box-shadow: 0 14px 36px rgba(0,0,0,0.12); }
            .sf-card.sf-disabled { opacity: 0.6; cursor: not-allowed; pointer-events: none; }
            .sf-icon { font-size: 26px; margin-bottom: 12px; } .sf-card h3 { margin: 0 0 6px; font-size: 16px; color: #111827; } .sf-card p { margin: 0; font-size: 13px; color: #6b7280; }
            .sf-badge { display: inline-block; margin-top: 10px; padding: 4px 10px; font-size: 11px; border-radius: 20px; font-weight: 600; }
            .badge-active { background: #e6f4ea; color: #1e7e34; } .badge-pending { background: #fff3cd; color: #856404; } .badge-coming { background: #e2e3e5; color: #6c757d; }
        </style>
        <?php
    }
}
