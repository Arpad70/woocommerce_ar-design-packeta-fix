<?php

declare(strict_types=1);

namespace ArDesign\PacketaFix;

if (! defined('ABSPATH')) {
    exit;
}

final class ArDesignPacketaFixRollbackManager
{
    private const BACKUP_DIR = 'ard-packeta-fix-backups';

    private string $pluginBasename;
    private string $pluginRoot;
    private bool $backupCreated = false;

    public function __construct(string $pluginBasename, string $pluginRoot)
    {
        $this->pluginBasename = $pluginBasename;
        $this->pluginRoot = untrailingslashit($pluginRoot);
    }

    public function register(): void
    {
        add_filter('upgrader_pre_install', array($this, 'createBackupBeforeInstall'), 10, 2);
        add_filter('upgrader_install_package_result', array($this, 'rollbackOnInstallFailure'), 10, 2);
    }

    /**
     * @param mixed $response
     * @param mixed $hookExtra
     * @return mixed
     */
    public function createBackupBeforeInstall($response, $hookExtra)
    {
        if (! $this->isCurrentPluginUpdate($hookExtra)) {
            return $response;
        }

        if (! $this->prepareFilesystem()) {
            return $response;
        }

        $backupTarget = $this->getBackupPath();
        $this->removeDirectory($backupTarget);

        if (! wp_mkdir_p(dirname($backupTarget))) {
            return $response;
        }

        if (! $this->copyDirectory($this->pluginRoot, $backupTarget)) {
            return $response;
        }

        $this->backupCreated = true;

        return $response;
    }

    /**
     * @param mixed $result
     * @param mixed $hookExtra
     * @return mixed
     */
    public function rollbackOnInstallFailure($result, $hookExtra)
    {
        if (! $this->isCurrentPluginUpdate($hookExtra) || ! $this->backupCreated) {
            return $result;
        }

        if (! is_wp_error($result)) {
            return $result;
        }

        if (! $this->prepareFilesystem()) {
            return $result;
        }

        $backupTarget = $this->getBackupPath();
        if (! is_dir($backupTarget)) {
            return $result;
        }

        $this->removeDirectory($this->pluginRoot);
        if (! $this->copyDirectory($backupTarget, $this->pluginRoot)) {
            return $result;
        }

        return new \WP_Error(
            'ard_packeta_fix_rollback_performed',
            __('Aktualizácia AR Design Packeta Fix zlyhala. Predchádzajúca verzia bola automaticky obnovená zo zálohy.', 'ar-design-packeta-fix')
        );
    }

    /**
     * @param mixed $hookExtra
     */
    private function isCurrentPluginUpdate($hookExtra): bool
    {
        if (! is_array($hookExtra)) {
            return false;
        }

        if ('plugin' !== ($hookExtra['type'] ?? '')) {
            return false;
        }

        if ('update' !== ($hookExtra['action'] ?? '')) {
            return false;
        }

        $plugins = isset($hookExtra['plugins']) && is_array($hookExtra['plugins']) ? $hookExtra['plugins'] : array();

        return in_array($this->pluginBasename, $plugins, true);
    }

    private function prepareFilesystem(): bool
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        if (! WP_Filesystem()) {
            return false;
        }

        return true;
    }

    private function getBackupPath(): string
    {
        $uploads = wp_upload_dir();
        $base = isset($uploads['basedir']) ? (string) $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';

        return untrailingslashit($base) . '/' . self::BACKUP_DIR . '/latest';
    }

    private function copyDirectory(string $source, string $destination): bool
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $result = copy_dir($source, $destination);

        return ! is_wp_error($result);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($target)) {
                $this->removeDirectory($target);
                continue;
            }

            @unlink($target);
        }

        @rmdir($path);
    }
}
