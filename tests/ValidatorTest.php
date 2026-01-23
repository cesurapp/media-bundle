<?php

namespace Cesurapp\MediaBundle\Tests;

use Cesurapp\MediaBundle\Validator\Base64FileValidator;
use Cesurapp\MediaBundle\Validator\Base64FileValidatorValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

class ValidatorTest extends TestCase
{
    private Base64FileValidatorValidator $validator;
    private ExecutionContextInterface $context;

    protected function setUp(): void
    {
        $this->validator = new Base64FileValidatorValidator();
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator->initialize($this->context);
    }

    public function testValidBase64Image(): void
    {
        // Create a simple 1x1 PNG image
        $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $base64 = base64_encode($pngData);

        $constraint = new Base64FileValidator(
            allowedMimes: ['image/png'],
            maxSize: 1024 * 1024
        );

        $this->context->expects($this->never())->method('buildViolation');

        $this->validator->validate($base64, $constraint);
    }

    public function testValidBase64ImageWithDataUri(): void
    {
        // Create a simple 1x1 PNG image with data URI format
        $base64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        $constraint = new Base64FileValidator(
            allowedMimes: ['image/png'],
            maxSize: 1024 * 1024
        );

        $this->context->expects($this->never())
            ->method('buildViolation');

        $this->validator->validate($base64, $constraint);
    }

    public function testInvalidMimeType(): void
    {
        // Create a simple 1x1 PNG image
        $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $base64 = base64_encode($pngData);

        $constraint = new Base64FileValidator(
            allowedMimes: ['image/jpeg'], // Only JPEG allowed, but we're sending PNG
            maxSize: 1024 * 1024
        );

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnSelf();
        $violationBuilder->expects($this->once())
            ->method('addViolation');

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->with($constraint->mimeMessage)
            ->willReturn($violationBuilder);

        $this->validator->validate($base64, $constraint);
    }

    public function testFileSizeExceedsMaxSize(): void
    {
        // Create a simple 1x1 PNG image
        $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $base64 = base64_encode($pngData);

        $constraint = new Base64FileValidator(
            allowedMimes: ['image/png'],
            maxSize: 10 // Very small size to trigger violation
        );

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnSelf();
        $violationBuilder->expects($this->once())
            ->method('addViolation');

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->with($constraint->sizeMessage)
            ->willReturn($violationBuilder);

        $this->validator->validate($base64, $constraint);
    }

    public function testInvalidBase64String(): void
    {
        $constraint = new Base64FileValidator();

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->once())
            ->method('addViolation');

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->with($constraint->invalidMessage)
            ->willReturn($violationBuilder);

        $this->validator->validate('invalid-base64!!!', $constraint);
    }

    public function testNullValueIsValid(): void
    {
        $constraint = new Base64FileValidator();

        $this->context->expects($this->never())
            ->method('buildViolation');

        $this->validator->validate(null, $constraint);
    }

    public function testEmptyStringIsValid(): void
    {
        $constraint = new Base64FileValidator();

        $this->context->expects($this->never())
            ->method('buildViolation');

        $this->validator->validate('', $constraint);
    }

    public function testNonStringValueIsInvalid(): void
    {
        $constraint = new Base64FileValidator();

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects($this->once())
            ->method('addViolation');

        $this->context->expects($this->once())
            ->method('buildViolation')
            ->with($constraint->invalidMessage)
            ->willReturn($violationBuilder);

        $this->validator->validate(123, $constraint);
    }
}
