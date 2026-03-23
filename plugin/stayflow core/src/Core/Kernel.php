<?php
/**
 * File: /stay4fair.com/wp-content/plugins/stayflow-core/src/Core/Kernel.php
 * Version: 1.1.3
 * RU: Главное ядро инициализации плагина с поддержкой Onboarding, Calendar Sync и Owner Profile.
 * EN: Main initialization kernel of the plugin with Onboarding, Calendar Sync and Owner Profile support.
 */

declare(strict_types=1);

namespace StayFlow\Core;

use StayFlow\Admin\Menu;
use StayFlow\BusinessModel\BusinessModelServiceProvider;
use StayFlow\CPT\OwnerPostType;
use StayFlow\CPT\PropertyMeta;
use StayFlow\Integration\BsbtPolicyAdapter;
use StayFlow\FeatureFlags\FeatureFlagStore;
use StayFlow\Settings\SettingsStore;
use StayFlow\Onboarding\OnboardingProvider;
use StayFlow\Onboarding\OnboardingHandler;
use StayFlow\BusinessModel\InvoiceModifier;
use StayFlow\Api\CalendarApiController;

final class Kernel
{
    /**
     * RU: Инициализация всех модулей плагина.
     * EN: Booting all plugin modules.
     */
    public function boot(): void
    {
        $settingsStore    = new SettingsStore();
        $ownerPostType    = new OwnerPostType();
        $propertyMeta     = new PropertyMeta();
        $rateSync         = new \StayFlow\BusinessModel\RateSyncService();
        $policyAdapter    = new BsbtPolicyAdapter();
        $featureFlagStore = new FeatureFlagStore();
        $menu             = new Menu();
        $calendarApi      = new CalendarApiController();
        $calendarApi->register();

        // RU: Регистрация CPT / EN: CPT Registration
        add_action('init', [$ownerPostType, 'register']);
        
        // RU: Инициализируем хуки для квартиры (метабоксы & сохранение)
        $propertyMeta->register(); 

        // RU: Инициализируем синхронизацию тарифов
        $rateSync->register();

        // RU: Инициализируем вывод шорткода отмены бронирования
        $policyAdapter->register();

        // RU: Регистрация настроек и меню
        add_action('admin_init', [$settingsStore, 'register']);
        add_action('admin_init', [$featureFlagStore, 'register']);
        add_action('admin_menu', [$menu, 'register']);

        // RU: Запуск бизнес-логики и налогов
        (new BusinessModelServiceProvider())->boot();

        // RU: Инициализация модуля регистрации новых владельцев (Onboarding)
        // EN: Initialize owner onboarding module
        (new OnboardingProvider())->register();
        (new OnboardingHandler($settingsStore))->register();
        (new \StayFlow\Onboarding\VerificationHandler())->register();
        (new \StayFlow\Integration\OwnerStepsShortcode())->register();
        
        // RU: CPT Провайдеры и Обработчики (Квартиры)
        (new \StayFlow\CPT\ApartmentProvider())->register();
        (new \StayFlow\CPT\ApartmentHandler())->register();
        (new \StayFlow\Admin\AccessGuard())->register();
        (new \StayFlow\Admin\SecurityGuard())->register();
        (new \StayFlow\Media\ImageOptimizer())->register();
        (new \StayFlow\CPT\ApartmentEditProvider())->register();
        (new \StayFlow\CPT\ApartmentEditHandler())->register();
        (new \StayFlow\CPT\ApartmentListProvider())->register();
        
        // RU: Модуль Ваучеров (Vouchers)
        (new \StayFlow\Voucher\VoucherSender())->register();
        (new \StayFlow\Voucher\VoucherMetabox())->register();
        (new \StayFlow\CPT\OwnerCalendarProvider())->register();

        // RU: Модуль Профиля Владельца (Owner Profile)
        // EN: Owner Profile Module
        if (class_exists('\StayFlow\CPT\OwnerProfileProvider')) {
            (new \StayFlow\CPT\OwnerProfileProvider())->register();
        }
        if (class_exists('\StayFlow\CPT\OwnerProfileHandler')) {
            (new \StayFlow\CPT\OwnerProfileHandler())->register();
        }
// Добавить в список инициализации (измененный сегмент)
if (class_exists('\StayFlow\Integration\ContractingPartyShortcode')) {
    (new \StayFlow\Integration\ContractingPartyShortcode())->register();
}
        // =========================================================
        // RU: ИНИЦИАЛИЗАЦИЯ ИНВОЙСОВ
        // EN: INVOICES INITIALIZATION
        // =========================================================
        add_action('init', [InvoiceModifier::class, 'init']);
    }
}
