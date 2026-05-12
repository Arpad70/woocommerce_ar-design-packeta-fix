<?php

declare(strict_types=1);

$pluginRoot = dirname(__DIR__);
$versionFile = $pluginRoot . '/VERSION';
$pluginFile = $pluginRoot . '/ar-design-packeta-fix.php';

if (! file_exists($versionFile) || ! file_exists($pluginFile)) {
    fwrite(STDERR, "Missing VERSION or plugin main file.\n");
    exit(1);
}

$version = trim((string) file_get_contents($versionFile));
$pluginSource = (string) file_get_contents($pluginFile);

if ('' === $version) {
    fwrite(STDERR, "VERSION file is empty.\n");
    exit(1);
}

$errors = array();

if (! preg_match('/^\s*\*\s*Version:\s*' . preg_quote($version, '/') . '\s*$/mi', $pluginSource)) {
    $errors[] = 'Plugin header Version does not match VERSION file.';
}

if (! preg_match("/define\\(\\s*'AR_DESIGN_PACKETA_FIX_VERSION'\\s*,\\s*'" . preg_quote($version, '/') . "'\\s*\\)/", $pluginSource)) {
    $errors[] = 'AR_DESIGN_PACKETA_FIX_VERSION does not match VERSION file.';
}

if (! empty($errors)) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . "\n");
    }

    exit(1);
}

echo "Version consistency OK ({$version}).\n";
