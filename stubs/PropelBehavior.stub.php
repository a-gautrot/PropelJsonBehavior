<?php

namespace Propel\Generator\Model;

/**
 * A list of stub classes for the IDE, PHPStan, and Psalm
 */

/**
 * # Behavior
 */
class Behavior
{
    /**
     * Propel modifiers may be separate objects, as in its bundled behaviors
     * The upstream $this annotation only describes the default implementation
     *
     * @return object
     */
    public function getQueryBuilderModifier()
    {
    }
}

namespace Propel\Runtime\ActiveQuery;

use Propel\Runtime\Map\ColumnMap;

/**
 * # ModelCriteria
 */
class ModelCriteria
{
    /**
     * @return array{ColumnMap|null, string|null}
     */
    protected function getColumnFromName(string $columnName, bool $failSilently = true): array
    {
    }
}

namespace Propel\Runtime\Map;

/**
 * # ColumnMap
 */
class ColumnMap
{
}
