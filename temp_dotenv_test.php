<?php
require_once __DIR__ . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__)->load();

$var = 'ENCRYPTION_KEY';
echo "getenv($var): ";
var_dump(getenv($var));
echo "\$_ENV[$var]: ";
var_dump($_ENV[$var] ?? null);
