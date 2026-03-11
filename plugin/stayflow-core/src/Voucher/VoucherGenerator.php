<?php

declare(strict_types=1);

namespace StayFlow\Voucher;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.2.0
 * RU: Генератор Ваучеров (HTML и PDF). Тексты и политики теперь берутся из админки.
 * EN: Voucher Generator (HTML & PDF). Texts and policies are now fetched from admin.
 */
final class VoucherGenerator
{
    private const BS_EXT_REF_META = '_bs_external_reservation_ref';

    /**
     * RU: Получить номер ваучера (с приоритетами).
     * EN: Get voucher number (with fallbacks).
     */
    public static function getVoucherNumber(int $bookingId): string
    {
        if (function_exists('bsbt_get_display_booking_ref')) {
            return (string) bsbt_get_display_booking_ref($bookingId);
        }
        
        $ext = trim((string) get_post_meta($bookingId, self::BS_EXT_REF_META, true));
        if ($ext !== '') return $ext;

        $candidateKeys = ['bs_external_reservation', 'external_reservation_number', 'bs_booking_number', 'reservation_number'];
        foreach ($candidateKeys as $key) {
            $val = trim((string) get_post_meta($bookingId, $key, true));
            if ($val !== '') return $val;
        }

        $internal = trim((string) get_post_meta($bookingId, 'bs_internal_booking_number', true));
        if ($internal !== '') return $internal;

        return (string) $bookingId;
    }

    /**
     * RU: Загрузчик PDF движка.
     * EN: PDF Engine loader.
     */
    public static function tryLoadPdfEngine(): string
    {
        if (class_exists('\Mpdf\Mpdf')) return 'mpdf';
        if (class_exists('\Dompdf\Dompdf')) return 'dompdf';
        
        $mpdfCandidates = [
            WP_PLUGIN_DIR . '/motopress-hotel-booking-pdf-invoices/vendor/autoload.php', 
            WP_PLUGIN_DIR . '/hotel-booking-pdf-invoices/vendor/autoload.php'
        ];
        
        foreach ($mpdfCandidates as $autoload) {
            if (is_file($autoload)) {
                require_once $autoload;
                if (class_exists('\Mpdf\Mpdf')) return 'mpdf';
            }
        }
        
        $dompdfAutoload = WP_PLUGIN_DIR . '/mphb-invoices/vendors/dompdf/autoload.inc.php';
        if (is_file($dompdfAutoload)) {
            require_once $dompdfAutoload;
            if (class_exists('\Dompdf\Dompdf')) return 'dompdf';
        }
        
        return '';
    }

    /**
     * RU: Генерация файла PDF.
     * EN: Generate PDF file.
     */
    public static function generatePdfFile(int $bookingId, string $suffix = ''): string
    {
        if ($bookingId <= 0) return '';
        
        $html = self::renderHtml($bookingId);
        if (!$html) return '';

        $uploadDir = wp_upload_dir();
        $dir = trailingslashit($uploadDir['basedir']) . 'bs-vouchers';
        if (!is_dir($dir)) wp_mkdir_p($dir);

        $suffixStr = $suffix ? '-' . $suffix : '-' . date('Ymd-His');
        $file = trailingslashit($dir) . 'Voucher-' . $bookingId . $suffixStr . '.pdf';

        if ($suffix === 'PAIDEMAIL' && is_file($file) && filesize($file) > 800) {
            return $file;
        }

        $engine = self::tryLoadPdfEngine();
        
        try {
            @ini_set('memory_limit', '512M');
            @ini_set('max_execution_time', '300');
            
            if ($engine === 'mpdf' && class_exists('\Mpdf\Mpdf')) {
                $mpdf = new \Mpdf\Mpdf(['format'=>'A4','margin_left'=>12,'margin_right'=>12,'margin_top'=>14,'margin_bottom'=>14]);
                $mpdf->WriteHTML($html);
                $mpdf->Output($file, \Mpdf\Output\Destination::FILE);
            } elseif ($engine === 'dompdf' && class_exists('\Dompdf\Dompdf')) {
                $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled'=>true]);
                $dompdf->loadHtml($html, 'UTF-8');
                $dompdf->setPaper('A4','portrait');
                $dompdf->render();
                file_put_contents($file, $dompdf->output());
            } else { 
                return ''; 
            }
        } catch (\Throwable $e) { 
            return ''; 
        }

        return (is_file($file) && filesize($file) > 800) ? $file : '';
    }

    /**
     * RU: Рендер HTML тела ваучера.
     * EN: Render HTML voucher body.
     */
    public static function renderHtml(int $bookingId): string
    {
        $owner = ['name'=>'','phone'=>'','email'=>'','address'=>'','doorbell'=>''];
        $roomTypeId = 0;

        // =========================================================
        // 1. OWNER DATA (Данные владельца)
        // =========================================================
        if (function_exists('MPHB')) {
            try {
                $booking = \MPHB()->getBookingRepository()->findById($bookingId);
                if ($booking) {
                    $reserved = $booking->getReservedRooms();
                    if (!empty($reserved)) {
                        $first = reset($reserved);
                        $roomTypeId = method_exists($first,'getRoomTypeId') ? (int) $first->getRoomTypeId() : 0;
                        if ($roomTypeId > 0) {
                            $owner['name']     = trim((string)get_post_meta($roomTypeId, 'owner_name', true));
                            $owner['phone']    = trim((string)get_post_meta($roomTypeId, 'owner_phone', true));
                            $owner['email']    = trim((string)get_post_meta($roomTypeId, 'owner_email', true));
                            $owner['address']  = trim((string)get_post_meta($roomTypeId, 'address', true));
                            $owner['doorbell'] = trim((string)get_post_meta($roomTypeId, 'doorbell_name', true));
                        }
                    }
                }
            } catch (\Throwable $e) {}
        }

        $ownerBlock = '';
        if ($owner['name'])     $ownerBlock .= 'Owner: ' . esc_html($owner['name']) . '<br>';
        if ($owner['phone'])    $ownerBlock .= 'Phone: ' . esc_html($owner['phone']) . '<br>';
        if ($owner['email'])    $ownerBlock .= 'Email: ' . esc_html($owner['email']) . '<br>';
        if ($owner['address'])  $ownerBlock .= '<br><strong>Apartment address:</strong><br>' . nl2br(esc_html($owner['address'])) . '<br>';
        if ($owner['doorbell']) $ownerBlock .= 'Doorbell: ' . esc_html($owner['doorbell']) . '<br>';
        if ($ownerBlock === '') $ownerBlock  = 'Details will be provided shortly by our team.';

        // =========================================================
        // 2. GUESTS PARSING (Агрессивный парсинг гостей)
        // =========================================================
        $guestNamesArr = [];
        $totalGuests = 0;

        $guestFirst = trim((string)get_post_meta($bookingId,'mphb_first_name',true));
        $guestLast  = trim((string)get_post_meta($bookingId,'mphb_last_name',true));
        $mainGuestName = trim($guestFirst . ' ' . $guestLast);
        if ($mainGuestName !== '') {
            $guestNamesArr[] = $mainGuestName;
        }

        if (isset($booking) && $booking) {
            try {
                $reserved = $booking->getReservedRooms();
                if (!empty($reserved)) {
                    foreach ($reserved as $room) {
                        if (method_exists($room, 'getAdults')) $totalGuests += (int)$room->getAdults();
                        if (method_exists($room, 'getChildren')) $totalGuests += (int)$room->getChildren();
                        
                        if (method_exists($room, 'getGuestName')) {
                            $gName = trim((string)$room->getGuestName());
                            if ($gName !== '') $guestNamesArr[] = $gName;
                        }
                    }
                }
            } catch (\Throwable $e) {}
        }

        if ($totalGuests === 0) {
            $roomDetails = get_post_meta($bookingId, 'mphb_room_details', true);
            if (is_array($roomDetails) && !empty($roomDetails)) {
                foreach ($roomDetails as $rd) {
                    if (is_array($rd)) {
                        if (isset($rd['adults'])) $totalGuests += (int)$rd['adults'];
                        if (isset($rd['children'])) $totalGuests += (int)$rd['children'];
                        if (!empty($rd['guest_name'])) {
                            $gName = trim((string)$rd['guest_name']);
                            if ($gName !== '') $guestNamesArr[] = $gName;
                        }
                    }
                }
            }
        }

        if ($totalGuests <= 0) {
            $totalGuests = (int)get_post_meta($bookingId, 'mphb_adults', true) + (int)get_post_meta($bookingId, 'mphb_children', true);
        }
        if ($totalGuests <= 0) {
            $totalGuests = (int)get_post_meta($bookingId, 'mphb_total_guests', true);
        }
        if ($totalGuests <= 0) $totalGuests = 1;

        $cleanNames = [];
        foreach ($guestNamesArr as $nameStr) {
            $parts = explode(',', $nameStr);
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p !== '') $cleanNames[] = $p;
            }
        }
        
        $allGuestNamesString = implode(', ', array_unique($cleanNames));
        if ($allGuestNamesString === '') $allGuestNamesString = 'Guest';


        // =========================================================
        // 3. DATES, TIMES & POLICY (Из базы данных Политик)
        // =========================================================
        $checkIn  = trim((string)get_post_meta($bookingId,'mphb_check_in_date',true));
        $checkOut = trim((string)get_post_meta($bookingId,'mphb_check_out_date',true));
        $timeIn   = get_post_meta($roomTypeId, '_sf_check_in_time', true) ?: '15:00–23:00';
        $timeOut  = get_post_meta($roomTypeId, '_sf_check_out_time', true) ?: '12:00';

        $policyType = get_post_meta($roomTypeId, '_sf_cancellation_policy', true) ?: 'non_refundable';
        $cancelDays = (int) get_post_meta($roomTypeId, '_sf_cancellation_days', true);
        
        // RU: Берем политики из админки (Policy Registry)
        $policyReg = get_option('stayflow_registry_policies', []);

        if ($policyType === 'free_cancellation' && $cancelDays > 0) {
            $penaltyDays = $cancelDays - 1;
            $policyRaw = $policyReg['free_cancellation'] ?? "<ul><li>Free cancellation up to <strong>{days} days before arrival</strong>.</li><li>Penalty from <strong>{penalty_days} days</strong>.</li></ul>";
            $policyHtml = str_replace(['{days}', '{penalty_days}'], [(string)$cancelDays, (string)$penaltyDays], $policyRaw);
        } else {
            $policyHtml = $policyReg['non_refundable'] ?? "<p><strong>Non-Refundable</strong></p>";
        }

        // =========================================================
        // 4. DYNAMIC TEXTS (Тексты из админки Content Registry)
        // =========================================================
        $contentReg = get_option('stayflow_registry_content', []);
        
        $defaultInstructions = "<strong>Check-in:</strong> ab 15:00 Uhr\n<strong>Check-out:</strong> bis 11:00 Uhr\n\nBitte kontaktieren Sie Ihren Gastgeber vorab bezüglich der Schlüsselübergabe.";
        $instructionsRaw = $contentReg['voucher_instructions'] ?? $defaultInstructions;
        $instructions = nl2br(wp_kses_post($instructionsRaw));
        
        $contactLine = 'WhatsApp: +49 176 24615269 · E-mail: business@stay4fair.com · stay4fair.com';

        $voucherNo = self::getVoucherNumber($bookingId);
        $logoUrl = 'https://stay4fair.com/wp-content/uploads/2025/12/gorizontal-color-4.png';

        ob_start(); ?>
        <!doctype html>
        <html>
        <head>
        <meta charset="utf-8">
        <title>Stay4Fair.com — Booking Voucher</title>
        <style>
            body{font-family:DejaVu Sans, Arial, sans-serif;font-size:12px;color:#111;}
            .h1{font-size:20px;font-weight:800;margin:0 0 4px;}
            .brand{font-size:12px;color:#555;margin-bottom:10px;}
            .muted{color:#666;}
            .grid{display:table;width:100%;border-collapse:collapse;}
            .col{display:table-cell;vertical-align:top;}
            .box{border:1px solid #ddd;border-radius:6px;padding:10px;}
            .mt{margin-top:10px;} .mb{margin-bottom:10px;} .sep{border-top:1px solid #eee;margin:14px 0;}
            .label{font-weight:700;}
            .small{font-size:11px;line-height:1.45;}
            .kv div{margin:2px 0;}
            .topbar{display:table;width:100%;margin-bottom:10px;}
            .topbar-left,.topbar-right{display:table-cell;vertical-align:middle;}
            .topbar-right{text-align:right;font-size:11px;line-height:1.5;color:#333;}
            .topbar-logo{max-height:60px;}
            .topbar-right a{color:#111;text-decoration:none;}
        </style>
        </head>
        <body>
            <div class="topbar">
                <div class="topbar-left">
                    <img src="<?php echo esc_url($logoUrl); ?>" alt="Stay4Fair" class="topbar-logo">
                </div>
                <div class="topbar-right">
                    <div>E-mail: business@stay4fair.com</div>
                    <div>WhatsApp: <a href="https://wa.me/4917624615269">+49 176 24615269</a></div>
                    <div>stay4fair.com</div>
                </div>
            </div>
            <div class="h1">Booking Voucher</div>
            <div class="brand">Stay4Fair.com</div>
            <div class="muted">Voucher No: <?php echo esc_html($voucherNo); ?> · Booking ID: <?php echo (int)$bookingId; ?></div>
            <div class="grid mt">
                <div class="col" style="width:58%;padding-right:10px;">
                    <div class="box">
                        <div class="label">Guest</div>
                        <div class="kv">
                            <div><?php echo esc_html($allGuestNamesString); ?></div>
                            <div>Total guests: <?php echo (int)$totalGuests; ?></div>
                        </div>
                        <div class="sep"></div>
                        <div class="label">Stay</div>
                        <div class="kv">
                            <div>Check-in date: <?php echo esc_html($checkIn); ?> (from <?php echo esc_html($timeIn); ?>)</div>
                            <div>Check-out date: <?php echo esc_html($checkOut); ?> (until <?php echo esc_html($timeOut); ?>)</div>
                        </div>
                    </div>
                </div>
                <div class="col" style="width:42%;">
                    <div class="box">
                        <div class="label">Owner / Check-in Information</div>
                        <?php echo $ownerBlock; ?>
                    </div>
                </div>
            </div>
            <div class="box mt small">
                <div class="label">Check-in / Check-out instructions</div>
                <div><?php echo $instructions; ?></div>
            </div>
            <div class="box mt small">
                <div class="label">Cancellation policy details</div>
                <div><?php echo wp_kses_post($policyHtml); ?></div>
            </div>
            <div class="box mt small">
                <div class="label">Contacts</div>
                <div><?php echo esc_html($contactLine); ?></div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}
