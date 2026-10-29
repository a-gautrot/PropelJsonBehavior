<?php

namespace Gautrot\Propel\Behavior\Json\Tests;

use Gautrot\Propel\Behavior\Json\JsonBehavior;
use PHPUnit\Framework\TestCase;
use Propel\Generator\Config\QuickGeneratorConfig;

/**
 * # JsonBehaviorTest
 */
final class JsonBehaviorTest extends TestCase
{
    /**
     * @return void
     */
    public function testComposerRegistersTheJsonBehavior(): void
    {
        $config = new QuickGeneratorConfig();
        self::assertSame(JsonBehavior::class, ltrim($config->getBehaviorLocator()->getBehavior('json'), '\\'));
    }
}
