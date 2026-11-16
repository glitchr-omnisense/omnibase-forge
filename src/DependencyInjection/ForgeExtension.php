<?php

namespace Base\Forge\DependencyInjection;

use Base\Bundle\AbstractBaseExtension;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class ForgeExtension extends AbstractBaseExtension
{
    public function getConfiguration(array $config, ContainerBuilder $container): ForgeConfiguration
    {
        return new ForgeConfiguration();
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.php');

        $configuration = new ForgeConfiguration();
        $config = (new Processor())->processConfiguration($configuration, $configs);

        // forge.storage_dir, forge.download_ttl, forge.hourly_rate...
        $this->setConfiguration($container, $config, $configuration->getConfigTreeBuilder()->buildTree()->getName());
    }
}
