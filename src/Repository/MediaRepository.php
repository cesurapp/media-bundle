<?php

namespace Cesurapp\MediaBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Cesurapp\MediaBundle\Entity\Media;

class MediaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Media::class);
    }

    /**
     * Media columns hold lazy references, so reading N of them costs N queries. Loading them here
     * first initializes every reference with a single query.
     *
     * Accepts media or (nested) iterables of media, e.g. the values of media column getters:
     * `$repo->preload(array_map(fn (User $u) => $u->getAvatar(), $users))`.
     */
    public function preload(iterable $medias): void
    {
        $uow = $this->getEntityManager()->getUnitOfWork();
        $ids = [];

        $collect = static function (mixed $item) use (&$collect, &$ids, $uow): void {
            if (is_iterable($item)) {
                foreach ($item as $child) {
                    $collect($child);
                }

                return;
            }

            if ($item instanceof Media && $uow->isUninitializedObject($item)) {
                $id = $uow->getEntityIdentifier($item)['id'];
                $ids[(string) $id] = $id;
            }
        };
        $collect($medias);

        if ($ids) {
            $this->findBy(['id' => array_values($ids)]);
        }
    }
}
