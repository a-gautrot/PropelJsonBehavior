<?php

namespace Gautrot\Propel\Behavior\Json;

use InvalidArgumentException;

/**
 * # JsonPath
 * A deliberately restricted, portable subset of JSON paths.
 */
final class JsonPath
{
    /**
     * @var string|null
     */
    public ?string $column = null;
    /** @var list<string> */
    public array $segments;

    /**
     * @param string $selector
     * @param bool $columnHasAlias Whether the initial dot qualifies a Propel column.
     */
    public function __construct(string $selector, bool $columnHasAlias = true)
    {
        $parts = preg_split('/->|\./', $selector);
        $column = array_shift($parts);

        if ($columnHasAlias && strpos($selector, '.') === strlen($column)) {
            $column .= '.' . array_shift($parts);
        }

        if (!preg_match('/\A[A-Za-z_]\w*(?:\.[A-Za-z_]\w*)?\z/', $column)) {
            throw new InvalidArgumentException('Use a Propel column PHP name, optionally prefixed by a model alias.');
        }

        foreach ($parts as $part) {
            if (!preg_match('/\A(?:[A-Za-z_]\w*|0|[1-9]\d*)\z/', $part)) {
                throw new InvalidArgumentException('JSON path segments must be identifier keys or non-negative array indexes.');
            }
        }

        $this->column = $column;
        $this->segments = $parts;
    }

    /**
     * @return string
     */
    public function sqlPath(): string
    {
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
        return "ARRAY[" . implode(', ', array_map(static fn(string $s): string => "'" . $s . "'", $this->segments)) . ']::text[]';
    }
}
