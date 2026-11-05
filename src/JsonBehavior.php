<?php

namespace Gautrot\Propel\Behavior\Json;

use Override;
use Propel\Generator\Model\Behavior;

/**
 * # JsonBehavior
 * Adds JSON filters to the generated base Query class
 * @api
 */
final class JsonBehavior extends Behavior
{
    /**
     * @var JsonBehaviorQueryBuilderModifier|null
     */
    protected ?JsonBehaviorQueryBuilderModifier $queryBuilderModifier = null;

    /**
     * @return JsonBehaviorQueryBuilderModifier
     */
    #[Override]
    public function getQueryBuilderModifier(): JsonBehaviorQueryBuilderModifier
    {
        $this->queryBuilderModifier ??= new JsonBehaviorQueryBuilderModifier($this);

        return $this->queryBuilderModifier;
    }
}
