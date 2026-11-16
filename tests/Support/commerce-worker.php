<?php

use App\Services\Commerce;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script,$mode,$barrier,$user,$product,$extra] = $argv;
$deadline = microtime(true) + 15;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, 'Barrier timed out');
        exit(1);
    }
    usleep(10000);
}
try {
    $commerce = app(Commerce::class);
    if ($mode === 'coupon') {
        $commerce->createOrder((int) $user, (int) $product, 'LAST', (string) Str::uuid());
    } else {
        $commerce->confirm((int) $product, (int) $user, $extra);
    }
    echo 'accepted';
} catch (ValidationException $e) {
    echo 'rejected';
}
