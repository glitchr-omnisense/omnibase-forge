<?php

namespace Base\Forge\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\AssociationField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\TextField;
use Base\Forge\Entity\Download;

/**
 * Who downloaded what, when, through which licence and which way (the site
 * or Composer): the log the downloads write themselves - to read, not to
 * edit.
 */
class DownloadCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Download::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-download';
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)->disable(Action::NEW, Action::EDIT);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield DateTimeField::new('downloadedAt', 'Quand');
        yield AssociationField::new('artifact', 'Fichier');
        yield AssociationField::new('user', 'Qui')->setRequired(false);
        yield AssociationField::new('license', 'Licence')->setRequired(false);
        yield TextField::new('channel', 'Par');
    }
}
