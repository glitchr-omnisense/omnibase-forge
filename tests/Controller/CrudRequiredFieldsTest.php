<?php

namespace Tests\Base\Forge\Controller;

use Base\Admin\Test\CrudFieldsTestCase;
use Base\Forge\Controller\Admin\Crud\HourPackCrudController;
use Base\Forge\Controller\Admin\Crud\LicenseOfferCrudController;

/**
 * The fields marked required on this bundle's back office screens, against
 * what its records need (see CrudFieldsTestCase): only those the record
 * cannot do without, all of them, an optional one able to take an empty
 * value, one field per property, a slug following its title.
 */
final class CrudRequiredFieldsTest extends CrudFieldsTestCase
{
    protected static function sourceDirectory(): string
    {
        return __DIR__.'/../../src';
    }

    protected static function requiredByTheScreen(): array
    {
        return [
            HourPackCrudController::class => [
                'parent' => "a product is sold in a store: its page, its basket and its orders are the store's",
            ],
            LicenseOfferCrudController::class => [
                'parent' => "a product is sold in a store: its page, its basket and its orders are the store's",
            ],
        ];
    }
}
