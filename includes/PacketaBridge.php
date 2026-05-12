<?php

namespace ArDesign\PacketaFix;

use WC_Order;

defined('ABSPATH') || exit;

class PacketaBridge
{
    public const CARRIER = 'packeta';
    public const CRON_HOOK = 'ard_shipping_packeta_tracking_sync_event';
    private const PACKETA_ORDER_TABLE_SUFFIX = 'packetery_order';
    private const PACKETA_ASYNC_SYNC_HOOK = 'packetery_packet_status_sync_hook';
    private const PACKETA_CRON_SYNC_HOOK = 'packetery_cron_packet_status_sync_hook';
    private const PACKETA_CRON_SYNC_HOOK_WEEKEND = 'packetery_cron_packet_status_sync_hook_weekend';
    private const PACKETA_PUBLIC_TRACKING_URL = 'https://tracking.packeta.com/sk/tracking/search?id=%s';

    public static function init(): void
    {
        add_action('init', [__CLASS__, 'maybeScheduleTrackingCron']);
        add_action(self::CRON_HOOK, [__CLASS__, 'syncOpenShipments']);
        add_action(self::PACKETA_ASYNC_SYNC_HOOK, [__CLASS__, 'syncShipmentByOrderId'], 20, 1);
        add_action(self::PACKETA_CRON_SYNC_HOOK, [__CLASS__, 'syncOpenShipments'], 20);
        add_action(self::PACKETA_CRON_SYNC_HOOK_WEEKEND, [__CLASS__, 'syncOpenShipments'], 20);
        add_action('woocommerce_admin_order_data_after_billing_address', [__CLASS__, 'primeAdminOrderShipment'], 5, 1);
    }

    public static function maybeScheduleTrackingCron(): void
    {
        if (!Settings::isTrackingEnabled() || !self::isAvailable()) {
            while ($timestamp = wp_next_scheduled(self::CRON_HOOK)) {
                wp_unschedule_event($timestamp, self::CRON_HOOK);
            }

            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + (15 * MINUTE_IN_SECONDS), 'hourly', self::CRON_HOOK);
        }
    }

    public static function primeAdminOrderShipment($order): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }

        self::syncOrderShipment($order);
    }

    public static function syncShipmentByOrderId($orderId): void
    {
        $order = wc_get_order(absint($orderId));

        if (!$order instanceof WC_Order) {
            return;
        }

        self::syncOrderShipment($order);
    }

    public static function syncOpenShipments(): void
    {
        if (!Settings::isTrackingEnabled() || !self::isAvailable()) {
            return;
        }

        foreach (self::getTrackedOrderIds() as $orderId) {
            $order = wc_get_order($orderId);

            if (!$order instanceof WC_Order || $order->has_status(['cancelled', 'refunded', 'failed'])) {
                continue;
            }

            self::syncOrderShipment($order);
        }
    }

    public static function buildTrackingUrl(string $trackingNumber): string
    {
        $trackingNumber = trim($trackingNumber);
        if ($trackingNumber === '') {
            return '';
        }

        return sprintf(self::PACKETA_PUBLIC_TRACKING_URL, rawurlencode(self::buildPacketBarcode($trackingNumber)));
    }

    public static function syncOrderShipment(WC_Order $order): bool
    {
        $row = self::getPacketaOrderRow((int) $order->get_id());
        if (!$row instanceof \stdClass) {
            return false;
        }

        $packetId = sanitize_text_field((string) ($row->packet_id ?? ''));
        if ($packetId === '') {
            return false;
        }

        $existingShipment = Shipment::getShipmentData($order);
        $hadPacketaShipment = self::hasExistingPacketaShipment($existingShipment);
        $shipmentData = self::buildShipmentDataFromRow($order, $row, $existingShipment);
        $trackingData = self::buildTrackingDataFromShipment($shipmentData);

        if (!self::hasShipmentChanged($order, $existingShipment, $shipmentData, $trackingData)) {
            return false;
        }

        Shipment::storeShipmentData($order, $shipmentData);
        self::storeTrackingSnapshot($order, $trackingData);
        $order->save_meta_data();

        if (!$hadPacketaShipment) {
            do_action('ard_shipping_shipment_created', $order->get_id(), $shipmentData, $order);
        }

        do_action('ard_shipping_shipment_updated', $order->get_id(), $shipmentData, $order);

        if (Tracking::isDeliveredStatus(
            (string) ($trackingData['current_status'] ?? ''),
            (string) ($trackingData['current_label'] ?? ''),
            (string) ($trackingData['current_description'] ?? '')
        )) {
            self::handleDeliveredShipment($order, $shipmentData, $trackingData);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $existingShipment
     * @return array<string, mixed>
     */
    private static function buildShipmentDataFromRow(WC_Order $order, \stdClass $row, array $existingShipment): array
    {
        $packetId = sanitize_text_field((string) ($row->packet_id ?? ''));
        $trackingNumber = self::buildPacketBarcode($packetId);
        $statusMeta = self::buildStatusMeta($row);
        $createdAt = (string) ($existingShipment['created_at'] ?? '');

        if ($createdAt === '') {
            $createdAt = current_time('mysql');
        }

        $payload = self::buildShipmentPayload($row, $trackingNumber, $statusMeta);

        return array_merge($existingShipment, [
            'carrier' => self::CARRIER,
            'reference' => $packetId,
            'tracking_number' => $trackingNumber,
            'tracking_numbers' => $trackingNumber !== '' ? [$trackingNumber] : [],
            'tracking_url' => self::buildTrackingUrl($trackingNumber),
            'label_url' => (string) ($existingShipment['label_url'] ?? ''),
            'status' => $statusMeta['status'],
            'status_label' => $statusMeta['label'],
            'created_at' => $createdAt,
            'updated_at' => current_time('mysql'),
            'payload' => $payload,
        ]);
    }

    /**
     * @param array<string, mixed> $shipmentData
     * @return array<string, mixed>
     */
    private static function buildTrackingDataFromShipment(array $shipmentData): array
    {
        $payload = isset($shipmentData['payload']) && is_array($shipmentData['payload'])
            ? $shipmentData['payload']
            : [];

        $events = isset($payload['events']) && is_array($payload['events'])
            ? $payload['events']
            : [];

        return [
            'current_status' => (string) ($shipmentData['status'] ?? ''),
            'current_label' => (string) ($shipmentData['status_label'] ?? ''),
            'current_description' => sanitize_text_field((string) ($payload['StatusInfo'] ?? '')),
            'current_date' => sanitize_text_field((string) ($payload['status_date'] ?? current_time('mysql'))),
            'current_location' => sanitize_text_field((string) ($payload['DepotCity'] ?? '')),
            'events' => $events,
        ];
    }

    /**
     * @param array<string, mixed> $existingShipment
     * @param array<string, mixed> $shipmentData
     * @param array<string, mixed> $trackingData
     */
    private static function hasShipmentChanged(WC_Order $order, array $existingShipment, array $shipmentData, array $trackingData): bool
    {
        $existingFingerprint = wp_json_encode([
            $existingShipment['carrier'] ?? '',
            $existingShipment['reference'] ?? '',
            $existingShipment['tracking_number'] ?? '',
            $existingShipment['status'] ?? '',
            $existingShipment['status_label'] ?? '',
            $existingShipment['tracking_url'] ?? '',
            $existingShipment['payload'] ?? [],
            (string) $order->get_meta(Tracking::CURRENT_STATUS_META_KEY, true),
            (string) $order->get_meta(Tracking::CURRENT_STATUS_LABEL_META_KEY, true),
            (string) $order->get_meta(Tracking::CURRENT_STATUS_DESCRIPTION_META_KEY, true),
            (string) $order->get_meta(Tracking::CURRENT_STATUS_LOCATION_META_KEY, true),
        ]);

        $newFingerprint = wp_json_encode([
            $shipmentData['carrier'] ?? '',
            $shipmentData['reference'] ?? '',
            $shipmentData['tracking_number'] ?? '',
            $shipmentData['status'] ?? '',
            $shipmentData['status_label'] ?? '',
            $shipmentData['tracking_url'] ?? '',
            $shipmentData['payload'] ?? [],
            $trackingData['current_status'] ?? '',
            $trackingData['current_label'] ?? '',
            $trackingData['current_description'] ?? '',
            $trackingData['current_location'] ?? '',
        ]);

        return $existingFingerprint !== $newFingerprint;
    }

    /**
     * @param array<string, mixed> $trackingData
     */
    private static function storeTrackingSnapshot(WC_Order $order, array $trackingData): void
    {
        $previousStatus = (string) $order->get_meta(Tracking::CURRENT_STATUS_META_KEY, true);
        $currentStatus = (string) ($trackingData['current_status'] ?? '');
        $currentLabel = (string) ($trackingData['current_label'] ?? $currentStatus);
        $currentDescription = (string) ($trackingData['current_description'] ?? '');
        $currentDate = (string) ($trackingData['current_date'] ?? current_time('mysql'));
        $currentLocation = (string) ($trackingData['current_location'] ?? '');
        $events = isset($trackingData['events']) && is_array($trackingData['events']) ? $trackingData['events'] : [];

        $order->update_meta_data(Tracking::CURRENT_STATUS_META_KEY, $currentStatus);
        $order->update_meta_data(Tracking::CURRENT_STATUS_LABEL_META_KEY, $currentLabel);
        $order->update_meta_data(Tracking::CURRENT_STATUS_DESCRIPTION_META_KEY, $currentDescription);
        $order->update_meta_data(Tracking::CURRENT_STATUS_DATE_META_KEY, $currentDate);
        $order->update_meta_data(Tracking::CURRENT_STATUS_LOCATION_META_KEY, $currentLocation);
        $order->update_meta_data(Tracking::LAST_SYNC_AT_META_KEY, current_time('mysql'));
        $order->delete_meta_data(Tracking::LAST_SYNC_ERROR_META_KEY);
        $order->update_meta_data(Tracking::STATUS_HISTORY_META_KEY, Tracking::mergeTrackingHistory($order, $events));

        if ($previousStatus !== '' && $currentStatus !== '' && $currentStatus !== $previousStatus) {
            $order->add_order_note(sprintf(
                __('Packeta tracking update: %1$s%2$s', 'ar-design-packeta-fix'),
                $currentLabel ?: $currentStatus,
                $currentLocation ? ' (' . $currentLocation . ')' : ''
            ));
        }
    }

    /**
     * @param array<string, mixed> $shipmentData
     * @param array<string, mixed> $trackingData
     */
    private static function handleDeliveredShipment(WC_Order $order, array $shipmentData, array $trackingData): void
    {
        if ($order->get_meta(Tracking::DELIVERY_CONFIRMED_AT_META_KEY, true)) {
            return;
        }

        $order->update_meta_data(Tracking::DELIVERY_CONFIRMED_AT_META_KEY, current_time('mysql'));

        $deliveredShipment = Shipment::markDelivered($order, $shipmentData);

        do_action('ard_packeta_fix_shipment_delivered_notification', $order->get_id(), $trackingData, $deliveredShipment, $order);
    }

    /**
     * @param array<string, string> $statusMeta
     * @return array<string, mixed>
     */
    private static function buildShipmentPayload(\stdClass $row, string $trackingNumber, array $statusMeta): array
    {
        $location = self::buildLocation($row);
        $statusDate = self::normalizeStatusDate((string) ($row->stored_until ?? ''));

        return [
            'source' => 'packeta',
            'packet_id' => sanitize_text_field((string) ($row->packet_id ?? '')),
            'barcode' => $trackingNumber,
            'carrier_id' => sanitize_text_field((string) ($row->carrier_id ?? '')),
            'carrier_number' => sanitize_text_field((string) ($row->carrier_number ?? '')),
            'packet_status' => sanitize_text_field((string) ($row->packet_status ?? '')),
            'stored_until' => sanitize_text_field((string) ($row->stored_until ?? '')),
            'status_date' => $statusDate,
            'point_id' => sanitize_text_field((string) ($row->point_id ?? '')),
            'point_name' => sanitize_text_field((string) ($row->point_name ?? '')),
            'point_city' => sanitize_text_field((string) ($row->point_city ?? '')),
            'point_zip' => sanitize_text_field((string) ($row->point_zip ?? '')),
            'point_street' => sanitize_text_field((string) ($row->point_street ?? '')),
            'StatusInfo' => $statusMeta['description'],
            'DepotCity' => $location,
            'events' => [
                [
                    'status' => $statusMeta['status'],
                    'label' => $statusMeta['label'],
                    'description' => $statusMeta['description'],
                    'date' => $statusDate,
                    'location' => $location,
                    'current' => true,
                    'reached' => true,
                    'source' => 'packeta',
                ],
            ],
        ];
    }

    /**
     * @return array{status: string, label: string, description: string}
     */
    private static function buildStatusMeta(\stdClass $row): array
    {
        $rawStatus = sanitize_text_field((string) ($row->packet_status ?? ''));
        $normalized = Tracking::normalizeStatusText($rawStatus);
        $storedUntil = sanitize_text_field((string) ($row->stored_until ?? ''));

        $map = [
            'received data' => [
                'status' => 'created',
                'label' => __('Shipment exported to Packeta', 'ar-design-packeta-fix'),
                'description' => __('The shipment data was handed over to Packeta and is awaiting pickup.', 'ar-design-packeta-fix'),
            ],
            'arrived' => [
                'status' => 'accepted_at_depot',
                'label' => __('Accepted at depot', 'ar-design-packeta-fix'),
                'description' => __('Packeta accepted the parcel at the depot.', 'ar-design-packeta-fix'),
            ],
            'prepared for departure' => [
                'status' => 'in_transit',
                'label' => __('On the way', 'ar-design-packeta-fix'),
                'description' => __('The parcel is on the way to the destination.', 'ar-design-packeta-fix'),
            ],
            'departed' => [
                'status' => 'departed_depot',
                'label' => __('Departed from depot', 'ar-design-packeta-fix'),
                'description' => __('The parcel departed from the depot.', 'ar-design-packeta-fix'),
            ],
            'ready for pickup' => [
                'status' => 'ready_for_pickup',
                'label' => __('Ready for pick-up', 'ar-design-packeta-fix'),
                'description' => $storedUntil !== ''
                    ? sprintf(__('Ready for pick-up until %s.', 'ar-design-packeta-fix'), $storedUntil)
                    : __('The parcel is ready for pick-up.', 'ar-design-packeta-fix'),
            ],
            'handed to carrier' => [
                'status' => 'handed_to_carrier',
                'label' => __('Handed over to carrier company', 'ar-design-packeta-fix'),
                'description' => __('The parcel was handed over to the carrier company.', 'ar-design-packeta-fix'),
            ],
            'delivered' => [
                'status' => 'delivered',
                'label' => __('Shipment delivered', 'ar-design-packeta-fix'),
                'description' => __('Packeta confirmed successful delivery.', 'ar-design-packeta-fix'),
            ],
            'posted back' => [
                'status' => 'return_in_transit',
                'label' => __('Return in transit', 'ar-design-packeta-fix'),
                'description' => __('The parcel is on the way back to the sender.', 'ar-design-packeta-fix'),
            ],
            'returned' => [
                'status' => 'returned',
                'label' => __('Shipment returned', 'ar-design-packeta-fix'),
                'description' => __('The parcel was returned to the sender.', 'ar-design-packeta-fix'),
            ],
            'cancelled' => [
                'status' => 'cancelled',
                'label' => __('Shipment cancelled', 'ar-design-packeta-fix'),
                'description' => __('The parcel was cancelled in Packeta.', 'ar-design-packeta-fix'),
            ],
            'collected' => [
                'status' => 'collected',
                'label' => __('Parcel has been collected', 'ar-design-packeta-fix'),
                'description' => __('The parcel has been collected.', 'ar-design-packeta-fix'),
            ],
            'customs' => [
                'status' => 'customs',
                'label' => __('Customs declaration process', 'ar-design-packeta-fix'),
                'description' => __('The parcel is in customs declaration process.', 'ar-design-packeta-fix'),
            ],
            'delivery attempt' => [
                'status' => 'delivery_attempt',
                'label' => __('Unsuccessful delivery attempt', 'ar-design-packeta-fix'),
                'description' => __('There was an unsuccessful delivery attempt.', 'ar-design-packeta-fix'),
            ],
            'rejected by recipient' => [
                'status' => 'rejected',
                'label' => __('Rejected by recipient', 'ar-design-packeta-fix'),
                'description' => __('The parcel was rejected by the recipient.', 'ar-design-packeta-fix'),
            ],
        ];

        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        if ($rawStatus === '') {
            return [
                'status' => 'created',
                'label' => __('Shipment exported to Packeta', 'ar-design-packeta-fix'),
                'description' => __('The shipment data was handed over to Packeta.', 'ar-design-packeta-fix'),
            ];
        }

        return [
            'status' => sanitize_title($rawStatus),
            'label' => $rawStatus,
            'description' => $rawStatus,
        ];
    }

    private static function buildLocation(\stdClass $row): string
    {
        $parts = array_filter([
            sanitize_text_field((string) ($row->point_name ?? '')),
            sanitize_text_field((string) ($row->point_city ?? '')),
        ]);

        return implode(', ', $parts);
    }

    private static function normalizeStatusDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return current_time('mysql');
        }

        $timestamp = strtotime($value);

        return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : sanitize_text_field($value);
    }

    /**
     * @param array<string, mixed> $shipment
     */
    private static function hasExistingPacketaShipment(array $shipment): bool
    {
        return (string) ($shipment['carrier'] ?? '') === self::CARRIER
            && ((string) ($shipment['tracking_number'] ?? '') !== '' || (string) ($shipment['reference'] ?? '') !== '');
    }

    /**
     * @return int[]
     */
    private static function getTrackedOrderIds(): array
    {
        global $wpdb;

        $table = self::getTableName();
        if (!self::tableExists($table)) {
            return [];
        }

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE packet_id IS NOT NULL AND packet_id != %s",
                ''
            )
        );

        return array_values(array_unique(array_map('absint', is_array($rows) ? $rows : [])));
    }

    private static function getPacketaOrderRow(int $orderId): ?\stdClass
    {
        global $wpdb;

        $table = self::getTableName();
        if (!self::tableExists($table)) {
            return null;
        }

        $query = $wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $orderId);
        $row = $wpdb->get_row($query);

        return $row instanceof \stdClass ? $row : null;
    }

    private static function isAvailable(): bool
    {
        return self::tableExists(self::getTableName());
    }

    private static function tableExists(string $table): bool
    {
        global $wpdb;

        static $cache = [];

        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $cache[$table] = is_string($exists) && $exists === $table;

        return $cache[$table];
    }

    private static function getTableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::PACKETA_ORDER_TABLE_SUFFIX;
    }

    private static function buildPacketBarcode(string $packetId): string
    {
        $packetId = strtoupper(trim($packetId));
        if ($packetId === '') {
            return '';
        }

        return strpos($packetId, 'Z') === 0 ? $packetId : 'Z' . preg_replace('/^Z+/i', '', $packetId);
    }
}
