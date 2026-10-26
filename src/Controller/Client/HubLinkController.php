<?php

namespace Base\Forge\Controller\Client;

use Base\Entity\User;
use Base\Forge\Entity\Product\LicenseOffer;
use Base\Forge\Repository\SoftwareRepository;
use Base\Forge\Service\DeviceLinks;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The page a hub client sends its user to (the device flow, DeviceLinks): signed in, they see the
 * code their editor shows, the item it asked for with its offers - bought here, in a tab of their
 * own, through the shop's cart and checkout - and, once they hold a licence, "Link this editor":
 * the editor then receives a key of theirs and installs.
 */
#[IsGranted('ROLE_USER')]
class HubLinkController extends AbstractController
{
    public function __construct(
        private readonly DeviceLinks $links,
        private readonly SoftwareRepository $software,
    ) {
    }

    #[Route('/hub/link', name: 'forge_hub_link', methods: ['GET', 'POST'])]
    public function link(Request $request): Response
    {
        $user = $this->getUser();
        $code = strtoupper(trim((string) ($request->request->get('code') ?? $request->query->get('code', ''))));
        $found = '' !== $code ? $this->links->find($code) : null;
        $linked = null;

        if ($request->isMethod('POST') && $found && $user instanceof User) {
            if (!$this->isCsrfTokenValid('forge_hub_link', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid token.');
            }
            $linked = $this->links->approve($code, $user);
            if ($linked) {
                $found['status'] = 'approved';
            }
        }

        $item = $found['item'] ?? $request->query->get('item');
        $software = \is_string($item) ? $this->software->findOneBy(['upmName' => $item]) : null;

        return $this->render('@Forge/client/hub/link.html.twig', [
            'code' => $code,
            'found' => $found,
            'linked' => $linked,
            'software' => $software,
            'offers' => $software ? $software->getOffers()->filter(fn (LicenseOffer $offer) => $offer->isForSell())->toArray() : [],
            'seat' => $user instanceof User ? $this->links->seatOf($user) : null,
        ]);
    }
}
