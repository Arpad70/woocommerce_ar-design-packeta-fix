<?php

namespace ArDesign\PacketaFix;

use WC_Order;

defined('ABSPATH') || exit;

class Automation
{
    public static function init(): void
    {
        add_action('ard_shipping_shipment_delivered', [__CLASS__, 'handleDeliveredShipment'], 5, 3);
    }

    public static function handleDeliveredShipment($order_id, $shipmentData = [], $order = null): void
    {
        $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);

        if (!$order instanceof WC_Order || (string) ($shipmentData['carrier'] ?? '') !== PacketaBridge::CARRIER) {
            return;
        }

        if ($order->get_meta(Shipment::DELIVERY_WORKFLOW_PROCESSED_AT_META_KEY, true)) {
            return;
        }

        $settings = Settings::getDefaultSettings();
        $shouldComplete = ($settings[Settings::AUTO_COMPLETE_ORDER_OPTION_KEY] ?? 'yes') === 'yes';

        if ($shouldComplete && !$order->has_status(['completed', 'cancelled', 'refunded', 'failed'])) {
            $order->update_status('completed', __('Packeta delivery status confirmed successful delivery.', 'ar-design-packeta-fix'));
        } else {
            $order->add_order_note(__('Packeta delivery status confirmed successful delivery.', 'ar-design-packeta-fix'));
        }

        if (self::shouldSendInvoiceAfterDelivery($order)) {
            $invoiceFile = self::ensureInvoiceFile($order);

            if ($invoiceFile) {
                $order->update_meta_data(Shipment::INVOICE_FILE_META_KEY, $invoiceFile);
                $order->add_order_note(__('Invoice was prepared for cash on delivery follow-up email.', 'ar-design-packeta-fix'));
            }
        }

        $order->update_meta_data(Shipment::DELIVERY_WORKFLOW_PROCESSED_AT_META_KEY, current_time('mysql'));
        $order->save_meta_data();
    }

    public static function shouldSendInvoiceAfterDelivery(WC_Order $order): bool
    {
        $codPaymentMethods = (array) apply_filters('ard_packeta_fix_cod_payment_method_ids', ['cod'], $order);

        return in_array($order->get_payment_method(), $codPaymentMethods, true);
    }

    public static function ensureInvoiceFile(WC_Order $order): ?string
    {
        if (!function_exists('wcpdf_get_document') || !function_exists('wcpdf_get_document_file')) {
            return null;
        }

        $document = wcpdf_get_document('invoice', $order, true);
        if (!$document) {
            return null;
        }

        $file = wcpdf_get_document_file($document, 'pdf');

        return $file && file_exists($file) ? $file : null;
    }
}
