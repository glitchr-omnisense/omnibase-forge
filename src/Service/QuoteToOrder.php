<?php

namespace Base\Forge\Service;

use Base\Database\Attribute\Backend\Backends;
use Base\Database\Backend\RowLocks;
use Base\Database\Repository\Repositories;
use Base\Entity\User;
use Base\Forge\Entity\Product\HourPack;
use Base\Forge\Entity\Quote;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Quote\AbstractQuote;
use Base\Marketplace\Entity\Store;
use Base\Marketplace\Service\Cart;
use Base\Marketplace\Service\QuoteStatusGuard;
use Base\Marketplace\Service\QuoteToOrder as MarketplaceQuoteToOrder;
use Base\Contract\UnitOfWork;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The client accepts a quote: it becomes a one-off HourPack of the support
 * store - its hours, its discounted total, one in stock - dropped in their
 * cart. From there it is an order like any other; OrderPaidSubscriber
 * credits the hours and marks the quote paid once it is.
 *
 * omnibase/marketplace's QuoteToOrder, the studio's way: one HourPack
 * rather than an order of the quote's lines.
 */
class QuoteToOrder extends MarketplaceQuoteToOrder
{
    /** The unit of work is the parent's (omnibase/marketplace's QuoteToOrder). */
    public function __construct(
        UnitOfWork $unitOfWork,
        private readonly Repositories $repositories,
        private readonly Cart $cart,
        #[Autowire('%forge.support_store%')] string $storeSlug = 'support',
    ) {
        parent::__construct($unitOfWork, null, null, $storeSlug);
    }

    public function accept(AbstractQuote $quote, User $client): Order
    {
        if (!$quote instanceof Quote) {
            return parent::accept($quote, $client);
        }

        // Sent and still valid - or accepted already and not paid yet: back to
        // the cart it goes.
        if (!$quote->isAcceptable() && !QuoteStatusGuard::isAwaitingPayment($quote)) {
            throw new \DomainException('quote.error.not_acceptable');
        }

        $store = $this->repositories->of(Store::class)->findOneBy(['slug' => (string) $this->storeSlug])
            ?? throw new \LogicException(sprintf('The support store "%s" does not exist: create it in the back office.', $this->storeSlug));

        // The quote's row locked and read again: a double click (or two tabs)
        // made two one-off packs, the client paid twice, and the second pack,
        // not the quote's, never marked it paid. A quote not written yet has
        // no row to lock (the flush is its own transaction).
        $accept = function () use ($quote, $client, $store): HourPack {
            $pack = $quote->getProduct();
            if (!$pack) {
                $pack = new HourPack(null, $store, $quote->getTotal(), $quote->getCurrency());
                $pack->setTitle(sprintf('%s — %s', $quote->getReference(), $quote->getTitle()));
                $pack->setSlug(strtolower($quote->getReference()).'-'.substr($quote->getToken(), 0, 6));
                $pack->setExcerpt($quote->getTitle());
                $pack->setMinutes($quote->getTotalMinutes());
                $pack->setListed(false);
                $pack->setStock(1);
                $this->unitOfWork->persist($pack);

                $quote->setProduct($pack);
                $quote->setClient($quote->getClient() ?? $client);
                $quote->accept();
                $this->unitOfWork->flush();
            }

            return $pack;
        };
        $pack = null === $quote->getId() ? $accept() : Backends::of($this->unitOfWork, RowLocks::class)->locked($quote, function () use ($quote, $accept): HourPack {
            $this->unitOfWork->refresh($quote);

            return $accept();
        });

        return $this->cart->add($pack, 1);
    }
}
