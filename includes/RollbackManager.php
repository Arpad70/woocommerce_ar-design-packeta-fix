<?php

declare(strict_types=1);

namespace ArDesign\PacketaFix;

use ArDesign\Shared\Updates\PluginRollbackManager as BasePluginRollbackManager;

if (! defined('ABSPATH')) {
    exit;
}

require_once WP_PLUGIN_DIR . '/ar-design-shared-support/includes/updates/PluginRollbackManager.php';

final class ArDesignPacketaFixRollbackManager extends BasePluginRollbackManager
{
    public function __construct(string $pluginBasename, string $pluginRoot)
    {
        parent::__construct(
            $pluginBasename,
            $pluginRoot,
            array(
                'backup_dir' => 'ard-packeta-fix-backups',
                'error_code' => 'ard_packeta_fix_rollback_performed',
                'error_message' => 'Aktualizácia AR Design Packeta Fix zlyhala. Predchádzajúca verzia bola automaticky obnovená zo zálohy.',
                'text_domain' => 'ar-design-packeta-fix',
            )
        );
    }
}
