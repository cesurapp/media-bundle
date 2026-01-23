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
                // Keep a minimal, compatible ORM configuration for tests.
                'auto_mapping' => true,
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
                'mappings' => [
                    'MediaBundle' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => '%kernel.project_dir%/src/Entity',
                        'prefix' => 'Cesurapp\MediaBundle\Entity',
                        'alias' => 'MediaBundle',
                    ],
                    'MediaBundleTests' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => '%kernel.project_dir%/tests/Entity',
                        'prefix' => 'Cesurapp\MediaBundle\Tests\Entity',
                        'alias' => 'MediaBundleTests',
                    ],
                ],
            ],
        ]);
    }
}
