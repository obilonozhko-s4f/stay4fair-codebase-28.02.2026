<?php
/**
 * Plugin Name: BSBT – Owner PDF
 * Description: Owner booking confirmation + payout summary PDF. (V2.2.0 - Monthly Report Added)
 * Version: 2.2.0
 * Author: BS Business Travelling / Stay4Fair.com
 */

if (!defined('ABSPATH')) exit;

final class BSBT_Owner_PDF {

    const META_LOG            = '_bsbt_owner_pdf_log';
    const META_MAIL_SENT      = '_bsbt_owner_pdf_mail_sent';
    const META_MAIL_SENT_AT   = '_bsbt_owner_pdf_mail_sent_at';
    const META_MAIL_LAST_ERR  = '_bsbt_owner_pdf_mail_last_error';
    const ACF_OWNER_EMAIL_KEY = 'field_68fccdd0cdffc';

    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'register_metabox'], 10, 2);
        add_action('add_meta_boxes_mphb_booking', [__CLASS__, 'register_metabox_direct'], 10, 1);

        add_action('admin_post_bsbt_owner_pdf_generate', [__CLASS__, 'admin_generate']);
        add_action('admin_post_bsbt_owner_pdf_open',     [__CLASS__, 'admin_open']);
        add_action('admin_post_bsbt_owner_pdf_resend',   [__CLASS__, 'admin_resend']);
        
        // RU: Хук для месячного отчета / EN: Hook for monthly report
        add_action('admin_post_bsbt_owner_monthly_pdf',  [__CLASS__, 'admin_monthly_pdf']);

        add_action('mphb_booking_status_confirmed', [__CLASS__, 'on_booking_confirmed'], 20, 1);
        add_action('bsbt_owner_booking_approved', [__CLASS__, 'maybe_auto_send'], 20, 1);
    }

    public static function on_booking_confirmed($booking) {
        if (!$booking || !is_object($booking) || !method_exists($booking, 'getId')) return;
        self::maybe_auto_send($booking->getId());
    }

    public static function maybe_auto_send( int $bid ) {
        if ($bid <= 0) return;
        if (get_post_meta($bid, self::META_MAIL_SENT, true) === '1') return;
        if (!function_exists('MPHB')) return;
        $booking = MPHB()->getBookingRepository()->findById($bid);
        if (!$booking) return;

        $res = self::generate_pdf($bid, ['trigger' => 'status_confirmed']);

        if (!empty($res['ok']) && !empty($res['path']) && file_exists($res['path'])) {
            $to = self::get_owner_email($bid);
            if (empty($to) || !is_email($to)) {
                update_post_meta($bid, self::META_MAIL_LAST_ERR, 'Keine E-Mail (weder beim Benutzer noch im Apartment) hinterlegt.');
                return;
            }

            $mail_ok = self::email_owner($bid, $to, $res['path']);

            if ($mail_ok) {
                update_post_meta($bid, self::META_MAIL_SENT, '1');
                update_post_meta($bid, self::META_MAIL_SENT_AT, current_time('mysql'));
                delete_post_meta($bid, self::META_MAIL_LAST_ERR);
            } else {
                update_post_meta($bid, self::META_MAIL_LAST_ERR, 'Fehler beim Senden der E-Mail (Server wp_mail error).');
            }
        }
    }

    /* =========================================================
     * MONTHLY PDF GENERATION (NEW)
     * ======================================================= */
    
    public static function admin_monthly_pdf() {
        if (!is_user_logged_in() || !isset($_POST['monthly_pdf_nonce']) || !wp_verify_nonce($_POST['monthly_pdf_nonce'], 'bsbt_owner_monthly_pdf')) {
            wp_die('Sicherheit Check fehlgeschlagen.');
        }

        $user_id = get_current_user_id();
        $is_admin = current_user_can('manage_options');
        $month = (int)($_POST['f_month'] ?? date('n'));
        $year  = (int)($_POST['f_year'] ?? date('Y'));

        $args = [
            'post_type'      => 'mphb_booking',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'meta_query'     => [
                'relation' => 'AND',
                ['key' => '_bsbt_snapshot_owner_payout', 'compare' => 'EXISTS']
            ]
        ];
        
        $query = new WP_Query($args);
        $items = [];
        $total_gross = 0.0;
        $total_prov = 0.0;
        $total_net = 0.0;
        $has_model_a = false;
        $has_model_b = false;

        if ($query->have_posts()) {
            foreach ($query->posts as $post) {
                $bid = $post->ID;
                
                // Проверка владельца (как в таблице)
                if (!$is_admin && self::get_owner_id_from_booking($bid) !== $user_id) continue;

                // Проверка по дате выезда (Check-out Date)
                $out = (string)get_post_meta($bid, 'mphb_check_out_date', true);
                if (!$out) continue;
                $out_time = strtotime($out);
                if ((int)date('n', $out_time) !== $month || (int)date('Y', $out_time) !== $year) continue;

                $data = self::collect_data($bid);
                if (empty($data['ok'])) continue;

                $p = $data['data'];
                $items[] = $p;

                // Суммируем сырые данные (добавлены в collect_data)
                $gross = (float)($p['raw_guest_gross'] ?? 0);
                $net   = (float)($p['raw_payout'] ?? 0);
                $prov  = 0;
                
                if (isset($p['pricing']['commission_gross_total'])) {
                    $prov = (float)$p['pricing']['commission_gross_total'];
                } elseif ($p['model_key'] === 'model_b') {
                    $prov = $gross - $net; // Fallback
                }

                $total_gross += $gross;
                $total_prov += $prov;
                $total_net += $net;

                if ($p['model_key'] === 'model_a') $has_model_a = true;
                if ($p['model_key'] === 'model_b') $has_model_b = true;
            }
        }

        if (empty($items)) {
            wp_die("Keine Abrechnungen für " . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . "/$year gefunden. <br><br><a href='javascript:history.back()'>Zurück</a>");
        }

        // Данные владельца
        $u = get_userdata($user_id);
        $owner_name = get_user_meta($user_id, 'sf_company_name', true) ?: (get_user_meta($user_id, 'first_name', true) . ' ' . get_user_meta($user_id, 'last_name', true));
        if (!trim($owner_name)) $owner_name = $u->display_name;
        
        $address = get_user_meta($user_id, 'billing_address_1', true) . ', ' . get_user_meta($user_id, 'billing_postcode', true) . ' ' . get_user_meta($user_id, 'billing_city', true);
        $tax_id = get_user_meta($user_id, 'bsbt_tax_number', true) ?: get_user_meta($user_id, 'sf_vat_id', true);
        $iban = get_user_meta($user_id, 'bsbt_iban', true);

        $pdf_data = [
            'month' => str_pad((string)$month, 2, '0', STR_PAD_LEFT),
            'year' => $year,
            'items' => $items,
            'total_gross' => $total_gross,
            'total_prov' => $total_prov,
            'total_net' => $total_net,
            'has_model_a' => $has_model_a,
            'has_model_b' => $has_model_b,
            'owner_name' => $owner_name,
            'owner_address' => trim($address, ', '),
            'owner_tax' => $tax_id,
            'owner_iban' => $iban,
        ];

        if (!class_exists('\StayFlow\Voucher\VoucherGenerator')) {
            wp_die('StayFlow PDF engine not available. Please activate StayFlow Core.');
        }

        ob_start();
        $d = $pdf_data;
        include plugin_dir_path(__FILE__) . 'templates/owner-monthly-pdf.php';
        $html = ob_get_clean();

        $engine = \StayFlow\Voucher\VoucherGenerator::tryLoadPdfEngine();
        $filename = "Monatsabrechnung_{$year}_{$pdf_data['month']}.pdf";

        try {
            if ($engine === 'mpdf') {
                $mpdf = new \Mpdf\Mpdf(['format' => 'A4-L']); // Landscape формат для таблиц
                $mpdf->WriteHTML($html);
                $mpdf->Output($filename, 'D'); // Форсируем скачивание
            } else {
                $dom = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
                $dom->setPaper('A4', 'landscape');
                $dom->loadHtml($html, 'UTF-8');
                $dom->render();
                $dom->stream($filename, ["Attachment" => true]);
            }
            exit;
        } catch (\Throwable $e) {
            wp_die('PDF Error: ' . $e->getMessage());
        }
    }

    private static function get_owner_id_from_booking(int $booking_id): int {
        $oid = (int) get_post_meta($booking_id, 'bsbt_owner_id', true);
        if ($oid) return $oid;
        if (!function_exists('MPHB')) return 0;
        $b = MPHB()->getBookingRepository()->findById($booking_id);
        if (!$b) return 0;
        $rooms = $b->getReservedRooms();
        if (empty($rooms)) return 0;
        return (int) get_post_meta($rooms[0]->getRoomTypeId(), 'bsbt_owner_id', true);
    }

    /* =========================================================
     * SINGLE PDF GENERATION
     * ======================================================= */

    private static function generate_pdf(int $bid, array $ctx): array {

        if (!class_exists('\StayFlow\Voucher\VoucherGenerator')) {
            return ['ok'=>false, 'message'=>'StayFlow PDF engine not available'];
        }

        $data = self::collect_data($bid);
        if (empty($data['ok'])) return ['ok'=>false, 'message'=>'Collect data failed'];

        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'bsbt-owner-pdf/';
        wp_mkdir_p($dir);

        $path = $dir . 'Owner_PDF_' . $bid . '.pdf';

        try {
            $engine = \StayFlow\Voucher\VoucherGenerator::tryLoadPdfEngine();
            $html = self::render_pdf_html($data['data']);

            if ($engine === 'mpdf') {
                $mpdf = new \Mpdf\Mpdf(['format' => 'A4']);
                $mpdf->WriteHTML($html);
                $mpdf->Output($path, 'F');
            } else {
                $dom = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
                $dom->loadHtml($html, 'UTF-8');
                $dom->render();
                file_put_contents($path, $dom->output());
            }

            self::log($bid, [
                'path'         => $path,
                'generated_at' => current_time('mysql'),
                'trigger'      => $ctx['trigger'] ?? 'ui',
            ]);

            return ['ok'=>true, 'path'=>$path];

        } catch (\Throwable $e) {
            update_post_meta($bid, self::META_MAIL_LAST_ERR, 'PDF Error: ' . $e->getMessage());
            return ['ok'=>false, 'message'=>$e->getMessage()];
        }
    }

    private static function render_pdf_html($data) {
        ob_start();
        $d = $data;
        $tpl = plugin_dir_path(__FILE__) . 'templates/owner-pdf.php';
        if (file_exists($tpl)) include $tpl;
        return ob_get_clean();
    }

    /* =========================================================
     * DATA COLLECTION
     * ======================================================= */

    private static function collect_data(int $bid): array {
        if (!function_exists('MPHB')) return ['ok'=>false];
        $b = MPHB()->getBookingRepository()->findById($bid);
        if (!$b) return ['ok'=>false];
        $rooms = $b->getReservedRooms();
        if (empty($rooms)) return ['ok'=>false];
        $rt = (int) $rooms[0]->getRoomTypeId();

        $in  = (string) get_post_meta($bid, 'mphb_check_in_date', true);
        $out = (string) get_post_meta($bid, 'mphb_check_out_date', true);

        $n = 0;
        if ($in && $out) {
            $n = (int) max(1, (strtotime($out) - strtotime($in)) / 86400);
        }

        $snap_payout = get_post_meta($bid, '_bsbt_snapshot_owner_payout', true);
        $snap_model  = (string) get_post_meta($bid, '_bsbt_snapshot_model', true);

        $model_key = 'model_a';
        $total_owner_payout = 0.0;
        $guest_total = 0.0;
        $pricing = null;

        if ($snap_payout !== '' && $snap_payout !== null) {
            $total_owner_payout = (float) $snap_payout;
            $guest_total        = (float) get_post_meta($bid, '_bsbt_snapshot_guest_total', true);
            $model_key          = $snap_model ?: 'model_a';

            if ($model_key === 'model_b') {
                $fee_rate = (float) get_post_meta($bid, '_bsbt_snapshot_fee_rate', true);
                if ($fee_rate <= 0) $fee_rate = (defined('BSBT_FEE') ? (float) BSBT_FEE : 0.15);

                $pricing = [
                    'commission_rate'        => $fee_rate,
                    'commission_net_total'   => (float) get_post_meta($bid, '_bsbt_snapshot_fee_net_total', true),
                    'commission_vat_total'   => (float) get_post_meta($bid, '_bsbt_snapshot_fee_vat_total', true),
                    'commission_gross_total' => (float) get_post_meta($bid, '_bsbt_snapshot_fee_gross_total', true),
                ];
            }
        } else {
            $model_key = (string) get_post_meta($rt, '_bsbt_business_model', true);
            $model_key = $model_key ?: 'model_a';

            $ppn = (float) get_post_meta($rt, 'owner_price_per_night', true);
            if (!$ppn && function_exists('get_field')) {
                $ppn = (float) get_field('owner_price_per_night', $rt);
            }

            $total_owner_payout = (float) ($ppn * $n);

            if ($model_key === 'model_b') {
                $f = defined('BSBT_FEE') ? (float) BSBT_FEE : 0.15;
                $v = defined('BSBT_VAT_ON_FEE') ? (float) BSBT_VAT_ON_FEE : 0.19;
                $guest_total = ($f > 0 && $f < 1) ? round($total_owner_payout / (1 - $f), 2) : $total_owner_payout;
                $fee_brut = round($guest_total * $f, 2);
                $fee_net  = round($fee_brut / (1 + $v), 2);
                $fee_vat  = round($fee_brut - $fee_net, 2);

                $pricing = [
                    'commission_rate'        => $f,
                    'commission_net_total'   => $fee_net,
                    'commission_vat_total'   => $fee_vat,
                    'commission_gross_total' => $fee_brut,
                ];
            } else {
                $guest_total = $total_owner_payout;
            }
        }

        $cc = (string) get_post_meta($bid, 'mphb_country', true);
        $countries = ['DE'=>'Deutschland','AT'=>'Österreich','CH'=>'Schweiz','FR'=>'Frankreich','IT'=>'Italien','ES'=>'Spanien'];
        $full_country = $countries[$cc] ?? $cc;

        return ['ok'=>true, 'data'=>[
            'booking_id'        => $bid,
            'business_model'    => ($model_key === 'model_b' ? 'Modell B (Vermittlung)' : 'Modell A (Direkt)'),
            'model_key'         => $model_key,
            'document_type'     => 'Abrechnung',
            'apt_title'         => get_the_title($rt),
            'apt_id'            => $rt,
            'apt_address'       => get_post_meta($rt, 'address', true),
            'owner_name'        => get_post_meta($rt, 'owner_name', true) ?: '—',
            'check_in'          => $in,
            'check_out'         => $out,
            'nights'            => $n,
            'guests'            => get_post_meta($bid, 'mphb_adults', true) ?: 1,
            'guest_name'        => trim((string)get_post_meta($bid, 'mphb_first_name', true) . ' ' . (string)get_post_meta($bid, 'mphb_last_name', true)),
            'guest_company'     => get_post_meta($bid, 'mphb_company', true),
            'guest_email'       => get_post_meta($bid, 'mphb_email', true),
            'guest_phone'       => get_post_meta($bid, 'mphb_phone', true),
            'guest_addr'        => get_post_meta($bid, 'mphb_address1', true),
            'guest_zip'         => get_post_meta($bid, 'mphb_zip', true),
            'guest_city'        => get_post_meta($bid, 'mphb_city', true),
            'guest_country'     => $full_country,

            // Возвращаем сырые значения для группового суммирования в PDF
            'raw_guest_gross'   => $guest_total,
            'raw_payout'        => $total_owner_payout,

            'guest_gross_total' => number_format((float)$guest_total, 2, ',', '.'),
            'payout'            => number_format((float)$total_owner_payout, 2, ',', '.'),
            'pricing'           => $pricing,
        ]];
    }

    /* =========================================================
     * METABOX & ADMIN
     * ======================================================= */

    public static function register_metabox($post_type) {
        if ($post_type === 'mphb_booking') self::add_metabox();
    }
    public static function register_metabox_direct() { self::add_metabox(); }
    private static function add_metabox() { add_meta_box('bsbt_owner_pdf', 'BSBT – Owner PDF', [__CLASS__, 'render_metabox'], 'mphb_booking', 'side', 'high'); }

    public static function render_metabox($post) {
        $bid = (int) $post->ID;
        $decision = (string) get_post_meta($bid, '_bsbt_owner_decision', true);
        $status = ($decision === 'approved') ? 'BESTÄTIGT' : (($decision === 'declined') ? 'ABGELEHNT' : 'OFFEN');
        $color  = ($decision === 'approved') ? '#2e7d32' : (($decision === 'declined') ? '#c62828' : '#f9a825');

        $sent = (get_post_meta($bid, self::META_MAIL_SENT, true) === '1');
        $nonce = wp_create_nonce('bsbt_owner_pdf_' . $bid);

        echo "<div style='font-size:12px;line-height:1.4'>";
        echo "<p><strong>Entscheidung:</strong> <span style='color:$color'>$status</span></p>";
        echo "<p><strong>E-Mail Status:</strong> " . ($sent ? "<span style='color:#2e7d32'>Versendet</span>" : "<span style='color:#f9a825'>Nicht versendet</span>") . "</p>";
        
        $err = get_post_meta($bid, self::META_MAIL_LAST_ERR, true);
        if (!$sent && $err) {
            echo "<p style='color:#c62828; font-size:11px; line-height:1.2; margin: 4px 0; border-left: 2px solid #c62828; padding-left: 6px;'><strong>Warnung:</strong> " . esc_html($err) . "</p>";
        }

        echo "<hr>";
        echo "<a class='button' target='_blank' href='" . admin_url("admin-post.php?action=bsbt_owner_pdf_open&booking_id=$bid&_wpnonce=$nonce") . "'>Öffnen</a> ";
        echo "<a class='button button-primary' href='" . admin_url("admin-post.php?action=bsbt_owner_pdf_generate&booking_id=$bid&_wpnonce=$nonce") . "'>Erzeugen</a> ";
        echo "<a class='button' href='" . admin_url("admin-post.php?action=bsbt_owner_pdf_resend&booking_id=$bid&_wpnonce=$nonce") . "'>Senden</a>";
        echo "</div>";
    }

    public static function admin_generate() { self::guard(); self::generate_pdf((int)($_GET['booking_id'] ?? 0), ['trigger' => 'admin']); wp_redirect(wp_get_referer()); exit; }
    public static function admin_open() {
        self::guard();
        $bid = (int)($_GET['booking_id'] ?? 0);
        $log = get_post_meta($bid, self::META_LOG, true);
        $last = is_array($log) ? end($log) : null;
        if (!$last || empty($last['path']) || !file_exists($last['path'])) wp_die('PDF Datei nicht gefunden.');
        header('Content-Type: application/pdf'); readfile($last['path']); exit;
    }
    public static function admin_resend() {
        self::guard(); $bid = (int)($_GET['booking_id'] ?? 0);
        delete_post_meta($bid, self::META_MAIL_SENT);
        self::maybe_auto_send($bid);
        wp_redirect(wp_get_referer()); exit;
    }
    private static function guard() { check_admin_referer('bsbt_owner_pdf_' . (int)($_GET['booking_id'] ?? 0)); }

    /* =========================================================
     * EMAIL & LOGS
     * ======================================================= */

    private static function email_owner($bid, $to, $path) {
        if (!$to || !file_exists($path)) return false;
        $subject = 'Buchungsbestätigung – Stay4Fair #' . $bid;
        $msg = "Guten Tag,\n\nanbei erhalten Sie die Bestätigung für die neue Buchung #$bid.\n\nMit freundlichen Grüßen\nStay4Fair Team";
        return wp_mail($to, $subject, $msg, ['Content-Type: text/plain; charset=UTF-8'], [$path]);
    }

    private static function get_owner_email($bid) {
        if (!function_exists('MPHB')) return '';
        $b = MPHB()->getBookingRepository()->findById($bid);
        if (!$b) return '';
        $rooms = $b->getReservedRooms();
        if (empty($rooms)) return '';
        $rt = (int) $rooms[0]->getRoomTypeId();

        $owner_id = (int) get_post_meta($rt, 'bsbt_owner_id', true);
        if ($owner_id > 0) {
            $user = get_userdata($owner_id);
            if ($user && is_email($user->user_email)) return $user->user_email;
        }

        $email = trim((string) get_post_meta($rt, 'owner_email', true));
        if ($email && is_email($email)) return $email;

        $acf = trim((string) get_post_meta($rt, self::ACF_OWNER_EMAIL_KEY, true));
        if ($acf && is_email($acf)) return $acf;

        return '';
    }

    private static function log($bid, $row) {
        $log = get_post_meta($bid, self::META_LOG, true);
        if (!is_array($log)) $log = [];
        $log[] = $row;
        update_post_meta($bid, self::META_LOG, $log);
    }
}

BSBT_Owner_PDF::init();
