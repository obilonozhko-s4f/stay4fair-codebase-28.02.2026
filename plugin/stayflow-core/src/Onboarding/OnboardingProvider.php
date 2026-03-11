<?php

declare(strict_types=1);

namespace StayFlow\Onboarding;

/**
 * Version: 1.1.4
 * RU: Форма регистрации с автозаполнением полей при возврате с ошибкой.
 * EN: Registration form with auto-fill fields on error redirect.
 */
final class OnboardingProvider
{
    public function register(): void
    {
        add_shortcode('sf_owner_onboarding', [$this, 'renderOnboardingForm']);
    }

    public function renderOnboardingForm(): string
    {
        wp_enqueue_style('sf-onboarding-style', plugins_url('assets/css/onboarding.css', dirname(__FILE__, 2)));

        // RU: Дефолтные пустые данные
        $formData = [
            'owner_type'       => 'private',
            'owner_first_name' => '',
            'owner_last_name'  => '',
            'owner_email'      => '',
            'owner_phone'      => '',
        ];

        // RU: Ловим ошибку
        $errorHtml = '';
        if (isset($_GET['sf_error'])) {
            $errorCode = sanitize_text_field($_GET['sf_error']);
            $errorMsg = 'Ein unbekannter Fehler ist aufgetreten.';

            switch ($errorCode) {
                case 'email_exists':
                    $errorMsg = 'Diese E-Mail-Adresse ist bereits registriert. Bitte loggen Sie sich ein oder verwenden Sie eine andere E-Mail.';
                    break;
                case 'password':
                    $errorMsg = 'Die Passwörter stimmen nicht überein. Bitte versuchen Sie es erneut.';
                    break;
                case 'terms':
                    $errorMsg = 'Bitte akzeptieren Sie die AGB und die Datenschutzerklärung.';
                    break;
                case 'nonce':
                    $errorMsg = 'Ihre Sitzung ist abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es noch einmal.';
                    break;
                case 'bot':
                    $errorMsg = 'Spam-Verdacht. Die Registrierung wurde blockiert.';
                    break;
                case 'system':
                    $errorMsg = 'Systemfehler bei der Kontoerstellung. Bitte kontaktieren Sie unseren Support.';
                    break;
            }

            $errorHtml = '<div style="background-color: #fef2f2; border-left: 4px solid #ef4444; color: #991b1b; padding: 12px 16px; margin-bottom: 20px; border-radius: 4px; font-size: 14px; line-height: 1.5;">' . 
                         '<strong>Fehler:</strong> ' . esc_html($errorMsg) . 
                         '</div>';
        }

        // RU: Восстанавливаем данные из сессии (Transients), если они есть
        if (isset($_GET['sf_sess'])) {
            $sessId = sanitize_text_field($_GET['sf_sess']);
            $savedData = get_transient('sf_ob_sess_' . $sessId);
            if (is_array($savedData)) {
                $formData = wp_parse_args($savedData, $formData);
                delete_transient('sf_ob_sess_' . $sessId); // Чистим память после использования
            }
        }

        ob_start();
        ?>
        <div class="sf-onboarding-container">
            
            <?php echo $errorHtml; ?>

            <form id="sf-onboarding-form" method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sf_process_onboarding">
                <?php wp_nonce_field('sf_onboarding_action', 'sf_onboarding_nonce'); ?>

                <h2 style="color:#082567; text-align:center; margin-bottom: 25px;">Als Gastgeber registrieren</h2>
                
                <div class="sf-form-group">
                    <label>Ich bin ein: *</label>
                    <select name="owner_type" required>
                        <option value="private" <?php selected($formData['owner_type'], 'private'); ?>>Privatperson (Einzelvermieter)</option>
                        <option value="business" <?php selected($formData['owner_type'], 'business'); ?>>Gewerblicher Anbieter / Hotel / Agentur</option>
                    </select>
                </div>

                <div style="display: flex; gap: 15px;">
                    <div class="sf-form-group" style="flex:1;">
                        <label>Vorname *</label>
                        <input type="text" name="owner_first_name" value="<?php echo esc_attr($formData['owner_first_name']); ?>" required>
                    </div>
                    <div class="sf-form-group" style="flex:1;">
                        <label>Nachname *</label>
                        <input type="text" name="owner_last_name" value="<?php echo esc_attr($formData['owner_last_name']); ?>" required>
                    </div>
                </div>

                <div class="sf-form-group">
                    <label>E-Mail Adresse *</label>
                    <input type="email" name="owner_email" value="<?php echo esc_attr($formData['owner_email']); ?>" required>
                </div>

                <div class="sf-form-group">
                    <label>Telefon / WhatsApp *</label>
                    <input type="text" name="owner_phone" placeholder="+49..." value="<?php echo esc_attr($formData['owner_phone']); ?>" required>
                </div>

                <div class="sf-form-group" style="position:relative;">
                    <label>Passwort wählen *</label>
                    <input type="password" id="sf_pass" name="owner_pass" required>
                    <span onclick="toggleSfPass()" style="position:absolute; right:15px; top:38px; cursor:pointer; font-size: 18px;" title="Passwort anzeigen">👁️</span>
                </div>

                <div class="sf-form-group">
                    <label>Passwort wiederholen *</label>
                    <input type="password" id="sf_pass_confirm" name="owner_pass_confirm" required>
                </div>

                <div class="sf-consent-block" style="margin-bottom: 20px; padding: 15px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                    <div class="sf-form-group" style="display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px;">
                        <input type="checkbox" name="accept_agb" id="accept_agb" style="margin-top: 4px;" required>
                        <label for="accept_agb" style="font-weight: 400; font-size: 13px; line-height: 1.4; color: #1e293b;">
                            Ich akzeptiere die <a href="https://stay4fair.com/owner-terms-agb/" target="_blank" style="color: #082567; text-decoration: underline;">Allgemeinen Geschäftsbedingungen</a> (AGB) für Vermieter. *
                        </label>
                    </div>

                    <div class="sf-form-group" style="display: flex; align-items: flex-start; gap: 10px; margin: 0;">
                        <input type="checkbox" name="accept_privacy" id="accept_privacy" style="margin-top: 4px;" required>
                        <label for="accept_privacy" style="font-weight: 400; font-size: 13px; line-height: 1.4; color: #1e293b;">
                            Ich habe die <a href="/datenschutz/" target="_blank" style="color: #082567; text-decoration: underline;">Datenschutzerklärung</a> gelesen und bin damit einverstanden. *
                        </label>
                    </div>
                </div>

                <div style="display:none !important;">
                    <input type="text" name="sf_confirm_email_field" tabindex="-1" autocomplete="off">
                </div>

                <button type="submit" class="sf-btn-submit btn-gold">Konto erstellen</button>
            </form>
        </div>

        <script>
        function toggleSfPass() {
            var x = document.getElementById("sf_pass");
            var y = document.getElementById("sf_pass_confirm");
            if (x.type === "password") { x.type = "text"; y.type = "text"; }
            else { x.type = "password"; y.type = "password"; }
        }
        </script>
        <?php
        return ob_get_clean();
    }
}