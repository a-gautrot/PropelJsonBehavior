<?php

namespace Gautrot\Propel\Behavior\Json;

use InvalidArgumentException;

/**
 * # JsonPath
 * A deliberately restricted, portable subset of JSON paths
 * @api
 */
final class JsonPath
{
    /**
     * @var string|null
     */
    public ?string $column = null;
    /**
     * @var list<string>
     */
    public array $segments;

    /**
     * @param string $selector
     * @param bool $columnHasAlias Whether the initial dot qualifies a Propel column.
     */
    public function __construct(string $selector, bool $columnHasAlias = true)
    {
        $parts = explode('.', str_replace('->', '.', $selector));
        $column = array_shift($parts);

        if ($columnHasAlias && strpos($selector, '.') === strlen($column)) {
            $aliasColumn = array_shift($parts);

            if ($aliasColumn === null) {
                throw new InvalidArgumentException('The JSON selector must contain an aliased column.');
            }

            $column .= '.' . $aliasColumn;
        }

        if (!preg_match('/\A[A-Za-z_]\w*(?:\.[A-Za-z_]\w*)?\z/', $column)) {
            throw new InvalidArgumentException('Use a Propel column PHP name, optionally prefixed by a model alias.');
        }

        $this->column = $column;
        $this->segments = $parts;
        $this->validateSegments();
    }

    /**
     * @return void
     */
    private function validateSegments(): void
    {
        foreach ($this->segments as $segment) {
            $this->validateSegment($segment);
        }
    }

    /**
     * Public array properties can receive non-string values at runtime.
     *
     * @param mixed $segment
     * @return void
     */
    private function validateSegment($segment): void
    {
        if (!is_string($segment) || !preg_match('/\A(?:[A-Za-z_]\w*|0|[1-9]\d*)\z/', $segment)) {
            throw new InvalidArgumentException(
                'JSON path segments must be identifier keys or non-negative array indexes.'
            );
        }
    }

    /**
     * @return string
     */
    public function sqlPath(): string
    {
        $this->validateSegments();
        $path = '$';

        foreach ($this->segments as $segment) {
            $path .= (ctype_digit($segment)) ? "[$segment]" : '."' . $segment . '"';
        }

        return "'" . $path . "'";
    }

    /**
     * @return string
     */
    public function postgresPath(): string
    {
        $this->validateSegments();
        $segments = array_map(static fn(string $s): string => "'" . $s . "'", $this->segments);

        return 'ARRAY[' . implode(', ', $segments) . ']::text[]';
    }
}
