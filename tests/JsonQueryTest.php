<?php

namespace Gautrot\Propel\Behavior\Json\Tests;

use InvalidArgumentException;
use JsonBehaviorRecord;
use JsonBehaviorRecordQuery;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Propel\Generator\Platform\MysqlPlatform;
use Propel\Generator\Platform\PgsqlPlatform;
use Propel\Generator\Platform\SqlitePlatform;
use Propel\Generator\Util\QuickBuilder;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Adapter\AdapterInterface;
use Propel\Runtime\Adapter\Pdo\MysqlAdapter;
use Propel\Runtime\Adapter\Pdo\PgsqlAdapter;
use Propel\Runtime\Adapter\Pdo\SqliteAdapter;
use Propel\Runtime\Map\ColumnMap;
use Propel\Runtime\Map\TableMap;
use Propel\Runtime\Propel;
use Random\RandomException;

/**
 * # JsonQueryTest
 */
final class JsonQueryTest extends TestCase
{
    /**
     * @return void
     * @throws RandomException
     */
    public static function setUpBeforeClass(): void
    {
        $schema = <<<'XML'
<database name="json_behavior" defaultIdMethod="native">
  <table name="json_behavior_record" phpName="JsonBehaviorRecord">
    <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
    <column name="label" type="VARCHAR" size="255" />
    <column name="options" type="JSON" />
    <column name="metadata" phpName="Preferences" type="JSON" />
    <column name="parent_id" type="INTEGER" />
    <foreign-key foreignTable="json_behavior_record" phpName="Parent" refPhpName="Children">
      <reference local="parent_id" foreign="id" />
    </foreign-key>
    <behavior name="Gautrot\Propel\Behavior\Json\JsonBehavior" />
  </table>
</database>
XML;
        $schema = str_replace('json_behavior_record', 'json_behavior_record_' . bin2hex(random_bytes(6)), $schema);
        $dsn = getenv('TEST_DSN') ?: 'sqlite::memory:';
        [$platform, $adapter] = [
            'mysql' => [new MysqlPlatform(), new MysqlAdapter()],
            'pgsql' => [new PgsqlPlatform(), new PgsqlAdapter()],
            'sqlite' => [new SqlitePlatform(), new SqliteAdapter()],
        ][strtok($dsn, ':')];
        $builder = new QuickBuilder();
        $builder->setSchema($schema);
        $builder->setPlatform($platform);
        $builder->build($dsn, getenv('TEST_USER') ?: null, getenv('TEST_PASSWORD') ?: null, $adapter);

        $documents = [
            'fr' => ['language' => 'fr', 'languages' => ['fr', 'en'], 'active' => true, 'score' => 12, 'nullable' => null, 'nested' => ['country' => 'FR']],
            'en' => ['language' => 'en', 'languages' => ['en'], 'active' => false, 'score' => '12'],
            'empty' => ['languages' => [], 'active' => 1],
            'injection' => ['language' => "fr' OR 1=1 --", 'languages' => [true, 1, null]],
        ];
        foreach ($documents as $label => $document) {
            $record = new JsonBehaviorRecord();
            $record->setLabel($label)->setOptions($document)->setPreferences(['locale' => $label])->save();
        }
        $record = new JsonBehaviorRecord();
        $record->setLabel('sql-null')->save();
        $child = JsonBehaviorRecordQuery::create()->findOneByLabel('en');
        $child->setParentId(JsonBehaviorRecordQuery::create()->findOneByLabel('fr')->getId())->save();
    }

    /**
     * @return array[]
     */
    public static function invalidSelectors(): array
    {
        return [
            ['Options->'], ["Options->language' OR 1=1 --"], ['Options->*'], ['Options->-1'], ['Options; DROP TABLE users'],
            ['Options..language'], ['Options.language.'], ['.Options.language'], ['u..Options.language'],
            ["Options.language' OR 1=1 --"], ['Options.languages.-1'], ['Options.languages.*'],
        ];
    }

    /**
     * @return void
     */
    public function testScalarFilters(): void
    {
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options->language', 'fr')));
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options->score', Criteria::GREATER_EQUAL, 10)));
        self::assertSame(['en'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options->score', '12')));
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options->active', true)));
        self::assertSame(['en'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options->active', false)));
        self::assertSame(['injection'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options->language', "fr' OR 1=1 --")));
    }

    /**
     * @return list<string>
     */
    private function labels($query): array
    {
        return $query->orderByLabel()->select('Label')->find()->getData();
    }

    /**
     * @return void
     */
    public function testOptions(): void
    {
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->filterByOptions(['language' => 'fr', 'active' => true])));
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->filterByOptions(['nested.country' => 'FR', 'languages->0' => 'fr'])));
        self::assertSame(['en'], $this->labels(JsonBehaviorRecordQuery::create()->filterByOptions(['score' => '12'])));
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->filterByOptions(['nullable' => null])));
        self::assertSame([], $this->labels(JsonBehaviorRecordQuery::create()->filterByOptions(['absent' => null])));
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->filterByOptions(['score' => 10], Criteria::GREATER_EQUAL)));
        self::assertSame(['injection'], $this->labels(JsonBehaviorRecordQuery::create()->filterByOptions(['language' => "fr' OR 1=1 --"])));
    }

    /**
     * @return void
     */
    public function testOptionAliases(): void
    {
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create('r')->filterByOptions(['language' => 'fr'])->filterByLabel('fr')));
        self::assertSame(['en', 'fr'], $this->labels(JsonBehaviorRecordQuery::create()->filterByLabel('en')->_or()->filterByOptions(['language' => 'fr', 'active' => true])));
        self::assertSame(['en', 'fr'], $this->labels(JsonBehaviorRecordQuery::create()->filterByOptions(['language' => 'fr', 'active' => true])->_or()->filterByLabel('en')));
        $query = JsonBehaviorRecordQuery::create();
        self::assertSame($query, $query->filterByOptions([]));
        self::assertSame(5, $query->count());
        self::assertSame(['sql-null'], $this->labels(JsonBehaviorRecordQuery::create()->filterByOptions()));
    }

    /**
     * @return void
     */
    public function testEmptyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonBehaviorRecordQuery::create()->filterByOptions(['' => 'fr']);
    }

    /**
     * @return void
     */
    public function testScalarOperator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonBehaviorRecordQuery::create()->filterByOptions('unmatched', ' = :p1 OR 1=1 OR 1 = ');
    }

    /**
     * @return void
     */
    public function testNullOperator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonBehaviorRecordQuery::create()->filterByOptions(null, ' IS NULL OR 1=1 -- ');
    }

    /**
     * @return void
     */
    public function testColumnOperators(): void
    {
        self::assertSame(['sql-null'], $this->labels(JsonBehaviorRecordQuery::create()->filterByOptions(null, Criteria::ISNULL)));
        self::assertSame(4, JsonBehaviorRecordQuery::create()->filterByOptions(null, Criteria::ISNOTNULL)->count());
        $query = JsonBehaviorRecordQuery::create()->filterByOptions('unmatched', Criteria::NOT_EQUAL);
        $parameters = [];
        self::assertStringContainsString('<>', $query->createSelectSql($parameters));
        self::assertSame('unmatched', $parameters[0]['value']);
    }

    /**
     * @return void
     */
    public function testInvalidOptionPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonBehaviorRecordQuery::create()->filterByOptions(["language' OR 1=1 --" => 'fr']);
    }

    /**
     * @return void
     */
    public function testColumnFilters(): void
    {
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create('r')->filterByPreferences(['locale' => 'fr'])));
        self::assertSame(['en', 'fr'], $this->labels(JsonBehaviorRecordQuery::create()->filterByLabel(['fr', 'en'])));
    }

    /**
     * @return void
     */
    public function testDocumentValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonBehaviorRecordQuery::create()->filterByOptions(['languages' => ['fr', 'en']]);
    }

    /**
     * @return void
     */
    public function testDottedPaths(): void
    {
        foreach (['Options.language', 'Options->language'] as $selector) {
            self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson($selector, 'fr')));
        }
        foreach (['Options.nested.country', 'Options.nested->country', 'Options->nested.country'] as $selector) {
            self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson($selector, 'FR')));
        }
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options.languages.0', 'fr')));
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create('r')->whereJson('r.Options.language', 'fr')));
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create('r')->whereJson('r.Options->nested.country', 'FR')));
        self::assertSame(['en'], $this->labels(JsonBehaviorRecordQuery::create('r')->joinParent('p')->whereJson('p.Options.language', 'fr')));
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create('Options')->whereJson('Options.language', 'fr')));
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create('Options')->whereJson('Options.Options.language', 'fr')));
        self::assertSame(4, JsonBehaviorRecordQuery::create('r')->whereJsonContainsKey('r.Options')->count());
    }

    /**
     * @return void
     */
    public function testDottedFilters(): void
    {
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonContains('Options.languages', ['en', 'fr'])));
        self::assertSame(['empty', 'en', 'injection'], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonDoesntContain('Options.languages', 'fr')));
        self::assertSame(['empty'], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonLength('Options.languages', 0)));
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonContainsKey('Options.nullable')));
        self::assertSame(['empty', 'en', 'injection', 'sql-null'], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonDoesntContainKey('Options.nullable')));
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options.nullable', null)));
    }

    /**
     * @return void
     */
    public function testNullValues(): void
    {
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options->nullable', null)));
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonContainsKey('Options->nullable')));
        self::assertSame(['empty', 'en', 'injection', 'sql-null'], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonDoesntContainKey('Options->nullable')));
    }

    /**
     * @return void
     */
    public function testArrays(): void
    {
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonContains('Options->languages', 'fr')));
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonContains('Options->languages', ['en', 'fr'])));
        self::assertSame(['empty', 'en', 'injection'], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonDoesntContain('Options->languages', 'fr')));
        self::assertSame(['fr', 'injection'], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonLength('Options->languages', Criteria::GREATER_EQUAL, 2)));
        self::assertSame(['empty'], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonLength('Options->languages', 0)));
        self::assertSame(['injection'], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonContains('Options->languages', true)));
    }

    /**
     * @return void
     */
    public function testNestedPaths(): void
    {
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create('r')->whereJson('r.Options->nested->country', 'FR')));
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create('r')->whereJson('r.Options->languages->0', 'fr')));
        self::assertSame(['en'], $this->labels(JsonBehaviorRecordQuery::create('r')->joinParent('p')->whereJson('p.Options->language', 'fr')));
    }

    /**
     * @return void
     */
    public function testCombinedFilters(): void
    {
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->filterByLabel('fr')->whereJsonContains('Options->languages', 'en')));
        self::assertSame(['en', 'fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options->language', 'fr')->_or()->whereJson('Options->language', 'en')));
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options->language', 'fr')->whereJson('Options->active', true)));
    }

    /** @dataProvider invalidSelectors */
    #[DataProvider('invalidSelectors')]
    public function testInvalidSelector(string $selector): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonBehaviorRecordQuery::create()->whereJson($selector, 'fr');
    }

    /**
     * @return void
     */
    public function testInvalidOperator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonBehaviorRecordQuery::create()->whereJson('Options->score', '= 1 OR 1=1 --', 1);
    }

    /**
     * @return void
     */
    public function testBoundValues(): void
    {
        $query = JsonBehaviorRecordQuery::create('r')->whereJson('r.Options->language', "fr' OR 1=1 --");
        $parameters = [];
        $sql = $query->createSelectSql($parameters);
        self::assertStringNotContainsString("fr' OR 1=1 --", $sql);
        self::assertSame("fr' OR 1=1 --", $parameters[0]['value']);
    }

    /**
     * @return void
     */
    public function testQuotedColumns(): void
    {
        self::assertSame(1, JsonBehaviorRecordQuery::create('r')->setIdentifierQuoting(true)->whereJson('r.Options->language', 'fr')->count());
    }

    /**
     * @return void
     */
    public function testMissingKeys(): void
    {
        self::assertSame(['empty', 'en', 'fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonContainsKey('Options->active')));
        self::assertSame([], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options->absent', null)));
        self::assertSame([], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonLength('Options->nested', 1)));
    }

    /**
     * @return void
     */
    public function testArrayTypes(): void
    {
        foreach ([true, 1, null] as $value) {
            self::assertSame(['injection'], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonContains('Options->languages', $value)));
        }
        self::assertSame([], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonContains('Options->languages', false)));
        self::assertSame([], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonContains('Options->languages', '1')));
    }

    /**
     * @return void
     */
    public function testInvalidColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonBehaviorRecordQuery::create()->whereJson('Id', 1);
    }

    /**
     * @return void
     */
    public function testOperatorType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonBehaviorRecordQuery::create()->whereJson('Options->score', [], 1);
    }

    /**
     * @return void
     */
    public function testMissingLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonBehaviorRecordQuery::create()->whereJsonLength('Options->languages', '>');
    }

    /**
     * @return void
     */
    public function testLengthOperatorType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonBehaviorRecordQuery::create()->whereJsonLength('Options->languages', [], 1);
    }

    /**
     * @return void
     */
    public function testColumnComparisons(): void
    {
        $operators = [
            Criteria::EQUAL, Criteria::NOT_EQUAL, Criteria::ALT_NOT_EQUAL,
            Criteria::LESS_THAN, Criteria::LESS_EQUAL, Criteria::GREATER_THAN, Criteria::GREATER_EQUAL,
            Criteria::LIKE, Criteria::NOT_LIKE, Criteria::ILIKE, Criteria::NOT_ILIKE,
            Criteria::IN, Criteria::NOT_IN, Criteria::ISNULL, Criteria::ISNOTNULL,
        ];
        foreach ($operators as $operator) {
            $query = JsonBehaviorRecordQuery::create()->filterByOptions('unmatched', ' ' . strtolower($operator) . ' ');
            self::assertSame($operator, $query->getLastCriterion()->getComparison());
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    public function testNonPdoAdapter(): void
    {
        $container = Propel::getServiceContainer();
        $query = JsonBehaviorRecordQuery::create();
        $database = $query->getDbName();
        $adapter = $container->getAdapter($database);
        $nonPdoAdapter = $this->createStub(AdapterInterface::class);
        try {
            $container->setAdapter($database, $nonPdoAdapter);
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/JSON filters require a PDO adapter\./');
            $query->whereJson('Options->language', 'fr');
        } finally {
            $container->setAdapter($database, $adapter);
        }
    }

    /**
     * @return void
     */
    public function testUnnamedTable(): void
    {
        $table = new TableMap();
        $column = new ColumnMap('options', $table, 'Options', 'JSON');
        $query = $this->getMockBuilder(JsonBehaviorRecordQuery::class)
            ->onlyMethods(['getColumnFromName'])
            ->getMock();
        $query->expects(self::once())->method('getColumnFromName')->willReturn([$column, 'record.options']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/JSON filters require a named table\./');
        $query->whereJson('Options->language', 'fr');
    }
}
