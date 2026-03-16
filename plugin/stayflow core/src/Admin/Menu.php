<?php

declare(strict_types=1);

namespace StayFlow\Admin;

use StayFlow\Registry\ModuleRegistry;
use StayFlow\Settings\SettingsStore;

/**
 * Version: 2.4.0
 * RU: Управление меню. Добавлена интеграция кастомной таблицы Owners.
 */
final class Menu
{
    public function register(): void
    {
        add_menu_page('StayFlow', 'StayFlow', 'manage_options', 'stayflow-core', [$this, 'renderDashboard'], 'dashicons-admin-generic', 58);
        add_submenu_page('stayflow-core', 'Settings', 'Settings', 'manage_options', 'stayflow-core-settings', [$this, 'renderSettings']);
        add_submenu_page('stayflow-core', 'Content Registry', 'Content Registry', 'manage_options', 'stayflow-core-content-registry', [$this, 'renderContentRegistry']);
        add_submenu_page('stayflow-core', 'Policies', 'Policies', 'manage_options', 'stayflow-core-policies', [$this, 'renderPolicies']);
        
        // RU: Измененный путь для Owners - теперь ведет на наш контроллер
        add_submenu_page('stayflow-core', 'Owners', 'Owners', 'manage_options', 'stayflow-owners', [$this, 'renderOwnersTable']);

        add_action('admin_init', function() {
            register_setting('stayflow_policies_group', 'stayflow_registry_policies');
            register_setting('stayflow_content_group', 'stayflow_registry_content');
        });
    }

    /**
     * RU: Рендер новой таблицы владельцев.
     */
    public function renderOwnersTable(): void
    {
        $table = new OwnersTable();
        $table->render();
    }

    // ... (остальные методы renderDashboard, renderSettings и т.д. остаются без изменений)
}
