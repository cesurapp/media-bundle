<?php

namespace Cesurapp\MediaBundle\EventListener;

use Cesurapp\StorageBundle\Storage\Storage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Events;
use Cesurapp\MediaBundle\Entity\Media;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Deletes a media's object from storage once its row is gone for good.
 *
 * - preRemove: read the object location while the row still exists. A media taken from a media
 *   column is an unloaded reference, and Doctrine only loads it on remove() when the entity has
 *   cascade-remove associations, which Media has not. Read on postRemove it would fail to load.
 * - postRemove: the DELETE ran, but inside the flush transaction; deleting the object here would
 *   lose it on rollback, so it is only queued.
 * - postFlush: once no transaction is open, drop the objects whose rows no longer exist. A queue
 *   left behind by an explicit transaction is drained on the next flush or on kernel reset.
 */
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: Media::class)]
#[AsEntityListener(event: Events::postRemove, method: 'postRemove', entity: Media::class)]
#[AsDoctrineListener(event: Events::postFlush)]
class MediaRemovedListener implements ResetInterface
{
    /**
     * Object location captured on preRemove. Weak, so a remove() that is never flushed does not
     * keep the entity alive in a long-running worker.
     *
     * @var \WeakMap<Media, array{path: string, storage: string, private: bool}>
     */
    private \WeakMap $captured;

    /**
     * @var array<string, array{id: Uuid, path: string, storage: string, private: bool}>
     */
    private array $queue = [];

    private ?EntityManagerInterface $em = null;

    public function __construct(private readonly Storage $storage, private readonly LoggerInterface $logger)
    {
        $this->captured = new \WeakMap();
    }

    public function preRemove(Media $media, PreRemoveEventArgs $event): void
    {
        $this->captured[$media] = [
            'path' => $media->getPath(),
            'storage' => $media->getStorage(),
            'private' => $media->isPrivate(),
        ];
    }

    public function postRemove(Media $media, PostRemoveEventArgs $event): void
    {
        if (!isset($this->captured[$media])) {
            return;
        }

        $this->queue[$media->getId()->toRfc4122()] = ['id' => $media->getId(), ...$this->captured[$media]];
        $this->em = $event->getObjectManager();
        unset($this->captured[$media]);
    }

    public function postFlush(PostFlushEventArgs $event): void
    {
        if ($event->getObjectManager() === $this->em) {
            $this->process($this->em);
        }
    }

    public function reset(): void
    {
        if ($this->em) {
            $this->process($this->em);
        }

        $this->captured = new \WeakMap();
        $this->queue = [];
        $this->em = null;
    }

    private function process(EntityManagerInterface $em): void
    {
        // A flush nested in a lifecycle callback or run inside an explicit transaction is not
        // committed yet; the outermost flush handles the queue.
        if (!$this->queue || $em->getConnection()->isTransactionActive()) {
            return;
        }

        $queue = $this->queue;
        $this->queue = [];

        try {
            // A rolled-back transaction restores the row: keep its object.
            foreach ($this->existingIds($em, array_column($queue, 'id')) as $id) {
                unset($queue[$id]);
            }
        } catch (\Throwable $exception) {
            // Without knowing whether the rows survived, an orphaned object beats a lost one.
            $this->logger->error('Media File Remove Skipped: '.$exception->getMessage());

            return;
        }

        foreach ($queue as $item) {
            $this->deleteObject($item['path'], $item['storage'], $item['private']);
        }
    }

    /**
     * @param Uuid[] $ids
     *
     * @return string[] RFC 4122 ids of the rows still present
     */
    private function existingIds(EntityManagerInterface $em, array $ids): array
    {
        $metadata = $em->getClassMetadata(Media::class);
        $connection = $em->getConnection();
        $type = Type::getType($metadata->getTypeOfField('id') ?? 'uuid');
        $platform = $connection->getDatabasePlatform();
        $quote = $em->getConfiguration()->getQuoteStrategy();
        $column = $quote->getColumnName('id', $metadata, $platform);

        $values = array_map(static fn (Uuid $id) => $type->convertToDatabaseValue($id, $platform), $ids);

        $rows = $connection->executeQuery(
            sprintf('SELECT %1$s FROM %2$s WHERE %1$s IN (?)', $column, $quote->getTableName($metadata, $platform)),
            [$values],
            [ParameterType::BINARY === $type->getBindingType() ? ArrayParameterType::BINARY : ArrayParameterType::STRING],
        )->fetchFirstColumn();

        return array_map(static fn ($value) => $type->convertToPHPValue($value, $platform)->toRfc4122(), $rows);
    }

    private function deleteObject(string $path, string $store, bool $private): void
    {
        $device = $this->storage->device($store);
        if ($private) {
            $device = $device->private();
        }

        $delete = function () use ($device, $path, $store) {
            try {
                if (!$device->delete($path)) {
                    $this->logger->error('Media File Remove Failed: '.$store.'::'.$path);
                }
            } catch (\Throwable $exception) {
                $this->logger->error('Media File Remove Failed: '.$store.'::'.$path.' '.$exception->getMessage());
            }
        };

        if (function_exists('go')) {
            go($delete);
        } else {
            $delete();
        }
    }
}
