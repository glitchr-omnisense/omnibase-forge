<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

/*
 * This file is part of the Glitchr package.
 *
 * (c) Marco Meyer <marco.meyer@glitchr.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Plain autowiring over src/, as base-bundle-market does. Entities, enums,
 * models and the backends' statements are not services; the admin CRUD controllers are loaded only when
 * base-bundle-admin is there.
 */
return function (ContainerConfigurator $configurator) {

    $src = dirname(__DIR__).'/src';

    $services = $configurator->services();
    $services->defaults()
        ->autowire(true)
        ->autoconfigure(true)
        ->public(false);

    $services->load('Base\\Forge\\', $src.'/')
        ->exclude([
            $src.'/DependencyInjection/',
            $src.'/Entity/',
            $src.'/Enum/',
            $src.'/Model/',
            $src.'/Exception/',
            $src.'/Controller/Admin/',
            $src.'/ForgeBundle.php',
        ]);

    $services->load('Base\\Forge\\Controller\\Client\\', $src.'/Controller/Client/')
        ->tag('controller.service_arguments');

    if (class_exists('Base\\Admin\\Controller\\AbstractCrudController') && is_dir($src.'/Controller/Admin')) {
        $services->load('Base\\Forge\\Controller\\Admin\\', $src.'/Controller/Admin/')
            ->tag('controller.service_arguments');
    }
};
