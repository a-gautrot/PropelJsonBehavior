<?php

declare(strict_types=1);

namespace Gautrot\Propel\Behavior\Json\Tests;

use Gautrot\Propel\Behavior\Json\JsonPath;
use Gautrot\Propel\Behavior\Json\JsonSqlCompiler;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * # JsonSqlCompilerTest
 */
final class JsonSqlCompilerTest extends TestCase
{
    /**
     * @return array[]
     */
    public static function drivers(): array
    {
        return [['mysql'], ['mariadb'], ['pgsql'], ['sqlite']];
    }

    /**
     * @dataProvider drivers
     * @param string $driver
     * @throws JsonException
     */
    #[DataProvider('drivers')]
    public function testEachExpressionHasOneBoundParameter(string $driver): void
    {
        $compiler = new JsonSqlCompiler($driver);
        $path = new JsonPath('Options->languages->0');
        $expressions = [
            $compiler->compare('r.options', $path, Criteria::EQUAL, 'fr'),
            $compiler->compare('r.options', $path, Criteria::GREATER_EQUAL, 1.5),
            $compiler->compare('r.options', $path, Criteria::EQUAL, true),
            $compiler->compare('r.options', $path, Criteria::EQUAL, null),
            $compiler->contains('r.options', $path, ['fr', 'en']),
            $compiler->length('r.options', $path, Criteria::GREATER_EQUAL, 2),
            $compiler->exists('r.options', $path),
        ];
        foreach ($expressions as [$sql]) {
            self::assertSame(1, substr_count($sql, '?'), $sql);
            self::assertStringContainsString('r.options', $sql);
        }
    }

    /**
     * @return void
     */
    public function testUnsupportedDriverIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new JsonSqlCompiler('sqlsrv');
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testSqliteRejectsObjectContainment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new JsonSqlCompiler('sqlite'))
            ->contains('r.options', new JsonPath('Options'), ['country' => 'FR']);
    }

    /**
     * @return void
     */
    public function testNegativeLengthIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new JsonSqlCompiler('mysql'))
            ->length('r.options', new JsonPath('Options'), '=', -1);
    }
}
