# Propel JSON Behavior

[![Latest stable version](https://img.shields.io/packagist/v/a-gautrot/propel-json-behavior)](https://packagist.org/packages/a-gautrot/propel-json-behavior)
![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%207.4-8892BF.svg)
[![GitHub Actions](https://img.shields.io/github/actions/workflow/status/a-gautrot/PropelJsonBehavior/ci.yml?branch=dev)](https://github.com/a-gautrot/PropelJsonBehavior/actions/workflows/ci.yml)
[![Travis CI (dev)](https://app.travis-ci.com/a-gautrot/PropelJsonBehavior.svg?branch=dev)](https://app.travis-ci.com/a-gautrot/PropelJsonBehavior)
[![Codecov (dev)](https://img.shields.io/codecov/c/github/a-gautrot/PropelJsonBehavior/dev)](https://codecov.io/gh/a-gautrot/PropelJsonBehavior/tree/dev)

**Propel JSON Behavior** is an extension for JSON query filters in Propel 2, inspired
by [Laravel's JSON queries](https://laravel.com/framework/docs/queries#json-where-clauses) for Eloquent.

## Installation

Install via composer:

```shell
composer require a-gautrot/propel-json-behavior
```

Or insert the following in `composer.json`:

```json
{
  "require": {
    "a-gautrot/propel-json-behavior": "~1.0"
  }
}
```

Then, add the new behavior, only if you have a JSON type column in the table:

```xml

<table name="user">
    <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true"/>
    <!-- Store the document to filter. -->
    <column name="options" type="JSON"/>
    <!-- Add JSON filters to the generated Query class. -->
    <behavior name="json"/>
</table>
```

Finally, regenerate Propel models after updating the schema:

```shell
vendor/bin/propel model:build
```

If the name `json` is not discovered, use `Gautrot\Propel\Behavior\Json\JsonBehavior` as the behavior name:

```xml

<behavior name="Gautrot\Propel\Behavior\Json\JsonBehavior"/>
```

## Usage

An example of a use case. Start with a JSON file (`options.json`), stored in the `options` column:

```json
{
  "language": "fr",
  "languages": [
    "fr",
    "en"
  ],
  "active": true,
  "score": 12,
  "address": {
    "country": "FR"
  }
}
```

```php
use Propel\Runtime\ActiveQuery\Criteria;

// Dots and arrows select the same JSON value; both separators can be combined.
UserQuery::create()
    ->whereJson('Options.address.country', 'FR')
    ->find();
UserQuery::create()
    ->whereJson('Options->address->country', 'FR')
    ->find();
UserQuery::create()
    ->whereJson('Options->address.country', 'FR')
    ->find();

// A leading alias still identifies the Propel column.
UserQuery::create('u')
    ->whereJson('u.Options.language', 'fr')
    ->find();

// Combine JSON filters using a Query alias.
$users = UserQuery::create('u')
    ->whereJson('u.Options->language', 'fr')
    ->whereJsonContains('u.Options->languages', 'en')
    ->whereJsonLength('u.Options->languages', Criteria::GREATER_EQUAL, 2)
    ->find();

// Compare a number using a Propel operator constant.
UserQuery::create()
    ->whereJson('Options->score', Criteria::GREATER_EQUAL, 10)
    ->find();

// Match a boolean without treating it as a number.
UserQuery::create()
    ->whereJson('Options->active', true)
    ->find();

// Require every listed value to be present in the array.
UserQuery::create()
    ->whereJsonContains('Options->languages', ['fr', 'en'])
    ->find();

// Exclude documents whose array contains this value.
UserQuery::create()
    ->whereJsonDoesntContain('Options->languages', 'de')
    ->find();

// Match an empty array.
UserQuery::create()
    ->whereJsonLength('Options->languages', 0)
    ->find();

// Check whether a key exists, including keys with a JSON null value.
UserQuery::create()
    ->whereJsonContainsKey('Options->language')
    ->find();
UserQuery::create()
    ->whereJsonDoesntContainKey('Options->language')
    ->find();

// Read nested keys or a zero-based array index.
UserQuery::create()
    ->whereJson('Options->address->country', 'FR')
    ->find();
UserQuery::create()
    ->whereJson('Options->languages->0', 'fr')
    ->find();

// Combine JSON filters with Propel's OR operator.
UserQuery::create()
    ->whereJson('Options->language', 'fr')
    ->_or()
    ->whereJson('Options->language', 'en')
    ->find();
```

Use column PHP names, such as `Options` in this example, and operators accept both `Criteria` comparison constants and
SQL strings. In addition to supporting arrow joins (`->`), like in Eloquent, all JSON filters accept dot joints (`.`).

The behavior also extends to Propel's generated `filterBy()` methods for JSON columns:

```php
UserQuery::create()
    ->filterByOptions(['language' => 'fr', 'active' => true, 'address.country' => 'FR'])
    ->find();

UserQuery::create('u')
    ->filterByOptions(['score' => 10], Criteria::GREATER_EQUAL)
    ->find();
```

Array keys are relative JSON paths and values are scalars or JSON null. All entries must match; an empty array adds no
condition. Use `whereJsonContains()` for array or object containment. Non-array arguments compare the whole column with
validated Propel operators; custom SQL is rejected. The method follows the JSON column's PHP name. Regenerate the Propel
models after updating the behavior.

A valid `alias.Column` reference takes precedence over a JSON path; literal JSON keys containing dots or arrows are not
supported.

Keys must match SQL's column standards, being `[A-Za-z_][A-Za-z0-9_]*` (or `[A-Za-z_]\w*`); numeric segments select
array indexes.

Joins, standard filters, pagination, and `count()` can be combined with these methods.

## Database support

Supports MySQL/MariaDB, PostgreSQL, and SQLite with JSON functions. SQLite containment accepts scalars and lists of
scalars; object containment requires MySQL/MariaDB or PostgreSQL.

- Values are bound parameters; selectors and operators are validated.
- JSON null, missing keys, and SQL NULL are distinct. `whereJson(..., null)` matches JSON null only.
- Strings, numbers, and booleans retain their JSON types; numeric precision and collation depend on the database.
- Length filters match arrays only. Negative containment excludes missing paths and SQL NULL.
- JSON and text columns are accepted; text must contain valid JSON. Indexes must be created separately.
