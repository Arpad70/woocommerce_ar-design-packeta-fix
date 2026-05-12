<?php

declare(strict_types=1);

namespace ArDesign\PacketaFix;

if (! defined('ABSPATH')) {
    exit;
}

final class ArDesignPacketaFixUpdater
{
    private const CACHE_TTL = 900;

    private string $repositoryFullName;
    private string $pluginBasename;
    private string $currentVersion;

    public function __construct(string $repositoryFullName, string $pluginBasename, string $currentVersion)
    {
        $this->repositoryFullName = $repositoryFullName;
        $this->pluginBasename = $pluginBasename;
        $this->currentVersion = $currentVersion;
    }

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', array($this, 'injectUpdateData'));
        add_filter('plugins_api', array($this, 'injectPluginInfo'), 20, 3);
        add_action('upgrader_process_complete', array($this, 'clearCacheAfterUpgrade'), 10, 2);
    }

    /**
     * @param object $transient
     * @return object
     */
    public function injectUpdateData($transient)
    {
        if (! is_object($transient) || ! isset($transient->checked) || ! is_array($transient->checked)) {
            return $transient;
        }

        $release = $this->getLatestRelease();
        if (empty($release)) {
            return $transient;
        }

        $latestVersion = (string) ($release['version'] ?? '');
        $packageUrl = (string) ($release['package_url'] ?? '');
        $detailsUrl = (string) ($release['details_url'] ?? '');

        if ('' === $latestVersion || '' === $packageUrl || version_compare($latestVersion, $this->currentVersion, '<=')) {
            return $transient;
        }

        $transient->response[$this->pluginBasename] = (object) array(
            'slug' => 'ar-design-packeta-fix',
            'plugin' => $this->pluginBasename,
            'new_version' => $latestVersion,
            'url' => $detailsUrl,
            'package' => $packageUrl,
        );

        return $transient;
    }

    /**
     * @param mixed $result
     * @param mixed $action
     * @param mixed $args
     * @return mixed
     */
    public function injectPluginInfo($result, $action, $args)
    {
        if ('plugin_information' !== $action || ! is_object($args) || ! isset($args->slug) || 'ar-design-packeta-fix' !== $args->slug) {
            return $result;
        }

        $release = $this->getLatestRelease();
        $version = ! empty($release['version']) ? (string) $release['version'] : $this->currentVersion;
        $details = ! empty($release['details_url']) ? (string) $release['details_url'] : 'https://github.com/' . $this->repositoryFullName;
        $body = ! empty($release['body']) ? (string) $release['body'] : '';

        return (object) array(
            'name' => 'AR Design Packeta Fix for WooCommerce',
            'slug' => 'ar-design-packeta-fix',
            'version' => $version,
            'author' => '<a href="https://github.com/' . esc_attr($this->repositoryFullName) . '">AR Design</a>',
            'homepage' => $details,
            'download_link' => (string) ($release['package_url'] ?? ''),
            'sections' => array(
                'description' => __('Samostatný Packeta fix modul pre WooCommerce spravovaný AR Design.', 'ar-design-packeta-fix'),
                'changelog' => '' !== $body ? wp_kses_post(nl2br(esc_html($body))) : __('Changelog nie je dostupný.', 'ar-design-packeta-fix'),
            ),
        );
    }

    /**
     * @return array<string, string>
     */
    private function getLatestRelease(): array
    {
        $cached = get_transient($this->getCacheKey());
        if (is_array($cached) && isset($cached['version'])) {
            return $cached;
        }

        $requestUrl = sprintf('https://api.github.com/repos/%s/releases/latest', $this->repositoryFullName);
        $response = wp_remote_get(
            $requestUrl,
            array(
                'timeout' => 15,
                'headers' => array(
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'ar-design-packeta-fix/' . $this->currentVersion,
                ),
            )
        );

        if (is_wp_error($response)) {
            return array();
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        if (200 !== $statusCode) {
            return array();
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode((string) $body, true);
        if (! is_array($data)) {
            return array();
        }

        $tagName = isset($data['tag_name']) ? (string) $data['tag_name'] : '';
        $version = ltrim($tagName, 'v');
        $package = $this->extractZipAssetUrl($data);
        $details = isset($data['html_url']) ? (string) $data['html_url'] : '';
        $changelog = isset($data['body']) ? (string) $data['body'] : '';

        if ('' === $version || '' === $package) {
            return array();
        }

        $release = array(
            'version' => $version,
            'package_url' => $package,
            'details_url' => $details,
            'body' => $changelog,
        );

        set_transient($this->getCacheKey(), $release, self::CACHE_TTL);

        return $release;
    }

    /**
     * @param mixed $upgrader
     * @param mixed $options
     */
    public function clearCacheAfterUpgrade($upgrader, $options): void
    {
        if (! is_array($options) || ! isset($options['type'], $options['action'])) {
            return;
        }

        if ('plugin' !== $options['type'] || 'update' !== $options['action']) {
            return;
        }

        $plugins = isset($options['plugins']) && is_array($options['plugins']) ? $options['plugins'] : array();
        if (in_array($this->pluginBasename, $plugins, true)) {
            delete_transient($this->getCacheKey());
        }
    }

    /**
     * @param array<string, mixed> $releaseData
     */
    private function extractZipAssetUrl(array $releaseData): string
    {
        $assets = isset($releaseData['assets']) && is_array($releaseData['assets']) ? $releaseData['assets'] : array();

        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            $name = isset($asset['name']) ? (string) $asset['name'] : '';
            $url = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';

            if ('' === $url || '.zip' !== substr(strtolower($name), -4)) {
                continue;
            }

            if ('ar-design-packeta-fix.zip' === strtolower($name)) {
                return $url;
            }
        }

        return '';
    }

    private function getCacheKey(): string
    {
        return 'ar_design_packeta_fix_release_data_' . md5($this->repositoryFullName);
    }
}
