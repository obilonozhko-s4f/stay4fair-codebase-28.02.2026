<?php

declare(strict_types=1);

namespace StayFlow\Admin;

use StayFlow\CPT\OwnerPostType;

if (!defined('ABSPATH')) exit;

/**
 * Version: 1.0.0
 * RU: Кастомная таблица управления владельцами (CRM-панель).
 * EN: Custom Owner Management Table (CRM Dashboard).
 */
final class OwnersTable
{
    public function render(): void
    {
        // RU: Получаем всех пользователей с ролью owner
        $owners = get_users(['role' => 'owner', 'orderby' => 'registered', 'order' => 'DESC']);

        ?>
        <div class="wrap stayflow-admin-wrap">
            <h1 class="sf-page-title">👥 Owners Management Center</h1>
            <p style="color: #64748b; margin-bottom: 20px;">Управление партнерами, проверка комплаенса и мониторинг объектов.</p>

            <table class="wp-list-table widefat fixed striped posts">
                <thead>
                    <tr>
                        <th style="width: 20%;">Владелец / Профиль</th>
                        <th>Статус</th>
                        <th>Объекты (MPHB)</th>
                        <th>Бизнес-Модель</th>
                        <th>Комплаенс (Платежи)</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($owners)): ?>
                        <tr><td colspan="6">Владельцы не найдены.</td></tr>
                    <?php else: 
                        foreach ($owners as $user): 
                            $this->renderRow($user);
                        endforeach; 
                    endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        $this->renderStyles();
    }

    private function renderRow(\WP_User $user): void
    {
        $userId = $user->ID;
        
        // 1. Статус верификации
        $status = get_user_meta($userId, '_sf_account_status', true) ?: 'pending';
        
        // 2. Сбор статистики по объектам (mphb_room_type)
        global $wpdb;
        $apts = $wpdb->get_results($wpdb->prepare("
            SELECT post_status, ID FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'bsbt_owner_id'
            WHERE p.post_type = 'mphb_room_type' 
            AND (p.post_author = %d OR pm.meta_value = %d)
        ", $userId, $userId));

        $counts = ['publish' => 0, 'pending' => 0, 'draft' => 0];
        $models = [];
        foreach ($apts as $apt) {
            $counts[$apt->post_status] = ($counts[$apt->post_status] ?? 0) + 1;
            // Собираем модели (A/B)
            $m = get_post_meta($apt->ID, '_bsbt_business_model', true) ?: 'model_a';
            $models[] = strtoupper(str_replace('model_', '', $m));
        }
        $models = array_unique($models);
        $modelText = empty($models) ? '—' : implode(' / ', $models);

        // 3. Комплаенс (IBAN, Tax)
        $iban = get_user_meta($userId, 'bsbt_iban', true);
        $tax  = get_user_meta($userId, 'bsbt_tax_number', true);
        $vat  = get_user_meta($userId, 'sf_vat_id', true); // Из CPT

        // 4. Ссылка на CPT профиль
        $ownerPost = get_posts(['post_type' => OwnerPostType::POST_TYPE, 'author' => $userId, 'posts_per_page' => 1]);
        $editProfileUrl = !empty($ownerPost) ? get_edit_post_link($ownerPost[0]->ID) : admin_url('user-edit.php?user_id=' . $userId);
        ?>
        <tr>
            <td class="column-primary">
                <strong><a href="<?php echo esc_url($editProfileUrl); ?>"><?php echo esc_html($user->display_name); ?></a></strong>
                <div class="sf-owner-email"><?php echo esc_html($user->user_email); ?></div>
            </td>
            <td>
                <?php if ($status === 'verified'): ?>
                    <span class="sf-badge badge-active">🟢 Verified</span>
                <?php else: ?>
                    <span class="sf-badge badge-pending">🟡 Pending</span>
                <?php endif; ?>
            </td>
            <td>
                <span class="sf-stat"><b><?php echo $counts['publish']; ?></b> Active</span><br>
                <span class="sf-stat-sub"><?php echo ($counts['pending'] + $counts['draft']); ?> Offline / Review</span>
            </td>
            <td>
                <span class="sf-model-tag"><?php echo esc_html($modelText); ?></span>
            </td>
            <td>
                <div class="sf-compliance-icons">
                    <span title="IBAN" class="<?php echo $iban ? 'is-ok' : 'is-empty'; ?>">🏦</span>
                    <span title="Tax Number" class="<?php echo $tax ? 'is-ok' : 'is-empty'; ?>">📄</span>
                    <span title="VAT ID" class="<?php echo $vat ? 'is-ok' : 'is-empty'; ?>">🏢</span>
                </div>
            </td>
            <td>
                <div class="sf-row-actions">
                    <a href="<?php echo admin_url('edit.php?post_type=mphb_room_type&author=' . $userId); ?>" class="button button-small">Объекты</a>
                    <?php if (function_exists('user_switching_url')): ?>
                        <a href="<?php echo esc_url(user_switching_url($user)); ?>" class="button button-small">Войти как..</a>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php
    }

    private function renderStyles(): void
    {
        ?>
        <style>
            .sf-owner-email { font-size: 11px; color: #64748b; }
            .sf-stat { color: #1e7e34; font-size: 13px; }
            .sf-stat-sub { color: #6b7280; font-size: 11px; }
            .sf-model-tag { background: #082567; color: #E0B849; padding: 2px 8px; border-radius: 4px; font-weight: bold; font-size: 11px; }
            .sf-compliance-icons span { font-size: 18px; margin-right: 5px; opacity: 0.2; filter: grayscale(1); }
            .sf-compliance-icons span.is-ok { opacity: 1; filter: none; }
            .sf-row-actions { display: flex; gap: 4px; }
            .badge-active { background: #dcfce7 !important; color: #166534 !important; }
            .badge-pending { background: #fef9c3 !important; color: #854d0e !important; }
        </style>
        <?php
    }
}
