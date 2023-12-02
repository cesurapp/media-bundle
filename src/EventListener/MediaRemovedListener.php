<?php

namespace Cesurapp\MediaBundle\EventListener;

use Cesurapp\StorageBundle\Storage\Storage;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Cesurapp\MediaBundle\Entity\Media;
use Psr\Log\LoggerInterface;

readonly class MediaRemovedListener
{
    public function __construct(private Storage $storage, private LoggerInterface $logger)
    {
    }

    public function postRemove(Media $media, LifecycleEventArgs $event): void
    {
        if (!$this->storage->device($media->getStorage())->delete($media->getPath())) {
            $this->logger->error('Media File Remove Failed: '.$media->getStorage().'::'.$media->getPath());
        }
    }
}
