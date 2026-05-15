<?php

namespace ArDesign\PacketaFix;

defined('ABSPATH') || exit;

class Shipment
{
    public const CARRIER_META_KEY = '_ard_shipping_carrier';
    public const REFERENCE_META_KEY = '_ard_shipping_reference';
    public const PRIMARY_TRACKING_NUMBER_META_KEY = '_ard_shipping_tracking_number';
    public const TRACKING_NUMBERS_META_KEY = '_ard_shipping_tracking_numbers';
    public const TRACKING_URL_META_KEY = '_ard_shipping_tracking_url';
    public const LABEL_URL_META_KEY = '_ard_shipping_label_url';
    public const STATUS_META_KEY = '_ard_shipping_status';
    public const STATUS_LABEL_META_KEY = '_ard_shipping_status_label';
    public const CREATED_AT_META_KEY = '_ard_shipping_created_at';
    public const UPDATED_AT_META_KEY = '_ard_shipping_updated_at';
    public const PAYLOAD_META_KEY = '_ard_shipping_payload';
    public const DELIVERED_AT_META_KEY = '_ard_shipping_delivered_at';
    public const DELIVERY_WORKFLOW_PROCESSED_AT_META_KEY = '_ard_shipping_delivery_workflow_processed_at';
    public const INVOICE_FILE_META_KEY = '_ard_shipping_invoice_file';

    public static function storeShipmentData(\WC_Order $order, array $shipmentData): void
    {
        $carrier = (string) ($shipmentData['carrier'] ?? $order->get_meta(self::CARRIER_META_KEY, true) ?: PacketaBridge::CARRIER);

        if (!self::canUpdateSharedShipmentData($order, $carrier)) {
            return;
        }

        $order->update_meta_data(self::CARRIER_META_KEY, $carrier);
        $order->update_meta_data(self::REFERENCE_META_KEY, (string) ($shipmentData['reference'] ?? ''));
        $order->update_meta_data(self::PRIMARY_TRACKING_NUMBER_META_KEY, (string) ($shipmentData['tracking_number'] ?? ''));
        $order->update_meta_data(self::TRACKING_NUMBERS_META_KEY, array_values((array) ($shipmentData['tracking_numbers'] ?? [])));
        $order->update_meta_data(self::TRACKING_URL_META_KEY, (string) ($shipmentData['tracking_url'] ?? ''));
        $order->update_meta_data(self::LABEL_URL_META_KEY, (string) ($shipmentData['label_url'] ?? ''));
        $order->update_meta_data(self::STATUS_META_KEY, (string) ($shipmentData['status'] ?? ''));
        $order->update_meta_data(self::STATUS_LABEL_META_KEY, (string) ($shipmentData['status_label'] ?? ''));
        $order->update_meta_data(self::UPDATED_AT_META_KEY, (string) ($shipmentData['updated_at'] ?? current_time('mysql')));
        $order->update_meta_data(self::PAYLOAD_META_KEY, isset($shipmentData['payload']) && is_array($shipmentData['payload']) ? $shipmentData['payload'] : []);

        if (!$order->get_meta(self::CREATED_AT_META_KEY, true)) {
            $order->update_meta_data(self::CREATED_AT_META_KEY, (string) ($shipmentData['created_at'] ?? current_time('mysql')));
        }
    }

    public static function getShipmentData(\WC_Order $order): array
    {
        return [
            'carrier' => (string) $order->get_meta(self::CARRIER_META_KEY, true),
            'reference' => (string) $order->get_meta(self::REFERENCE_META_KEY, true),
            'tracking_number' => (string) $order->get_meta(self::PRIMARY_TRACKING_NUMBER_META_KEY, true),
            'tracking_numbers' => array_values((array) $order->get_meta(self::TRACKING_NUMBERS_META_KEY, true)),
            'tracking_url' => (string) $order->get_meta(self::TRACKING_URL_META_KEY, true),
            'label_url' => (string) $order->get_meta(self::LABEL_URL_META_KEY, true),
            'status' => (string) $order->get_meta(self::STATUS_META_KEY, true),
            'status_label' => (string) $order->get_meta(self::STATUS_LABEL_META_KEY, true),
            'created_at' => (string) $order->get_meta(self::CREATED_AT_META_KEY, true),
            'updated_at' => (string) $order->get_meta(self::UPDATED_AT_META_KEY, true),
            'payload' => (array) $order->get_meta(self::PAYLOAD_META_KEY, true),
            'delivered_at' => (string) $order->get_meta(self::DELIVERED_AT_META_KEY, true),
            'delivery_workflow_processed_at' => (string) $order->get_meta(self::DELIVERY_WORKFLOW_PROCESSED_AT_META_KEY, true),
            'invoice_file' => (string) $order->get_meta(self::INVOICE_FILE_META_KEY, true),
        ];
    }

    public static function markDelivered(\WC_Order $order, array $shipmentData = []): array
    {
        $deliveredAt = current_time('mysql');
        $shipmentData = array_merge(self::getShipmentData($order), $shipmentData, [
            'carrier' => PacketaBridge::CARRIER,
            'status' => 'delivered',
            'status_label' => __('Shipment delivered', 'ar-design-packeta-fix'),
            'updated_at' => $deliveredAt,
            'delivered_at' => $deliveredAt,
        ]);

        self::storeShipmentData($order, $shipmentData);
        $order->update_meta_data(self::DELIVERED_AT_META_KEY, $deliveredAt);
        $order->save_meta_data();

        do_action('ard_shipping_shipment_delivered', $order->get_id(), $shipmentData, $order);

        return $shipmentData;
    }

    private static function canUpdateSharedShipmentData(\WC_Order $order, string $carrier): bool
    {
        $existingCarrier = (string) $order->get_meta(self::CARRIER_META_KEY, true);

        if ($existingCarrier === '' || $existingCarrier === $carrier) {
            return true;
        }

        return self::orderUsesCarrier($order, $carrier);
    }

    private static function orderUsesCarrier(\WC_Order $order, string $carrier): bool
    {
        if ($carrier !== PacketaBridge::CARRIER) {
            return false;
        }

        foreach ($order->get_shipping_methods() as $shippingMethod) {
            if (!is_object($shippingMethod) || !method_exists($shippingMethod, 'get_method_id')) {
                continue;
            }

            $methodId = sanitize_key((string) $shippingMethod->get_method_id());
            if ($methodId === 'packetery_shipping_method' || 0 === strpos($methodId, 'packeta_method_')) {
                return true;
            }

            if (false !== strpos($methodId, 'packeta') || false !== strpos($methodId, 'packetery')) {
                return true;
            }
        }

        return false;
    }
}
