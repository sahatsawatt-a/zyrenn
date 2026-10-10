<?php

namespace App\Support\Workflow;

use RuntimeException;

/**
 * A definition that is not a workflow at all: a step of a type that doesn't
 * exist, a connection to a step that isn't there. Such a thing is not kept.
 */
class DefinitionInvalid extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct($errors[0] ?? 'The workflow is not valid.');
    }
}
