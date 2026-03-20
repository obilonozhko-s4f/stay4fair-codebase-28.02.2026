<?php

declare(strict_types=1);

namespace StayFlow\CPT;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.1.0
 * RU: Провайдер страницы профиля владельца.
 * - [FIX]: Ключи синхронизированы с админкой (bsbt_iban, bsbt_account_holder, bsbt_tax_number).
 * - [NEW]: Добавлены хуки для вывода недостающих полей (доп. телефон, компания) в админку WP.
 */
final class OwnerProfileProvider
{
    public function register(): void
    {
        add_shortcode('sf_owner_profile', [$this, 'renderProfile']);
        
        // RU: Вывод полей в админку (wp-admin/user-edit.php)
        add_action('show_user_profile', [$this, 'addAdminUserFields']);
        add_action('edit_user_profile', [$this, 'addAdminUserFields']);
        
        // RU: Сохранение полей из админки
        add_action('personal_options_update', [$this, 'saveAdminUserFields']);
        add_action('edit_user_profile_update', [$this, 'saveAdminUserFields']);
    }

    public static function isActionRequired(int $userId): bool
    {
        // Используем правильные ключи
        $iban = get_user_meta($userId, 'bsbt_iban', true);
        $steuernummer = get_user_meta($userId, 'bsbt_tax_number', true);
        
        return empty(trim((string)$iban)) || empty(trim((string)$steuernummer));
    }

    // === SECTION: Admin Fields ===

    public function addAdminUserFields(\WP_User $user): void
    {
        $altPhone = get_user_meta($user->ID, 'bsbt_alt_phone', true);
        $accType = get_user_meta($user->ID, 'sf_account_type', true) ?: 'private';
        $companyName = get_user_meta($user->ID, 'sf_company_name', true);
        $vatId = get_user_meta($user->ID, 'sf_vat_id', true);
        $companyReg = get_user_meta($user->ID, 'sf_company_reg', true);

        echo '<h3>StayFlow: Zusätzliche Owner-Daten</h3>';
        echo '<table class="form-table">';
        
        echo '<tr><th><label>Account Typ</label></th><td><input type="text" value="' . esc_attr(ucfirst($accType)) . '" class="regular-text" readonly></td></tr>';
        echo '<tr><th><label for="bsbt_alt_phone">Alternative Telefonnummer</label></th><td><input type="text" name="bsbt_alt_phone" id="bsbt_alt_phone" value="' . esc_attr($altPhone) . '" class="regular-text"></td></tr>';
        
        echo '<tr><th><label for="sf_company_name">Firmenname</label></th><td><input type="text" name="sf_company_name" id="sf_company_name" value="' . esc_attr($companyName) . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="sf_vat_id">USt-IdNr. (VAT ID)</label></th><td><input type="text" name="sf_vat_id" id="sf_vat_id" value="' . esc_attr($vatId) . '" class="regular-text"></td></tr>';
        echo '<tr><th><label for="sf_company_reg">Handelsregisternummer</label></th><td><input type="text" name="sf_company_reg" id="sf_company_reg" value="' . esc_attr($companyReg) . '" class="regular-text"></td></tr>';
        
        echo '</table>';
    }

    public function saveAdminUserFields(int $userId): void
    {
        if (!current_user_can('edit_user', $userId)) return;
        
        if (isset($_POST['bsbt_alt_phone'])) update_user_meta($userId, 'bsbt_alt_phone', sanitize_text_field($_POST['bsbt_alt_phone']));
        if (isset($_POST['sf_company_name'])) update_user_meta($userId, 'sf_company_name', sanitize_text_field($_POST['sf_company_name']));
        if (isset($_POST['sf_vat_id'])) update_user_meta($userId, 'sf_vat_id', sanitize_text_field($_POST['sf_vat_id']));
        if (isset($_POST['sf_company_reg'])) update_user_meta($userId, 'sf_company_reg', sanitize_text_field($_POST['sf_company_reg']));
    }

    // === SECTION: Render Page ===

    public function renderProfile(): string
    {
        if (!is_user_logged_in()) return '<div class="sf-alert">Bitte loggen Sie sich ein.</div>';

        $user = wp_get_current_user();
        $userId = $user->ID;

        $accType     = get_user_meta($userId, 'sf_account_type', true) ?: 'private';
        $firstName   = get_user_meta($userId, 'first_name', true) ?: $user->first_name;
        $lastName    = get_user_meta($userId, 'last_name', true) ?: $user->last_name;
        $email       = $user->user_email;
        $phone       = get_user_meta($userId, 'billing_phone', true);
        
        // RU: Исправленные ключи
        $altPhone    = get_user_meta($userId, 'bsbt_alt_phone', true);
        $bankName    = get_user_meta($userId, 'bsbt_account_holder', true) ?: "$firstName $lastName";
        $iban        = get_user_meta($userId, 'bsbt_iban', true);
        $steuerId    = get_user_meta($userId, 'bsbt_tax_number', true);

        $address     = get_user_meta($userId, 'billing_address_1', true);
        $postcode    = get_user_meta($userId, 'billing_postcode', true);
        $city        = get_user_meta($userId, 'billing_city', true);

        $companyName = get_user_meta($userId, 'sf_company_name', true);
        $vatId       = get_user_meta($userId, 'sf_vat_id', true);
        $companyReg  = get_user_meta($userId, 'sf_company_reg', true);

        $actionRequired = self::isActionRequired($userId);

        ob_start();
        ?>
        <style>
            .sf-profile-wrap { max-width: 900px; margin: 0 auto; font-family: 'Segoe UI', Roboto, sans-serif; color: #1e293b; }
            .sf-alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; }
            .sf-alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #f87171; }
            .sf-alert-success { background: #f0fdf4; color: #166534; border: 1px solid #4ade80; }
            .sf-alert-warning { background: #fffbeb; color: #b45309; border: 1px solid #fbbf24; }

            .sf-profile-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; margin-bottom: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); }
            .sf-profile-card h3 { color: #082567; margin: 0 0 5px 0; font-size: 20px; font-weight: 700; border-bottom: 2px solid #E0B849; padding-bottom: 10px; }
            .sf-field-hint { font-size: 12px; color: #64748b; margin: 5px 0 15px 0; line-height: 1.4; }
            
            .sf-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 15px; }
            @media(max-width: 768px) { .sf-form-grid { grid-template-columns: 1fr; } }
            
            .sf-form-group { display: flex; flex-direction: column; }
            .sf-form-group label { font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 5px; }
            .sf-form-group input { padding: 10px 12px; border-radius: 8px; border: 1px solid #cbd5e1; outline: none; font-size: 14px; transition: 0.2s; }
            .sf-form-group input:focus { border-color: #082567; box-shadow: 0 0 0 3px rgba(8,37,103,0.1); }

            .sf-3d-btn { position: relative !important; overflow: hidden !important; border-radius: 10px !important; border: none !important; box-shadow: 0 14px 28px rgba(0,0,0,0.45), 0 4px 8px rgba(0,0,0,0.25), inset 0 -5px 10px rgba(0,0,0,0.50), inset 0 1px 0 rgba(255,255,255,0.30), inset 0 0 0 1px rgba(255,255,255,0.06) !important; transition: all 0.25s ease !important; cursor: pointer !important; display: inline-flex; align-items: center; justify-content: center; padding: 12px 24px; background-color: #E0B849 !important; color: #082567 !important; background-image: linear-gradient(180deg, rgba(255,255,255,0.35) 0%, rgba(255,255,255,0.1) 55%, rgba(0,0,0,0.18) 100%) !important; background-blend-mode: overlay; font-weight: 700; font-size: 14px; }
            .sf-3d-btn:hover { transform: translateY(-2px) !important; background-color: #082567 !important; color: #E0B849 !important; }
            .btn-danger { background-color: #ef4444 !important; color: #fff !important; }
            .btn-danger:hover { background-color: #991b1b !important; color: #f87171 !important; }
            .btn-submit { margin-top: 20px; width: 100%; }
        </style>

        <div class="sf-profile-wrap">
            
            <?php if (isset($_GET['updated'])): ?>
                <div class="sf-alert sf-alert-success">✅ Profil erfolgreich aktualisiert.</div>
            <?php endif; ?>
            <?php if (isset($_GET['security_updated'])): ?>
                <div class="sf-alert sf-alert-success">🔐 Sicherheitseinstellungen erfolgreich gespeichert.</div>
            <?php endif; ?>
            <?php if (isset($_GET['security_error'])): ?>
                <div class="sf-alert sf-alert-danger">❌ Falsches aktuelles Passwort. Änderungen nicht gespeichert.</div>
            <?php endif; ?>
            <?php if (isset($_GET['delete_error']) && $_GET['delete_error'] === 'active_bookings'): ?>
                <div class="sf-alert sf-alert-danger">
                    <strong>⚠️ Aktion blockiert!</strong><br>
                    Ihre Apartments wurden vom Netz genommen (Offline). Eine vollständige Löschung des Accounts ist jedoch nicht möglich, da Sie noch aktive zukünftige Buchungen haben. Bitte kontaktieren Sie unseren Support.
                </div>
            <?php endif; ?>
            <?php if ($actionRequired): ?>
                <div class="sf-alert sf-alert-warning" style="display:flex; align-items:center; gap:10px;">
                    <span style="font-size:24px;">🔴</span>
                    <div>
                        <strong>Aktion erforderlich (DAC7)</strong><br>
                        Bitte füllen Sie Ihre Steuer-ID (Steuernummer) und IBAN aus, um Auszahlungen zu erhalten.
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sf_process_owner_profile">
                <input type="hidden" name="profile_action" value="save_profile">
                <?php wp_nonce_field('sf_owner_profile_nonce', 'sf_profile_csrf'); ?>

                <div class="sf-profile-card">
                    <h3>Persönliche Daten & Kontakt</h3>
                    <p class="sf-field-hint">Ihre primären Kontaktdaten. Account Typ: <strong><?php echo ucfirst($accType); ?></strong></p>
                    
                    <div class="sf-form-grid">
                        <div class="sf-form-group">
                            <label>Vorname *</label>
                            <input type="text" name="first_name" value="<?php echo esc_attr($firstName); ?>" required>
                        </div>
                        <div class="sf-form-group">
                            <label>Nachname *</label>
                            <input type="text" name="last_name" value="<?php echo esc_attr($lastName); ?>" required>
                        </div>
                        <div class="sf-form-group">
                            <label>Telefonnummer *</label>
                            <input type="text" name="phone" value="<?php echo esc_attr($phone); ?>" required>
                        </div>
                        <div class="sf-form-group">
                            <label>Alternative Telefonnummer</label>
                            <input type="text" name="alt_phone" value="<?php echo esc_attr($altPhone); ?>">
                        </div>
                    </div>

                    <h4 style="margin: 25px 0 10px 0; color:#475569;">Rechnungsadresse</h4>
                    <div class="sf-form-grid">
                        <div class="sf-form-group" style="grid-column: 1 / -1;">
                            <label>Straße & Hausnummer *</label>
                            <input type="text" name="address" value="<?php echo esc_attr($address); ?>" required>
                        </div>
                        <div class="sf-form-group">
                            <label>Postleitzahl *</label>
                            <input type="text" name="postcode" value="<?php echo esc_attr($postcode); ?>" required>
                        </div>
                        <div class="sf-form-group">
                            <label>Stadt *</label>
                            <input type="text" name="city" value="<?php echo esc_attr($city); ?>" required>
                        </div>
                    </div>
                </div>

                <?php if ($accType === 'commercial'): ?>
                <div class="sf-profile-card">
                    <h3>Unternehmensdaten</h3>
                    <p class="sf-field-hint">Zusätzliche Daten für gewerbliche Gastgeber.</p>
                    <div class="sf-form-grid">
                        <div class="sf-form-group" style="grid-column: 1 / -1;">
                            <label>Firmenname *</label>
                            <input type="text" name="company_name" value="<?php echo esc_attr($companyName); ?>" required>
                        </div>
                        <div class="sf-form-group">
                            <label>USt-IdNr. (VAT ID) *</label>
                            <input type="text" name="vat_id" value="<?php echo esc_attr($vatId); ?>" required>
                        </div>
                        <div class="sf-form-group">
                            <label>Handelsregisternummer (Optional)</label>
                            <input type="text" name="company_reg" value="<?php echo esc_attr($companyReg); ?>">
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="sf-profile-card" style="border-color: <?php echo $actionRequired ? '#fbbf24' : '#e2e8f0'; ?>;">
                    <h3>Bank & Steuern (DAC7)</h3>
                    <p class="sf-field-hint">Pflichtangaben zur Abwicklung Ihrer Auszahlungen und gemäß EU-Richtlinie DAC7.</p>
                    <div class="sf-form-grid">
                        <div class="sf-form-group">
                            <label>Kontoinhaber *</label>
                            <input type="text" name="bank_name" value="<?php echo esc_attr($bankName); ?>" required>
                        </div>
                        <div class="sf-form-group">
                            <label>IBAN (Standard) *</label>
                            <input type="text" name="iban" value="<?php echo esc_attr($iban); ?>" required style="<?php echo empty($iban) ? 'border-color:#ef4444;' : ''; ?>">
                        </div>
                        <div class="sf-form-group" style="grid-column: 1 / -1;">
                            <label>Steuernummer (Steuer-ID) *</label>
                            <input type="text" name="steuernummer" value="<?php echo esc_attr($steuerId); ?>" required style="<?php echo empty($steuerId) ? 'border-color:#ef4444;' : ''; ?>">
                            <p class="sf-field-hint" style="margin-top:4px;">Ihre persönliche Identifikationsnummer (IdNr.) oder Steuernummer des Unternehmens.</p>
                        </div>
                    </div>
                </div>

                <button type="submit" class="sf-3d-btn btn-submit">Profil Speichern</button>
            </form>

            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 40px;">
                <input type="hidden" name="action" value="sf_process_owner_profile">
                <input type="hidden" name="profile_action" value="save_security">
                <?php wp_nonce_field('sf_owner_profile_nonce', 'sf_profile_csrf'); ?>

                <div class="sf-profile-card">
                    <h3>Sicherheit & Login</h3>
                    <p class="sf-field-hint">Um E-Mail oder Passwort zu ändern, müssen Sie Ihr aktuelles Passwort bestätigen.</p>
                    
                    <div class="sf-form-grid">
                        <div class="sf-form-group" style="grid-column: 1 / -1;">
                            <label>Aktuelle E-Mail Adresse</label>
                            <input type="email" name="user_email" value="<?php echo esc_attr($email); ?>" required>
                        </div>
                        <div class="sf-form-group">
                            <label>Neues Passwort (Optional)</label>
                            <input type="password" name="new_pass" placeholder="Leer lassen, wenn keine Änderung">
                        </div>
                        <div class="sf-form-group">
                            <label>Neues Passwort bestätigen</label>
                            <input type="password" name="new_pass_confirm" placeholder="Passwort wiederholen">
                        </div>
                        <div class="sf-form-group" style="grid-column: 1 / -1; border-top: 1px dashed #cbd5e1; padding-top: 15px; margin-top: 5px;">
                            <label style="color:#991b1b;">Aktuelles Passwort (Pflichtfeld zur Bestätigung) *</label>
                            <input type="password" name="current_pass" required>
                        </div>
                    </div>
                    <button type="submit" class="sf-3d-btn btn-submit">Sicherheitseinstellungen speichern</button>
                </div>
            </form>

            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 40px;" onsubmit="return confirm('Möchten Sie Ihren Account wirklich deaktivieren? Ihre Apartments werden sofort offline genommen.');">
                <input type="hidden" name="action" value="sf_process_owner_profile">
                <input type="hidden" name="profile_action" value="soft_delete">
                <?php wp_nonce_field('sf_owner_profile_nonce', 'sf_profile_csrf'); ?>

                <div class="sf-profile-card" style="border-color: #fca5a5; background: #fef2f2;">
                    <h3 style="color: #991b1b; border-color: #fca5a5;">Gefahrenzone (Danger Zone)</h3>
                    <p class="sf-field-hint" style="color: #7f1d1d;">Wenn Sie Ihren Account deaktivieren, werden alle Ihre Apartments sofort vom Netz genommen (offline). Sollten Sie noch zukünftige Buchungen haben, können wir den Account nicht sofort komplett löschen.</p>
                    
                    <button type="submit" class="sf-3d-btn btn-danger" style="margin-top: 10px;">Account deaktivieren / löschen</button>
                </div>
            </form>

        </div>

        <script>
            document.querySelector('input[name="new_pass_confirm"]').addEventListener('input', function(e) {
                const p1 = document.querySelector('input[name="new_pass"]').value;
                if (p1 && e.target.value !== p1) {
                    e.target.setCustomValidity("Passwörter stimmen nicht überein");
                } else {
                    e.target.setCustomValidity("");
                }
            });
        </script>
        <?php
        return ob_get_clean();
    }
}