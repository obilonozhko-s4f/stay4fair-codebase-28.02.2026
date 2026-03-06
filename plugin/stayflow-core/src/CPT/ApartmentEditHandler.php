<?php

declare(strict_types=1);

namespace StayFlow\CPT;

/**
 * Version: 1.4.0
 *
 * RU: Промышленный бэкенд (Native MPHB API Integration):
 * - Интеграция с родным API MotoPress (RoomRepository->setSyncUrls) для запуска Cron.
 * - Multi-iCal (поддержка нескольких URL).
 * - Upsert без затирания "чужих" календарей.
 * - Mutex (защита от race condition при двойном клике).
 * - Авто-конвертация webcal:// -> https://.
 * - Fallback: прямая СТРОГАЯ запись (1..N) в БД, если API MotoPress недоступен.
 * - Fallback: дублируем mphb_sync_urls на Room Type.
 * - Осторожный фикс 500 ошибки: если комната (Room) в корзине — восстанавливаем.
 *
 * EN: Production backend (Native MPHB API Integration):
 * - Native MotoPress API integration (RoomRepository->setSyncUrls) to trigger Cron.
 * - Multi-iCal (support for multiple URLs).
 * - Upsert without wiping "foreign" calendars.
 * - Mutex (race condition protection).
 * - Auto-conversion webcal:// -> https://.
 * - Fallback: STRICT direct DB write (1..N) if MPHB API is unavailable.
 * - Fallback: duplicate mphb_sync_urls to Room Type.
 * - Safe 500 error fix: if Room is in trash — restore it.
 */
final class ApartmentEditHandler
{
    /** RU: Каноническая мета на Room Type (JSON список). EN: Canonical meta on Room Type (JSON list). */
    private const META_SF_ICAL_IMPORT = '_sf_ical_import';

    /** RU: Мета на физической комнате: последние записанные НАМИ ссылки (JSON). EN: Meta on Room: last URLs written by us (JSON). */
    private const META_SF_ROOM_LAST_URLS = '_sf_ical_urls_last';

    /** RU/EN: Lock TTL seconds (Время жизни блокировки в секундах). */
    private const LOCK_TTL = 20;

    /**
     * RU: Включить true для диагностики в debug.log.
     * EN: Enable true temporarily for diagnostics in debug.log.
     */
    private const DEBUG = true;

    public function register(): void
    {
        add_action('admin_post_sf_process_edit_apartment', [$this, 'handleForm']);
    }

    public function handleForm(): void
    {
        // ========================================================================
        // 1) Security / Безопасность
        // ========================================================================
        if (
            !is_user_logged_in()
            || !isset($_POST['sf_edit_apt_nonce'])
            || !wp_verify_nonce((string) $_POST['sf_edit_apt_nonce'], 'sf_edit_apt_action')
        ) {
            wp_die('Sicherheit Check fehlgeschlagen.');
        }

        $apt_id = isset($_POST['apt_id']) ? (int) $_POST['apt_id'] : 0;
        if ($apt_id <= 0) {
            wp_die('Fehlende Apartment-ID.');
        }

        $post = get_post($apt_id);
        if (!$post) {
            wp_die('Apartment nicht gefunden.');
        }

        // ========================================================================
        // 2) Permission / Права доступа (Владелец или Админ)
        // ========================================================================
        $userId = get_current_user_id();
        if ((int) $post->post_author !== $userId && !current_user_can('manage_options')) {
            wp_die('Zugriff verweigert. Keine Berechtigung.');
        }

        // ========================================================================
        // 3) Mutex Lock / Блокировка от двойного клика
        // ========================================================================
        $lock_key = 'sf_edit_apt_lock_' . $apt_id;
        if (get_transient($lock_key)) {
            wp_die('Bitte warten: Speichern läuft bereits.');
        }
        set_transient($lock_key, 1, self::LOCK_TTL);

        try {
            if (self::DEBUG) {
                error_log('[SF iCal] handleForm start | apt_id=' . $apt_id . ' | user=' . $userId);
            }

            // ========================================================================
            // 4) Status / Статус (Только для Room Type)
            // ========================================================================
            $req_status = isset($_POST['apt_status']) ? (string) $_POST['apt_status'] : 'online';
            $new_status = ($req_status === 'offline')
                ? 'draft'
                : (($post->post_status === 'draft') ? 'publish' : $post->post_status);

            $update_res = wp_update_post([
                'ID'           => $apt_id,
                'post_title'   => sanitize_text_field((string) ($_POST['apt_name'] ?? '')),
                'post_content' => wp_kses_post(wp_unslash((string) ($_POST['apt_description'] ?? ''))),
                'post_status'  => $new_status,
            ], true);

            if (is_wp_error($update_res)) {
                wp_die('Update fehlgeschlagen: ' . esc_html($update_res->get_error_message()));
            }

            // ========================================================================
            // 5) Parse iCal URLs / Парсинг и очистка iCal ссылок
            // ========================================================================
            $raw_ical  = (string) ($_POST['apt_ical'] ?? '');
            $ical_urls = $this->parseIcalUrls($raw_ical);

            // RU: Канонический JSON сохраняем на Room Type (для истории и фронтенда).
            // EN: Store canonical JSON on Room Type (for history and frontend).
            update_post_meta($apt_id, self::META_SF_ICAL_IMPORT, wp_json_encode($ical_urls, JSON_UNESCAPED_SLASHES));

            if (self::DEBUG) {
                error_log('[SF iCal] parsed urls | ' . wp_json_encode($ical_urls));
            }

            // ========================================================================
            // 6) Find Physical Rooms / Поиск физических комнат (mphb_room)
            // ========================================================================
            global $wpdb;

            $room_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'mphb_room_type_id' AND meta_value = %d",
                $apt_id
            ));

            if (self::DEBUG) {
                error_log('[SF iCal] found rooms | apt_id=' . $apt_id . ' | room_ids=' . wp_json_encode(array_map('intval', (array) $room_ids)));
            }

            // ========================================================================
            // 7) Upsert calendars / Синхронизация календарей для каждой комнаты
            // ========================================================================
            if (!empty($room_ids)) {
                foreach ($room_ids as $room_id_raw) {
                    $room_id = (int) $room_id_raw;
                    if ($room_id <= 0) {
                        continue;
                    }

                    // RU: Проверка — убеждаемся, что это реально mphb_room, а не тариф или бронь.
                    // EN: Check — ensure this is actually mphb_room, not a rate or booking.
                    $room_post = get_post($room_id);
                    if (!$room_post || $room_post->post_type !== 'mphb_room') {
                        if (self::DEBUG) {
                            error_log('[SF iCal] skip room_id=' . $room_id . ' | invalid post_type');
                        }
                        continue;
                    }

                    // RU: Фикс 500 ошибки: восстанавливаем комнату из корзины, если нужно.
                    // EN: 500 error fix: restore room from trash if necessary.
                    $this->maybeRestoreTrashedRoom($room_id, $new_status);

                    // RU: ГЛАВНАЯ ЛОГИКА СИНХРОНИЗАЦИИ
                    // EN: MAIN SYNCHRONIZATION LOGIC
                    $this->upsertMphbSyncUrlsForRoom($room_id, $ical_urls);

                    // RU: Очищаем кэши WordPress для этой записи
                    // EN: Clear WordPress caches for this post
                    wp_cache_delete($room_id, 'post_meta');
                    clean_post_cache($room_id);
                }
            }

            // ========================================================================
            // 8) Fallback on Room Type / Дублируем ссылки на сам тип размещения
            // ========================================================================
            $this->writeMphbSyncUrlsToRoomTypeFallback($apt_id, $ical_urls);
            wp_cache_delete($apt_id, 'post_meta');
            clean_post_cache($apt_id);

            // ========================================================================
            // 9) Update other meta / Обновление остальных метаданных
            // ========================================================================
            update_post_meta($apt_id, 'address', sanitize_text_field((string) ($_POST['apt_address'] ?? '')));
            update_post_meta($apt_id, 'doorbell_name', sanitize_text_field((string) ($_POST['apt_doorbell'] ?? '')));
            update_post_meta($apt_id, 'owner_phone', sanitize_text_field((string) ($_POST['apt_contact_phone'] ?? '')));
            update_post_meta($apt_id, '_sf_commune_reg_id', sanitize_text_field((string) ($_POST['apt_reg_id'] ?? '')));

            $price = max(0.0, (float) ($_POST['apt_price'] ?? 0));
            update_post_meta($apt_id, '_sf_selling_price', $price);

            $min_stay = max(1, (int) ($_POST['apt_min_stay'] ?? 1));
            update_post_meta($apt_id, 'sf_min_stay', $min_stay);

            if (self::DEBUG) {
                error_log('[SF iCal] handleForm done | redirecting...');
            }

            wp_safe_redirect(add_query_arg('apt_updated', '1', home_url('/owner-apartments/')));
            exit;

        } finally {
            delete_transient($lock_key);
        }
    }

    // ========================================================================
    // HELPER METHODS / ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ
    // ========================================================================

    /**
     * RU: Парсит несколько URL (newline / comma / semicolon), чистит, webcal->https.
     * EN: Parses multiple URLs (newline / comma / semicolon), cleans, webcal->https.
     *
     * @return string[]
     */
    private function parseIcalUrls(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
        $out = [];

        foreach ($parts as $p) {
            $u = trim((string) $p);
            if ($u === '') {
                continue;
            }

            if (stripos($u, 'webcal://') === 0) {
                $u = 'https://' . substr($u, 9);
            }

            $u = preg_replace('/\s+/', '', $u);
            if (!$u) {
                continue;
            }

            $u = esc_url_raw($u, ['http', 'https']);
            if ($u === '') {
                continue;
            }

            // Soft heuristic
            $looks_like_ical =
                (stripos($u, '.ics') !== false) ||
                (stripos($u, 'ical') !== false) ||
                (stripos($u, 'calendar') !== false);

            if (!$looks_like_ical) {
                continue;
            }

            $out[] = $u;
        }

        return array_values(array_unique($out));
    }

    /**
     * RU: Upsert mphb_sync_urls на mphb_room:
     * - Использует родной API MotoPress (RoomRepository->setSyncUrls).
     * - Это гарантирует постановку комнаты в очередь (mphb_sync_queue).
     *
     * EN: Upsert mphb_sync_urls on mphb_room:
     * - Uses native MotoPress API (RoomRepository->setSyncUrls).
     * - This guarantees the room is added to the cron queue (mphb_sync_queue).
     */
    private function upsertMphbSyncUrlsForRoom(int $room_id, array $new_urls): void
    {
        $old_sync = get_post_meta($room_id, 'mphb_sync_urls', true);
        if (!is_array($old_sync)) {
            $old_sync = [];
        }

        $existing_urls = [];
        foreach ($old_sync as $row) {
            if (is_array($row) && !empty($row['url'])) {
                $existing_urls[] = trim((string) $row['url']);
            }
        }

        $sf_last = get_post_meta($room_id, self::META_SF_ROOM_LAST_URLS, true);
        $sf_last_urls = $this->decodeJsonStringArray($sf_last);

        // RU: Оставляем чужие ссылки, удаляем только наши старые.
        // EN: Keep foreign URLs, remove only our old ones.
        $urls_to_keep = [];
        foreach ($existing_urls as $u) {
            if (!in_array($u, $sf_last_urls, true)) {
                $urls_to_keep[] = $u;
            }
        }

        // RU: Добавляем новые ссылки.
        // EN: Add new URLs.
        foreach ($new_urls as $u) {
            if (!in_array($u, $urls_to_keep, true)) {
                $urls_to_keep[] = $u;
            }
        }

        // ========================================================================
        // THE MAGIC / ГЛАВНАЯ МАГИЯ: NATIVE MOTOPRESS API
        // ========================================================================
        if (function_exists('MPHB') && method_exists(MPHB(), 'getRoomRepository')) {
            try {
                $room = MPHB()->getRoomRepository()->findById($room_id);
                if ($room) {
                    // RU: Этот метод сам сохранит мету и добавит задачу в cron queue MotoPress!
                    // EN: This method will save meta and add task to MotoPress cron queue!
                    $room->setSyncUrls($urls_to_keep);
                    
                    if (self::DEBUG) {
                        error_log('[SF iCal] SUCCESS: Used Native MPHB API to set Sync URLs for room_id=' . $room_id);
                    }
                }
            } catch (\Exception $e) {
                if (self::DEBUG) {
                    error_log('[SF iCal] ERROR using Native MPHB API: ' . $e->getMessage());
                }
                $this->saveMphbSyncUrlsDirectly($room_id, $urls_to_keep);
            }
        } else {
            // RU: Fallback, если API MotoPress недоступен (например, плагин отключен).
            // EN: Fallback if MotoPress API is unavailable.
            $this->saveMphbSyncUrlsDirectly($room_id, $urls_to_keep);
        }

        // RU: Запоминаем текущий набор наших ссылок для будущих редактирований.
        // EN: Store our current URLs for future surgical edits.
        update_post_meta(
            $room_id,
            self::META_SF_ROOM_LAST_URLS,
            wp_json_encode(array_values(array_unique($new_urls)), JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * RU: Fallback метод прямой записи (СТРОГО 1-based массив).
     * EN: Fallback direct write method (STRICT 1-based array).
     */
    private function saveMphbSyncUrlsDirectly(int $room_id, array $urls_to_keep): void
    {
        $new_sync_norm = [];
        $idx = 1; // STRICT 1-based required by MotoPress UI
        foreach ($urls_to_keep as $u) {
            $new_sync_norm[$idx] = ['url' => $u];
            $idx++;
        }

        if (empty($new_sync_norm)) {
            delete_post_meta($room_id, 'mphb_sync_urls');
            delete_post_meta($room_id, '_mphb_sync_urls_hash');
        } else {
            update_post_meta($room_id, 'mphb_sync_urls', $new_sync_norm);
            
            $hash = function_exists('mphb_generate_uid')
                ? (string) mphb_generate_uid()
                : md5(uniqid((string) $room_id, true));

            update_post_meta($room_id, '_mphb_sync_urls_hash', $hash);
        }

        if (self::DEBUG) {
            error_log('[SF iCal] WARNING: Used Direct DB Fallback for room_id=' . $room_id);
        }
    }

    /**
     * RU: Fallback запись на Room Type (mphb_room_type).
     * EN: Fallback write on Room Type (mphb_room_type).
     */
    private function writeMphbSyncUrlsToRoomTypeFallback(int $room_type_id, array $urls): void
    {
        $sync = [];
        $idx = 1;
        foreach ($urls as $u) {
            $sync[$idx] = ['url' => $u];
            $idx++;
        }

        $old = get_post_meta($room_type_id, 'mphb_sync_urls', true);
        if (!is_array($old)) {
            $old = [];
        }

        $changed = wp_json_encode($old) !== wp_json_encode($sync);

        if (!$changed) {
            return;
        }

        if (empty($sync)) {
            delete_post_meta($room_type_id, 'mphb_sync_urls');
            delete_post_meta($room_type_id, '_mphb_sync_urls_hash');
            return;
        }

        update_post_meta($room_type_id, 'mphb_sync_urls', $sync);

        $hash = function_exists('mphb_generate_uid')
            ? (string) mphb_generate_uid()
            : md5(uniqid((string) $room_type_id, true));

        update_post_meta($room_type_id, '_mphb_sync_urls_hash', $hash);
    }

    /**
     * RU: Если Room в корзине (trash), MotoPress admin screen иногда падает (500).
     * EN: If Room is in trash, MotoPress admin screen can crash (500).
     */
    private function maybeRestoreTrashedRoom(int $room_id, string $room_type_status): void
    {
        $room_post = get_post($room_id);
        if (!$room_post) {
            return;
        }

        if ($room_post->post_status !== 'trash') {
            return;
        }

        $target = ($room_type_status === 'draft') ? 'draft' : 'publish';

        wp_update_post([
            'ID'          => $room_id,
            'post_status' => $target,
        ]);

        if (self::DEBUG) {
            error_log('[SF iCal] restored trashed room | room_id=' . $room_id . ' -> ' . $target);
        }
    }

    /**
     * RU: Декодер JSON массива строк из meta.
     * EN: JSON string-array decoder from meta.
     *
     * @param mixed $value
     * @return string[]
     */
    private function decodeJsonStringArray($value): array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_values(array_filter($decoded, 'is_string'));
            }
        }

        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }

        return [];
    }
}