<?php

namespace Cesurapp\MediaBundle\Tests;

use Cesurapp\MediaBundle\MediaBundle;
use Cesurapp\StorageBundle\StorageBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * Create App Test Kernel.
 */
class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DoctrineBundle(),
            new StorageBundle(),
            new MediaBundle(),
        ];
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
        ]);

        // Storage Bundle Default Configuration
        $container->extension('storage', [
            'default' => 'main',
            'devices' => [
                'main' => [
                    'driver' => 'local',
                    'root' => '%kernel.project_dir%/var',
                ],
            ],
        ]);

        // Doctrine Bundle Default Configuration
        $container->extension('doctrine', [
            'dbal' => [
                'default_connection' => 'default',
                'url' => 'sqlite:///%kernel.project_dir%/var/database.sqlite',
            ],
            'orm' => [
                'auto_generate_proxy_classes' => false,
                'enable_lazy_ghost_objects' => true,
                'report_fields_where_declared' => true,
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
                'auto_mapping' => true,
                'controller_resolver' => [
                    'auto_mapping' => false,
                ],
            ],
        ]);
    }
}
