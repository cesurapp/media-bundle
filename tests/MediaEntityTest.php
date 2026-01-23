<?php

namespace Cesurapp\MediaBundle\Tests;

use Cesurapp\MediaBundle\Entity\Media;
use Symfony\Component\Uid\UuidV7;
use PHPUnit\Framework\TestCase;

class MediaEntityTest extends TestCase
{
    public function testOwnerIsUuidV7(): void
    {
        $media = new Media();
        $uuid = new UuidV7();

        $media->setOwner($uuid);

        $this->assertInstanceOf(UuidV7::class, $media->getOwner());
        $this->assertEquals($uuid, $media->getOwner());
    }

    public function testOwnerCanBeNull(): void
    {
        $media = new Media();

        $this->assertNull($media->getOwner());

        $media->setOwner(null);

        $this->assertNull($media->getOwner());
    }

    public function testOwnerSetterReturnsMedia(): void
    {
        $media = new Media();
        $uuid = new UuidV7();

        $result = $media->setOwner($uuid);

        $this->assertInstanceOf(Media::class, $result);
        $this->assertSame($media, $result);
    }
}
