<?php

namespace ArDesign\PacketaFix;

defined('ABSPATH') || exit;

class Settings extends \WC_Shipping_Method
{
    public const SETTINGS_ID_KEY = 'ar_design_packeta_fix';
    public const SETTINGS_OPTION_KEY = 'woocommerce_ar_design_packeta_fix_settings';
    public const TRACKING_ENABLED_OPTION_KEY = 'packeta_tracking_enabled';
    public const AUTO_COMPLETE_ORDER_OPTION_KEY = 'packeta_tracking_auto_complete_order';
    public const FUEL_SURCHARGE_PERCENT_OPTION_KEY = 'packeta_fuel_surcharge_percent';
    public const TOLL_SURCHARGE_PERCENT_OPTION_KEY = 'packeta_toll_surcharge_percent';
    public const TOLL_SURCHARGE_FIXED_OPTION_KEY = 'packeta_toll_surcharge_fixed';

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
            self::FUEL_SURCHARGE_PERCENT_OPTION_KEY => [
                'title' => __('Palivový príplatok (%)', 'ar-design-packeta-fix'),
                'type' => 'text',
                'desc' => __('Percentuálny palivový príplatok aplikovaný na Packeta sadzby dopravy (napr. 6.4).', 'ar-design-packeta-fix'),
                'default' => '0',
                'desc_tip' => false,
                'placeholder' => '0',
            ],
            self::TOLL_SURCHARGE_PERCENT_OPTION_KEY => [
                'title' => __('Mýtny príplatok (%)', 'ar-design-packeta-fix'),
                'type' => 'text',
                'desc' => __('Voliteľná percentuálna korekcia mýta. Oficiálny cenník Packeta používa primárne mýto ako EUR/kg.', 'ar-design-packeta-fix'),
                'default' => '0',
                'desc_tip' => false,
                'placeholder' => '0',
            ],
            self::TOLL_SURCHARGE_FIXED_OPTION_KEY => [
                'title' => __('Mýtny príplatok (EUR/kg)', 'ar-design-packeta-fix'),
                'type' => 'text',
                'desc' => __('Mýtny príplatok za každý začatý kilogram zásielky (napr. 0.04).', 'ar-design-packeta-fix'),
                'default' => '0',
                'desc_tip' => false,
                'placeholder' => wc_format_localized_price(0),
            ],
            [
                'type' => 'info',
                'id' => self::SETTINGS_ID_KEY . '_surcharge_sync_info',
                'title' => __('Kontrola cien z CRONu', 'ar-design-packeta-fix'),
                'text' => \ArDesign\PacketaFix\SurchargeMonitor::getAdminStatusHtml(),
                'is_option' => false,
                'row_class' => 'ard-surcharge-sync-info',
            ],
        ];

        $this->form_fields = self::injectSurchargeHelpers($this->form_fields);
    }

    private static function injectSurchargeHelpers(array $fields): array
    {
        $helpers = \ArDesign\PacketaFix\SurchargeMonitor::getHelperTexts();

        foreach ($helpers as $fieldKey => $helperText) {
            if (!isset($fields[$fieldKey])) {
                continue;
            }

            $baseDescription = (string) ($fields[$fieldKey]['desc'] ?? $fields[$fieldKey]['description'] ?? '');
            $fields[$fieldKey]['desc'] = trim($baseDescription . ' ' . $helperText);
            $fields[$fieldKey]['desc_tip'] = false;
        }

        return $fields;
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
            self::FUEL_SURCHARGE_PERCENT_OPTION_KEY => '0',
            self::TOLL_SURCHARGE_PERCENT_OPTION_KEY => '0',
            self::TOLL_SURCHARGE_FIXED_OPTION_KEY => '0',
        ], $settings);
    }

    public static function isTrackingEnabled(): bool
    {
        $settings = self::getDefaultSettings();

        return ($settings[self::TRACKING_ENABLED_OPTION_KEY] ?? 'yes') === 'yes';
    }
}
