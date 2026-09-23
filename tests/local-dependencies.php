<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/../EPP/namingo/vendor/autoload.php';
// Simulate a globally available Tembo factory before loading an incomplete module.
check(class_exists(\Pinga\Tembo\EppRegistryFactory::class), 'Global Tembo fixture');
$file = tempnam(sys_get_temp_dir(), 'pnlcs-epp-module-');
try {
    copy(__DIR__.'/../EPP/EppRegistrar.php', $file);
    require $file;
    $module = new \Modules\Registrars\EPP\EppRegistrar();
    $result = $module->testConnection();
    check(! $result['success'], 'Missing local dependency must fail');
    check(str_contains($result['message'], 'Module-local Tembo is missing'), 'No global autoloader fallback');
    echo "PASS missing module-local Tembo fails even with globally loaded factory\n";
} finally {
    unlink($file);
}
