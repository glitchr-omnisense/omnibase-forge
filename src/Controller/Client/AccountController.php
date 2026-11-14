<?php

namespace Base\Forge\Controller\Client;

use Base\Entity\User;
use Base\Forge\Entity\Download;
use Base\Forge\Entity\License;
use Base\Forge\Entity\LicenseSeat;
use Base\Forge\Repository\LicenseRepository;
use Base\Forge\Repository\QuoteRepository;
use Base\Forge\Service\HourLedger;
use Base\Forge\Service\HubAccounts;
use Base\Forge\Service\LicenseActivations;
use Base\Forge\Service\LicenseIssuer;
use Base\Forge\Service\LicenseSeats;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The client's side of the forge: their licences, the seats they give and
 * the keys of the ones they hold, how to install
 * from the Composer repository, their support hours, their quotes, what
 * they downloaded and the editors linked to their account.
 */
#[IsGranted('ROLE_USER')]
class AccountController extends AbstractController
{
    public function __construct(
        private readonly LicenseRepository $licenses,
        private readonly QuoteRepository $quotes,
        private readonly HourLedger $ledger,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?HubAccounts $accounts = null,
    ) {
    }

    #[Route('/compte/licences', name: 'forge_account_licenses')]
    public function licenses(): Response
    {
        return $this->page();
    }

    /** A new key for the signed-in user's seat, shown once on the page that answers; the old one stops working. */
    #[Route('/compte/licences/{id}/cle', name: 'forge_license_rekey', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function rekey(Request $request, int $id, LicenseIssuer $issuer): Response
    {
        $license = $this->licenses->find($id);
        $user = $this->user();
        $seat = $license instanceof License ? $license->findSeatOf($user) : null;
        if (!$seat) {
            throw $this->createNotFoundException('No seat of yours on this licence.');
        }
        $this->checkToken($request, 'forge_license_'.$id);
        if (!$license->isValid()) {
            $this->addFlash('warning', '@forge.license.not_valid');

            return $this->redirectToRoute('forge_account_licenses');
        }

        $response = $this->page(['license' => $license, 'value' => $issuer->rekey($seat)]);
        // The key is in this page only: never cached, never replayed.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * An editor or desktop hub linked to the account signed out: its token revoked (docs/hub.md,
     * "Linked editors and signing out") - the way for one the user no longer has at hand.
     */
    #[Route('/compte/editeurs/{id}/retirer', name: 'forge_account_hub_token_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revokeHubToken(Request $request, int $id): Response
    {
        $this->checkToken($request, 'forge_hub_token_'.$id);
        if (null === $this->accounts || !$this->accounts->revoke($this->user(), $id)) {
            throw $this->createNotFoundException('No such linked editor of yours.');
        }
        $this->addFlash('success', '@forge.account.editor_revoked');

        return $this->redirect($this->generateUrl('forge_account_licenses').'#editeurs');
    }

    /** The owner gives a seat to an e-mail - who is told by e-mail. */
    #[Route('/compte/licences/{id}/postes', name: 'forge_license_seat_give', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function give(Request $request, int $id, LicenseSeats $seats): Response
    {
        $license = $this->owned($id);
        $this->checkToken($request, 'forge_license_seats_'.$id);

        $email = trim((string) $request->request->get('email'));
        try {
            $seats->give($license, $email, $this->user());
            $this->addFlash('success', '@forge.seat.given');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('warning', '@'.$e->getMessage());
        }

        return $this->redirectToRoute('forge_account_licenses', ['_fragment' => 'licence-'.$id]);
    }

    /** The owner takes a seat back: its key stops working at once. */
    #[Route('/compte/licences/{id}/postes/{seat}/retirer', name: 'forge_license_seat_take_back', requirements: ['id' => '\d+', 'seat' => '\d+'], methods: ['POST'])]
    public function takeBack(Request $request, int $id, int $seat, LicenseSeats $seats): Response
    {
        $license = $this->owned($id);
        $this->checkToken($request, 'forge_license_seats_'.$id);

        $holder = $license->getHolders()->findFirst(fn (int $key, LicenseSeat $candidate) => $candidate->getId() === $seat)
            ?? throw $this->createNotFoundException('No such seat.');
        $seats->takeBack($holder);
        $this->addFlash('success', '@forge.seat.taken_back');

        return $this->redirectToRoute('forge_account_licenses', ['_fragment' => 'licence-'.$id]);
    }

    /**
     * Frees a machine's place: the seat's holder, for their own, and the
     * licence's owner, for their team's. The software asks again at its
     * next start, and finds the place free for another machine.
     */
    #[Route('/compte/licences/{id}/machines/{activation}/liberer', name: 'forge_license_machine_release', requirements: ['id' => '\d+', 'activation' => '\d+'], methods: ['POST'])]
    public function releaseMachine(Request $request, int $id, int $activation, LicenseActivations $activations): Response
    {
        $license = $this->licenses->find($id);
        $user = $this->user();
        if (!$license instanceof License) {
            throw $this->createNotFoundException('No such licence.');
        }
        $this->checkToken($request, 'forge_license_machines_'.$id);

        $owner = $license->getOwner()?->getId() === $user->getId();
        foreach ($license->getHolders() as $seat) {
            if (!$owner && !$seat->isHeldBy($user)) {
                continue;
            }
            foreach ($seat->getActivations() as $candidate) {
                if ($candidate->getId() === $activation) {
                    $activations->release($candidate);
                    $this->addFlash('success', '@forge.machine.released');

                    return $this->redirectToRoute('forge_account_licenses', ['_fragment' => 'licence-'.$id]);
                }
            }
        }

        throw $this->createNotFoundException('No such machine of yours.');
    }

    /**
     * The account page: the licences the user owns (their seats to give)
     * and those they hold a seat of (their key), each once.
     *
     * @param array{license: License, value: string}|null $key
     */
    private function page(?array $key = null): Response
    {
        $user = $this->user();
        $licenses = [];
        foreach ([...$this->licenses->findOwnedBy($user), ...$this->licenses->findHeldBy($user)] as $license) {
            $licenses[$license->getId()] = $license;
        }

        return $this->render('@Forge/client/account/licenses.html.twig', [
            'licenses' => array_values($licenses),
            'balance' => $this->ledger->balance($user),
            'history' => $this->ledger->history($user, 20),
            'quotes' => $this->quotes->findForClient($user),
            'downloads' => $this->entityManager->getRepository(Download::class)->findBy(['user' => $user], ['downloadedAt' => 'DESC'], 10),
            'hub_tokens' => $this->accounts?->tokensOf($user) ?? [],
            'key' => $key,
        ]);
    }

    private function owned(int $id): License
    {
        $license = $this->licenses->find($id);
        if (!$license instanceof License || $license->getOwner()?->getId() !== $this->user()->getId()) {
            throw $this->createNotFoundException('No such licence.');
        }

        return $license;
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
