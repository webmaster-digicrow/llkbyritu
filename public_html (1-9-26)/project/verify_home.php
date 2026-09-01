<?php
require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $controller = new \App\Http\Controllers\Front\FrontendController();
    echo "SUCCESS! Frontend controller loaded without errors.\n";
    echo "Home page route is configured and accessible.\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Controller: " . get_class($e) . "\n";
}
?>
