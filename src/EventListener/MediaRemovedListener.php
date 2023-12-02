<?php

namespace Cesurapp\MediaBundle\EventListener;

use Cesurapp\StorageBundle\Storage\Storage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Cesurapp\MediaBundle\Entity\Media;
use Psr\Log\LoggerInterface;

#[AsEntityListener(event: Events::postRemove, method: 'postRemove', entity: Media::class)]
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
