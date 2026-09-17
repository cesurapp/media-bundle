<?php

namespace Cesurapp\MediaBundle\Tests;

use Cesurapp\MediaBundle\Entity\Media;
use Cesurapp\MediaBundle\Entity\Traits\LogoTrait;
use Cesurapp\MediaBundle\Tests\Entity\TestEntity;
use PHPUnit\Framework\TestCase;

class TraitTest extends TestCase
{
    public function testRemoveFirstMedia(): void
    {
        $first = new Media();
        $second = new Media();

        $entity = new TestEntity()->setMedia([$first, $second]);
        $entity->removeMedia($first);

        $this->assertSame([1 => $second], $entity->getMedia());
    }

    public function testRemoveFirstLogo(): void
    {
        $first = new Media();
        $second = new Media();

        $entity = new class () {
            use LogoTrait;
        };
        $entity->setLogo([$first, $second]);
        $entity->removeLogo($first);

        $this->assertSame([1 => $second], $entity->getLogo());
    }

    public function testRemoveMissingMediaKeepsCollection(): void
    {
        $media = new Media();

        $entity = new TestEntity()->setMedia([$media]);
        $entity->removeMedia(new Media());

        $this->assertSame([$media], $entity->getMedia());
    }
}
