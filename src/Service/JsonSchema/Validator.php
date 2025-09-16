<?php

namespace App\Service\JsonSchema;

use JsonSchema\Validator as JsonSchemaValidator;
use JsonSchema\Constraints\Constraint;

class Validator
{
    private $validator;

    public function __construct()
    {
        $this->validator = new JsonSchemaValidator();
    }

    public function validate($data, $schema)
    {   
        $this->validator->validate($data, $schema, Constraint::CHECK_MODE_NORMAL);

        if (!$this->validator->isValid()) {
            return $this->validator->getErrors();
        }

        return [];
    }
}
