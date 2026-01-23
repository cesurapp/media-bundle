<?php

namespace Cesurapp\MediaBundle\Entity\Traits;

use Cesurapp\MediaBundle\Entity\Media;
use Doctrine\ORM\Mapping as ORM;

/** @phpstan-ignore-next-line */
trait MediaTrait
{
    #[ORM\Column(type: 'media', nullable: true)]
    private ?array $media = [];

    /**
     * @return Media[]|null
     */
    public function getMedia(): ?array
    {
        return $this->media;
    }

    public function setMedia(array $medias): self
    {
        $this->media = [];

        array_walk_recursive($medias, fn (Media $media) => $this->media[] = $media);

        return $this;
    }

    public function addMedia(Media|array $media): self
    {
        if (is_array($media)) {
            array_walk_recursive($media, fn (Media $media) => $this->addMedia($media));

            return $this;
        }

        if (!in_array($media, $this->media, true)) {
            $this->media[] = $media;
        }

        return $this;
    }

    public function removeMedia(Media $media): self
    {
        if ($key = array_search($media, $this->media, true)) {
            unset($this->media[$key]);
        }

        return $this;
    }

    public function getMediaColumns(): array
    {
        return ['media'];
    }
}
