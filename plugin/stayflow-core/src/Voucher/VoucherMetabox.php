<?php

declare(strict_types=1);

namespace StayFlow\Voucher;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.0.0
 * RU: Метабоксы и AJAX обработчик для Ваучера в админке MPHB Booking.
 * EN: Metaboxes and AJAX handler for Voucher in MPHB Booking admin.
 */
final class VoucherMetabox
{
    public function register(): void
    {
        add_action('admin_init', [$this, 'handlePdfDownload']);
        add_action('add_meta_boxes', [$this, 'addMetaBoxes'], 10, 1);
        add_action('wp_ajax_bsbt_send_voucher_now', [$this, 'ajaxSendVoucherManual']);
    }

    public function handlePdfDownload(): void
    {
        if (empty($_GET['bs_voucher_pdf']) || empty($_GET['booking_id'])) return;

        $bookingId = (int) $_GET['booking_id'];
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'bs_voucher_pdf_' . $bookingId)) wp_die('Nonce failed');
        if (!current_user_can('edit_post', $bookingId)) wp_die('No permission');

        $html = VoucherGenerator::renderHtml($bookingId);
        $openInline = !empty($_GET['inline']);

        $uploadDir = wp_upload_dir();
        $dir  = trailingslashit($uploadDir['basedir']) . 'bs-vouchers';
        if (!is_dir($dir)) wp_mkdir_p($dir);
        $file = trailingslashit($dir) . 'Voucher-' . $bookingId . '-' . date('Ymd-His') . '.pdf';

        $engine = VoucherGenerator::tryLoadPdfEngine();

        try {
            if ($engine === 'mpdf' && class_exists('\Mpdf\Mpdf')) {
                $mpdf = new \Mpdf\Mpdf(['format'=>'A4','margin_left'=>12,'margin_right'=>12,'margin_top'=>14,'margin_bottom'=>14]);
                $mpdf->WriteHTML($html);
                $mpdf->Output($file, \Mpdf\Output\Destination::FILE);

                header('Content-Type: application/pdf');
                header('Content-Disposition: ' . ($openInline ? 'inline' : 'attachment') . '; filename="'.basename($file).'"');
                readfile($file); exit;
            } elseif ($engine === 'dompdf' && class_exists('\Dompdf\Dompdf')) {
                $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled'=>true]);
                $dompdf->loadHtml($html, 'UTF-8');
                $dompdf->setPaper('A4','portrait');
                $dompdf->render();
                file_put_contents($file, $dompdf->output());

                header('Content-Type: application/pdf');
                header('Content-Disposition: ' . ($openInline ? 'inline' : 'attachment') . '; filename="'.basename($file).'"');
                readfile($file); exit;
            }
        } catch (\Throwable $e) {}

        echo $html; exit;
    }

    public function addMetaBoxes(string $postType): void
    {
        if ($postType !== 'mphb_booking') return;

        add_meta_box(
            'bs_voucher_pdf_box',
            __('BS Voucher (PDF)', 'stayflow-core'),
            [$this, 'renderActionBox'],
            'mphb_booking',
            'side',
            'high'
        );

        add_meta_box(
            'bsbt_voucher_log_box',
            __('Voucher Log', 'stayflow-core'),
            [$this, 'renderLogBox'],
            'mphb_booking',
            'side',
            'default'
        );
    }

    public function renderActionBox($post): void
    {
        if (!$post || empty($post->ID)) return;

        $base = admin_url('post.php?post=' . $post->ID . '&action=edit');

        $viewUrl = wp_nonce_url(add_query_arg([
            'bs_voucher_pdf' => 1,
            'booking_id'     => $post->ID,
            'inline'         => 1,
        ], $base), 'bs_voucher_pdf_' . $post->ID);

        $downloadUrl = wp_nonce_url(add_query_arg([
            'bs_voucher_pdf' => 1,
            'booking_id'     => $post->ID,
            'download'       => 1,
        ], $base), 'bs_voucher_pdf_' . $post->ID);

        $guestEmail = trim((string)get_post_meta($post->ID, 'mphb_email', true));
        $nonceSend  = wp_create_nonce('bsbt_send_voucher_now');

        echo '<div style="display:flex;flex-direction:column;gap:8px;">';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap">';
        echo '<a class="button" target="_blank" href="'.esc_url($viewUrl).'">Open Voucher (PDF)</a>';
        echo '<a class="button button-primary" href="'.esc_url($downloadUrl).'">Download Voucher (PDF)</a>';
        echo '</div>';

        echo '<div>';
        echo '<label for="bsbt_voucher_email"><strong>Email:</strong></label><br/>';
        printf('<input type="email" id="bsbt_voucher_email" value="%s" style="width:100%%" placeholder="guest@example.com" />', esc_attr($guestEmail));
        echo '</div>';

        echo '<div><button class="button button-secondary" id="bsbt_send_btn">Send Voucher</button></div>';
        echo '</div>';

        ?>
        <script>
        (function(){
            const btn = document.getElementById('bsbt_send_btn');
            if(!btn) return;
            btn.addEventListener('click', function(e){
                e.preventDefault();
                const email = (document.getElementById('bsbt_voucher_email')||{}).value || '';
                btn.disabled = true; const old = btn.textContent; btn.textContent = 'Sending...';
                const data = new FormData();
                data.append('action','bsbt_send_voucher_now');
                data.append('booking_id','<?php echo esc_js($post->ID); ?>');
                data.append('nonce','<?php echo esc_js($nonceSend); ?>');
                data.append('email', email.trim());
                fetch(ajaxurl,{method:'POST',body:data}).then(r=>r.json()).then(j=>{
                    alert(j && j.message ? j.message : 'Done');
                }).catch(()=>alert('Request failed')).finally(()=>{
                    btn.disabled=false; btn.textContent=old;
                });
            });
        })();
        </script>
        <?php
    }

    public function renderLogBox($post): void
    {
        $log = get_post_meta($post->ID, '_bsbt_voucher_log', true);
        if (!is_array($log) || empty($log)) { 
            echo '<p>No voucher emails sent yet.</p>'; 
            return; 
        }
        
        echo '<div style="max-height:260px;overflow:auto;font-family:monospace;font-size:12px;line-height:1.35">';
        foreach (array_reverse($log) as $row) {
            $badge = ($row['status'] === 'ok') ? 'background:#22c55e;color:#fff' : 'background:#ef4444;color:#fff';
            printf(
                '<div style="border-bottom:1px solid #e5e5e5;padding:8px 0;margin:0">
                    <div><span style="padding:2px 6px;border-radius:6px;%s">%s</span> <strong>%s</strong></div>
                    <div>%s</div>
                    <div style="color:#666">%s → %s</div>
                    %s
                </div>',
                esc_attr($badge),
                esc_html(strtoupper($row['status'] ?? '')),
                esc_html($row['source'] ?? ''),
                esc_html($row['time'] ?? ''),
                esc_html($row['to'] ?? ''),
                esc_html($row['subject'] ?? ''),
                !empty($row['error']) ? '<div style="color:#ef4444">Error: '.esc_html($row['error']).'</div>' : ''
            );
        }
        echo '</div>';
    }

    public function ajaxSendVoucherManual(): void
    {
        if (!current_user_can('edit_posts')) wp_send_json(['ok'=>false,'message'=>'No permission']);
        $nonce = sanitize_text_field($_POST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'bsbt_send_voucher_now')) wp_send_json(['ok'=>false,'message'=>'Nonce error']);

        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $email     = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if ($bookingId <= 0) wp_send_json(['ok'=>false,'message'=>'Bad booking id']);

        $sender = new VoucherSender();
        $res = $sender->sendManualOrAutoEmail($bookingId, 'manual:button', $email ?: null);
        
        if (!empty($res['error'])) wp_send_json(['ok'=>false,'message'=>'Error: '.$res['error']]);
        wp_send_json(['ok'=>true,'message'=>'Voucher sent to ' . ($email ?: 'guest email')]);
    }
}