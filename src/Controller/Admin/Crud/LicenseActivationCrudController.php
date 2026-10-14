<?php

namespace Base\Forge\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\AssociationField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\TextField;
use Base\Forge\Entity\LicenseActivation;

/**
 * The machines the licensed software is activated on, seat by seat: made by
 * the software itself (the licence API), freed here by deleting - its token
 * runs out, and it must ask again. To give more places, raise the licence's
 * machines per seat.
 */
class LicenseActivationCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return LicenseActivation::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-laptop';
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)->disable(Action::NEW, Action::EDIT);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('seat', 'Poste');
        yield TextField::new('name', 'Machine')->setRequired(false);
        yield TextField::new('platform', 'Plateforme')->setRequired(false);
        yield TextField::new('version', 'Version')->setRequired(false);
        yield DateTimeField::new('activatedAt', 'Activée le');
        yield DateTimeField::new('lastSeenAt', 'Vue le');
    }
}
