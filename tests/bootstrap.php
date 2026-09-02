<?php
spl_autoload_register(function (string $class): void {
    foreach ([
        'Ernestdefoe\\Steward\\Tests\\' => __DIR__ . '/',
        'Ernestdefoe\\Steward\\'        => __DIR__ . '/../src/',
    ] as $prefix => $base) {
        if (str_starts_with($class, $prefix)) {
            $path = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($path)) { require $path; return; }
        }
    }
});
