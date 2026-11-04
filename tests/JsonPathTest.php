<?php

namespace Gautrot\Propel\Behavior\Json\Tests;

use Gautrot\Propel\Behavior\Json\JsonPath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * # JsonPathTest
 */
final class JsonPathTest extends TestCase
{
    /**
     * @return void
     */
    public function testModifiedSqlPath(): void
    {
        $path = new JsonPath('Options->safe');
        $path->segments = ["safe\"') OR 1=1 -- "];
        $this->expectException(InvalidArgumentException::class);
        $path->sqlPath();
    }

    /**
     * @return void
     */
    public function testModifiedPostgresPath(): void
    {
        $path = new JsonPath('Options->safe');
        $path->segments = ["safe']::text[]) OR TRUE -- "];
        $this->expectException(InvalidArgumentException::class);
        $path->postgresPath();
    }

    /**
     * @return void
     */
    public function testValidPathChanges(): void
    {
        $path = new JsonPath('Options->safe');
        $path->segments = ['nested', '0'];
        self::assertSame("'$.\"nested\"[0]'", $path->sqlPath());
        self::assertSame("ARRAY['nested', '0']::text[]", $path->postgresPath());
    }

    /**
     * @return void
     */
    public function testAliasedPath(): void
    {
        $path = new JsonPath('r.Options->languages->0');
        self::assertSame('r.Options', $path->column);
        self::assertSame(['languages', '0'], $path->segments);
        self::assertSame("'$.\"languages\"[0]'", $path->sqlPath());
        self::assertSame("ARRAY['languages', '0']::text[]", $path->postgresPath());
    }

    /**
     * @return void
     */
    public function testNonStringSegment(): void
    {
        $path = new JsonPath('Options->safe');
        $path->segments = [0];
        $this->expectException(InvalidArgumentException::class);
        $path->sqlPath();
    }
}
