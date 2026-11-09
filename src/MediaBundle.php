<?php

namespace Cesurapp\MediaBundle;

use Doctrine\DBAL\Types\Type;
use Cesurapp\MediaBundle\Manager\MediaManager;
use Cesurapp\MediaBundle\Type\MediaType;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
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
        $manager = $services->set(MediaManager::class, MediaManager::class)->arg('$proxy', $config['proxy']);
        if ('test' === $container->env()) {
            $manager->public();
        }
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('proxy')
                    ->defaultNull()
                    ->info('Forward proxy link downloads leave through (http://host:port), so the linked server sees its address; null = direct.')
                ->end()
            ->end();
    }
}
