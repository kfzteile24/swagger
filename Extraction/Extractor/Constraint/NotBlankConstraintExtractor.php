<?php

namespace Draw\Swagger\Extraction\Extractor\Constraint;

use Draw\Swagger\Extraction\Extractor\ConstraintExtractor;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\NotBlank as SupportedConstraint;

class NotBlankConstraintExtractor extends ConstraintExtractor
{
    /**
     * @param \Symfony\Component\Validator\Constraint $constraint
     *
     * @return bool
     */
    public function supportConstraint(Constraint $constraint)
    {
        return $constraint instanceof SupportedConstraint;
    }

    /**
     * @param \Symfony\Component\Validator\Constraint $constraint
     * @param \Draw\Swagger\Extraction\Extractor\Constraint\ConstraintExtractionContext $context
     *
     * @return void
     */
    public function extractConstraint(Constraint $constraint, ConstraintExtractionContext $context)
    {
        $this->assertSupportConstraint($constraint);
        if(!isset($context->propertySchema->format)) {
            $context->propertySchema->format = "not empty";
            $context->classSchema->required[] = $context->propertyName;
        }
    }
}
