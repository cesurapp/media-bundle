<?php

namespace Cesurapp\MediaBundle\Factory;

use Cesurapp\MediaBundle\Entity\Media;
use Cesurapp\MediaBundle\Manager\MediaManager;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<Media>
 */
final class MediaFactory extends PersistentProxyObjectFactory
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

    public static function class(): string
    {
        return Media::class;
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#model-factories
     *
     * @todo add your default values here
     */
    protected function defaults(): array
    {
        $content = file_get_contents(__DIR__.'/../../tests/resources/image.png');
        $media = $this->mediaManager
            ->setImageCompress(false)
            ->setImageConvertJPG(false)
            ->createMedia('image/png', 'png', $content, strlen($content));
        $this->mediaManager->save($media);

        return [
            'counter' => 1,
            'mime' => $media->getMime(),
            'path' => $media->getPath(),
            'size' => $media->getSize(),
            'storage' => $media->getStorage(),
        ];
    }
}
