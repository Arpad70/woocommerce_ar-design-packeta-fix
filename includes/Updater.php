<?php

declare(strict_types=1);

namespace ArDesign\PacketaFix;

use ArDesign\Shared\Updates\GitHubPluginUpdater as BaseGitHubPluginUpdater;

if (! defined('ABSPATH')) {
    exit;
}

require_once WP_PLUGIN_DIR . '/ar-design-shared-support/includes/updates/GitHubPluginUpdater.php';

final class ArDesignPacketaFixUpdater extends BaseGitHubPluginUpdater
{
    public function __construct(string $repositoryFullName, string $pluginBasename, string $currentVersion)
    {
        parent::__construct(
            $repositoryFullName,
            $pluginBasename,
            $currentVersion,
            array(
                'plugin_slug' => 'ar-design-packeta-fix',
                'plugin_name' => 'AR Design Packeta Fix for WooCommerce',
                'text_domain' => 'ar-design-packeta-fix',
                'description' => 'Samostatný Packeta fix modul pre WooCommerce spravovaný AR Design.',
                'author_label' => 'AR Design',
                'user_agent_slug' => 'ar-design-packeta-fix',
                'cache_key_prefix' => 'ar_design_packeta_fix_release_data_',
                'preferred_zip_names' => array('ar-design-packeta-fix.zip'),
                'allow_any_zip_fallback' => false,
            )
        );
    }
}
