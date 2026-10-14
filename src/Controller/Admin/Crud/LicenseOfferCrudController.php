<?php

namespace Base\Forge\Controller\Admin\Crud;

use Base\Field\AssociationField;
use Base\Field\IntegerField;
use Base\Forge\Entity\Product\LicenseOffer;
use Base\Marketplace\Controller\Admin\Crud\ProductCrudController;

/** Licence offers: a market product that issues a licence of its software per unit paid. */
class LicenseOfferCrudController extends ProductCrudController
{
    public static function getEntityFqcn(): string
    {
        return LicenseOffer::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-certificate';
    }

    public function configureFields(string $pageName): iterable
    {
        yield from parent::configureFields($pageName);
        yield AssociationField::new('software')->setRequired(false)->setColumns(6);
        yield IntegerField::new('seats')->setColumns(2);
        yield IntegerField::new('machinesPerSeat', 'Machines per seat')->setRequired(false)->setColumns(2)->setHelp('On how many computers each seat may activate it');
        yield IntegerField::new('durationMonths')->setRequired(false)->setColumns(2)->setHelp('Empty: perpetual');
        yield IntegerField::new('updatesMonths')->setRequired(false)->setColumns(2)->setHelp('Empty: every future release');
    }
}
