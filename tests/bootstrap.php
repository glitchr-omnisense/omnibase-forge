<?php

// Standalone (composer install in this checkout) or inside a host application
// (vendor/omnibase/forge): whichever autoloader exists is used,
// and the test namespace is registered by hand because a host's autoloader
// never reads a dependency's autoload-dev.
$candidates = [__DIR__.'/../vendor/autoload.php', __DIR__.'/../../../autoload.php'];
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $loader = require $candidate;
        $loader->addPsr4('Tests\\Base\\Forge\\', __DIR__);
        // Prepended: the classes under test are this checkout's, even when a
        // host application has an installed copy of the bundle too.
        $loader->addPsr4('Base\\Forge\\', __DIR__.'/../src', true);

        // Its own classes (prepended: this checkout's, whatever the host installs)
        // and those of the omnibase/* bundles it requires or suggests, read beside this checkout
        // (the workspace, the harness's vendor/omnibase/) after the host's own: the
        // omnibase harness installs only some of them.
        $composer = static fn (string $directory): array => json_decode((string) file_get_contents($directory.'/composer.json'), true) ?: [];
        foreach ($composer(__DIR__.'/..')['autoload']['psr-4'] ?? [] as $namespace => $path) {
            $loader->addPsr4($namespace, __DIR__.'/../'.$path, true);
        }
        $required = array_keys(($composer(__DIR__.'/..')['require'] ?? []) + ($composer(__DIR__.'/..')['suggest'] ?? []));
        for ($seen = []; null !== $package = array_shift($required);) {
            $sibling = __DIR__.'/../../'.substr($package, \strlen('omnibase/'));
            if (!str_starts_with($package, 'omnibase/') || isset($seen[$package]) || !is_file($sibling.'/composer.json')) {
                continue;
            }
            $seen[$package] = true;
            foreach ($composer($sibling)['autoload']['psr-4'] ?? [] as $namespace => $path) {
                $loader->addPsr4($namespace, $sibling.'/'.$path);
            }
            array_push($required, ...array_keys(($composer($sibling)['require'] ?? []) + ($composer($sibling)['suggest'] ?? [])));
        }

        return;
    }
}

throw new RuntimeException('No autoloader found: run composer install in this checkout or install the bundle in an application.');
