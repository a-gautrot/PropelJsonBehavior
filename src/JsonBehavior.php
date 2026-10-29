<?php

namespace Gautrot\Propel\Behavior\Json;

use Propel\Generator\Builder\Om\AbstractOMBuilder;
use Propel\Generator\Model\Behavior;

/**
 * # JsonBehavior
 * Adds JSON filters to the generated base Query class
 */
class JsonBehavior extends Behavior
{
    /**
     * @param AbstractOMBuilder $builder
     * @return string
     */
    public function queryAttributes(AbstractOMBuilder $builder): string
    {
        return $this->renderTemplate('queryAttributes', [], dirname(__DIR__) . '/templates/');
    }
}
