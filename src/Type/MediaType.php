<?php

namespace Cesurapp\MediaBundle\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Exception\InvalidArgumentException as InvalidUidException;
use Cesurapp\MediaBundle\Entity\Media;
use Symfony\Component\Uid\UuidV7;

/**
 * Doctrine Media Type Stored in JSONB.
 */
class MediaType extends Type
{
    private EntityManagerInterface $entityManager;

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getJsonbTypeDeclarationSQL($column);
    }

    public function getName(): string
    {
        return 'media';
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        try {
            // Always a JSON list: loaded values are keyed by id and removals leave gaps.
            return json_encode(
                array_values(array_map(fn (Media $media) => $media->getId(), $value)),
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (\JsonException $e) {
            throw InvalidFormat::new($value, 'json', 'array', $e);
        }
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?array
    {
        $array = [];

        if (null === $value || '' === $value) {
            return $array;
        }

        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        try {
            $ids = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            foreach (is_array($ids) ? $ids : [] as $id) {
                $array[$id] = $this->entityManager->getReference(Media::class, UuidV7::fromString($id));
            }

            return $array;
        } catch (\JsonException|InvalidUidException|\TypeError $e) {
            throw ValueNotConvertible::new($value, $this->getName(), $e->getMessage(), $e);
        }
    }

    public function setEntityManager(EntityManagerInterface $em): void
    {
        $this->entityManager = $em;
    }
}
