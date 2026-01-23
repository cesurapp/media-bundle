<?php

namespace Cesurapp\MediaBundle\Entity;

use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Mapping\PostRemove;

abstract class MediaSuperClass
{
    abstract public function getMediaColumns(): array;

    #[PostRemove]
    public function postRemoveMedia(PostRemoveEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $flush = false;
        foreach ($this->getMediaColumns() as $column) {
            $getter = 'get'.ucfirst($column);
            if (!method_exists($this, $getter)) {
                continue;
            }

            $mediaIds = $this->$getter(); // array<string, Media> (UUID => Media proxy)
            if (!$mediaIds) {
                continue;
            }

            foreach ($mediaIds as $mediaId) {
                $em->remove($mediaId);
                $flush = true;
            }
        }

        if ($flush) {
            $em->flush();
        }
    }
}
