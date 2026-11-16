<?php

namespace Base\Forge\EventListener;

use Base\Forge\Entity\LicenseSeat;
use Base\Forge\Service\LicenseSeats;
use Base\Contract\Lifecycle\Listeners;
use Base\Contract\Lifecycle\PostPersist;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A seat saved from the back office tells its holder, as one given from
 * the owner's account does (LicenseSeats::give, which sends it itself:
 * the `notified` flag keeps it to one e-mail). A neutral lifecycle listener
 * (PostPersist): the persistence that wrote the seat calls it.
 */
#[AutoconfigureTag(Listeners::TAG)]
final class LicenseSeatListener
{
    public function __construct(private readonly LicenseSeats $seats)
    {
    }

    public function __invoke(object $event): void
    {
        if ($event instanceof PostPersist && $event->entity instanceof LicenseSeat && !$event->entity->isNotified()) {
            $this->seats->welcome($event->entity);
        }
    }
}
