<?php

namespace Base\Forge\Controller\Admin\Crud;

use Base\Admin\Controller\AbstractCrudController;
use Base\Field\AssociationField;
use Base\Field\IdField;
use Base\Field\ImageField;
use Base\Field\IntegerField;
use Base\Field\SlugField;
use Base\Field\StateField;
use Base\Field\WysiwygField;
use Base\Field\TextField;
use Base\Forge\Entity\Software;
use Base\Forge\Enum\Pricing;
use Base\Forge\Enum\SoftwareStatus;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;

/**
 * The software (base-bundle threads: translated title, tagline and
 * description; published, they are on the applications page).
 */
class SoftwareCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Software::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-cubes';
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('title')->setColumns(6);
        yield SlugField::new('slug')->setTargetFieldName('title')->setColumns(3)->hideOnIndex();
        yield StateField::new('state')->setColumns(3);
        yield TextField::new('headline', 'Tagline')->setRequired(false)->setColumns(12)->hideOnIndex();
        yield TextField::new('category')->setColumns(3)
            ->setFormType(ChoiceType::class)
            ->setFormTypeOptions(['choices' => array_combine(Software::CATEGORIES, Software::CATEGORIES)]);
        yield TextField::new('status')->setColumns(3)
            ->setFormType(EnumType::class)->setFormTypeOptions(['class' => SoftwareStatus::class])
            ->formatValue(fn ($value) => $value instanceof SoftwareStatus ? $value->value : $value);
        yield TextField::new('pricing')->setColumns(3)
            ->setFormType(EnumType::class)->setFormTypeOptions(['class' => Pricing::class])
            ->formatValue(fn ($value) => $value instanceof Pricing ? $value->value : $value);
        yield TextField::new('year')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield WysiwygField::new('content', 'Description')->hideOnIndex();
        yield TextField::new('stackAsText', 'Stack')->setRequired(false)->setColumns(6)->hideOnIndex()->setHelp('Comma separated: Symfony, TransparentJS, Stripe');
        yield ImageField::new('image')->setColumns(6)->hideOnIndex()->setRequired(false)->setHelp('The card\'s picture (an older path under the public assets, or a URL, still shows)');
        yield TextField::new('homepage')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield TextField::new('sourceUrl')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield TextField::new('demoUrl')->setRequired(false)->setColumns(4)->hideOnIndex()->setHelp('Opened in a nested panel from the applications page');
        yield TextField::new('gitRepository', 'Git repository')->setRequired(false)->setColumns(4)->setHelp('The git-bundle repository name (releases are built from its tags)');
        yield TextField::new('repositoryUrl')->setRequired(false)->setColumns(4)->hideOnIndex()->setHelp('Cloned into forge.repositories_dir when not in git.repositories');
        yield TextField::new('packageName')->setRequired(false)->setColumns(4)->hideOnIndex()->setHelp('vendor/name, to serve it from the Composer repository');
        yield AssociationField::new('parent', 'Famille')->setClass(Software::class)->setColumns(3)->setRequired(false)->setHelp('Listed under it on the site (base-bundle-admin under base-bundle)');
        yield IntegerField::new('position')->setColumns(3);
    }
}
