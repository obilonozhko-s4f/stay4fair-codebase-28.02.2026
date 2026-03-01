<?php
/**
 * Core логика принятия решений владельцем.
 * Core logic for owner decision making.
 *
 * Version V10.28.0 - Synchronous State Machine (Approve -> Confirmed -> Snapshot -> Event)
 *
 * SCENARIOS / СЦЕНАРИИ:
 * 1) APPROVE  -> Woo capture -> write decision -> MPHB confirmed -> Snapshot freeze -> Business Event.
 * 2) DECLINE  -> Woo cancel/void or refund -> MPHB cancelled (calendar unlock).
 * 3) EXPIRE   -> Woo cancel/refund -> MPHB pending (calendar locked for admin investigation).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BSBT_Owner_Decision_Core {

    /* =========================
     * META KEYS / META
     * ========================= */

    const META_DECISION       = '_bsbt_owner_decision';              // approved|declined|expired
    const META_DECISION_TIME  = '_bsbt_owner_decision_time';         // mysql datetime
    const META_BSBT_REF       = '_bsbt_booking_id';                  // order meta: welded booking id
    const META_PAYMENT_ISSUE  = '_bsbt_payment_issue';               // 1
    const META_DECISION_LOCK  = '_bsbt_owner_decision_lock';         // uuid token

    // RU: Аудит-трейл по capture (на перспективу: расследование денег/вебхуков).
    // EN: Capture audit trail (for forensic / webhook timing).
    const META_CAPTURE_STARTED_AT   = '_bsbt_capture_started_at';    // unix timestamp
    const META_CAPTURE_COMPLETED_AT = '_bsbt_capture_completed_at';  // unix timestamp

    // RU: Маркер версии движка принятия решений (для future forensic).
    // EN: Decision engine version marker.
    const META_ENGINE_VERSION = '_bsbt_decision_engine_version';     // string

    /* =========================
     * HARDENING HELPERS
     * ========================= */

    /**
     * RU: Критический лог hardening-слоя.
     * EN: Critical hardening layer log.
     */
    private static function hardening_log( int $booking_id, string $reason, int $order_id = 0 ): void {
        error_log(sprintf(
            '[BSBT_DECISION_HARDENING] booking_id=%d order_id=%d reason=%s timestamp=%s',
            $booking_id,
            $order_id,
            $reason,
            current_time('mysql')
        ));
    }

    /**
     * RU: Определяем "pending" статусы брони MPHB, допустимые для решения.
     * EN: Determine allowed "pending" statuses for decision flow.
     */
    private static function is_booking_pending( int $booking_id ): bool {

        if ( function_exists('MPHB') ) {
            try {
                $booking = MPHB()->getBookingRepository()->findById( $booking_id );
                if ( $booking && method_exists( $booking, 'getStatus' ) ) {

                    $status = (string) $booking->getStatus();

                    // Normalize possible "mphb-" prefix
                    if ( strpos( $status, 'mphb-' ) === 0 ) {
                        $status = substr( $status, 5 );
                    }

                    $allowed_pending = [
                        'pending',
                        'pending-user',
                        'pending-payment',
                    ];

                    return in_array( $status, $allowed_pending, true );
                }
            } catch ( \Throwable $e ) {
                // fall through
            }
        }

        // Fallback to WP post_status
        $post_status = (string) get_post_status( $booking_id );
        return in_array( $post_status, [ 'pending', 'mphb-pending' ], true );
    }

    /**
     * RU: Unified terminal-guard ДО lock (быстрый отказ).
     * EN: Unified terminal guard before lock (fast fail).
     */
    private static function terminal_guard( int $booking_id ): array {

        if ( $booking_id <= 0 ) {
            return ['ok' => false, 'message' => 'Invalid ID'];
        }

        $existing_decision = (string) get_post_meta( $booking_id, self::META_DECISION, true );
        if ( $existing_decision !== '' ) {
            self::hardening_log( $booking_id, 'decision_exists' );
            return ['ok' => false, 'message' => 'Already processed: ' . $existing_decision];
        }

        $capture_started = (string) get_post_meta( $booking_id, self::META_CAPTURE_STARTED_AT, true );
        if ( $capture_started !== '' ) {
            self::hardening_log( $booking_id, 'capture_in_flight' );
            return ['ok' => false, 'message' => 'Capture already started'];
        }

        if ( ! self::is_booking_pending( $booking_id ) ) {
            return ['ok' => false, 'message' => 'Booking status is not pending'];
        }

        return ['ok' => true];
    }

    /**
     * RU: Атомарный захват единого lock для terminal-flow.
     * EN: Acquire atomic lock for terminal flow.
     */
    private static function acquire_lock( int $booking_id ): array {
        $lock_token = wp_generate_uuid4();

        if ( ! add_post_meta( $booking_id, self::META_DECISION_LOCK, $lock_token, true ) ) {
            self::hardening_log( $booking_id, 'lock_failed' );
            return [ 'ok' => false, 'token' => '' ];
        }

        return [ 'ok' => true, 'token' => $lock_token ];
    }

    /**
     * RU: Безопасное снятие lock по токену.
     * EN: Release lock by token.
     */
    private static function release_lock( int $booking_id, string $lock_token ): void {
        if ( $lock_token === '' ) return;
        if ( (string) get_post_meta( $booking_id, self::META_DECISION_LOCK, true ) === $lock_token ) {
            delete_post_meta( $booking_id, self::META_DECISION_LOCK );
        }
    }

    /**
     * RU: Повторная проверка после lock (двойная защита от гонок).
     * EN: Re-check after lock to ensure invariants.
     */
    private static function post_lock_guard( int $booking_id ): array {

        $existing_decision = (string) get_post_meta( $booking_id, self::META_DECISION, true );
        if ( $existing_decision !== '' ) {
            self::hardening_log( $booking_id, 'decision_exists_post_lock' );
            return ['ok' => false, 'message' => 'Already processed: ' . $existing_decision];
        }

        $capture_started = (string) get_post_meta( $booking_id, self::META_CAPTURE_STARTED_AT, true );
        if ( $capture_started !== '' ) {
            self::hardening_log( $booking_id, 'capture_in_flight_post_lock' );
            return ['ok' => false, 'message' => 'Capture already started'];
        }

        if ( ! self::is_booking_pending( $booking_id ) ) {
            return ['ok' => false, 'message' => 'Booking status is not pending'];
        }

        return ['ok' => true];
    }

    /**
     * RU: Центральная проверка инвариантов (мини state-machine).
     * EN: Central invariant check (mini state machine).
     */
    private static function assert_transition_allowed( int $booking_id, string $mode ): array {

        if ( $booking_id <= 0 ) {
            return ['ok' => false, 'message' => 'Invalid ID'];
        }

        $existing_decision = (string) get_post_meta( $booking_id, self::META_DECISION, true );
        if ( $existing_decision !== '' ) {
            self::hardening_log( $booking_id, 'decision_exists_invariant' );
            return ['ok' => false, 'message' => 'Already processed: ' . $existing_decision];
        }

        $capture_started = (string) get_post_meta( $booking_id, self::META_CAPTURE_STARTED_AT, true );
        if ( $capture_started !== '' ) {
            self::hardening_log( $booking_id, 'capture_in_flight_invariant' );
            return ['ok' => false, 'message' => 'Capture already started'];
        }

        if ( ! self::is_booking_pending( $booking_id ) ) {
            return ['ok' => false, 'message' => 'Booking status is not pending'];
        }

        $order = self::find_order_for_booking( $booking_id );
        if ( $order instanceof WC_Order && $order->is_paid() ) {
            $order_id = (int) $order->get_id();
            self::hardening_log( $booking_id, 'order_already_paid_invariant', $order_id );
            return ['ok' => false, 'message' => 'Order already paid'];
        }

        return ['ok' => true];
    }

    /**
     * RU: Пишем версию decision-engine в мету брони (forensic).
     * EN: Store decision engine version marker on booking.
     */
    private static function stamp_engine_version( int $booking_id ): void {
        add_post_meta( $booking_id, self::META_ENGINE_VERSION, '10.28.0', true );
    }

    /**
     * RU: Снимаем маркер "started" при неуспехе, чтобы разрешить повтор.
     */
    private static function clear_capture_started_on_failure( int $booking_id ): void {
        delete_post_meta( $booking_id, self::META_CAPTURE_STARTED_AT );
    }

    /* =========================================================
     * APPROVE FLOW (Synchronous State Machine)
     * ========================================================= */

    /**
     * Одобрение брони владельцем (Safe Capture Flow).
     * Owner approves -> capture -> write decision -> set confirmed -> snapshot freeze -> event
     */
    public static function approve_and_send_payment( int $booking_id ): array {

        $guard = self::terminal_guard( $booking_id );
        if ( empty( $guard['ok'] ) ) {
            return ['ok'=>false,'message'=> $guard['message'] ?? 'Guard failed'];
        }

        $lock = self::acquire_lock( $booking_id );
        if ( empty( $lock['ok'] ) ) {
            return ['ok'=>false,'message'=>'Already locked / processing'];
        }
        $lock_token = (string) ( $lock['token'] ?? '' );

        try {

            $post_lock = self::post_lock_guard( $booking_id );
            if ( empty( $post_lock['ok'] ) ) {
                return ['ok'=>false,'message'=> $post_lock['message'] ?? 'Guard failed'];
            }

            $inv = self::assert_transition_allowed( $booking_id, 'approve' );
            if ( empty( $inv['ok'] ) ) {
                return ['ok'=>false,'message'=> $inv['message'] ?? 'Invariant failed'];
            }

            self::stamp_engine_version( $booking_id );

            $order = self::find_order_for_booking( $booking_id );
            if ( ! ( $order instanceof WC_Order ) ) {
                update_post_meta( $booking_id, self::META_PAYMENT_ISSUE, 1 );
                return ['ok'=>false,'message'=>'Order not found'];
            }

            $order_id = (int) $order->get_id();

            // Weld order and booking
            $order->update_meta_data( self::META_BSBT_REF, $booking_id );
            $order->save();

            // 1) Capture Payment
            if ( ! $order->is_paid() ) {

                if ( ! add_post_meta( $booking_id, self::META_CAPTURE_STARTED_AT, time(), true ) ) {
                    return ['ok'=>false,'message'=>'Capture already started'];
                }

                $gateway = function_exists('wc_get_payment_gateway_by_order')
                    ? wc_get_payment_gateway_by_order( $order )
                    : null;

                try {
                    if ( $gateway && method_exists( $gateway, 'capture_payment' ) ) {
                        $gateway->capture_payment( $order_id );
                    }

                    $order = wc_get_order( $order_id );
                    if ( $order && ! $order->is_paid() ) {
                        $order->payment_complete();
                    }
                } catch ( \Throwable $e ) {
                    self::clear_capture_started_on_failure( $booking_id );
                    throw $e;
                }
            }

            // Verify Paid
            $order = wc_get_order( $order_id );
            if ( ! $order || ! $order->is_paid() ) {
                self::clear_capture_started_on_failure( $booking_id );
                update_post_meta( $booking_id, self::META_PAYMENT_ISSUE, 1 );
                return ['ok'=>false,'message'=>'Payment not captured'];
            }

            // 2) Write Decision
            add_post_meta( $booking_id, self::META_CAPTURE_COMPLETED_AT, time(), true );
            delete_post_meta( $booking_id, self::META_PAYMENT_ISSUE );
            update_post_meta( $booking_id, self::META_DECISION_TIME, current_time('mysql') );

            $written = add_post_meta( $booking_id, self::META_DECISION, 'approved', true );
            if ( ! $written ) {
                return ['ok'=>false,'message'=>'Decision write failed'];
            }

            // =========================================================
            // 3) SET CONFIRMED STATUS
            // This synchronously triggers the Snapshot plugin freeze
            // =========================================================
            self::set_mphb_status_safe( $booking_id, 'confirmed' );

            // =========================================================
            // 4) BUSINESS EVENT
            // Triggers Owner PDF generation with the fresh Snapshot data
            // =========================================================
            do_action( 'bsbt_owner_booking_approved', $booking_id );

            error_log('[BSBT SUCCESS] Booking #' . $booking_id . ' approved | Order #' . $order_id);

            return ['ok'=>true,'paid'=>true,'order_id'=>$order_id,'message'=>'Approved & Captured'];

        } catch ( \Throwable $e ) {

            error_log('[BSBT APPROVE ERROR] #' . $booking_id . ' | ' . $e->getMessage());
            update_post_meta( $booking_id, self::META_PAYMENT_ISSUE, 1 );
            self::clear_capture_started_on_failure( $booking_id );

            return ['ok'=>false,'message'=>'Capture error'];

        } finally {
            self::release_lock( $booking_id, $lock_token );
        }
    }

    /* =========================================================
     * DECLINE FLOW (Atomic + Cancel calendar)
     * ========================================================= */

    /**
     * Owner manual decline within 24h:
     * Woo: cancel/void or refund
     * MPHB: cancelled (calendar unlock)
     */
    public static function decline_booking( int $booking_id ): array {

        $guard = self::terminal_guard( $booking_id );
        if ( empty( $guard['ok'] ) ) {
            return ['ok'=>false,'message'=> $guard['message'] ?? 'Guard failed'];
        }

        $lock = self::acquire_lock( $booking_id );
        if ( empty( $lock['ok'] ) ) {
            return ['ok'=>false,'message'=>'Already locked / processing'];
        }
        $lock_token = (string) ( $lock['token'] ?? '' );

        try {

            $post_lock = self::post_lock_guard( $booking_id );
            if ( empty( $post_lock['ok'] ) ) {
                return ['ok'=>false,'message'=> $post_lock['message'] ?? 'Guard failed'];
            }

            $inv = self::assert_transition_allowed( $booking_id, 'decline' );
            if ( empty( $inv['ok'] ) ) {
                return ['ok'=>false,'message'=> $inv['message'] ?? 'Invariant failed'];
            }

            self::stamp_engine_version( $booking_id );

            $order = self::find_order_for_booking( $booking_id );

            $written = add_post_meta( $booking_id, self::META_DECISION, 'declined', true );
            if ( ! $written ) {
                self::hardening_log( $booking_id, 'decision_write_failed' );
                return ['ok'=>false,'message'=>'Decision write failed'];
            }

            update_post_meta( $booking_id, self::META_DECISION_TIME, current_time('mysql') );

            if ( $order instanceof WC_Order ) {
                try {
                    $order->update_meta_data( self::META_BSBT_REF, $booking_id );
                    $order->save();

                    if ( $order->is_paid() ) {
                        self::hardening_log( $booking_id, 'order_paid_after_decline_write', (int) $order->get_id() );
                    } else {
                        $order->update_status( 'cancelled', 'BSBT: Owner declined – hold released.' );
                    }

                } catch ( \Throwable $e ) {
                    error_log('[BSBT DECLINE ERROR] #' . $booking_id . ' | ' . $e->getMessage());
                }
            } else {
                error_log('[BSBT DECLINE] Booking #' . $booking_id . ' | Woo order not found.');
            }

            self::set_mphb_status_safe( $booking_id, 'cancelled' );

            return ['ok'=>true,'message'=>'Declined'];

        } finally {
            self::release_lock( $booking_id, $lock_token );
        }
    }

    /* =========================================================
     * AUTO EXPIRE (CRON)
     * ========================================================= */

    /**
     * Auto-expire after 24h with no owner decision:
     * decision=expired (atomic)
     * Woo cancel/refund
     * MPHB pending (keep locked)
     */
    public static function process_auto_expire(): void {

        if ( ! function_exists('MPHB') ) {
            return;
        }

        $q = new WP_Query([
            'post_type'      => 'mphb_booking',
            'post_status'    => 'any',
            'posts_per_page' => 20,
            'fields'         => 'ids',
            'meta_query'     => [
                ['key' => self::META_DECISION, 'compare' => 'NOT EXISTS'],
            ],
            'date_query'     => [
                ['before' => '24 hours ago'],
            ],
        ]);

        if ( ! $q->have_posts() ) {
            return;
        }

        foreach ( $q->posts as $booking_id ) {

            $booking_id = (int) $booking_id;
            if ( $booking_id <= 0 ) continue;

            $guard = self::terminal_guard( $booking_id );
            if ( empty( $guard['ok'] ) ) continue;

            $lock = self::acquire_lock( $booking_id );
            if ( empty( $lock['ok'] ) ) continue;
            $lock_token = (string) ( $lock['token'] ?? '' );

            try {

                $post_lock = self::post_lock_guard( $booking_id );
                if ( empty( $post_lock['ok'] ) ) continue;

                $inv = self::assert_transition_allowed( $booking_id, 'expire' );
                if ( empty( $inv['ok'] ) ) continue;

                self::stamp_engine_version( $booking_id );

                $order = self::find_order_for_booking( $booking_id );

                $written = add_post_meta( $booking_id, self::META_DECISION, 'expired', true );
                if ( ! $written ) {
                    self::hardening_log( $booking_id, 'decision_write_failed' );
                    continue;
                }

                update_post_meta( $booking_id, self::META_DECISION_TIME, current_time('mysql') );

                if ( $order instanceof WC_Order ) {
                    try {
                        $order->update_meta_data( self::META_BSBT_REF, $booking_id );
                        $order->save();

                        if ( $order->is_paid() ) {
                            self::hardening_log( $booking_id, 'order_paid_after_expire_write', (int) $order->get_id() );
                        } else {
                            $order->update_status( 'cancelled', 'BSBT: Auto-expire – hold released.' );
                        }

                    } catch ( \Throwable $e ) {
                        error_log('[BSBT AUTO-EXPIRE WC ERROR] Booking #' . $booking_id . ' | ' . $e->getMessage());
                    }
                }

                self::set_mphb_status_safe( $booking_id, 'pending' );
                error_log('[BSBT AUTO-EXPIRE] Booking #' . $booking_id . ' -> MPHB pending, Woo released.');

            } finally {
                self::release_lock( $booking_id, $lock_token );
            }
        }
    }

    /* =========================================================
     * ORDER FINDER (Meta + Payment Bridge)
     * ========================================================= */

    private static function find_order_for_booking( int $booking_id ): ?WC_Order {

        if ( $booking_id <= 0 ) return null;
        if ( ! function_exists('wc_get_orders') ) return null;

        $statuses = array_keys( wc_get_order_statuses() );

        // 1) meta lookup (fast): welded booking id
        $orders = wc_get_orders([
            'limit'      => 1,
            'meta_key'   => self::META_BSBT_REF,
            'meta_value' => $booking_id,
            'status'     => $statuses,
            'orderby'    => 'date',
            'order'      => 'DESC',
        ]);

        if ( ! empty($orders) && $orders[0] instanceof WC_Order ) {
            return $orders[0];
        }

        // 2) meta lookup: some bridges store booking id on order
        $orders = wc_get_orders([
            'limit'      => 1,
            'meta_key'   => '_mphb_booking_id',
            'meta_value' => $booking_id,
            'status'     => $statuses,
            'orderby'    => 'date',
            'order'      => 'DESC',
        ]);

        if ( ! empty($orders) && $orders[0] instanceof WC_Order ) {
            return $orders[0];
        }

        // 3) MPHB Payment bridge
        $order_id = self::resolve_order_id_via_mphb_payment_bridge( $booking_id );
        if ( $order_id > 0 ) {
            $order = wc_get_order( $order_id );
            if ( $order instanceof WC_Order ) {
                return $order;
            }
        }

        return null;
    }

    private static function resolve_order_id_via_mphb_payment_bridge( int $booking_id ): int {

        if ( $booking_id <= 0 ) return 0;

        global $wpdb;

        $payments = get_posts([
            'post_type'      => 'mphb_payment',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'meta_query'     => [
                [
                    'key'     => '_mphb_booking_id',
                    'value'   => (string) $booking_id,
                    'compare' => '=',
                ],
            ],
        ]);

        $payment_id = ! empty($payments) ? (int) $payments[0] : 0;

        if ( $payment_id <= 0 ) {
            $payments = get_posts([
                'post_type'      => 'mphb_payment',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'DESC',
                'meta_query'     => [
                    [
                        'key'     => 'mphb_booking_id',
                        'value'   => (string) $booking_id,
                        'compare' => '=',
                    ],
                ],
            ]);

            $payment_id = ! empty($payments) ? (int) $payments[0] : 0;
        }

        if ( $payment_id <= 0 ) {
            return 0;
        }

        $table_itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $table_items    = $wpdb->prefix . 'woocommerce_order_items';

        $exists_itemmeta = (string) $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $table_itemmeta) );
        $exists_items    = (string) $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $table_items) );

        if ( $exists_itemmeta !== $table_itemmeta || $exists_items !== $table_items ) {
            return 0;
        }

        $sql = "
            SELECT oi.order_id
            FROM {$table_itemmeta} oim
            JOIN {$table_items} oi ON oi.order_item_id = oim.order_item_id
            WHERE oim.meta_key = %s
              AND oim.meta_value = %s
            ORDER BY oi.order_id DESC
            LIMIT 1
        ";

        $order_id = (int) $wpdb->get_var(
            $wpdb->prepare( $sql, '_mphb_payment_id', (string) $payment_id )
        );

        return $order_id > 0 ? $order_id : 0;
    }

    /* =========================================================
     * MPHB STATUS (SAFE, NO PREFIX)
     * ========================================================= */

    private static function set_mphb_status_safe( int $booking_id, string $status ): void {

        if ( ! function_exists('MPHB') ) return;

        try {
            $booking = MPHB()->getBookingRepository()->findById( $booking_id );
            if ( ! $booking ) return;

            $allowed = [
                'pending-user',
                'pending-payment',
                'pending',
                'abandoned',
                'confirmed',
                'cancelled',
            ];

            if ( ! in_array( $status, $allowed, true ) ) {
                return;
            }

            $booking->setStatus( $status );
            MPHB()->getBookingRepository()->save( $booking );

            if ( class_exists('\MPHB\Shortcodes\SearchAvailabilityShortcode') ) {
                \MPHB\Shortcodes\SearchAvailabilityShortcode::clearCache();
            }

        } catch ( \Throwable $e ) {
            error_log('[BSBT MPHB ERR] ' . $e->getMessage());
        }
    }
}