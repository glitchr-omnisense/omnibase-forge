<?php

namespace Base\Forge\EventListener;

use Base\Forge\Entity\HourInvoice;
use Base\Forge\Entity\TimeEntry;
use Base\Marketplace\Entity\Invoice;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * An invoice of hours cancelled by a credit note - from the forge's screen
 * (Service\HourInvoices::credit()) or from marketplace's own (its "credit
 * note", its "reissue") - releases its hours in the same flush: each time
 * entry forgets the line that billed it and can be invoiced again. The rule
 * lives here once, so no screen can cancel an invoice and leave its hours
 * billed.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class HourInvoiceCancelListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        foreach ($unitOfWork->getScheduledEntityUpdates() as $invoice) {
            if (!$invoice instanceof Invoice || !\array_key_exists('creditNote', $unitOfWork->getEntityChangeSet($invoice)) || null === $invoice->getCreditNote()) {
                continue;
            }
            $hourInvoice = $entityManager->getRepository(HourInvoice::class)->findOneBy(['invoice' => $invoice]);
            if (null === $hourInvoice || $hourInvoice->getCreditNote()) {
                continue;
            }
            $released = $hourInvoice->cancelledBy($invoice->getCreditNote());
            $unitOfWork->recomputeSingleEntityChangeSet($entityManager->getClassMetadata(HourInvoice::class), $hourInvoice);
            $metadata = $entityManager->getClassMetadata(TimeEntry::class);
            foreach ($released as $entry) {
                $unitOfWork->recomputeSingleEntityChangeSet($metadata, $entry);
            }
        }
    }
}
