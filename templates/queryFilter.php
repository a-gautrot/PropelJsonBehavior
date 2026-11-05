<?php

$columnLiteral = var_export($columnPhpName, true);
echo <<<PHP

        if (is_array(\$$variableName)) {
            return \$this->filterByJsonPaths($columnLiteral, \$$variableName, \$comparison);
        }

        \$comparison = \$this->validateJsonColumnComparison(\$comparison);

PHP;
