<?php

namespace ArDesign\PacketaFix;

defined('ABSPATH') || exit;

class Tracking
{
    public const CURRENT_STATUS_META_KEY = 'dpd_shipment_tracking_status';
    public const CURRENT_STATUS_LABEL_META_KEY = 'dpd_shipment_tracking_label';
    public const CURRENT_STATUS_DESCRIPTION_META_KEY = 'dpd_shipment_tracking_description';
    public const CURRENT_STATUS_DATE_META_KEY = 'dpd_shipment_tracking_date';
    public const CURRENT_STATUS_LOCATION_META_KEY = 'dpd_shipment_tracking_location';
    public const STATUS_HISTORY_META_KEY = 'dpd_shipment_tracking_history';
    public const LAST_SYNC_AT_META_KEY = 'dpd_shipment_tracking_last_sync_at';
    public const LAST_SYNC_ERROR_META_KEY = 'dpd_shipment_tracking_last_error';
    public const DELIVERY_CONFIRMED_AT_META_KEY = 'dpd_shipment_delivered_at';

    public static function normalizeStatusText(string $status): string
    {
        $status = strtolower(trim(wp_strip_all_tags($status)));
        $status = preg_replace('/\s+/', ' ', $status);

        return is_string($status) ? $status : '';
    }

    public static function isDeliveredStatus(string $status, string $label = '', string $description = ''): bool
    {
        $haystack = self::normalizeStatusText($status . ' ' . $label . ' ' . $description);

        foreach (['delivered', 'doručen', 'dorucen', 'prevzat', 'vyzdvihnut', 'collected'] as $needle) {
            if (strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    public static function isTerminalStatus(string $status): bool
    {
        $status = self::normalizeStatusText($status);

        return in_array($status, ['delivered', 'returned', 'cancelled', 'rejected'], true);
    }

    public static function mergeTrackingHistory(\WC_Order $order, array $events): array
    {
        $history = (array) $order->get_meta(self::STATUS_HISTORY_META_KEY, true);
        $historyIndex = [];

        foreach (array_merge($history, $events) as $event) {
            if (!is_array($event)) {
                continue;
            }

            $normalizedEvent = [
                'status' => sanitize_text_field((string) ($event['status'] ?? '')),
                'label' => sanitize_text_field((string) ($event['label'] ?? '')),
                'description' => sanitize_text_field((string) ($event['description'] ?? '')),
                'date' => sanitize_text_field((string) ($event['date'] ?? current_time('mysql'))),
                'location' => sanitize_text_field((string) ($event['location'] ?? '')),
                'current' => !empty($event['current']),
                'reached' => !empty($event['reached']),
                'source' => sanitize_text_field((string) ($event['source'] ?? 'packeta')),
            ];

            $historyIndex[self::getHistoryHash($normalizedEvent)] = $normalizedEvent;
        }

        $merged = array_values($historyIndex);
        usort($merged, static function ($left, $right) {
            return strcmp((string) ($left['date'] ?? ''), (string) ($right['date'] ?? ''));
        });

        return $merged;
    }

    public static function getHistoryHash(array $event): string
    {
        return md5(wp_json_encode([
            (string) ($event['status'] ?? ''),
            (string) ($event['label'] ?? ''),
            (string) ($event['description'] ?? ''),
            (string) ($event['date'] ?? ''),
            (string) ($event['location'] ?? ''),
        ]));
    }
}
