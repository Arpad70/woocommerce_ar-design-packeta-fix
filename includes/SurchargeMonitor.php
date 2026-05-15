<?php

namespace ArDesign\PacketaFix;

defined('ABSPATH') || exit;

class SurchargeMonitor
{
    private const CRON_HOOK = 'ard_packeta_surcharge_sync_event';
    private const CRON_SCHEDULE = 'ard_weekly';
    private const MANUAL_SYNC_ACTION = 'ard_packeta_surcharge_sync_now';
    private const MANUAL_SYNC_NONCE = 'ard_packeta_surcharge_sync_now_nonce';
    private const STATE_OPTION_KEY = 'ar_design_packeta_surcharge_monitor_state';
    private const SOURCE_URL_PRICE_LIST = 'https://www.packeta.sk/cenniky-a-priplatky';
    private const SOURCE_URL_FUEL_ARTICLE = 'https://www.packeta.sk/blog/palivovy-priplatok-prisposobujeme-vyvoju-cien-na-trhu-s-palivami';

    public static function init(): void
    {
        add_filter('cron_schedules', [__CLASS__, 'registerCronSchedule']);
        add_action(self::CRON_HOOK, [__CLASS__, 'runSync']);
        add_action('admin_post_' . self::MANUAL_SYNC_ACTION, [__CLASS__, 'handleManualSyncRequest']);
        add_action('init', [__CLASS__, 'ensureCronScheduled']);
        add_action('admin_notices', [__CLASS__, 'renderAdminNotice']);
    }

    public static function handleManualSyncRequest(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nedostatečné oprávnění.', 'ar-design-packeta-fix'));
        }

        check_admin_referer(self::MANUAL_SYNC_ACTION, self::MANUAL_SYNC_NONCE);

        self::runSync();

        $redirect = wp_get_referer();
        if (!$redirect) {
            $redirect = admin_url('admin.php?page=wc-settings&tab=shipping&section=' . Settings::SETTINGS_ID_KEY);
        }

        wp_safe_redirect(add_query_arg('ard_packeta_manual_sync', '1', $redirect));
        exit;
    }

    public static function getManualSyncUrl(): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=' . self::MANUAL_SYNC_ACTION),
            self::MANUAL_SYNC_ACTION,
            self::MANUAL_SYNC_NONCE
        );
    }

    public static function getAdminStatusHtml(): string
    {
        $state = self::getState();
        $checkedAt = !empty($state['last_checked_at']) ? wp_date('d.m.Y H:i', (int) $state['last_checked_at']) : null;
        $fuelPercent = isset($state['values']['fuel_percent']) && $state['values']['fuel_percent'] !== null ? self::formatPercent((float) $state['values']['fuel_percent']) . ' %' : __('není dostupné', 'ar-design-packeta-fix');
        $tollPerKg = isset($state['values']['toll_fixed_per_kg']) && $state['values']['toll_fixed_per_kg'] !== null ? self::formatMoney((float) $state['values']['toll_fixed_per_kg']) . ' EUR/kg' : __('není dostupné', 'ar-design-packeta-fix');

        $html = '<p><strong>' . esc_html__('Stav z CRONu', 'ar-design-packeta-fix') . ':</strong> ';
        $html .= $checkedAt ? esc_html(sprintf(__('naposledy %s', 'ar-design-packeta-fix'), $checkedAt)) : esc_html__('zatím neproběhl', 'ar-design-packeta-fix');
        $html .= '</p>';
        $html .= '<p>' . esc_html(sprintf(__('Palivový príplatok: %1$s. Mýtny príplatok: %2$s.', 'ar-design-packeta-fix'), $fuelPercent, $tollPerKg)) . '</p>';
        $html .= '<p><a class="button button-primary" href="' . esc_url(self::getManualSyncUrl()) . '">' . esc_html__('Načíst ceny ručně', 'ar-design-packeta-fix') . '</a></p>';

        return $html;
    }

    public static function registerCronSchedule(array $schedules): array
    {
        if (!isset($schedules[self::CRON_SCHEDULE])) {
            $schedules[self::CRON_SCHEDULE] = [
                'interval' => WEEK_IN_SECONDS,
                'display' => __('Once weekly', 'ar-design-packeta-fix'),
            ];
        }

        return $schedules;
    }

    public static function ensureCronScheduled(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        $nextRun = self::getNextMondayTenTimestamp();

        if (!$timestamp) {
            wp_schedule_event($nextRun, self::CRON_SCHEDULE, self::CRON_HOOK);
            return;
        }

        if (abs($timestamp - $nextRun) > DAY_IN_SECONDS) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            wp_schedule_event($nextRun, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    public static function runSync(): void
    {
        $state = self::getState();
        $result = self::fetchCurrentSurcharges();

        $state['last_checked_at'] = time();
        $state['last_error'] = $result['error'] ?? '';

        $newValues = [];
        if (isset($result['values']['fuel_percent']) && $result['values']['fuel_percent'] !== null) {
            $newValues['fuel_percent'] = (float) $result['values']['fuel_percent'];
        }
        if (isset($result['values']['toll_fixed_per_kg']) && $result['values']['toll_fixed_per_kg'] !== null) {
            $newValues['toll_fixed_per_kg'] = (float) $result['values']['toll_fixed_per_kg'];
        }

        if (!empty($newValues)) {
            $newHash = md5(wp_json_encode($newValues));
            $oldHash = (string) ($state['last_hash'] ?? '');

            if ($oldHash !== '' && $oldHash !== $newHash) {
                $state['pending_notice'] = self::buildChangeNotice((array) ($state['values'] ?? []), $newValues);
            }

            $state['values'] = $newValues;
            $state['last_hash'] = $newHash;
            $state['source_urls'] = [self::SOURCE_URL_PRICE_LIST, self::SOURCE_URL_FUEL_ARTICLE];
        }

        update_option(self::STATE_OPTION_KEY, $state, false);
    }

    public static function renderAdminNotice(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $state = self::getState();
        $notice = trim((string) ($state['pending_notice'] ?? ''));

        if ($notice === '') {
            return;
        }

        echo '<div class="notice notice-warning"><p>' . esc_html($notice) . '</p></div>';

        $state['pending_notice'] = '';
        update_option(self::STATE_OPTION_KEY, $state, false);
    }

    public static function getHelperTexts(): array
    {
        $state = self::getState();
        $values = (array) ($state['values'] ?? []);
        $checkedAt = !empty($state['last_checked_at']) ? wp_date('d.m.Y H:i', (int) $state['last_checked_at']) : null;

        $prefix = $checkedAt
            ? sprintf(__('Poslední kontrola CRONem (%s): ', 'ar-design-packeta-fix'), $checkedAt)
            : __('Poslední kontrola CRONem: ', 'ar-design-packeta-fix');

        $fuel = isset($values['fuel_percent']) && $values['fuel_percent'] !== null
            ? $prefix . sprintf(__('palivový příplatek je aktuálně %s %% podle ceníku Packeta (zdroj: %s).', 'ar-design-packeta-fix'), self::formatPercent((float) $values['fuel_percent']), self::SOURCE_URL_FUEL_ARTICLE)
            : __('Aktuální palivový příplatek se zatím nepodařilo z ceníku zjistit. Zkontrolujte jej prosím ručně.', 'ar-design-packeta-fix');

        $tollPerKg = isset($values['toll_fixed_per_kg']) && $values['toll_fixed_per_kg'] !== null
            ? sprintf(__('mýtný příplatek je aktuálně %s EUR za každý začatý kilogram podle ceníku Packeta', 'ar-design-packeta-fix'), self::formatMoney((float) $values['toll_fixed_per_kg']))
            : __('Aktuální mýtný příplatek se zatím nepodařilo z ceníku zjistit.', 'ar-design-packeta-fix');

        $toll = $prefix . sprintf(__('%s (zdroj: %s).', 'ar-design-packeta-fix'), $tollPerKg, self::SOURCE_URL_PRICE_LIST);

        $fixed = __('Pevná korekce je ruční. CRON sleduje palivový príplatok v % a mýtny príplatok v EUR/kg podľa ceníku Packeta.', 'ar-design-packeta-fix');

        if (!empty($state['last_error'])) {
            $suffix = sprintf(__(' Posledná chyba synchronizácie: %s', 'ar-design-packeta-fix'), (string) $state['last_error']);
            $fuel .= $suffix;
            $toll .= $suffix;
            $fixed .= $suffix;
        }

        return [
            Settings::FUEL_SURCHARGE_PERCENT_OPTION_KEY => $fuel,
            Settings::TOLL_SURCHARGE_PERCENT_OPTION_KEY => __('Packeta mýto je v aktuálnom cenníku vedené ako EUR/kg, nie ako percento.', 'ar-design-packeta-fix'),
            Settings::TOLL_SURCHARGE_FIXED_OPTION_KEY => $fixed,
        ];
    }

    private static function fetchCurrentSurcharges(): array
    {
        $priceResult = self::fetchUrl(self::SOURCE_URL_PRICE_LIST);
        $fuelResult = self::fetchUrl(self::SOURCE_URL_FUEL_ARTICLE);

        $errors = [];
        if (!empty($priceResult['error'])) {
            $errors[] = $priceResult['error'];
        }
        if (!empty($fuelResult['error'])) {
            $errors[] = $fuelResult['error'];
        }

        $priceSource = (string) ($priceResult['body'] ?? '');
        $fuelSource = (string) ($fuelResult['body'] ?? '');
        $priceNuxtPayload = self::extractNuxtDataText($priceSource);
        $fuelNuxtPayload = self::extractNuxtDataText($fuelSource);

        $values = [
            'fuel_percent' => self::extractExplicitPercentByLabel($fuelSource . ' ' . $fuelNuxtPayload, 'palivov'),
            'toll_fixed_per_kg' => self::extractExplicitTollPerKg($priceSource . ' ' . $priceNuxtPayload),
        ];

        if ($values['fuel_percent'] === null) {
            $errors[] = __('Packeta palivový príplatok sa nepodarilo vyparsovať z dostupných dát.', 'ar-design-packeta-fix');
        }
        if ($values['toll_fixed_per_kg'] === null) {
            $errors[] = __('Packeta mýtny príplatok sa nepodarilo vyparsovať z dostupných dát.', 'ar-design-packeta-fix');
        }

        return [
            'values' => $values,
            'error' => $errors ? implode(' | ', $errors) : '',
        ];
    }

    private static function fetchUrl(string $url): array
    {
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'redirection' => 5,
            'user-agent' => 'AR-Design-Surcharge-Monitor/1.0',
        ]);

        if (is_wp_error($response)) {
            return ['body' => '', 'error' => $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return ['body' => '', 'error' => sprintf('HTTP %d for %s', $status, $url)];
        }

        return [
            'body' => (string) wp_remote_retrieve_body($response),
            'error' => '',
        ];
    }

    private static function extractNuxtDataText(string $html): string
    {
        if ($html === '') {
            return '';
        }

        if (!preg_match('/<script[^>]*id="__NUXT_DATA__"[^>]*>(.*?)<\/script>/is', $html, $matches)) {
            return '';
        }

        $json = html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $json = preg_replace('/\\u([0-9a-fA-F]{4})/', '&#x$1;', $json) ?? $json;
        $json = html_entity_decode($json, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $json;
    }

    private static function extractExplicitPercentByLabel(string $text, string $labelStem): ?float
    {
        if ($text === '') {
            return null;
        }

        $normalized = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?: $normalized;

        $pattern = '/(?:' . preg_quote($labelStem, '/') . '[^%]{0,600}?pr[ií]platok[^%]{0,600}?)([0-9]{1,2}(?:[\.,][0-9]{1,2})?)\s*%/iu';
        if (!preg_match_all($pattern, $normalized, $matches) || empty($matches[1])) {
            return null;
        }

        $best = null;
        foreach ((array) $matches[1] as $raw) {
            $value = self::toFloat((string) $raw);
            if ($value <= 0) {
                continue;
            }
            if ($best === null || $value > $best) {
                $best = $value;
            }
        }

        if ($best !== null) {
            return $best;
        }

        return null;
    }

    private static function extractExplicitTollPerKg(string $text): ?float
    {
        if ($text === '') {
            return null;
        }

        $normalized = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?: $normalized;

        if (preg_match_all('/m[ýy]tn[^\n\r]{0,260}?([0-9]{1,2}(?:[\.,][0-9]{1,3})?)\s*(?:€|eur)[^\n\r]{0,180}(?:za\s*každ[ýy].{0,40}kilogram|kg|kilogram)/iu', $normalized, $matches) && !empty($matches[1])) {
            $value = end($matches[1]);
            return self::toFloat((string) $value);
        }

        return null;
    }

    private static function getState(): array
    {
        $state = get_option(self::STATE_OPTION_KEY, []);

        return is_array($state) ? $state : [];
    }

    private static function buildChangeNotice(array $oldValues, array $newValues): string
    {
        $oldFuel = isset($oldValues['fuel_percent']) && $oldValues['fuel_percent'] !== null ? self::formatPercent((float) $oldValues['fuel_percent']) . ' %' : 'n/a';
        $newFuel = isset($newValues['fuel_percent']) && $newValues['fuel_percent'] !== null ? self::formatPercent((float) $newValues['fuel_percent']) . ' %' : 'n/a';
        $oldToll = isset($oldValues['toll_fixed_per_kg']) && $oldValues['toll_fixed_per_kg'] !== null ? self::formatMoney((float) $oldValues['toll_fixed_per_kg']) . ' EUR/kg' : 'n/a';
        $newToll = isset($newValues['toll_fixed_per_kg']) && $newValues['toll_fixed_per_kg'] !== null ? self::formatMoney((float) $newValues['toll_fixed_per_kg']) . ' EUR/kg' : 'n/a';

        return sprintf(
            __('Packeta príplatky sa zmenili: palivový %1$s → %2$s, mýtny %3$s → %4$s. Skontrolujte nastavenia dopravy.', 'ar-design-packeta-fix'),
            $oldFuel,
            $newFuel,
            $oldToll,
            $newToll
        );
    }

    private static function getNextMondayTenTimestamp(): int
    {
        $tz = wp_timezone();
        $now = new \DateTimeImmutable('now', $tz);
        $target = $now->setTime(10, 0, 0);
        $weekday = (int) $now->format('N');
        $daysUntilMonday = (8 - $weekday) % 7;
        $next = $target->modify('+' . $daysUntilMonday . ' days');

        if ($daysUntilMonday === 0 && $now >= $target) {
            $next = $next->modify('+7 days');
        }

        return $next->getTimestamp();
    }

    private static function toFloat($value): float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function formatPercent(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private static function formatMoney(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
