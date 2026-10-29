<?php

namespace Gautrot\Propel\Behavior\Json;

use InvalidArgumentException;
use JsonException;
use PDO;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * # JsonSqlCompiler
 * Produces expressions with exactly one bound parameter for Propel's RawModelCriterion
 */
final class JsonSqlCompiler
{
    /**
     * @var string
     */
    private string $driver;

    /**
     * @param string $driver
     */
    public function __construct(string $driver)
    {
        $drivers = [
            'mysql' => 'mysql',
            'mysqli' => 'mysql',
            'mariadb' => 'mysql',
            'pgsql' => 'pgsql',
            'postgresql' => 'pgsql',
            'sqlite' => 'sqlite',
        ];

        if (!isset($drivers[$driver])) {
            throw new InvalidArgumentException("Unsupported JSON query adapter: $driver");
        }

        $this->driver = $drivers[$driver];
    }

    /**
     * @param string $column
     * @param JsonPath $path
     * @param string $operator
     * @param $value
     * @return array{string, mixed, int}
     * @throws JsonException
     */
    public function compare(string $column, JsonPath $path, string $operator, $value): array
    {
        $operator = self::operator($operator);
        $type = $this->type($column, $path);
        $extract = $this->extract($column, $path);
        $criterias = [Criteria::EQUAL, Criteria::ALT_NOT_EQUAL, Criteria::NOT_EQUAL];

        if ($value === null) {
            if (!in_array($operator, $criterias, true)) {
                throw new InvalidArgumentException('JSON null supports only equality and inequality.');
            }

            return [
                "$type $operator ?",
                ($this->driver === 'mysql') ? 'NULL' : 'null',
                PDO::PARAM_STR
            ];
        }

        if (!is_scalar($value) || (is_float($value) && !is_finite($value))) {
            throw new InvalidArgumentException('whereJson expects a finite scalar or null; use whereJsonContains for documents.');
        }

        $text = [
            'mysql' => "JSON_UNQUOTE($extract)",
            'pgsql' => "($extract #>> '{}'::text[])",
            'sqlite' => $extract,
        ][$this->driver];

        if (is_bool($value)) {
            if (!in_array($operator, $criterias, true)) {
                throw new InvalidArgumentException('JSON booleans support only equality and inequality.');
            }

            if ($this->driver === 'sqlite') {
                return [
                    "($type IN ('true', 'false') AND $text $operator ?)",
                    (int)$value,
                    PDO::PARAM_INT
                ];
            }

            $booleanType = $this->driver === 'mysql' ? 'BOOLEAN' : 'boolean';

            return [
                "($type = '$booleanType' AND $text $operator ?)",
                ($value) ? 'true' : 'false',
                PDO::PARAM_STR
            ];
        }

        if (is_int($value) || is_float($value)) {
            $types = [
                'mysql' => "('INTEGER', 'DOUBLE', 'DECIMAL')",
                'pgsql' => "('number')",
                'sqlite' => "('integer', 'real')",
            ][$this->driver];
            $cast = [
                'mysql' => 'DECIMAL(65, 20)',
                'pgsql' => 'NUMERIC',
                'sqlite' => 'REAL',
            ][$this->driver];

            // CASE prevents PostgreSQL from attempting numeric casts of non-numeric JSON values
            return [
                "(CASE WHEN $type IN $types THEN CAST($text AS $cast) ELSE NULL END $operator CAST(? AS $cast))",
                json_encode($value, JSON_THROW_ON_ERROR),
                PDO::PARAM_STR
            ];
        }

        switch ($this->driver) {
            case 'mysql':
                $stringType = 'STRING';
                break;
            case 'pgsql':
                $stringType = 'string';
                break;
            default:
                $stringType = 'text';
                break;
        }

        return [
            "($type = '$stringType' AND $text $operator ?)",
            $value,
            PDO::PARAM_STR
        ];
    }

    /**
     * @param string $operator
     * @return string
     */
    public static function operator(string $operator): string
    {
        $operator = mb_strtoupper(trim($operator));
        $criterias = [
            Criteria::EQUAL, Criteria::ALT_NOT_EQUAL, Criteria::NOT_EQUAL, Criteria::LESS_THAN, Criteria::LESS_EQUAL,
            Criteria::GREATER_THAN, Criteria::GREATER_EQUAL
        ];

        if (!in_array($operator, $criterias, true)) {
            throw new InvalidArgumentException("Unsupported JSON comparison operator: $operator");
        }

        return $operator;
    }

    /**
     * @param string $column
     * @param JsonPath $path
     * @return string
     */
    private function type(string $column, JsonPath $path): string
    {
        switch ($this->driver) {
            case 'mysql':
                return "JSON_TYPE({$this->extract($column, $path)})";
            case 'pgsql':
                return "jsonb_typeof({$this->extract($column, $path)})";
            default:
                return "json_type($column, {$path->sqlPath()})";
        }
    }

    /**
     * @param string $column
     * @param JsonPath $path
     * @return string
     */
    private function extract(string $column, JsonPath $path): string
    {
        switch ($this->driver) {
            case 'mysql':
                return "JSON_EXTRACT($column, {$path->sqlPath()})";
            case 'pgsql':
                return "(CAST($column AS jsonb) #> {$path->postgresPath()})";
            default:
                return "json_extract($column, {$path->sqlPath()})";
        }
    }

    /**
     * @param string $column
     * @param JsonPath $path
     * @param $value
     * @param bool $not
     * @return array{string, string, int}
     * @throws JsonException
     */
    public function contains(string $column, JsonPath $path, $value, bool $not = false): array
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        $extract = $this->extract($column, $path);

        switch ($this->driver) {
            case 'mysql':
                $sql = "JSON_CONTAINS($column, ?, {$path->sqlPath()}) = 1";
                break;
            case 'pgsql':
                $sql = "$extract @> CAST(? AS jsonb)";
                break;
            default:
                $values = is_array($value) ? $value : [$value];

                if ($values !== array_values($values)) {
                    throw new InvalidArgumentException('SQLite containment supports scalars and lists of scalars only.');
                }

                foreach ($values as $item) {
                    if ($item !== null && !is_scalar($item)) {
                        throw new InvalidArgumentException('SQLite containment supports scalars and lists of scalars only.');
                    }
                }

                $encoded = json_encode($values, JSON_THROW_ON_ERROR);
                $type = $this->type($column, $path);
                $sql = "$type = 'array' AND NOT EXISTS (SELECT 1 FROM json_each(?) AS json_wanted WHERE NOT EXISTS (SELECT 1 FROM json_each($column, {$path->sqlPath()}) AS json_actual WHERE (json_actual.type = json_wanted.type OR (json_actual.type IN ('integer', 'real') AND json_wanted.type IN ('integer', 'real'))) AND json_actual.value IS json_wanted.value))";
                break;
        }

        $sql = ($not) ? "NOT ($sql)" : "($sql)";

        if ($not && $this->driver === 'sqlite') {
            $sql = "{$this->type($column, $path)} IS NOT NULL AND ($sql)";
        }

        return [
            $sql,
            $encoded,
            PDO::PARAM_STR
        ];
    }

    /**
     * @param string $column
     * @param JsonPath $path
     * @param string $operator
     * @param int $length
     * @return array{string, int, int}
     */
    public function length(string $column, JsonPath $path, string $operator, int $length): array
    {
        if ($length < 0) {
            throw new InvalidArgumentException('JSON array length must be non-negative.');
        }

        $operator = self::operator($operator);
        $extract = $this->extract($column, $path);
        $type = $this->type($column, $path);
        $arrayType = ($this->driver === 'mysql') ? 'ARRAY' : 'array';

        $lengthSql = [
            'mysql' => "JSON_LENGTH($extract)",
            'pgsql' => "jsonb_array_length($extract)",
            'sqlite' => "json_array_length($column, {$path->sqlPath()})",
        ][$this->driver];

        return [
            "CASE WHEN $type = '$arrayType' THEN $lengthSql ELSE NULL END $operator ?",
            $length,
            PDO::PARAM_INT
        ];
    }

    /**
     * @param string $column
     * @param JsonPath $path
     * @param bool $not
     * @return array{string, int, int}
     */
    public function exists(string $column, JsonPath $path, bool $not = false): array
    {
        if ($this->driver === 'mysql') {
            $sql = "COALESCE(JSON_CONTAINS_PATH($column, 'one', {$path->sqlPath()}), 0)";
        } else {
            $expression = $this->driver === 'pgsql' ? $this->extract($column, $path) : $this->type($column, $path);
            $sql = "(CASE WHEN $expression IS NOT NULL THEN 1 ELSE 0 END)";
        }

        return [
            "$sql = ?",
            ($not) ? 0 : 1,
            PDO::PARAM_INT
        ];
    }
}
