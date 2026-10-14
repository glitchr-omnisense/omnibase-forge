<?php

namespace Base\Forge\Controller\Admin\Crud;

use Base\Admin\Controller\AbstractCrudController;
use Base\Field\AssociationField;
use Base\Field\BooleanField;
use Base\Field\DateField;
use Base\Field\IdField;
use Base\Field\IntegerField;
use Base\Field\TextField;
use Base\Forge\Entity\TimeEntry;

/**
 * Time spent on client projects. Every billable entry comes off the client's
 * support hours in the same save (TimeEntryLedgerSubscriber).
 */
class TimeEntryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TimeEntry::class;
    }

    public static function getPreferredIcon(): ?string
    {
        return 'fa-solid fa-stopwatch';
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('project')->setColumns(4);
        yield DateField::new('spentOn')->setColumns(3);
        yield IntegerField::new('minutes')->setColumns(2);
        yield BooleanField::new('billable')->setColumns(3);
        yield TextField::new('note')->setColumns(8);
        yield TextField::new('commitSha')->setRequired(false)->setColumns(4)->hideOnIndex();
        yield AssociationField::new('author')->setRequired(false)->setColumns(4)->hideOnIndex();
    }

    public function createEntity(string $entityFqcn): object
    {
        $entry = new TimeEntry();
        $user = $this->getUser();
        if ($user instanceof \Base\Entity\User) {
            $entry->setAuthor($user);
        }

        return $entry;
    }
}
