<?php

namespace Cesurapp\MediaBundle\Entity;

use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Mapping\PreRemove;

abstract class MediaSuperClass
{
    abstract public function getMediaColumns(): array;

    /**
     * Schedules the owned media for removal in the same flush as the owner. Flushing from a
     * lifecycle callback is not supported by Doctrine, so nothing is flushed here.
     */
    #[PreRemove]
    public function preRemoveMedia(PreRemoveEventArgs $args): void
    {
        $em = $args->getObjectManager();
        foreach ($this->getMediaColumns() as $column) {
            $getter = 'get'.ucfirst($column);
            if (!method_exists($this, $getter)) {
                continue;
            }

            foreach ($this->$getter() ?? [] as $media) {
                try {
                    $em->remove($media);
                } catch (EntityNotFoundException) {
                    // The row is already gone, the reference was stale.
                }
            }
        }
    }
}
