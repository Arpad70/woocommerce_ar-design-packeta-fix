<?php

namespace ArDesign\PacketaFix;

defined('ABSPATH') || exit;

class Settings extends \WC_Shipping_Method
{
    public const SETTINGS_ID_KEY = 'ar_design_packeta_fix';
    public const SETTINGS_OPTION_KEY = 'woocommerce_ar_design_packeta_fix_settings';
    public const TRACKING_ENABLED_OPTION_KEY = 'packeta_tracking_enabled';
    public const AUTO_COMPLETE_ORDER_OPTION_KEY = 'packeta_tracking_auto_complete_order';

    public static function init(): void
    {
        add_filter('woocommerce_get_sections_shipping', [__CLASS__, 'addShippingSection'], 60, 1);
        add_filter('woocommerce_get_settings_shipping', [__CLASS__, 'getShippingSectionSettings'], 60, 2);
    }

    public function __construct()
    {
        $this->id = self::SETTINGS_ID_KEY;
        $this->method_title = __('AR Design Packeta Fix', 'ar-design-packeta-fix');
        $this->method_description = __('Automation bridge for the Packeta WooCommerce plugin.', 'ar-design-packeta-fix');
        $this->title = __('AR Design Packeta Fix', 'ar-design-packeta-fix');
        $this->enabled = 'yes';

        $this->init_form_fields();
        $this->init_settings();
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            self::TRACKING_ENABLED_OPTION_KEY => [
                'title' => __('Enable Packeta automation', 'ar-design-packeta-fix'),
                'type' => 'checkbox',
                'label' => __('Synchronize Packeta shipments and delivery workflow automatically.', 'ar-design-packeta-fix'),
                'default' => 'yes',
            ],
            self::AUTO_COMPLETE_ORDER_OPTION_KEY => [
                'title' => __('Complete order after delivery', 'ar-design-packeta-fix'),
                'type' => 'checkbox',
                'label' => __('Change WooCommerce order status to completed when Packeta confirms delivery.', 'ar-design-packeta-fix'),
                'default' => 'yes',
            ],
        ];
    }

    public static function addShippingSection(array $sections): array
    {
        $sections[self::SETTINGS_ID_KEY] = __('Packeta Fix', 'ar-design-packeta-fix');

        return $sections;
    }

    public static function getShippingSectionSettings(array $settings, $current_section): array
    {
        if ($current_section !== self::SETTINGS_ID_KEY) {
            return $settings;
        }

        return self::getAdminSectionSettings();
    }

    public static function getAdminSectionSettings(): array
    {
        $instance = new self();
        $storedSettings = self::getDefaultSettings();
        $settings = [
            [
                'title' => __('AR Design Packeta Fix', 'ar-design-packeta-fix'),
                'type' => 'title',
                'desc' => __('Runs Packeta export, tracking synchronization and delivered-order automation independently from the DPD module.', 'ar-design-packeta-fix'),
                'id' => self::SETTINGS_ID_KEY,
            ],
        ];

        foreach ($instance->form_fields as $key => $field) {
            $field['id'] = self::SETTINGS_OPTION_KEY . '_' . $key;
            $field['field_name'] = self::SETTINGS_OPTION_KEY . '[' . $key . ']';
            $field['value'] = $storedSettings[$key] ?? ($field['default'] ?? '');
            $settings[] = $field;
        }

        $settings[] = [
            'type' => 'sectionend',
            'id' => self::SETTINGS_ID_KEY,
        ];

        return $settings;
    }

    public static function getDefaultSettings(): array
    {
        $settings = get_option(self::SETTINGS_OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];

        return array_merge([
            self::TRACKING_ENABLED_OPTION_KEY => 'yes',
            self::AUTO_COMPLETE_ORDER_OPTION_KEY => 'yes',
        ], $settings);
    }

    public static function isTrackingEnabled(): bool
    {
        $settings = self::getDefaultSettings();

        return ($settings[self::TRACKING_ENABLED_OPTION_KEY] ?? 'yes') === 'yes';
    }
}
