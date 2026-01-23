<?php

namespace Cesurapp\MediaBundle\Tests\Entity;

use Cesurapp\MediaBundle\Entity\MediaSuperClass;
use Cesurapp\MediaBundle\Entity\Traits\MediaTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity]
#[ORM\Table(name: 'test_entity')]
#[ORM\HasLifecycleCallbacks]
class TestEntity extends MediaSuperClass
{
    use MediaTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private UuidV7 $id;

    public function __construct()
    {
        $this->id = UuidV7::v7();
    }

    public function getId(): UuidV7
    {
        return $this->id;
    }
}
