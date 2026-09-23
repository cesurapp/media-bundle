<?php

namespace Cesurapp\MediaBundle\Validator;

use Symfony\Component\Mime\MimeTypes;
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

        // Reject clearly oversized payloads before decoding them into memory. Base64 is ~4/3 of the
        // bytes; the slack covers padding and line breaks, the exact check follows the decode.
        $data = explode(',', $value, 2);
        $encoded = $data[1] ?? $data[0];
        if (null !== $constraint->maxSize && strlen($encoded) * 3 / 4 > $constraint->maxSize * 1024 * 1.1 + 16) {
            $this->context->buildViolation($constraint->sizeMessage)
                ->setParameter('{{ size }}', (string) (int) (strlen($encoded) * 3 / 4))
                ->setParameter('{{ max_size }}', (string) ($constraint->maxSize * 1024))
                ->addViolation();

            return;
        }

        // Decode base64
        $decodedContent = base64_decode($encoded, true);
        if (false === $decodedContent || '' === $decodedContent) {
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

            return;
        }

        if ($constraint->replaceData) {
            $this->context->getObject()->{$this->context->getPropertyName()} = [
                'content' => $decodedContent,
                'mimeType' => $mimeType,
                'extension' => MimeTypes::getDefault()->getExtensions($mimeType)[0] ?? 'bin',
                'size' => $fileSize,
            ];
        }
    }
}
