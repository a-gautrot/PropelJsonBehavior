<?php

namespace Gautrot\Propel\Behavior\Json\Tests;

use InvalidArgumentException;
use JsonBehaviorRecord;
use JsonBehaviorRecordQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Propel\Generator\Platform\MysqlPlatform;
use Propel\Generator\Platform\PgsqlPlatform;
use Propel\Generator\Platform\SqlitePlatform;
use Propel\Generator\Util\QuickBuilder;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Adapter\Pdo\MysqlAdapter;
use Propel\Runtime\Adapter\Pdo\PgsqlAdapter;
use Propel\Runtime\Adapter\Pdo\SqliteAdapter;
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
            $record->setLabel($label)->setOptions($document)->save();
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
    public function testScalarFiltersAndBinding(): void
    {
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options->language', 'fr')));
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options->score', Criteria::GREATER_EQUAL, 10)));
        self::assertSame(['en'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options->score', '12')));
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options->active', true)));
        self::assertSame(['en'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options->active', false)));
        self::assertSame(['injection'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options->language', "fr' OR 1=1 --")));
    }

    /** @return list<string> */
    private function labels($query): array
    {
        return $query->orderByLabel()->select('Label')->find()->getData();
    }

    public function testDottedPathsWithAliasesAndMixedSeparators(): void
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

    public function testAllFiltersAcceptDottedPaths(): void
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
    public function testNullAndMissingAreDistinct(): void
    {
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options->nullable', null)));
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonContainsKey('Options->nullable')));
        self::assertSame(['empty', 'en', 'injection', 'sql-null'], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonDoesntContainKey('Options->nullable')));
    }

    /**
     * @return void
     */
    public function testContainmentAndLength(): void
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
    public function testAliasesNestedPathsAndArrayIndexes(): void
    {
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create('r')->whereJson('r.Options->nested->country', 'FR')));
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create('r')->whereJson('r.Options->languages->0', 'fr')));
        self::assertSame(['en'], $this->labels(JsonBehaviorRecordQuery::create('r')->joinParent('p')->whereJson('p.Options->language', 'fr')));
    }

    /**
     * @return void
     */
    public function testCombinationWithStandardPropelFiltersAndOr(): void
    {
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->filterByLabel('fr')->whereJsonContains('Options->languages', 'en')));
        self::assertSame(['en', 'fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options->language', 'fr')->_or()->whereJson('Options->language', 'en')));
        self::assertSame(['fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options->language', 'fr')->whereJson('Options->active', true)));
    }

    /** @dataProvider invalidSelectors */
    #[DataProvider('invalidSelectors')]
    public function testInvalidSelectorsAreRejected(string $selector): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonBehaviorRecordQuery::create()->whereJson($selector, 'fr');
    }

    /**
     * @return void
     */
    public function testInvalidOperatorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonBehaviorRecordQuery::create()->whereJson('Options->score', '= 1 OR 1=1 --', 1);
    }

    /**
     * @return void
     */
    public function testSqlContainsBoundValuesOnly(): void
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
    public function testIdentifierQuotingAndCount(): void
    {
        self::assertSame(1, JsonBehaviorRecordQuery::create('r')->setIdentifierQuoting(true)->whereJson('r.Options->language', 'fr')->count());
    }

    /**
     * @return void
     */
    public function testKeyExistenceDoesNotConfuseFalseAndMissing(): void
    {
        self::assertSame(['empty', 'en', 'fr'], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonContainsKey('Options->active')));
        self::assertSame([], $this->labels(JsonBehaviorRecordQuery::create()->whereJson('Options->absent', null)));
        self::assertSame([], $this->labels(JsonBehaviorRecordQuery::create()->whereJsonLength('Options->nested', 1)));
    }

    /**
     * @return void
     */
    public function testContainmentDistinguishesBooleansNumbersAndNull(): void
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
    public function testIntegerColumnIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JsonBehaviorRecordQuery::create()->whereJson('Id', 1);
    }
}
