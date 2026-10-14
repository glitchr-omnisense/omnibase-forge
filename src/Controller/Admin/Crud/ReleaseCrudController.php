<?php

namespace Base\Forge\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\AssociationField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Forge\Entity\Release;

/** Releases: made from tags by forge:release:sync, or by hand; "Build" (re)makes the zip. */
class ReleaseCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Release::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-tag';
    }

    public function configureActions(Actions $actions): Actions
    {
        $build = Action::new('forgeBuild', 'Build', 'fa-solid fa-file-zipper')
            ->linkToRoute('forge_admin_release_build', fn (Release $release) => ['id' => $release->getId(), '_token' => $this->actionToken('forge_release_build', $release->getId())]);

        return parent::configureActions($actions)->add(Action::INDEX, $build)->add(Action::DETAIL, $build);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('software')->setColumns(4);
        yield TextField::new('version')->setColumns(2);
        yield TextField::new('tag')->setRequired(false)->setColumns(3);
        yield TextField::new('commitSha')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield DateTimeField::new('publishedAt')->setRequired(false)->setColumns(4);
        yield TextareaField::new('changelog')->setRequired(false)->hideOnIndex();
    }

    /** A token in the action's link, as for a logout: a link from elsewhere, a prefetch, does nothing (ActionController checks it). */
    private function actionToken(string $action, ?int $id): string
    {
        return $this->container->get('security.csrf.token_manager')->getToken($action.'_'.$id)->getValue();
    }
}
