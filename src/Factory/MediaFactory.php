<?php

namespace Cesurapp\MediaBundle\Factory;

use Cesurapp\MediaBundle\Entity\Media;
use Cesurapp\MediaBundle\Manager\MediaManager;
use Cesurapp\MediaBundle\Repository\MediaRepository;
use Zenstruck\Foundry\ModelFactory;
use Zenstruck\Foundry\Proxy;
use Zenstruck\Foundry\RepositoryProxy;

/**
 * @extends ModelFactory<Media>
 *
 * @method        Media|Proxy                     create(array|callable $attributes = [])
 * @method static Media|Proxy                     createOne(array $attributes = [])
 * @method static Media|Proxy                     find(object|array|mixed $criteria)
 * @method static Media|Proxy                     findOrCreate(array $attributes)
 * @method static Media|Proxy                     first(string $sortedField = 'id')
 * @method static Media|Proxy                     last(string $sortedField = 'id')
 * @method static Media|Proxy                     random(array $attributes = [])
 * @method static Media|Proxy                     randomOrCreate(array $attributes = [])
 * @method static MediaRepository|RepositoryProxy repository()
 * @method static Media[]|Proxy[]                 all()
 * @method static Media[]|Proxy[]                 createMany(int $number, array|callable $attributes = [])
 * @method static Media[]|Proxy[]                 createSequence(iterable|callable $sequence)
 * @method static Media[]|Proxy[]                 findBy(array $attributes)
 * @method static Media[]|Proxy[]                 randomRange(int $min, int $max, array $attributes = [])
 * @method static Media[]|Proxy[]                 randomSet(int $number, array $attributes = [])
 */
final class MediaFactory extends ModelFactory
{
    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#factories-as-services
     *
     * @todo inject services if required
     */
    public function __construct(private readonly MediaManager $mediaManager)
    {
        parent::__construct();
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#model-factories
     *
     * @todo add your default values here
     */
    protected function getDefaults(): array
    {
        $content = file_get_contents(__DIR__.'/../../tests/resources/image.png');
        $media = $this->mediaManager
            ->setImageCompress(false)
            ->setImageConvertJPG(false)
            ->createMedia('image/png', 'png', $content, strlen($content), false);

        return [
            'approved' => true,
            'counter' => 1,
            'mime' => $media->getMime(),
            'path' => $media->getPath(),
            'size' => $media->getSize(),
            'storage' => $media->getStorage(),
        ];
    }

    protected static function getClass(): string
    {
        return Media::class;
    }
}
