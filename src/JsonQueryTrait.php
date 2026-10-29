<?php

namespace Gautrot\Propel\Behavior\Json;

use InvalidArgumentException;
use LogicException;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\Criterion\RawModelCriterion;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\Propel;

/**
 * # JsonQueryTrait
 * @mixin ModelCriteria
 */
trait JsonQueryTrait
{
    /**
     * @param string $selector
     * @param mixed $operator
     * @param mixed $value
     * @return $this
     */
    public function whereJson(string $selector, $operator, $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = Criteria::EQUAL;
        }

        if (!is_string($operator)) {
            throw new InvalidArgumentException('The JSON comparison operator must be a string.');
        }

        return $this->addJsonFilter($selector, 'compare', [$operator, $value]);
    }

    /**
     * @param string $selector
     * @param string $operation
     * @param array $arguments
     * @return $this
     */
    private function addJsonFilter(string $selector, string $operation, array $arguments): self
    {
        if (!$this instanceof ModelCriteria) {
            throw new LogicException('JSON query filters require a Propel ModelCriteria query.');
        }

        $path = new JsonPath($selector, false);

        // Prefer a real alias.column reference; otherwise dots select JSON keys.
        if ($path->segments && strpos($selector, '.') === strlen($path->column)) {
            $qualifiedColumn = $path->column . '.' . $path->segments[0];
            [$qualifiedColumnMap] = $this->getColumnFromName($qualifiedColumn, true);
            if ($qualifiedColumnMap !== null) {
                $path->column = $qualifiedColumn;
                array_shift($path->segments);
            }
        }

        [$columnMap, $column] = $this->getColumnFromName($path->column, false);

        if ($columnMap === null || !in_array(strtoupper($columnMap->getType()), ['JSON', 'VARCHAR', 'LONGVARCHAR', 'CLOB'], true)) {
            throw new InvalidArgumentException('JSON filters require a JSON or text column.');
        }

        $adapter = Propel::getServiceContainer()->getAdapter($this->getDbName());
        $compiler = new JsonSqlCompiler($adapter->getAdapterId());
        $column = $this->quoteIdentifier($column, $columnMap->getTable()->getName());
        [$sql, $value, $bindingType] = $compiler->$operation($column, $path, ...$arguments);
        $criterion = new RawModelCriterion($this, $sql, $columnMap, $value, null, $bindingType);
        $this->addUsingOperator($criterion, null, null, false);

        return $this;
    }

    /**
     * @param string $selector
     * @param mixed $value
     * @return $this
     */
    public function whereJsonContains(string $selector, $value): self
    {
        return $this->addJsonFilter($selector, 'contains', [$value]);
    }

    /**
     * @param string $selector
     * @param mixed $value
     * @return $this
     */
    public function whereJsonDoesntContain(string $selector, $value): self
    {
        return $this->addJsonFilter($selector, 'contains', [$value, true]);
    }

    /**
     * @param string $selector
     * @param mixed $operator
     * @param int|null $length
     * @return $this
     */
    public function whereJsonLength(string $selector, $operator, ?int $length = null): self
    {
        if (is_int($operator) && $length === null) {
            $length = $operator;
            $operator = Criteria::EQUAL;
        }

        if (!is_string($operator) || $length === null) {
            throw new InvalidArgumentException('Use whereJsonLength(selector, length) or whereJsonLength(selector, operator, length).');
        }

        return $this->addJsonFilter($selector, 'length', [$operator, $length]);
    }

    /**
     * @param string $selector
     * @return $this
     */
    public function whereJsonContainsKey(string $selector): self
    {
        return $this->addJsonFilter($selector, 'exists', []);
    }

    /**
     * @param string $selector
     * @return $this
     */
    public function whereJsonDoesntContainKey(string $selector): self
    {
        return $this->addJsonFilter($selector, 'exists', [true]);
    }
}
