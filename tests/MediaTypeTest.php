<?php

namespace Cesurapp\MediaBundle\Tests;

use Cesurapp\MediaBundle\Entity\Media;
use Cesurapp\MediaBundle\Type\MediaType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

class MediaTypeTest extends TestCase
{
    public function testPostgreSQLDeclarationIsJsonb(): void
    {
        $this->assertSame('JSONB', new MediaType()->getSQLDeclaration([], new PostgreSQLPlatform()));
    }

    public function testPlatformWithoutJsonbFallsBackToJson(): void
    {
        $platform = new SQLitePlatform();

        $this->assertSame(
            $platform->getJsonTypeDeclarationSQL([]),
            new MediaType()->getSQLDeclaration([], $platform)
        );
    }

    public function testRoundTripFromJsonbValue(): void
    {
        $first = new Media();
        $second = new Media();

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getReference')->willReturnCallback(
            fn (string $class, UuidV7 $id) => $id->equals($first->getId()) ? $first : $second
        );

        $type = new MediaType();
        $type->setEntityManager($em);
        $platform = new PostgreSQLPlatform();

        $stored = $type->convertToDatabaseValue([$first, $second], $platform);

        // PostgreSQL normalizes JSONB output with a space after separators
        $jsonb = str_replace(',', ', ', $stored);
        $medias = $type->convertToPHPValue($jsonb, $platform);

        $this->assertSame([$first->getId()->toString(), $second->getId()->toString()], array_keys($medias));
        $this->assertSame($first, $medias[$first->getId()->toString()]);
        $this->assertSame($second, $medias[$second->getId()->toString()]);
    }

    public function testNullValues(): void
    {
        $type = new MediaType();
        $platform = new PostgreSQLPlatform();

        $this->assertNull($type->convertToDatabaseValue(null, $platform));
        $this->assertSame([], $type->convertToPHPValue(null, $platform));
    }
}
