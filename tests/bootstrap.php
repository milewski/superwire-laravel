<?php

declare(strict_types = 1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

spl_autoload_register(function (string $class): void {

    $prefix = 'Superwire\\Laravel\\Tests\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }

});
