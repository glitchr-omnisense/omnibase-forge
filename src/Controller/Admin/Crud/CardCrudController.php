<?php

namespace Base\Forge\Controller\Admin\Crud;

use Base\Admin\Controller\AbstractCrudController;
use Base\Field\AssociationField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Forge\Entity\Card;
use Base\Forge\Enum\CardColumn;
use Symfony\Component\Form\Extension\Core\Type\EnumType;

/** The cards of the project boards (the clients move them from their dashboard). */
class CardCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Card::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-note-sticky';
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('project')->setColumns(4);
        yield TextField::new('title')->setColumns(8);
        yield TextField::new('column')->setColumns(3)
            ->setFormType(EnumType::class)->setFormTypeOptions(['class' => CardColumn::class])
            ->formatValue(fn ($value) => $value instanceof CardColumn ? $value->value : $value);
        yield IntegerField::new('position')->setColumns(2);
        yield TextField::new('label')->setRequired(false)->setColumns(3);
        yield TextareaField::new('description')->setRequired(false)->hideOnIndex();
    }
}
