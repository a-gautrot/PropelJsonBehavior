<?php

declare(strict_types=1);

use PHPUnit\Runner\Version;
use PHPUnit\TextUI\Application;
use PHPUnit\TextUI\Command;

require dirname(__DIR__) . '/vendor/autoload.php';

$configuration = version_compare(Version::id(), '10.0.0', '<') ? 'phpunit-legacy.dist.xml' : 'phpunit.dist.xml';

$_SERVER['argv'] = array_merge(
    [$_SERVER['argv'][0], '--configuration', dirname(__DIR__) . '/' . $configuration],
    array_slice($_SERVER['argv'], 1)
);
$_SERVER['argc'] = count($_SERVER['argv']);

if (version_compare(Version::id(), '10.0.0', '<')) {
    Command::main();
} else {
    exit((new Application())->run($_SERVER['argv']));
}
