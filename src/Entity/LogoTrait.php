<?php

namespace Cesurapp\MediaBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

trait LogoTrait
{
    #[ORM\Column(type: 'media', nullable: true)]
    private ?array $logo = [];

    /**
     * @return Media[]|null
     */
    public function getLogo(): ?array
    {
        return $this->logo;
    }

    public function addLogo(Media $logo): self
    {
        if (!in_array($logo, $this->logo, true)) {
            $this->logo[] = $logo;
        }

        return $this;
    }

    public function addLogos(array $logos): self
    {
        array_walk_recursive($logos, fn (Media $logo) => $this->addLogo($logo));

        return $this;
    }

    public function removeLogo(Media $logo): self
    {
        if ($key = array_search($logo, $this->logo, true)) {
            unset($this->logo[$key]);
        }

        return $this;
    }

    public function setLogo(array $logos): self
    {
        $this->logo = [];

        array_walk_recursive($logos, fn (Media $logo) => $this->logo[] = $logo);

        return $this;
    }

    public function getLogoFirst(): ?Media
    {
        return $this->logo[array_key_first($this->logo)] ?? null;
    }
}
