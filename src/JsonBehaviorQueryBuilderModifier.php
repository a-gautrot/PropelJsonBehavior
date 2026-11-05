<?php

namespace Gautrot\Propel\Behavior\Json;

use LogicException;
use Propel\Generator\Util\PhpParser;

/**
 * # JsonBehaviorQueryBuilderModifier
 * @api
 */
final class JsonBehaviorQueryBuilderModifier
{
    /**
     * @var JsonBehavior
     */
    protected JsonBehavior $behavior;

    /**
     * @param JsonBehavior $behavior
     */
    public function __construct(JsonBehavior $behavior)
    {
        $this->behavior = $behavior;
    }

    /**
     * @return string
     */
    public function queryAttributes(): string
    {
        return $this->renderTemplate('queryAttributes');
    }

    /**
     * @param string $name
     * @param array<string, mixed> $vars
     * @return string
     */
    protected function renderTemplate(string $name, array $vars = []): string
    {
        return $this->behavior->renderTemplate($name, $vars, dirname(__DIR__) . '/templates/');
    }

    /**
     * Extend generated JSON filters without changing whole-column comparisons
     *
     * @param string $script
     * @return void
     */
    public function queryFilter(string &$script): void
    {
        $parser = new PhpParser($script, true);
        $table = $this->behavior->getTable();
        if ($table === null) {
            throw new LogicException('JSON query generation requires a table.');
        }

        foreach ($table->getColumns() as $column) {
            if ($column->getType() !== 'JSON') {
                continue;
            }

            $method = 'filterBy' . $column->getPhpName();
            $code = $parser->findMethod($method);
            if ($code === false) {
                continue;
            }

            $body = $this->renderTemplate('queryFilter', [
                'columnPhpName' => $column->getPhpName(),
                'variableName' => $column->getCamelCaseName(),
            ]);
            $openingBrace = strpos($code, '{');
            if ($openingBrace === false) {
                throw new LogicException('Unable to locate the generated JSON filter body.');
            }
            $position = $openingBrace + 1;
            $code = substr_replace($code, $body, $position, 0);
            $code = str_replace('The value to use as filter', 'A JSON path/value map or a whole-column value', $code);
            $parser->replaceMethod($method, $code);
        }
        $script = $parser->getCode();
    }
}
