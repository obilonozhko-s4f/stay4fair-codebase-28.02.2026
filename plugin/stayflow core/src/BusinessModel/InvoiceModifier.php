<?php

declare(strict_types=1);

namespace StayFlow\BusinessModel;

use DOMDocument;
use DOMXPath;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version: 1.6.3
 * RU: Модификатор инвойсов. Дисклеймер перенесен строго под таблицу PAYMENT_INFO (Payment details).
 */
final class InvoiceModifier
{
    public static function init(): void
    {
        static $booted = false;

        if ($booted) {
            return;
        }

        $booted = true;

        add_action('mphb_invoices_print_pdf_before', [self::class, 'forceEnglishBefore'], 1);
        add_action('mphb_invoices_print_pdf_after', [self::class, 'forceEnglishAfter'], 99);

        add_filter(
            'mphb_invoices_print_pdf_variables',
            [self::class, 'filterInvoiceVariables'],
            20,
            2
        );
    }

    private static function getSettings(): array
    {
        $settings = get_option('stayflow_core_settings', []);
        
        $fee_raw = isset($settings['commission_default']) ? (float)$settings['commission_default'] : 15.0;
        if ($fee_raw > 0.0 && $fee_raw <= 1.0) {
            $fee_raw *= 100;
        }
        
        $vat_b = isset($settings['platform_vat_rate']) ? (float)$settings['platform_vat_rate'] : 19.0;
        $vat_a = isset($settings['platform_vat_rate_a']) ? (float)$settings['platform_vat_rate_a'] : 7.0;

        return [
            'fee'       => $fee_raw / 100.0,
            'vat_b'     => $vat_b / 100.0,
            'vat_a'     => $vat_a / 100.0,
            'vat_b_raw' => $vat_b,
            'vat_a_raw' => $vat_a,
        ];
    }

    public static function forceEnglishBefore($booking): void
    {
        if (function_exists('switch_to_locale')) {
            switch_to_locale('en_US');
        }
    }

    public static function forceEnglishAfter($booking): void
    {
        if (function_exists('restore_previous_locale')) {
            restore_previous_locale();
        }
    }

    public static function filterInvoiceVariables(array $vars, $booking): array
    {
        if (!function_exists('MPHB')) {
            return $vars;
        }

        $bookingId = is_object($booking) && method_exists($booking, 'getId')
            ? (int)$booking->getId()
            : (int)$booking;

        if ($bookingId <= 0) {
            return $vars;
        }

        try {
            $bookingObj = \MPHB()->getBookingRepository()->findById($bookingId);

            if (!$bookingObj) {
                return $vars;
            }

            $model = self::resolveModel($bookingId);
            $vars = self::replaceCustomerBlock($vars, $bookingObj, $bookingId);
            $vars = self::modifyBookingDetails($vars, $bookingObj, $bookingId, $model);

        } catch (\Throwable $e) {
            // Silence
        }

        return $vars;
    }

    private static function resolveModel(int $bookingId): string
    {
        $snapshot = trim((string)get_post_meta($bookingId, '_bsbt_snapshot_model', true));

        if ($snapshot !== '') {
            return $snapshot === 'model_b' ? 'model_b' : 'model_a';
        }

        $roomDetails = get_post_meta($bookingId, 'mphb_room_details', true);

        if (is_array($roomDetails) && !empty($roomDetails)) {
            $first = reset($roomDetails);
            if (isset($first['room_type_id'])) {
                $roomType = (int)$first['room_type_id'];
                $model = trim((string)get_post_meta($roomType, '_bsbt_business_model', true));
                if ($model === 'model_b') {
                    return 'model_b';
                }
            }
        }
        return 'model_a';
    }

    private static function replaceCustomerBlock(array $vars, $booking, int $bookingId): array
    {
        $customer = $booking->getCustomer();
        if (!$customer) return $vars;

        $name = trim($customer->getFirstName() . ' ' . $customer->getLastName());
        $company = self::metaFirstNonEmpty($bookingId, ['mphb_company', '_mphb_company', 'company']);
        $street = self::metaFirstNonEmpty($bookingId, ['mphb_address1', 'mphb_street']);
        $house = self::metaFirstNonEmpty($bookingId, ['mphb_house', 'mphb_house_number']);
        $zip = self::metaFirstNonEmpty($bookingId, ['mphb_zip']);
        $city = self::metaFirstNonEmpty($bookingId, ['mphb_city']);
        $country = self::countryFullName((string)$customer->getCountry());

        $html = '';
        if ($name !== '') $html .= '<strong>' . esc_html($name) . '</strong><br/>';
        if ($company !== '') $html .= esc_html($company) . '<br/>';
        
        $line1 = trim($street . ' ' . $house);
        if ($line1 !== '') $html .= esc_html($line1) . '<br/>';
        
        $line2 = trim($zip . ' ' . $city);
        if ($line2 !== '') $html .= esc_html($line2) . '<br/>';
        
        if ($country !== '') $html .= esc_html($country);

        $vars['CUSTOMER_INFORMATION'] = $html;
        $vars['CUSTOMER_INFO'] = $html;
        $vars['CUSTOMER_DETAILS'] = $html;
        $vars['GUEST_DETAILS'] = $html;
        $vars['customer_info'] = $html;
        $vars['customer_details'] = $html;

        return $vars;
    }

    private static function countryFullName(string $code): string
    {
        $code = trim($code);
        if ($code === '') return '';

        if (function_exists('WC') && \WC() && \WC()->countries) {
            try {
                $countries = \WC()->countries->get_countries();
                $upper = strtoupper($code);
                if (isset($countries[$upper])) return $countries[$upper];
            } catch (\Throwable $e) {}
        }
        return $code;
    }

    private static function modifyBookingDetails(array $vars, $booking, int $bookingId, string $model): array
    {
        if (empty($vars['BOOKING_DETAILS']) || !is_string($vars['BOOKING_DETAILS'])) {
            return $vars;
        }

        if (!function_exists('mphb_format_price')) {
            return $vars;
        }

        $html = $vars['BOOKING_DETAILS'];
        $gross = (float)$booking->getTotalPrice();
        
        $s = self::getSettings();

        // 1. Модифицируем таблицу BOOKING_DETAILS (добавляем НДС и комиссии)
        if ($model === 'model_b') {
            $snapFee = get_post_meta($bookingId, '_bsbt_snapshot_fee_gross_total', true);
            $snapVat = get_post_meta($bookingId, '_bsbt_snapshot_fee_vat_total', true);

            if ($snapFee !== '') {
                $fee = (float)$snapFee;
                $vat = (float)$snapVat;
            } else {
                $rate = get_post_meta($bookingId, '_bsbt_snapshot_fee_rate', true);
                $f = $rate !== '' ? (float)$rate : $s['fee'];
                $fee = round($gross * $f, 2);
                $net = round($fee / (1 + $s['vat_b']), 2);
                $vat = round($fee - $net, 2);
            }

            if ($fee > 0) {
                $html = self::insertRowBeforeTotal(
                    $html,
                    'incl. Service Fee',
                    mphb_format_price($fee)
                );
            }

            if ($vat > 0) {
                $vatPercent = fmod((float)$s['vat_b_raw'], 1) == 0 ? (int)$s['vat_b_raw'] : round((float)$s['vat_b_raw'], 1);
                $html = self::insertRowBeforeTotal(
                    $html,
                    "incl. Service Fee VAT ({$vatPercent}%)",
                    mphb_format_price($vat)
                );
            }
        } else {
            // Модель A
            $vat = round($gross - ($gross / (1 + $s['vat_a'])), 2);

            if ($vat > 0) {
                $vatPercent = fmod((float)$s['vat_a_raw'], 1) == 0 ? (int)$s['vat_a_raw'] : round((float)$s['vat_a_raw'], 1);
                $html = self::insertRowBeforeTotal(
                    $html,
                    "VAT ({$vatPercent}%) included",
                    mphb_format_price($vat)
                );
            }
        }

        $vars['BOOKING_DETAILS'] = $html;

        // 2. Формируем юридический текст-дисклеймер
        $disclaimerText = '';
        if ($model === 'model_b') {
            $vatPercentB = fmod((float)$s['vat_b_raw'], 1) == 0 ? (int)$s['vat_b_raw'] : round((float)$s['vat_b_raw'], 1);
            $disclaimerText .= '<strong style="color:#212F54; font-size:13px;">Contracting Party: The Property Owner</strong><br><br>';
            $disclaimerText .= 'Stay4Fair acts solely as an intermediary (booking agent) for this reservation. The direct contracting party for the accommodation is the property owner. The total amount includes the Stay4Fair service fee (incl. ' . $vatPercentB . '% VAT).<br><br>';
            $disclaimerText .= '<em>Please note:</em> The accommodation portion of the price may not include VAT if the owner is a private individual or a small business (Kleinunternehmer). If the accommodation is a hotel or a VAT-registered business, please request a separate tax invoice directly from them for the accommodation part. City Tax may apply and is to be settled directly with the host unless otherwise stated.';
        } else {
            $vatPercentA = fmod((float)$s['vat_a_raw'], 1) == 0 ? (int)$s['vat_a_raw'] : round((float)$s['vat_a_raw'], 1);
            $disclaimerText .= '<strong style="color:#212F54; font-size:13px;">Contracting Party: Stay4Fair.com</strong><br><br>';
            $disclaimerText .= 'Stay4Fair acts as the merchant of record and direct contracting party for this accommodation service. The total amount includes the statutory VAT (' . $vatPercentA . '%) on accommodation services and the applicable City Tax. Stay4Fair.com is responsible for remitting these taxes to the respective authorities. If you require a VAT invoice for business purposes, this document serves as your official receipt.';
        }

        // Строгий квадратный блок отдельной таблицей
        $disclaimerHtml = '<br><table style="width:100%; border-collapse:collapse;"><tr><td style="padding:15px; border:1px solid #D3D7E0; font-size:11px; color:#555; line-height:1.5; text-align:left;">' . $disclaimerText . '</td></tr></table>';

        // 3. ДОБАВЛЯЕМ ДИСКЛЕЙМЕР ПОД PAYMENT_INFO
        if (isset($vars['PAYMENT_INFO'])) {
            $vars['PAYMENT_INFO'] .= $disclaimerHtml;
        } elseif (isset($vars['PAYMENT_DETAILS'])) {
            $vars['PAYMENT_DETAILS'] .= $disclaimerHtml;
        } else {
            // Фолбэк, если блока оплат вдруг нет в шаблоне
            $vars['BOOKING_DETAILS'] .= $disclaimerHtml;
        }

        return $vars;
    }

    private static function metaFirstNonEmpty(int $postId, array $keys): string
    {
        foreach ($keys as $k) {
            $v = get_post_meta($postId, $k, true);
            if (is_scalar($v)) {
                $v = trim((string)$v);
                if ($v !== '') return $v;
            }
        }
        return '';
    }

    private static function insertRowBeforeTotal(string $html, string $label, string $value): string
    {
        if (!class_exists('DOMDocument')) return $html;

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<html><body>' . $html . '</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);

        $rows = $xpath->query("//tr[th and (translate(normalize-space(th[1]),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ')='TOTAL' or translate(normalize-space(th[1]),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ')='GESAMT')]");

        if ($rows && $rows->length > 0) {
            $target = $rows->item(0);
            $tr = $dom->createElement('tr');
            $th = $dom->createElement('th', $label);
            $td = $dom->createElement('td', wp_strip_all_tags($value));
            $tr->appendChild($th);
            $tr->appendChild($td);
            $target->parentNode->insertBefore($tr, $target);
        }

        $html = $dom->saveHTML();
        $html = preg_replace('~^.*?<body>(.*)</body>.*$~is', '$1', $html);
        return $html ?: $html;
    }
}
