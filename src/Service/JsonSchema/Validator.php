<?php

namespace App\Service\JsonSchema;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator as JsonSchemaValidator;

class Validator
{
    private JsonSchemaValidator $validator;

    public function __construct()
    {
        $this->validator = new JsonSchemaValidator();
    }

    public function validate(mixed $data, mixed $schema): array
    {
        $this->validator->validate($data, $schema, Constraint::CHECK_MODE_NORMAL);

        if (!$this->validator->isValid()) {
            return $this->validator->getErrors();
        }

        return [];
    }
}
