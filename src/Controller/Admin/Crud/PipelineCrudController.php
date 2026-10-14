<?php

namespace Base\Forge\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\AssociationField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\TextField;
use Base\Forge\Entity\Pipeline;
use Base\Forge\Enum\PipelineStatus;
use Symfony\Component\Form\Extension\Core\Type\EnumType;

/** The pipelines recorded: which project, which commit, where they ran - and each as its graph. */
class PipelineCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Pipeline::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-diagram-next';
    }

    public function configureActions(Actions $actions): Actions
    {
        $graph = Action::new('forgePipelineGraph', 'Graphe', 'fa-solid fa-diagram-project')
            ->linkToRoute('forge_admin_pipeline', fn (Pipeline $pipeline) => ['id' => $pipeline->getId()]);

        return parent::configureActions($actions)->add(Action::INDEX, $graph)->add(Action::DETAIL, $graph);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('project')->setRequired(false)->setColumns(4);
        yield TextField::new('ref', 'Branch or tag')->setRequired(false)->setColumns(4);
        yield TextField::new('commitSha', 'Commit')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield TextField::new('status')->setColumns(3)
            ->setFormType(EnumType::class)->setFormTypeOptions(['class' => PipelineStatus::class, 'required' => false])
            ->setHelp('Empty: what its stages say')
            ->formatValue(fn ($value) => $value instanceof PipelineStatus ? $value->value : $value);
        yield TextField::new('url')->setRequired(false)->setColumns(9)->hideOnIndex();
        yield DateTimeField::new('createdAt')->hideOnForm();
    }
}
