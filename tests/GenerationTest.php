<?php

namespace Gautrot\Propel\Behavior\Json\Tests;

use JsonCoverageRecord;
use JsonCoverageRecordQuery;
use PHPUnit\Framework\TestCase;
use Propel\Generator\Platform\SqlitePlatform;
use Propel\Generator\Util\QuickBuilder;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * # GenerationTest
 */
final class GenerationTest extends TestCase
{
    /**
     * @return void
     */
    public function testGeneratedFilters(): void
    {
        // Generate inside the measured test, rather than setUpBeforeClass().
        $schema = <<<'XML'
<database name="json_coverage">
    <table name="json_coverage_record" phpName="JsonCoverageRecord">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="label" type="VARCHAR" />
        <column name="payload" type="JSON" />
        <behavior name="Gautrot\Propel\Behavior\Json\JsonBehavior" />
    </table>
</database>
XML;
        $builder = new QuickBuilder();
        $builder->setSchema($schema);
        $builder->setPlatform(new SqlitePlatform());
        $builder->build();

        $record = new JsonCoverageRecord();
        $record->setLabel('French')->setPayload(['language' => 'fr', 'active' => true])->save();
        (new JsonCoverageRecord())->setLabel('English')->setPayload(['language' => 'en'])->save();

        $found = JsonCoverageRecordQuery::create()->filterByPayload(['language' => 'fr', 'active' => true])->findOne();
        self::assertNotNull($found);
        self::assertSame($record->getPrimaryKey(), $found->getPrimaryKey());
        self::assertSame('French', $found->getLabel());
        self::assertSame(1, JsonCoverageRecordQuery::create()->whereJson('Payload->language', 'en')->count());
        self::assertSame(2, JsonCoverageRecordQuery::create()->filterByPayload(null, Criteria::ISNOTNULL)->count());
        self::assertSame(2, JsonCoverageRecordQuery::create()->filterByPayload([])->count());
    }
}
