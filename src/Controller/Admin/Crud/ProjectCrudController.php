<?php

namespace Base\Forge\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\AssociationField;
use Base\Field\DateField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\TextField;
use Base\Forge\Entity\Project;
use Base\Forge\Enum\ProjectStatus;
use Symfony\Component\Form\Extension\Core\Type\EnumType;

/** Client projects: who, which repository, how many hours agreed. */
class ProjectCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Project::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-diagram-project';
    }

    public function configureActions(Actions $actions): Actions
    {
        // Delivered: the project closes and its client is asked for a review.
        $review = Action::new('forgeReview', 'Demander un avis', 'fa-solid fa-star')
            ->linkToRoute('forge_admin_project_review', fn (Project $project) => ['id' => $project->getId(), '_token' => $this->actionToken('forge_project_review', $project->getId())])
            ->displayIf(fn (Project $project) => null !== $project->getClient());

        return parent::configureActions($actions)->add(Action::INDEX, $review)->add(Action::DETAIL, $review);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('title')->setColumns(6);
        yield AssociationField::new('client')->setColumns(6);
        yield TextField::new('excerpt', 'Summary')->setRequired(false)->setColumns(12)->hideOnIndex();
        yield TextField::new('status')->setColumns(3)
            ->setFormType(EnumType::class)->setFormTypeOptions(['class' => ProjectStatus::class])
            ->formatValue(fn ($value) => $value instanceof ProjectStatus ? $value->value : $value);
        yield IntegerField::new('budgetMinutes', 'Budget (minutes)')->setRequired(false)->setColumns(3);
        yield DateField::new('dueOn')->setRequired(false)->setColumns(3);
        yield TextField::new('gitRepository', 'Git repository')->setRequired(false)->setColumns(4)->setHelp('git-bundle repository name');
        yield TextField::new('repositoryUrl')->setRequired(false)->setColumns(8)->hideOnIndex()->setHelp('Cloned by git:sync when not in git.repositories');
    }

    /** A token in the action's link, as for a logout: a link from elsewhere, a prefetch, does nothing (ActionController checks it). */
    private function actionToken(string $action, ?int $id): string
    {
        return $this->container->get('security.csrf.token_manager')->getToken($action.'_'.$id)->getValue();
    }
}
