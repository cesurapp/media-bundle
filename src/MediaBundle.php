<?php

namespace Cesurapp\MediaBundle;

use Doctrine\DBAL\Types\Type;
use Cesurapp\MediaBundle\Entity\Media;
use Cesurapp\MediaBundle\Manager\MediaManager;
use Cesurapp\MediaBundle\Type\MediaType;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class MediaBundle extends AbstractBundle
{
    public function __construct()
    {
        if (!Type::getTypeRegistry()->has('media')) {
            Type::getTypeRegistry()->register('media', new MediaType());
        }
    }

    public function boot(): void
    {
        /** @var MediaType $type */
        $type = Type::getType('media');
        $type->setEntityManager($this->container->get('doctrine')->getManager());
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Set Autoconfigure
        $services = $container->services()->defaults()->autowire()->autoconfigure();

        $services->load('Cesurapp\\MediaBundle\\EventListener\\', 'EventListener');
        $services->load('Cesurapp\\MediaBundle\\Command\\', 'Command');
        $services->load('Cesurapp\\MediaBundle\\Repository\\', 'Repository');

        // Media Manager
        $manager = $services->set(MediaManager::class, MediaManager::class);
        if ('test' === $container->env()) {
            $manager->public();
        }
    }
}
