<?php

namespace Gautrot\Propel\Behavior\Json\Tests;

use Gautrot\Propel\Behavior\Json\JsonBehavior;
use LogicException;
use PHPUnit\Framework\TestCase;
use Propel\Generator\Config\QuickGeneratorConfig;
use Propel\Generator\Model\Database;

/**
 * # JsonBehaviorTest
 */
final class JsonBehaviorTest extends TestCase
{
    /**
     * @return void
     */
    public function testRegistration(): void
    {
        $config = new QuickGeneratorConfig();
        self::assertSame(JsonBehavior::class, ltrim($config->getBehaviorLocator()->getBehavior('json'), '\\'));
    }

    /**
     * @return void
     */
    public function testModifierReused(): void
    {
        $behavior = new JsonBehavior();
        self::assertSame($behavior->getQueryBuilderModifier(), $behavior->getQueryBuilderModifier());
    }

    /**
     * @return void
     */
    public function testMissingTable(): void
    {
        $script = 'class IncompleteQuery {}';
        $modifier = (new JsonBehavior())->getQueryBuilderModifier();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/JSON query generation requires a table\./');
        $modifier->queryFilter($script);
    }

    /**
     * @return void
     */
    public function testMissingFilter(): void
    {
        $database = new Database('incomplete');
        $table = $database->addTable(['name' => 'incomplete_record']);
        $table->addColumn(['name' => 'payload', 'type' => 'JSON', 'phpName' => 'Payload']);
        $behavior = new JsonBehavior();
        $behavior->setTable($table);
        $script = 'class IncompleteQuery {}';
        $behavior->getQueryBuilderModifier()->queryFilter($script);
        self::assertSame('class IncompleteQuery {}', $script);
    }
}
