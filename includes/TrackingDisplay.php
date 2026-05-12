<?php

namespace ArDesign\PacketaFix;

use WC_Order;

defined('ABSPATH') || exit;

class TrackingDisplay
{
    public static function init(): void
    {
        add_action('woocommerce_order_details_after_order_table', [__CLASS__, 'displayTrackingInfo'], 20, 1);
        add_action('woocommerce_admin_order_data_after_billing_address', [__CLASS__, 'displayAdminTrackingInfo'], 20, 1);
    }

    public static function displayTrackingInfo(WC_Order $order): void
    {
        echo self::getTrackingSummaryHtml($order, 'customer');
    }

    public static function displayAdminTrackingInfo(WC_Order $order): void
    {
        echo self::getTrackingSummaryHtml($order, 'admin');
    }

    public static function getTrackingSummaryHtml(WC_Order $order, string $type = 'customer'): string
    {
        $view = self::buildShipmentSummaryViewModel($order);
        if ($view === []) {
            return '';
        }

        ob_start();
        self::renderTrackingSummary($view, $type);

        return (string) ob_get_clean();
    }

    private static function buildShipmentSummaryViewModel(WC_Order $order): array
    {
        $shipment = Shipment::getShipmentData($order);
        $carrier = sanitize_key((string) ($shipment['carrier'] ?? ''));

        if ($carrier !== PacketaBridge::CARRIER) {
            return [];
        }

        $trackingNumbers = isset($shipment['tracking_numbers']) && is_array($shipment['tracking_numbers'])
            ? array_values(array_filter(array_map('strval', $shipment['tracking_numbers'])))
            : [];

        $primaryTrackingNumber = trim((string) ($shipment['tracking_number'] ?? ''));
        if ($primaryTrackingNumber !== '' && !in_array($primaryTrackingNumber, $trackingNumbers, true)) {
            array_unshift($trackingNumbers, $primaryTrackingNumber);
        }

        $trackingLinks = [];
        foreach ($trackingNumbers as $trackingNumber) {
            $trackingNumber = trim((string) $trackingNumber);
            if ($trackingNumber === '') {
                continue;
            }

            $trackingLinks[] = [
                'number' => $trackingNumber,
                'url' => self::buildTrackingUrl($trackingNumber, $shipment),
            ];
        }

        $payloadStatusMeta = self::extractPayloadStatusMeta(is_array($shipment['payload'] ?? null) ? $shipment['payload'] : []);
        $status = trim((string) ($order->get_meta(Tracking::CURRENT_STATUS_META_KEY, true) ?: ($shipment['status'] ?? '')));
        $statusLabel = trim((string) ($order->get_meta(Tracking::CURRENT_STATUS_LABEL_META_KEY, true) ?: ($shipment['status_label'] ?? $status)));
        $statusDescription = trim((string) ($order->get_meta(Tracking::CURRENT_STATUS_DESCRIPTION_META_KEY, true) ?: ($payloadStatusMeta['description'] ?? '')));
        $statusDate = trim((string) ($order->get_meta(Tracking::CURRENT_STATUS_DATE_META_KEY, true) ?: ($payloadStatusMeta['date'] ?? '')));
        $statusLocation = trim((string) ($order->get_meta(Tracking::CURRENT_STATUS_LOCATION_META_KEY, true) ?: ($payloadStatusMeta['location'] ?? '')));

        if ($trackingLinks === [] && trim((string) ($shipment['label_url'] ?? '')) === '' && $statusLabel === '' && $status === '') {
            return [];
        }

        return [
            'carrier_label' => 'PACKETA',
            'label_url' => esc_url_raw((string) ($shipment['label_url'] ?? '')),
            'tracking_links' => $trackingLinks,
            'status' => $status,
            'status_label' => $statusLabel,
            'status_description' => $statusDescription,
            'status_date' => $statusDate,
            'status_location' => $statusLocation,
            'last_sync_at' => trim((string) $order->get_meta(Tracking::LAST_SYNC_AT_META_KEY, true)),
            'last_error' => trim((string) $order->get_meta(Tracking::LAST_SYNC_ERROR_META_KEY, true)),
        ];
    }

    private static function buildTrackingUrl(string $trackingNumber, array $shipment): string
    {
        $primaryTrackingNumber = trim((string) ($shipment['tracking_number'] ?? ''));
        $storedTrackingUrl = esc_url_raw((string) ($shipment['tracking_url'] ?? ''));

        if ($storedTrackingUrl !== '' && ($primaryTrackingNumber === '' || $primaryTrackingNumber === $trackingNumber)) {
            return $storedTrackingUrl;
        }

        return esc_url_raw(PacketaBridge::buildTrackingUrl($trackingNumber));
    }

    private static function extractPayloadStatusMeta(array $payload): array
    {
        $meta = [
            'description' => '',
            'date' => '',
            'location' => '',
        ];

        if (isset($payload['events']) && is_array($payload['events'])) {
            $currentEvent = [];

            foreach ($payload['events'] as $event) {
                if (!is_array($event)) {
                    continue;
                }

                $currentEvent = $event;
                if (!empty($event['current'])) {
                    break;
                }
            }

            if ($currentEvent !== []) {
                $meta['description'] = sanitize_text_field((string) ($currentEvent['description'] ?? ''));
                $meta['date'] = sanitize_text_field((string) ($currentEvent['date'] ?? ''));
                $meta['location'] = sanitize_text_field((string) ($currentEvent['location'] ?? ''));

                return $meta;
            }
        }

        $meta['description'] = sanitize_text_field((string) ($payload['StatusInfo'] ?? ''));
        $meta['date'] = sanitize_text_field((string) ($payload['status_date'] ?? ''));
        $meta['location'] = sanitize_text_field((string) ($payload['DepotCity'] ?? ''));

        return $meta;
    }

    private static function renderTrackingSummary(array $view, string $type): void
    {
        $containerStyle = $type === 'admin'
            ? 'style="width: 100%; display: block; margin-top: 12px; padding-top: 8px; border-top: 1px solid #dcdcde;"'
            : 'style="margin-top: 16px;"';

        ?>
        <div class="ar-design-packeta-fix-shipment-summary" <?php echo $containerStyle; ?>>
            <p>
                <strong><?php echo esc_html(sprintf(__('%s Shipment', 'ar-design-packeta-fix'), $view['carrier_label'])); ?></strong><br>

                <?php if ($view['label_url'] !== '') : ?>
                    <a class="button" href="<?php echo esc_url($view['label_url']); ?>" target="_blank" rel="noopener noreferrer" style="margin: 6px 0 8px;">
                        <?php echo esc_html(sprintf(__('Download %s label', 'ar-design-packeta-fix'), $view['carrier_label'])); ?>
                    </a>
                    <br>
                <?php endif; ?>

                <?php if ($view['tracking_links'] !== []) : ?>
                    <strong><?php echo esc_html__('Tracking', 'ar-design-packeta-fix'); ?></strong>:<br>
                    <?php foreach ($view['tracking_links'] as $trackingLink) : ?>
                        <?php if ($trackingLink['url'] !== '') : ?>
                            <a href="<?php echo esc_url($trackingLink['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($trackingLink['number']); ?></a><br>
                        <?php else : ?>
                            <?php echo esc_html($trackingLink['number']); ?><br>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($view['status_label'] !== '' || $view['status'] !== '') : ?>
                    <strong><?php echo esc_html__('Current status', 'ar-design-packeta-fix'); ?></strong>:
                    <?php echo esc_html($view['status_label'] !== '' ? $view['status_label'] : $view['status']); ?><br>
                <?php endif; ?>

                <?php if ($view['status_description'] !== '') : ?>
                    <span><?php echo esc_html($view['status_description']); ?></span><br>
                <?php endif; ?>

                <?php if ($view['status_date'] !== '') : ?>
                    <strong><?php echo esc_html__('Status date', 'ar-design-packeta-fix'); ?></strong>: <?php echo esc_html($view['status_date']); ?><br>
                <?php endif; ?>

                <?php if ($view['status_location'] !== '') : ?>
                    <strong><?php echo esc_html__('Location', 'ar-design-packeta-fix'); ?></strong>: <?php echo esc_html($view['status_location']); ?><br>
                <?php endif; ?>

                <?php if ($view['last_sync_at'] !== '') : ?>
                    <strong><?php echo esc_html__('Last sync', 'ar-design-packeta-fix'); ?></strong>: <?php echo esc_html($view['last_sync_at']); ?><br>
                <?php endif; ?>

                <?php if ($view['last_error'] !== '') : ?>
                    <strong><?php echo esc_html__('Last tracking error', 'ar-design-packeta-fix'); ?></strong>: <?php echo esc_html($view['last_error']); ?><br>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }
}
