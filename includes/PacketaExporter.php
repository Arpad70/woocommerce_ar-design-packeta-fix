<?php

namespace ArDesign\PacketaFix;

use Packetery\Core\Api\Soap\Client as PacketaSoapClient;
use Packetery\Core\Api\Soap\Request\PacketsCourierLabelsPdf as PacketaCarrierLabelsPdfRequest;
use Packetery\Core\Api\Soap\Request\PacketsLabelsPdf as PacketaLabelsPdfRequest;
use Packetery\Core\Entity\Order as PacketaOrderEntity;
use Packetery\Core\Entity\PickupPoint as PacketaPickupPoint;
use Packetery\Core\Entity\Size as PacketaSize;
use Packetery\Module\CompatibilityBridge as PacketaCompatibilityBridge;
use Packetery\Module\Labels\CarrierLabelService as PacketaCarrierLabelService;
use Packetery\Module\Labels\LabelPrintParametersService as PacketaLabelPrintParametersService;
use Packetery\Module\Options\OptionsProvider as PacketaOptionsProvider;
use Packetery\Module\Order\Attribute as PacketaOrderAttribute;
use Packetery\Module\Order\OrderValidatorFactory as PacketaOrderValidatorFactory;
use Packetery\Module\Order\PacketSubmitter as PacketaPacketSubmitter;
use Packetery\Module\Order\Repository as PacketaOrderRepository;
use Packetery\Module\Shipping\BaseShippingMethod as PacketaBaseShippingMethod;
use Packetery\Module\Shipping\ShippingProvider as PacketaShippingProvider;
use WC_Order;

defined('ABSPATH') || exit;

class PacketaExporter
{
    private const LABEL_UPLOAD_SUBDIR = 'ar-design-packeta-fix/packeta-labels';

    /**
     * @var array<int, true>
     */
    private static array $processedOrders = [];

    public static function init(): void
    {
        add_action('woocommerce_order_status_zabalena', [__CLASS__, 'exportOrderById'], 30, 1);
        add_action('woocommerce_order_status_changed', [__CLASS__, 'maybeExportOnStatusChange'], 30, 4);
    }

    public static function maybeExportOnStatusChange(int $orderId, string $from, string $to, $order = null): void
    {
        if ($to !== 'zabalena' && $to !== 'wc-zabalena') {
            return;
        }

        if ($from === $to) {
            return;
        }

        self::exportOrderById($orderId, $order);
    }

    public static function exportOrderById(int $orderId, $order = null): void
    {
        $orderId = absint($orderId);
        if ($orderId <= 0 || isset(self::$processedOrders[$orderId])) {
            return;
        }

        $wcOrder = $order instanceof WC_Order ? $order : wc_get_order($orderId);
        if (!$wcOrder instanceof WC_Order) {
            return;
        }

        self::$processedOrders[$orderId] = true;
        self::maybeExportOrder($wcOrder);
    }

    public static function maybeExportOrder(WC_Order $order): bool
    {
        if (!self::isRuntimeAvailable() || !self::isPacketaOrder($order)) {
            return false;
        }

        if ($order->has_status(['cancelled', 'refunded', 'failed'])) {
            return false;
        }

        $repository = self::getService(PacketaOrderRepository::class);
        $submitter = self::getService(PacketaPacketSubmitter::class);

        if (!$repository instanceof PacketaOrderRepository || !$submitter instanceof PacketaPacketSubmitter) {
            self::logFailure($order, __('Packeta services are not available for automatic export.', 'ar-design-packeta-fix'));

            return false;
        }

        self::ensureRepositoryRow($order, $repository);

        $packetaOrder = $repository->getByIdWithValidCarrier((int) $order->get_id());
        if (!$packetaOrder instanceof PacketaOrderEntity) {
            self::logFailure($order, __('Packeta order row is missing or invalid, so automatic export could not start.', 'ar-design-packeta-fix'));

            return false;
        }

        $optionsProvider = self::getService(PacketaOptionsProvider::class);
        self::hydrateOrderForExport($order, $packetaOrder, $optionsProvider instanceof PacketaOptionsProvider ? $optionsProvider : null);
        $repository->save($packetaOrder);

        $validationMessage = self::getPreflightValidationMessage($order, $packetaOrder);
        if ($validationMessage !== '') {
            self::logFailure($order, $validationMessage);

            return false;
        }

        if (!$packetaOrder->isExported() || !$packetaOrder->getPacketId()) {
            $submissionResult = $submitter->submitPacket($order, $packetaOrder, true);
            $counter = $submissionResult->getCounter();

            $packetaOrder = $repository->getByIdWithValidCarrier((int) $order->get_id());
            if (!$packetaOrder instanceof PacketaOrderEntity || empty($counter['success']) || !$packetaOrder->isExported() || !$packetaOrder->getPacketId()) {
                $validationMessage = $packetaOrder instanceof PacketaOrderEntity
                    ? self::getPreflightValidationMessage($order, $packetaOrder)
                    : '';
                $errorMessage = $packetaOrder instanceof PacketaOrderEntity
                    ? (string) $packetaOrder->getLastApiErrorMessage()
                    : '';

                if ($validationMessage !== '') {
                    $errorMessage = $validationMessage;
                }

                if ($errorMessage === '') {
                    $errorMessage = __('Packeta shipment submission failed.', 'ar-design-packeta-fix');
                }

                self::logFailure($order, $errorMessage);

                return false;
            }
        }

        $labelUrl = self::ensureLabelUrl($order, $packetaOrder, $repository);
        self::syncShipmentData($order, $labelUrl);

        return true;
    }

    private static function isRuntimeAvailable(): bool
    {
        return class_exists(PacketaCompatibilityBridge::class)
            && class_exists(PacketaOrderRepository::class)
            && class_exists(PacketaPacketSubmitter::class);
    }

    public static function isPacketaOrder(WC_Order $order): bool
    {
        if (class_exists(PacketaShippingProvider::class) && method_exists(PacketaShippingProvider::class, 'wcOrderHasOurMethod')) {
            return PacketaShippingProvider::wcOrderHasOurMethod($order);
        }

        foreach ($order->get_shipping_methods() as $shippingMethod) {
            if (!is_object($shippingMethod) || !method_exists($shippingMethod, 'get_method_id')) {
                continue;
            }

            $methodId = (string) $shippingMethod->get_method_id();
            if ($methodId === 'packetery_shipping_method' || strpos($methodId, 'packeta_method_') === 0) {
                return true;
            }
        }

        return false;
    }

    public static function getOrderIssueMessage(WC_Order $order): string
    {
        if (!self::isRuntimeAvailable() || !self::isPacketaOrder($order)) {
            return '';
        }

        $repository = self::getService(PacketaOrderRepository::class);
        if (!$repository instanceof PacketaOrderRepository) {
            return __('Packeta repository service is not available.', 'ar-design-packeta-fix');
        }

        self::ensureRepositoryRow($order, $repository);

        $packetaOrder = $repository->getByIdWithValidCarrier((int) $order->get_id());
        if (!$packetaOrder instanceof PacketaOrderEntity) {
            return __('Packeta order row is missing or invalid.', 'ar-design-packeta-fix');
        }

        $optionsProvider = self::getService(PacketaOptionsProvider::class);
        self::hydrateOrderForExport($order, $packetaOrder, $optionsProvider instanceof PacketaOptionsProvider ? $optionsProvider : null);

        return self::getPreflightValidationMessage($order, $packetaOrder);
    }

    private static function ensureRepositoryRow(WC_Order $order, PacketaOrderRepository $repository): void
    {
        if (is_object($repository->getDataById((int) $order->get_id()))) {
            return;
        }

        $carrierId = self::resolveCarrierId($order);
        if ($carrierId === '') {
            return;
        }

        $pickupMeta = self::getPickupPointMeta($order);

        $repository->saveData([
            'id' => (int) $order->get_id(),
            'carrier_id' => $carrierId,
            'is_exported' => 0,
            'packet_id' => null,
            'packet_claim_id' => null,
            'packet_claim_password' => null,
            'is_label_printed' => 0,
            'point_id' => $pickupMeta['id'] ?: null,
            'point_place' => $pickupMeta['place'] ?: null,
            'point_name' => $pickupMeta['name'] ?: null,
            'point_url' => $pickupMeta['url'] ?: null,
            'point_street' => $pickupMeta['street'] ?: null,
            'point_zip' => $pickupMeta['zip'] ?: null,
            'point_city' => $pickupMeta['city'] ?: null,
            'address_validated' => 0,
            'delivery_address' => null,
            'weight' => null,
            'length' => null,
            'width' => null,
            'height' => null,
            'adult_content' => null,
            'value' => null,
            'cod' => null,
            'api_error_message' => null,
            'api_error_date' => null,
            'carrier_number' => null,
            'packet_status' => null,
            'stored_until' => null,
            'deliver_on' => null,
            'car_delivery_id' => null,
        ]);
    }

    private static function hydrateOrderForExport(WC_Order $wcOrder, PacketaOrderEntity $packetaOrder, ?PacketaOptionsProvider $optionsProvider): void
    {
        if ($packetaOrder->isPickupPointDelivery() && !$packetaOrder->hasPickupPointOrCarrierId()) {
            $pickupMeta = self::getPickupPointMeta($wcOrder);
            if ($pickupMeta['id'] !== '') {
                $packetaOrder->setPickupPoint(new PacketaPickupPoint(
                    $pickupMeta['id'],
                    $pickupMeta['place'] ?: null,
                    $pickupMeta['name'] ?: null,
                    $pickupMeta['city'] ?: null,
                    $pickupMeta['zip'] ?: null,
                    $pickupMeta['street'] ?: null,
                    $pickupMeta['url'] ?: null
                ));
            }
        }

        if ((float) $packetaOrder->getFinalWeight() <= 0) {
            $fallbackWeight = self::resolveWeightFallback($wcOrder, $optionsProvider);
            if ($fallbackWeight > 0) {
                $packetaOrder->setWeight($fallbackWeight);
            }
        }

        if ($packetaOrder->getCarrier()->requiresSize()) {
            $size = $packetaOrder->getSize();
            $hasSize = $size instanceof PacketaSize
                && (float) $size->getLength() > 0
                && (float) $size->getWidth() > 0
                && (float) $size->getHeight() > 0;

            if (!$hasSize && $optionsProvider instanceof PacketaOptionsProvider && $optionsProvider->isDefaultDimensionsEnabled()) {
                $packetaOrder->setSize(new PacketaSize(
                    $optionsProvider->getSanitizedDimensionValueInMm($optionsProvider->getDefaultLength()),
                    $optionsProvider->getSanitizedDimensionValueInMm($optionsProvider->getDefaultWidth()),
                    $optionsProvider->getSanitizedDimensionValueInMm($optionsProvider->getDefaultHeight())
                ));
            }
        }
    }

    private static function resolveCarrierId(WC_Order $order): string
    {
        foreach ($order->get_shipping_methods() as $shippingMethod) {
            if (!is_object($shippingMethod) || !method_exists($shippingMethod, 'get_method_id')) {
                continue;
            }

            $methodId = (string) $shippingMethod->get_method_id();
            if (class_exists(PacketaBaseShippingMethod::class) && strpos($methodId, PacketaBaseShippingMethod::PACKETA_METHOD_PREFIX) === 0) {
                return sanitize_text_field(substr($methodId, strlen(PacketaBaseShippingMethod::PACKETA_METHOD_PREFIX)));
            }
        }

        return sanitize_text_field((string) $order->get_meta(PacketaOrderAttribute::CARRIER_ID, true));
    }

    /**
     * @return array{id: string, place: string, name: string, city: string, zip: string, street: string, url: string}
     */
    private static function getPickupPointMeta(WC_Order $order): array
    {
        return [
            'id' => sanitize_text_field((string) $order->get_meta(PacketaOrderAttribute::POINT_ID, true)),
            'place' => sanitize_text_field((string) $order->get_meta(PacketaOrderAttribute::POINT_PLACE, true)),
            'name' => sanitize_text_field((string) $order->get_meta(PacketaOrderAttribute::POINT_NAME, true)),
            'city' => sanitize_text_field((string) $order->get_meta(PacketaOrderAttribute::POINT_CITY, true)),
            'zip' => sanitize_text_field((string) $order->get_meta(PacketaOrderAttribute::POINT_ZIP, true)),
            'street' => sanitize_text_field((string) $order->get_meta(PacketaOrderAttribute::POINT_STREET, true)),
            'url' => esc_url_raw((string) $order->get_meta(PacketaOrderAttribute::POINT_URL, true)),
        ];
    }

    private static function resolveWeightFallback(WC_Order $order, ?PacketaOptionsProvider $optionsProvider): float
    {
        if ($optionsProvider instanceof PacketaOptionsProvider && $optionsProvider->isDefaultWeightEnabled()) {
            $configuredWeight = $optionsProvider->getDefaultWeight() + $optionsProvider->getPackagingWeight();
            if ($configuredWeight > 0) {
                return $configuredWeight;
            }
        }

        return 0.0;
    }

    private static function ensureLabelUrl(WC_Order $order, PacketaOrderEntity $packetaOrder, PacketaOrderRepository $repository): string
    {
        $existingLabelUrl = trim((string) Shipment::getShipmentData($order)['label_url']);
        if ($existingLabelUrl !== '') {
            return $existingLabelUrl;
        }

        $pdfContents = self::downloadLabelPdf($order, $packetaOrder, $repository);
        if ($pdfContents === '') {
            return '';
        }

        $labelUrl = self::storeLabelPdf($order, $packetaOrder, $pdfContents);
        if ($labelUrl === '') {
            return '';
        }

        if (!$packetaOrder->isLabelPrinted()) {
            $packetaOrder->setIsLabelPrinted(true);
            $repository->save($packetaOrder);
        }

        $order->add_order_note(sprintf(
            /* translators: %s: Packeta packet barcode. */
            __('Packeta label for packet %s was generated and saved for download.', 'ar-design-packeta-fix'),
            self::buildPacketBarcode((string) $packetaOrder->getPacketId())
        ));
        $order->save();

        return $labelUrl;
    }

    private static function downloadLabelPdf(WC_Order $order, PacketaOrderEntity $packetaOrder, PacketaOrderRepository $repository): string
    {
        $packetId = trim((string) $packetaOrder->getPacketId());
        if ($packetId === '') {
            return '';
        }

        $soapClient = self::getService(PacketaSoapClient::class);
        if (!$soapClient instanceof PacketaSoapClient) {
            return '';
        }

        $labelParametersService = self::getService(PacketaLabelPrintParametersService::class);
        $optionsProvider = self::getService(PacketaOptionsProvider::class);

        if ($packetaOrder->isExternalCarrier()) {
            $carrierPdf = self::downloadCarrierLabelPdf($order, $packetaOrder, $repository, $soapClient, $labelParametersService, $optionsProvider);
            if ($carrierPdf !== '') {
                return $carrierPdf;
            }
        }

        $packetaFormat = $labelParametersService instanceof PacketaLabelPrintParametersService
            ? $labelParametersService->getLabelFormatByOrder($packetaOrder)
            : ($optionsProvider instanceof PacketaOptionsProvider ? $optionsProvider->get_packeta_label_format() : PacketaOptionsProvider::DEFAULT_VALUE_PACKETA_LABEL_FORMAT);

        $response = $soapClient->packetsLabelsPdf(new PacketaLabelsPdfRequest([$packetId], $packetaFormat, 0));
        if ($response->hasFault()) {
            ard_packeta_fix_log('Packeta label download failed', [
                'order_id' => $order->get_id(),
                'packet_id' => $packetId,
                'error' => $response->getFaultString(),
            ]);

            return '';
        }

        return (string) $response->getPdfContents();
    }

    private static function downloadCarrierLabelPdf(
        WC_Order $order,
        PacketaOrderEntity $packetaOrder,
        PacketaOrderRepository $repository,
        PacketaSoapClient $soapClient,
        ?PacketaLabelPrintParametersService $labelParametersService,
        ?PacketaOptionsProvider $optionsProvider
    ): string {
        $carrierLabelService = self::getService(PacketaCarrierLabelService::class);
        $packetId = trim((string) $packetaOrder->getPacketId());

        if (!$carrierLabelService instanceof PacketaCarrierLabelService || $packetId === '') {
            return '';
        }

        $pairs = $carrierLabelService->getPacketIdsWithCourierNumbers([(int) $order->get_id() => $packetId]);
        if ($pairs === []) {
            return '';
        }

        $carrierFormat = $labelParametersService instanceof PacketaLabelPrintParametersService
            ? $labelParametersService->getLabelFormatByOrder($packetaOrder)
            : ($optionsProvider instanceof PacketaOptionsProvider ? $optionsProvider->get_carrier_label_format() : PacketaOptionsProvider::DEFAULT_VALUE_CARRIER_LABEL_FORMAT);

        $response = $soapClient->packetsCarrierLabelsPdf(new PacketaCarrierLabelsPdfRequest(array_values($pairs), $carrierFormat, 0));
        if ($response->hasFault()) {
            ard_packeta_fix_log('Packeta carrier label download failed', [
                'order_id' => $order->get_id(),
                'packet_id' => $packetId,
                'error' => $response->getFaultString(),
            ]);

            return '';
        }

        $refreshedOrder = $repository->getByIdWithValidCarrier((int) $order->get_id());
        if ($refreshedOrder instanceof PacketaOrderEntity) {
            $packetaOrder->setCarrierNumber($refreshedOrder->getCarrierNumber());
        }

        return (string) $response->getPdfContents();
    }

    private static function storeLabelPdf(WC_Order $order, PacketaOrderEntity $packetaOrder, string $pdfContents): string
    {
        if ($pdfContents === '') {
            return '';
        }

        $uploadDir = wp_upload_dir();
        if (!empty($uploadDir['error']) || empty($uploadDir['basedir']) || empty($uploadDir['baseurl'])) {
            ard_packeta_fix_log('Packeta label upload directory error', $uploadDir);

            return '';
        }

        $baseDir = trailingslashit((string) $uploadDir['basedir']) . self::LABEL_UPLOAD_SUBDIR;
        if (!wp_mkdir_p($baseDir) || !is_dir($baseDir) || !is_writable($baseDir)) {
            ard_packeta_fix_log('Packeta label directory is not writable', $baseDir);

            return '';
        }

        $barcode = self::buildPacketBarcode((string) $packetaOrder->getPacketId());
        $filename = sanitize_file_name(sprintf('packeta-label-order-%d-%s.pdf', (int) $order->get_id(), strtolower($barcode)));
        $filePath = trailingslashit($baseDir) . $filename;

        $bytesWritten = @file_put_contents($filePath, $pdfContents);
        if ($bytesWritten === false || $bytesWritten <= 0) {
            ard_packeta_fix_log('Packeta label file could not be saved', [
                'order_id' => $order->get_id(),
                'file' => $filePath,
            ]);

            return '';
        }

        return trailingslashit((string) $uploadDir['baseurl']) . self::LABEL_UPLOAD_SUBDIR . '/' . rawurlencode($filename);
    }

    private static function syncShipmentData(WC_Order $order, string $labelUrl = ''): void
    {
        if ($labelUrl !== '') {
            $shipmentData = Shipment::getShipmentData($order);
            $shipmentData['carrier'] = PacketaBridge::CARRIER;
            $shipmentData['label_url'] = $labelUrl;
            $shipmentData['updated_at'] = current_time('mysql');
            Shipment::storeShipmentData($order, $shipmentData);
            $order->save_meta_data();
        }

        PacketaBridge::syncOrderShipment($order);
    }

    private static function getPreflightValidationMessage(WC_Order $order, PacketaOrderEntity $packetaOrder): string
    {
        $validatorFactory = self::getService(PacketaOrderValidatorFactory::class);
        if (!$validatorFactory instanceof PacketaOrderValidatorFactory) {
            return '';
        }

        $errors = $validatorFactory->create()->validate($packetaOrder);
        if ($errors === []) {
            return '';
        }

        $messages = array_values(array_filter(array_map('strval', $errors)));

        if (isset($errors['validation_error_pickup_point_or_carrier_id'])) {
            $shippingLabel = self::getPacketaPickupLabel($order);
            $messages[0] = $shippingLabel !== ''
                ? sprintf(
                    /* translators: %s: human-readable pickup point label stored in the order. */
                    __('Pickup point or carrier id is not set. The order only contains the pickup-point label "%s" without the technical Packeta point ID.', 'ar-design-packeta-fix'),
                    $shippingLabel
                )
                : __('Pickup point or carrier id is not set. The order is missing the technical Packeta pickup point ID required for export.', 'ar-design-packeta-fix');
        }

        return implode(' ', array_unique($messages));
    }

    private static function getPacketaPickupLabel(WC_Order $order): string
    {
        $parts = array_filter([
            trim((string) $order->get_shipping_address_1()),
            trim((string) $order->get_shipping_address_2()),
            trim((string) $order->get_shipping_city()),
        ]);

        if ($parts !== []) {
            return sanitize_text_field(implode(', ', $parts));
        }

        foreach ($order->get_shipping_methods() as $shippingMethod) {
            if (!is_object($shippingMethod) || !method_exists($shippingMethod, 'get_name')) {
                continue;
            }

            $label = trim((string) $shippingMethod->get_name());
            if ($label !== '') {
                return sanitize_text_field($label);
            }
        }

        return '';
    }

    private static function logFailure(WC_Order $order, string $message): void
    {
        $message = sanitize_text_field($message);
        if ($message === '') {
            return;
        }

        ard_packeta_fix_log('Packeta automatic export failed', [
            'order_id' => $order->get_id(),
            'message' => $message,
        ]);

        $order->add_order_note(sprintf(
            /* translators: %s: Packeta export error message. */
            __('Packeta automatic export failed: %s', 'ar-design-packeta-fix'),
            $message
        ));
        $order->save();
    }

    private static function getService(string $className): ?object
    {
        if (!self::isRuntimeAvailable()) {
            return null;
        }

        try {
            $container = PacketaCompatibilityBridge::getContainer();
            if (!is_object($container) || !method_exists($container, 'getByType')) {
                return null;
            }

            $service = $container->getByType($className);

            return is_object($service) ? $service : null;
        } catch (\Throwable $exception) {
            ard_packeta_fix_log('Packeta service lookup failed', [
                'service' => $className,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
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
