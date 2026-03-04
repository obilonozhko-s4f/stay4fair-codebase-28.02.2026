<?php
/**
 * File: /stay4fair.com/wp-content/plugins/stayflow-core/src/Core/Kernel.php
 * Version: 1.1.0
 * RU: Главное ядро инициализации плагина с поддержкой Onboarding.
 * EN: Main initialization kernel of the plugin with Onboarding support.
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

        // RU: Регистрация CPT
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
        (new \StayFlow\CPT\ApartmentProvider())->register();
        (new \StayFlow\CPT\ApartmentHandler())->register();
        (new \StayFlow\Admin\AccessGuard())->register();
        (new \StayFlow\Admin\SecurityGuard())->register();
        (new \StayFlow\Media\ImageOptimizer())->register();
        (new \StayFlow\CPT\ApartmentEditProvider())->register();
        (new \StayFlow\CPT\ApartmentEditHandler())->register();
        (new \StayFlow\CPT\ApartmentListProvider())->register();
    }
}