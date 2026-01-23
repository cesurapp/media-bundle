<?php

namespace Cesurapp\MediaBundle\Entity\Traits;

use Cesurapp\MediaBundle\Entity\Media;
use Doctrine\ORM\Mapping as ORM;

/** @phpstan-ignore-next-line */
trait AvatarTrait
{
    #[ORM\Column(type: 'media', nullable: true)]
    private ?array $avatar = [];

    /**
     * @return Media[]|null
     */
    public function getAvatar(): ?array
    {
        return $this->avatar;
    }

    public function getAvatarFirst(): ?Media
    {
        return $this->avatar[array_key_first($this->avatar)] ?? null;
    }

    public function setAvatar(array $avatars): self
    {
        $this->avatar = [];

        array_walk_recursive($avatars, fn (Media $avatar) => $this->avatar[] = $avatar);

        return $this;
    }

    public function addAvatar(Media $avatar): self
    {
        $this->avatar[] = $avatar;

        return $this;
    }

    public function clearAvatar(): self
    {
        $this->avatar = [];

        return $this;
    }
}
