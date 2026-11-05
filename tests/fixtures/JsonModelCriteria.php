<?php

namespace Gautrot\Propel\Behavior\Json\Tests\fixtures;

use Gautrot\Propel\Behavior\Json\JsonQueryTrait;
use Propel\Runtime\ActiveQuery\ModelCriteria;

/**
 * # JsonModelCriteria
 * Analyze the runtime trait in the same context as a generated Propel query
 *
 * @api
 */
abstract class JsonModelCriteria extends ModelCriteria
{
    use JsonQueryTrait;
}
