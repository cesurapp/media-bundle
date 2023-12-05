<?php

namespace Cesurapp\MediaBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

trait LogoCoverTrait
{
    #[ORM\Column(type: 'media', nullable: true)]
    private ?array $logoCover = [];

    /**
     * @return Media[]|null
     */
    public function getLogoCover(): ?array
    {
        return $this->logoCover;
    }

    public function addLogoCover(Media $logoCover): self
    {
        if (!in_array($logoCover, $this->logoCover, true)) {
            $this->logoCover[] = $logoCover;
        }

        return $this;
    }

    public function addLogoCovers(array $logoCovers): self
    {
        array_walk_recursive($logoCovers, fn (Media $logoCovers) => $this->addLogoCover($logoCovers));

        return $this;
    }

    public function removeLogoCover(Media $logoCover): self
    {
        if ($key = array_search($logoCover, $this->logoCover, true)) {
            unset($this->logoCover[$key]);
        }

        return $this;
    }

    public function setLogoCover(array $logoCovers): self
    {
        $this->logoCover = [];

        array_walk_recursive($logoCovers, fn (Media $logoCovers) => $this->logoCover[] = $logoCovers);

        return $this;
    }

    public function getLogoCoverFirst(): ?Media
    {
        return $this->logoCover[array_key_first($this->logoCover)] ?? null;
    }
}
