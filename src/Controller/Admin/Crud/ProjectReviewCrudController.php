<?php

namespace Base\Forge\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Forge\Entity\ProjectReview;

/**
 * The clients' reviews of their delivered projects: asked from the
 * projects ("Demander un avis"), given by the client. Tick "Affiché" to
 * show one on the site; the signature can be shortened, the words are the
 * client's.
 */
class ProjectReviewCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ProjectReview::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-star';
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)->disable(Action::NEW);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('project', 'Projet')->setColumns(6)->setDisabled();
        yield IntegerField::new('rating', 'Note /5')->setColumns(2)->setDisabled();
        yield BooleanField::new('published', 'Affiché')->setColumns(4)->setHelp('Sur le site, dans « Ce qu\'en disent nos clients ».');
        yield TextField::new('signature', 'Signé')->setRequired(false)->setColumns(6);
        yield TextareaField::new('comment', 'Leur avis')->setColumns(12)->setDisabled();
        yield DateTimeField::new('requestedAt', 'Demandé le')->onlyOnIndex();
        yield DateTimeField::new('submittedAt', 'Donné le')->onlyOnIndex();
    }
}
