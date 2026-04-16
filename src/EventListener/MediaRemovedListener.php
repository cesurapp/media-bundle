<?php

namespace Cesurapp\MediaBundle\EventListener;

use Cesurapp\StorageBundle\Storage\Storage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Events;
use Cesurapp\MediaBundle\Entity\Media;
use Psr\Log\LoggerInterface;

#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: Media::class)]
#[AsEntityListener(event: Events::postRemove, method: 'postRemove', entity: Media::class)]
class MediaRemovedListener
{
    private \SplObjectStorage $pendingRemovals;

    public function __construct(private readonly Storage $storage, private readonly LoggerInterface $logger)
    {
        $this->pendingRemovals = new \SplObjectStorage();
    }

    public function preRemove(Media $media, PreRemoveEventArgs $event): void
    {
        $this->pendingRemovals->attach($media, [
            'path'    => $media->getPath(),
            'storage' => $media->getStorage(),
        ]);
    }

    public function postRemove(Media $media, PostRemoveEventArgs $event): void
    {
        if (!$this->pendingRemovals->contains($media)) {
            return;
        }

        $data  = $this->pendingRemovals[$media];
        $this->pendingRemovals->detach($media);

        $path    = $data['path'];
        $store   = $data['storage'];

        if (function_exists('go')) {
            go(function () use ($path, $store) {
                if (!$this->storage->device($store)->delete($path)) {
                    $this->logger->error('Media File Remove Failed: '.$store.'::'.$path);
                }
            });
        } else {
            if (!$this->storage->device($store)->delete($path)) {
                $this->logger->error('Media File Remove Failed: '.$store.'::'.$path);
            }
        }
    }
}