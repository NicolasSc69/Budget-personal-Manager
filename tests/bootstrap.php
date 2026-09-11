<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// The Docker image sets APP_ENV=prod as a real container environment
// variable (see Dockerfile). That takes precedence over anything read from
// .env files, so without this the kernel would boot in "prod" (no
// framework.test service) even though phpunit.dist.xml asks for "test",
// and WebTestCase::createClient() would fail with "framework.test is not
// set to true".
putenv('APP_ENV=test');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
