<?php

declare(strict_types=1);

namespace StayFlow\BusinessModel;

use DOMDocument;
use DOMXPath;

if (!defined('ABSPATH')) {
    exit;
}

final class InvoiceModifier
{
    private const VAT_A = 0.07;
    private const VAT_B = 0.19;
    private const DEFAULT_FEE = 0.15;

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

        if (!$customer) {
            return $vars;
        }

        $name = trim($customer->getFirstName() . ' ' . $customer->getLastName());

        $company = self::metaFirstNonEmpty($bookingId, [
            'mphb_company',
            '_mphb_company',
            'company'
        ]);

        $street = self::metaFirstNonEmpty($bookingId, [
            'mphb_address1',
            'mphb_street'
        ]);

        $house = self::metaFirstNonEmpty($bookingId, [
            'mphb_house',
            'mphb_house_number'
        ]);

        $zip = self::metaFirstNonEmpty($bookingId, [
            'mphb_zip'
        ]);

        $city = self::metaFirstNonEmpty($bookingId, [
            'mphb_city'
        ]);

        $country = self::countryFullName((string)$customer->getCountry());

        $html = '';

        if ($name !== '') {
            $html .= '<strong>' . esc_html($name) . '</strong><br/>';
        }

        if ($company !== '') {
            $html .= esc_html($company) . '<br/>';
        }

        $line1 = trim($street . ' ' . $house);

        if ($line1 !== '') {
            $html .= esc_html($line1) . '<br/>';
        }

        $line2 = trim($zip . ' ' . $city);

        if ($line2 !== '') {
            $html .= esc_html($line2) . '<br/>';
        }

        if ($country !== '') {
            $html .= esc_html($country);
        }

        /**
         * MotoPress templates use different variable names
         */

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

        if ($code === '') {
            return '';
        }

        if (function_exists('WC') && \WC() && \WC()->countries) {

            try {

                $countries = \WC()->countries->get_countries();

                $upper = strtoupper($code);

                if (isset($countries[$upper])) {
                    return $countries[$upper];
                }

            } catch (\Throwable $e) {
            }
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

        if ($model === 'model_b') {

            $snapFee = get_post_meta($bookingId, '_bsbt_snapshot_fee_gross_total', true);
            $snapVat = get_post_meta($bookingId, '_bsbt_snapshot_fee_vat_total', true);

            if ($snapFee !== '') {

                $fee = (float)$snapFee;
                $vat = (float)$snapVat;

            } else {

                $rate = get_post_meta($bookingId, '_bsbt_snapshot_fee_rate', true);

                $f = $rate !== '' ? (float)$rate : self::DEFAULT_FEE;

                $fee = round($gross * $f, 2);

                $net = round($fee / (1 + self::VAT_B), 2);

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

                $html = self::insertRowBeforeTotal(
                    $html,
                    'incl. Service Fee VAT (19%)',
                    mphb_format_price($vat)
                );
            }

        } else {

            $vat = round($gross - ($gross / (1 + self::VAT_A)), 2);

            if ($vat > 0) {

                $html = self::insertRowBeforeTotal(
                    $html,
                    'VAT (7%) included',
                    mphb_format_price($vat)
                );
            }
        }

        $vars['BOOKING_DETAILS'] = self::addTableRadius($html);

        return $vars;
    }

    private static function metaFirstNonEmpty(int $postId, array $keys): string
    {
        foreach ($keys as $k) {

            $v = get_post_meta($postId, $k, true);

            if (is_scalar($v)) {

                $v = trim((string)$v);

                if ($v !== '') {
                    return $v;
                }
            }
        }

        return '';
    }

    private static function insertRowBeforeTotal(string $html, string $label, string $value): string
    {
        if (!class_exists('DOMDocument')) {
            return $html;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');

        libxml_use_internal_errors(true);

        $dom->loadHTML(
            '<html><body>' . $html . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $xpath = new DOMXPath($dom);

        $rows = $xpath->query(
            "//tr[th and (translate(normalize-space(th[1]),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ')='TOTAL'
            or translate(normalize-space(th[1]),'abcdefghijklmnopqrstuvwxyz','ABCDEFGHIJKLMNOPQRSTUVWXYZ')='GESAMT')]"
        );

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

    private static function addTableRadius(string $html): string
    {
        if (!class_exists('DOMDocument')) {
            return $html;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');

        libxml_use_internal_errors(true);

        $dom->loadHTML(
            '<html><body>' . $html . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $xpath = new DOMXPath($dom);

        $tables = $xpath->query("//table");

        if ($tables) {

            foreach ($tables as $table) {

                if (!$table instanceof \DOMElement) {
                    continue;
                }

                $style = $table->getAttribute('style');

                if (stripos($style, 'border-radius') === false) {

                    $style .= '; border-radius:10px; overflow:hidden; border-collapse:separate;';

                    $table->setAttribute('style', trim($style));
                }
            }
        }

        $html = $dom->saveHTML();

        $html = preg_replace('~^.*?<body>(.*)</body>.*$~is', '$1', $html);

        return $html ?: $html;
    }
}