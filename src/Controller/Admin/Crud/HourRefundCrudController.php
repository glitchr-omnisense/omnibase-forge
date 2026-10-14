<?php

namespace Base\Forge\Controller\Admin\Crud;

use Base\Admin\Config\Action;
use Base\Admin\Config\Actions;
use Base\Admin\Controller\AbstractCrudController;
use Base\Field\AssociationField;
use Base\Field\DateTimeField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\TextField;
use Base\Forge\Entity\HourRefund;

/** The refunds made, read-only: they are made from a purchase (RefundController). */
class HourRefundCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return HourRefund::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-rotate-left';
    }

    public function configureActions(Actions $actions): Actions
    {
        $open = Action::new('forgeRefundOrder', 'Commande', 'fa-solid fa-receipt')
            ->linkToRoute('forge_admin_order_refund', fn (HourRefund $refund) => ['reference' => $refund->getOrderReference()]);

        return parent::configureActions($actions)->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Action::INDEX, $open)->add(Action::DETAIL, $open);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('orderReference');
        yield AssociationField::new('customer')->setRequired(false);
        yield TextField::new('amount')->formatValue(fn ($value, HourRefund $refund) => number_format($refund->getAmount() / 100, 2, ',', ' ').' '.$refund->getCurrency());
        yield IntegerField::new('minutes');
        yield TextField::new('method')->formatValue(fn ($value) => HourRefund::TRANSFER === $value ? 'virement (à faire)' : 'carte (Stripe)');
        yield TextField::new('stripeRefund')->setRequired(false)->hideOnIndex();
        yield TextField::new('reason')->hideOnIndex();
        yield AssociationField::new('refundedBy')->setRequired(false);
        yield DateTimeField::new('createdAt');
    }
}
