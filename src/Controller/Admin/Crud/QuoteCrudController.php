<?php

namespace Base\Forge\Controller\Admin\Crud;

use Doctrine\ORM\EntityManagerInterface;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\AssociationField;
use Base\Field\CollectionField;
use Base\Field\DateField;
use Base\Field\DateTimeField;
use Base\Field\EmailField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\TextareaField;
use Base\Field\TextField;
use Base\Forge\Entity\Quote;
use Base\Forge\Enum\QuoteStatus;
use Base\Forge\Form\QuoteLineType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;

/**
 * Quotes and special offers: requests arrive from the site; price the lines,
 * set a discount and a date, then "Send" mails the client their link.
 */
class QuoteCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Quote::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-file-invoice';
    }

    public function configureActions(Actions $actions): Actions
    {
        $send = Action::new('forgeSend', 'Send', 'fa-solid fa-paper-plane')
            ->linkToRoute('forge_admin_quote_send', fn (Quote $quote) => ['id' => $quote->getId(), '_token' => $this->actionToken('forge_quote_send', $quote->getId())])
            ->displayIf(fn (Quote $quote) => \in_array($quote->getStatus(), [QuoteStatus::REQUESTED, QuoteStatus::DRAFT, QuoteStatus::SENT], true));

        return parent::configureActions($actions)->add(Action::INDEX, $send)->add(Action::DETAIL, $send);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('reference')->setColumns(3)->setDisabled();
        yield TextField::new('title')->setColumns(9);
        yield TextField::new('contactName')->setColumns(4);
        yield EmailField::new('email')->setColumns(4);
        yield AssociationField::new('client')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield TextField::new('siret', 'SIRET')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield TextField::new('companyBadge', 'Entreprise (registre)')->setColumns(8)->setDisabled()->hideOnForm();
        yield TextField::new('status')->setColumns(3)
            ->setFormType(EnumType::class)->setFormTypeOptions(['class' => QuoteStatus::class])
            ->formatValue(fn ($value) => $value instanceof QuoteStatus ? $value->value : $value);
        yield DateField::new('validUntil')->setRequired(false)->setColumns(3);
        yield IntegerField::new('discountPercent', 'Discount %')->setColumns(3);
        yield AssociationField::new('project')->setRequired(false)->setColumns(3)->hideOnIndex();
        yield TextareaField::new('request')->setRequired(false)->hideOnIndex()->setHelp('What the client asked');
        yield TextareaField::new('message')->setRequired(false)->hideOnIndex()->setHelp('Shown to the client above the lines');
        yield CollectionField::new('lines')->setEntryType(QuoteLineType::class)->allowAdd()->allowDelete()->hideOnIndex()
            ->setFormTypeOptions(['by_reference' => false]);
        yield IntegerField::new('total', 'Total (cents)')->onlyOnIndex();
        yield DateTimeField::new('createdAt')->onlyOnIndex();
    }

    public function createEntity(string $entityFqcn): object
    {
        return (new Quote())->setStatus(QuoteStatus::DRAFT);
    }

    /** Numbered when saved, not when the form opens: two forms open took the same number. */
    public function persistEntity(EntityManagerInterface $entityManager, object $entity): void
    {
        if ($entity instanceof Quote) {
            $entityManager->getRepository(Quote::class)->saveNumbered($entity);

            return;
        }
        parent::persistEntity($entityManager, $entity);
    }

    /** A token in the action's link, as for a logout: a link from elsewhere, a prefetch, does nothing (ActionController checks it). */
    private function actionToken(string $action, ?int $id): string
    {
        return $this->container->get('security.csrf.token_manager')->getToken($action.'_'.$id)->getValue();
    }
}
