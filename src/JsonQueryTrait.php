<?php

namespace Gautrot\Propel\Behavior\Json;

use Gautrot\Propel\Behavior\Json\Tests\fixtures\JsonModelCriteria;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\Criterion\RawModelCriterion;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\Adapter\Pdo\PdoAdapter;
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
     * @return JsonQueryTrait|JsonModelCriteria
     * @throws JsonException
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

        return $this->addJsonFilter($selector, 'compare', $value, $operator);
    }

    /**
     * @param string $selector
     * @param string $operation
     * @param mixed $value
     * @param string $operator
     * @param bool $not
     * @return JsonQueryTrait|JsonModelCriteria
     * @throws JsonException
     */
    private function addJsonFilter(
        string $selector,
        string $operation,
               $value = null,
        string $operator = Criteria::EQUAL,
        bool   $not = false
    ): self
    {
        $criterion = $this->createJsonCriterion($selector, $operation, $value, $operator, $not);
        $this->addUsingOperator($criterion, null, null, false);

        return $this;
    }

    /**
     * @param string $selector
     * @param string $operation
     * @param mixed $value
     * @param string $operator
     * @param bool $not
     * @return RawModelCriterion
     * @throws JsonException
     */
    private function createJsonCriterion(
        string $selector,
        string $operation,
               $value = null,
        string $operator = Criteria::EQUAL,
        bool   $not = false
    ): RawModelCriterion
    {
        $path = new JsonPath($selector, false);

        if ($path->column === null) {
            throw new InvalidArgumentException('The JSON selector must contain a column.');
        }

        // Prefer a real alias.column reference; otherwise dots select JSON keys.
        if ($path->segments && strpos($selector, '.') === strlen($path->column)) {
            $qualifiedColumn = $path->column . '.' . $path->segments[0];
            [$qualifiedColumnMap] = $this->getColumnFromName($qualifiedColumn);

            if ($qualifiedColumnMap !== null) {
                $path->column = $qualifiedColumn;
                array_shift($path->segments);
            }
        }

        [$columnMap, $column] = $this->getColumnFromName($path->column, false);

        $types = ['JSON', 'VARCHAR', 'LONGVARCHAR', 'CLOB'];

        if ($columnMap === null || $column === null || !in_array(strtoupper($columnMap->getType()), $types, true)) {
            throw new InvalidArgumentException('JSON filters require a JSON or text column.');
        }

        $adapter = Propel::getServiceContainer()->getAdapter($this->getDbName());

        if (!$adapter instanceof PdoAdapter) {
            throw new InvalidArgumentException('JSON filters require a PDO adapter.');
        }

        $compiler = new JsonSqlCompiler($adapter->getAdapterId());
        $tableName = $columnMap->getTable()->getName();

        if ($tableName === null) {
            throw new LogicException('JSON filters require a named table.');
        }

        $column = $this->quoteIdentifier($column, $tableName);

        switch ($operation) {
            case 'compare':
                [$sql, $value, $bindingType] = $compiler->compare($column, $path, $operator, $value);
                break;
            case 'contains':
                [$sql, $value, $bindingType] = $compiler->contains($column, $path, $value, $not);
                break;
            case 'length':
                if (!is_int($value)) {
                    throw new InvalidArgumentException('JSON array length must be an integer.');
                }

                [$sql, $value, $bindingType] = $compiler->length($column, $path, $operator, $value);
                break;
            default:
                [$sql, $value, $bindingType] = $compiler->exists($column, $path, $not);
                break;
        }

        return new RawModelCriterion($this, $sql, $columnMap, $value, null, $bindingType);
    }

    /**
     * @param string $selector
     * @param mixed $value
     * @return JsonQueryTrait|JsonModelCriteria
     * @throws JsonException
     */
    public function whereJsonContains(string $selector, $value): self
    {
        return $this->addJsonFilter($selector, 'contains', $value);
    }

    /**
     * @param string $selector
     * @param mixed $value
     * @return JsonQueryTrait|JsonModelCriteria
     * @throws JsonException
     */
    public function whereJsonDoesntContain(string $selector, $value): self
    {
        return $this->addJsonFilter($selector, 'contains', $value, Criteria::EQUAL, true);
    }

    /**
     * @param string $selector
     * @param mixed $operator
     * @param int|null $length
     * @return JsonQueryTrait|JsonModelCriteria
     * @throws JsonException
     */
    public function whereJsonLength(string $selector, $operator, ?int $length = null): self
    {
        if (is_int($operator) && $length === null) {
            $length = $operator;
            $operator = Criteria::EQUAL;
        }

        if (!is_string($operator) || $length === null) {
            throw new InvalidArgumentException(
                'Use whereJsonLength(selector, length) or whereJsonLength(selector, operator, length).'
            );
        }

        return $this->addJsonFilter($selector, 'length', $length, $operator);
    }

    /**
     * @param string $selector
     * @return JsonQueryTrait|JsonModelCriteria
     * @throws JsonException
     */
    public function whereJsonContainsKey(string $selector): self
    {
        return $this->addJsonFilter($selector, 'exists');
    }

    /**
     * @param string $selector
     * @return JsonQueryTrait|JsonModelCriteria
     * @throws JsonException
     */
    public function whereJsonDoesntContainKey(string $selector): self
    {
        return $this->addJsonFilter($selector, 'exists', null, Criteria::EQUAL, true);
    }

    /**
     * Validate operators before handing whole-column comparisons to Propel
     *
     * @param string|null $comparison
     * @return string|null
     */
    protected function validateJsonColumnComparison(?string $comparison): ?string
    {
        if ($comparison === null) {
            return null;
        }

        $allowed = [
            Criteria::EQUAL, Criteria::NOT_EQUAL, Criteria::ALT_NOT_EQUAL,
            Criteria::LESS_THAN, Criteria::LESS_EQUAL, Criteria::GREATER_THAN, Criteria::GREATER_EQUAL,
            Criteria::LIKE, Criteria::NOT_LIKE, Criteria::ILIKE, Criteria::NOT_ILIKE,
            Criteria::IN, Criteria::NOT_IN, Criteria::ISNULL, Criteria::ISNOTNULL,
        ];

        foreach ($allowed as $operator) {
            if (strtoupper(trim($comparison)) === trim($operator)) {
                return $operator;
            }
        }

        throw new InvalidArgumentException('Unsupported JSON column comparison operator.');
    }

    /**
     * @param string $column
     * @param array<string|int, mixed> $filters
     * @param string|null $comparison
     * @return JsonQueryTrait|JsonModelCriteria
     * @throws JsonException
     */
    protected function filterByJsonPaths(string $column, array $filters, ?string $comparison = null): self
    {
        $criterion = null;

        foreach ($filters as $path => $value) {
            if ((string)$path === '') {
                throw new InvalidArgumentException('JSON filter paths must not be empty.');
            }

            $next = $this->createJsonCriterion(
                $column . '->' . $path,
                'compare',
                $value,
                $comparison ?? Criteria::EQUAL
            );

            if ($criterion === null) {
                $criterion = $next;
            } else {
                $criterion->addAnd($next);
            }
        }

        if ($criterion !== null) {
            $this->addUsingOperator($criterion, null, null, false);
        }

        return $this;
    }
}
