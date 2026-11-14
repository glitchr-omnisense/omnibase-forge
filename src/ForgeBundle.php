<?php

namespace Base\Forge;

use Base\Traits\SingletonTrait;
use Omnibridge\Symfony\Omnibase\Bundle\AbstractBaseBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The studio's forge on top of base-bundle-market: software and its releases
 * (built from git tags through git/git-bundle), free and licensed downloads,
 * a private Composer repository, client projects with a shared board and
 * logged time, prepaid support hours and quotes.
 */
class ForgeBundle extends AbstractBaseBundle
{
    // Own singleton storage rather than sharing AbstractBaseBundle's - see
    // that class's constructor for why every concrete bundle needs this.
    use SingletonTrait;

    // The trait's protected no-op __construct() would hide the inherited
    // one; redeclared public, as MarketplaceBundle and AdminBundle do.
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Modern bundle layout: the class lives in src/, the bundle root is the
     * package root - so TwigBundle picks up ./templates as @Forge and
     * Doctrine's auto_mapping ./src/Entity.
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // base-bundle's App\-wins convention, as base-bundle-market's: every
        // Base\Forge\Entity\* is aliased onto App\Entity\Forge\* unless the
        // application declares a real class there - which is how an app
        // extends a forge entity (its own Software fields, say) without
        // touching the bundle.
        $this->setMapping($this->getPath().'/src/Entity', 'Base\Forge\Entity', 'App\Entity\Forge');
        $this->setMapping($this->getPath().'/src/Repository', 'Base\Forge\Repository', 'App\Repository\Forge');
    }
}
