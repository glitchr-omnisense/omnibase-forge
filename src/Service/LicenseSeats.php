<?php

namespace Base\Forge\Service;

use Base\Database\Attribute\Backend\Backends;
use Base\Database\Backend\RowLocks;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Repositories;
use Base\Entity\User;
use Base\Forge\Entity\License;
use Base\Forge\Entity\LicenseSeat;
use Base\Contract\UnitOfWork;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Gives a licence's seats to e-mails, and takes them back. The one it is
 * given to gets an e-mail: what they may now download, and how - sign in,
 * or create their account, with that address.
 */
class LicenseSeats
{
    public function __construct(
        private readonly UnitOfWork $unitOfWork,
        private readonly Repositories $repositories,
        private readonly MailerInterface $mailer,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * A seat for that e-mail - or the one it already has. Flushed, and the
     * e-mail sent (a mail that fails does not undo the seat).
     *
     * @throws \InvalidArgumentException not an e-mail, or no seat left
     */
    public function give(License $license, string $email, ?User $by = null): LicenseSeat
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('forge.seat.invalid_email');
        }

        // The licence's row locked, its seats read from the database: two
        // e-mails given at once both found the last seat free, and the same
        // e-mail twice broke on the unique seat. A licence not written yet has
        // no row to lock: its seats are its collection's, and the flush is its
        // own transaction.
        $seats = $this->repositories->of(LicenseSeat::class);
        $existing = null;
        $give = function () use ($license, $email, $seats, &$existing): LicenseSeat {
            if ($existing = $license->getId() ? $seats->findOneBy(['license' => Key::of($license), 'email' => $email]) : $license->findSeat($email)) {
                return $existing;
            }
            $taken = $license->getId() ? $seats->count(['license' => Key::of($license)]) : $license->getSeatsTaken();
            if ($license->getSeats() - $taken < 1) {
                throw new \InvalidArgumentException('forge.seat.none_left');
            }

            $seat = new LicenseSeat($license, $email, $this->verifiedUser($email));
            // Told below, with who gave it: not again by the entity listener.
            $seat->markNotified();
            $license->addHolder($seat);
            $this->unitOfWork->persist($seat);
            $this->unitOfWork->flush();

            return $seat;
        };
        $seat = null !== $license->getId() ? Backends::of($this->unitOfWork, RowLocks::class)->locked($license, $give) : $give();
        if ($existing) {
            return $existing;
        }

        $this->notify($seat, $by);

        return $seat;
    }

    /** Takes the seat back: its key stops working at once. Flushed. */
    public function takeBack(LicenseSeat $seat): void
    {
        $seat->getLicense()?->removeHolder($seat);
        $this->unitOfWork->remove($seat);
        $this->unitOfWork->flush();
    }

    /**
     * A seat given from the back office: its holder told. Its account needs
     * no link - a verified account with that e-mail holds it anyway.
     */
    public function welcome(LicenseSeat $seat): void
    {
        $this->notify($seat, null);
    }

    /** The account behind that e-mail, when it proved the e-mail is theirs. */
    private function verifiedUser(string $email): ?User
    {
        $user = $this->repositories->of(User::class)->findOneBy(['email' => $email]);

        return $user instanceof User && $user->isVerified() ? $user : null;
    }

    private function notify(LicenseSeat $seat, ?User $by): void
    {
        $seat->markNotified();
        $license = $seat->getLicense();
        if (!$license || ($by && 0 === strcasecmp((string) $by->getEmail(), $seat->getEmail()))) {
            return;
        }

        try {
            $this->mailer->send((new TemplatedEmail())
                ->to($seat->getEmail())
                ->subject(sprintf('Votre accès à %s', $license->getSoftware()?->getName()))
                ->htmlTemplate('@Forge/email/license_seat.html.twig')
                ->context(['seat' => $seat, 'license' => $license, 'software' => $license->getSoftware(), 'by' => $by]));
        } catch (TransportExceptionInterface $e) {
            $this->logger?->warning('The seat e-mail to {email} failed: {error}', ['email' => $seat->getEmail(), 'error' => $e->getMessage()]);
        }
    }
}
