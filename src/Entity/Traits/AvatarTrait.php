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

    public function setAvatar(array $avatars): self
    {
        $this->avatar = [];

        array_walk_recursive($avatars, fn (Media $avatar) => $this->avatar[] = $avatar);

        return $this;
    }

    public function addAvatar(Media|array $avatar): self
    {
        if (is_array($avatar)) {
            array_walk_recursive($avatar, fn (Media $avatar) => $this->addAvatar($avatar));

            return $this;
        }

        if (!in_array($avatar, $this->avatar, true)) {
            $this->avatar[] = $avatar;
        }

        return $this;
    }

    public function removeAvatar(Media $avatar): self
    {
        if ($key = array_search($avatar, $this->avatar, true)) {
            unset($this->avatar[$key]);
        }

        return $this;
    }

    public function getAvatarFirst(): ?Media
    {
        return $this->avatar[array_key_first($this->avatar)] ?? null;
    }
}
