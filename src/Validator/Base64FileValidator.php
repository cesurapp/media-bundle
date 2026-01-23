<?php

namespace Cesurapp\MediaBundle\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute]
class Base64FileValidator extends Constraint
{
    public function __construct(
        public ?array $allowedMimes = null,
        public ?int $maxSize = null,
        public bool $replaceData = true,
        public string $mimeMessage = 'Invalid file type.',
        public string $sizeMessage = 'File size exceeds the maximum allowed size.',
        public string $invalidMessage = 'Invalid base64 file.',
        mixed $options = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct($options, $groups, $payload);
    }
}
