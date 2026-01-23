<?php

namespace Cesurapp\MediaBundle\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class Base64FileValidatorValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Base64FileValidator) {
            throw new UnexpectedTypeException($constraint, Base64FileValidator::class);
        }

        if (null === $value || '' === $value ||  '-1' === $value) {
            return;
        }

        if (!is_string($value)) {
            $this->context->buildViolation($constraint->invalidMessage)->addViolation();

            return;
        }

        // Decode base64
        $data = explode(',', $value);
        $decodedContent = base64_decode($data[1] ?? $data[0], true);
        if (false === $decodedContent) {
            $this->context->buildViolation($constraint->invalidMessage)->addViolation();

            return;
        }

        // Get MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (false === $finfo) {
            $this->context->buildViolation($constraint->invalidMessage)->addViolation();

            return;
        }

        $mimeType = finfo_buffer($finfo, $decodedContent);
        finfo_close($finfo);
        if (false === $mimeType) {
            $this->context->buildViolation($constraint->invalidMessage)->addViolation();

            return;
        }

        // Validate MIME type
        if (null !== $constraint->allowedMimes && !in_array($mimeType, $constraint->allowedMimes, true)) {
            $this->context->buildViolation($constraint->mimeMessage)
                ->setParameter('{{ mime }}', $mimeType)
                ->setParameter('{{ allowed_mimes }}', implode(', ', $constraint->allowedMimes))
                ->addViolation();

            return;
        }

        // Validate file size
        $fileSize = strlen($decodedContent);
        if (null !== $constraint->maxSize && $fileSize > ($constraint->maxSize * 1024)) {
            $this->context->buildViolation($constraint->sizeMessage)
                ->setParameter('{{ size }}', (string) $fileSize)
                ->setParameter('{{ max_size }}', (string) ($constraint->maxSize * 1024))
                ->addViolation();
        }
    }
}
