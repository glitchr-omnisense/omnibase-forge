<?php

namespace Tests\Base\Forge\Controller;

use Base\Entity\User;
use Base\Forge\Entity\HubPurchase;
use Base\Forge\Entity\License;
use Base\Forge\Entity\PaymentCustomer;
use Base\Forge\Entity\Product\LicenseOffer;
use Base\Forge\Entity\Software;
use Base\Forge\EventListener\HubPurchaseCharges;
use Base\Forge\Enum\LicenseStatus;
use Base\Forge\Enum\Pricing;
use Base\Forge\Enum\SoftwareKind;
use Base\Forge\Exception\HubPurchaseException;
use Base\Forge\Model\PurchaseRequest;
use Base\Forge\Service\HubPayments;
use Base\Forge\Service\HubPurchases;
use Base\Forge\Service\LicenseIssuer;
use Base\Forge\Service\LicenseToken;
use Base\Forge\Service\UpmRegistry;
use Base\Marketplace\Entity\Order;
use Base\Marketplace\Entity\Order\Method\PaymentMethod;
use Base\Marketplace\Entity\Sales\Region;
use Base\Marketplace\Entity\Store;
use Base\Marketplace\Enum\OrderState;
use Base\Marketplace\Payment\Omnitrade\OmnitradeGateways;
use Base\Marketplace\Service\Checkout;
use Base\Marketplace\Service\Consents;
use Base\Marketplace\Entity\Order\Consent;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Base\Marketplace\Service\Pricing as ShopPricing;
use Base\Marketplace\Service\RegionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Omnitrade\Registry;
use Omnitrade\Request\SetupMethod;
use Omnitrade\Stripe\StripeGatewayFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Tests\Base\Forge\ForgeWebTestCase;
use Tests\Base\Forge\LicenseEnvironment;

/**
 * Buying from the editor, as Forge Store and Forge Hub do it (plan items 16.1 to 16.4 and 17.3),
 * in a host application, Stripe answering through a recorded client (no call leaves the test):
 * the editor linked to the account by the device flow, a card saved on Stripe's page, the price
 * confirmed then charged off-session - the bank's 3-D Secure check, Stripe's signed webhook, the
 * licence issued, its key handed to the editor once, and a v2 token activated with it that
 * verifies with the public key the hub manifest publishes.
 */
class HubPurchaseTest extends ForgeWebTestCase
{
    use LicenseEnvironment;

    private const WEBHOOK_SECRET = 'whsec_forge_test';

    /** @var list<array{string, string, array<string, mixed>, list<string>}> what Stripe was sent: method, path, form, headers */
    private array $stripe = [];
    /** What Stripe answers to the payment intent: paid, authentication, declined. */
    private string $card = 'authentication';
    /** The intent's status as GET /v1/payment_intents/{id} gives it. */
    private string $intentStatus = 'requires_action';

    /** @var array{secret: string, public: string} */
    private array $pair;
    private User $buyer;
    private LicenseOffer $offer;
    private Software $package;
    /** @var list<int> the payment methods made, removed after */
    private array $methods = [];
    /** @var array<string, mixed>|null what a client sends once its user ticked every statement the quote listed */
    private ?array $consent = null;

    protected function setUp(): void
    {
        if (!class_exists(SetupMethod::class) || !class_exists(StripeGatewayFactory::class)) {
            self::markTestSkipped('Needs omnisense/omnitrade 1.1 and omnitrade/stripe 1.1 (saved methods).');
        }
        $this->pair = LicenseToken::keypair();
        $this->licenseEnvironment(['FORGE_LICENSE_SIGNING_KEY' => $this->pair['secret']]);
        parent::setUp();
        $this->stripeAnswers();
        $this->shop();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($this->methods as $id) {
            if ($method = $em->find(PaymentMethod::class, $id)) {
                foreach ($em->getRepository(Order::class)->findBy(['paymentMethod' => $method]) as $order) {
                    $order->setPaymentMethod(null);
                }
                $em->remove($method);
            }
        }
        $em->flush();
        $this->restoreLicenseEnvironment();
        parent::tearDown();
    }

    public function testACardSavedThenAPurchaseConfirmedByTheBankAndTheWebhookGivesALicence(): void
    {
        $token = $this->link();
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.$token];

        // No method yet; one saved on Stripe's page, for a customer made then.
        self::assertSame(['gateway' => 'stripe', 'methods' => []], $this->json('GET', '/upm/-/hub/payment-methods', $auth));
        $quote = $this->json('GET', '/upm/-/hub/quote?offer='.$this->offer->getId(), $auth);
        // The store's name is the hub manifest's: "Forge" by default, the host's forge.hub.name when it sets one.
        $store = static::getContainer()->getParameter('forge.hub')['name'] ?? 'Forge';
        self::assertSame([4900, 'EUR', $store, null], [$quote['price'], $quote['currency'], $quote['store'], $quote['method']], 'the amount, the currency and the store\'s name to confirm');
        self::assertSame('payment_method_required', $this->json('POST', '/upm/-/hub/purchases', $auth + ['HTTP_IDEMPOTENCY_KEY' => 'buy-'.bin2hex(random_bytes(6))], ['offer' => $this->offer->getId(), 'price' => 4900, 'currency' => 'EUR'], 409)['error']);

        $setup = $this->json('POST', '/upm/-/hub/payment-methods', $auth, null, 201);
        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_setup', $setup['url']);
        [, , $session] = $this->sent('POST', '/v1/checkout/sessions');
        self::assertSame(['setup', 'cus_forge'], [$session['mode'], $session['customer']]);
        self::assertSame('https://localhost/hub/payment-methods/saved', $session['success_url']);
        $customer = static::getContainer()->get(EntityManagerInterface::class)->getRepository(PaymentCustomer::class)->findOneBy(['reference' => 'cus_forge']);
        self::assertInstanceOf(PaymentCustomer::class, $customer, 'the provider\'s customer is all the forge keeps');
        self::assertSame(200, $this->request('GET', '/hub/payment-methods/saved')->getStatusCode(), 'the page Stripe sends the buyer back to');

        $methods = $this->json('GET', '/upm/-/hub/payment-methods', $auth)['methods'];
        self::assertSame([['id' => 'pm_forge', 'type' => 'card', 'label' => 'Visa •••• 4242', 'brand' => 'visa', 'last4' => '4242', 'expires' => '2030-12', 'expired' => false]], $methods);

        // 17.3: a price that is not the amount charged is refused, with the amount, before any charge.
        $changed = $this->json('POST', '/upm/-/hub/purchases', $auth + ['HTTP_IDEMPOTENCY_KEY' => 'buy-stale-1'], ['offer' => $this->offer->getId(), 'price' => 3900, 'currency' => 'EUR'], 409);
        self::assertSame(['error' => 'price_changed', 'offer' => $this->offer->getId(), 'price' => 4900, 'currency' => 'EUR'], $changed);
        self::assertSame(400, $this->request('POST', '/upm/-/hub/purchases', $auth + ['CONTENT_TYPE' => 'application/json'], json_encode(['offer' => $this->offer->getId(), 'price' => 4900, 'currency' => 'EUR']))->getStatusCode(), 'no Idempotency-Key, no purchase');
        self::assertSame([], $this->sentAll('POST', '/v1/payment_intents'), 'nothing charged so far');

        // The purchase: the bank asks its buyer (3-D Secure) - its page to open.
        $key = 'buy-'.bin2hex(random_bytes(8));
        $bought = $this->json('POST', '/upm/-/hub/purchases', $auth + ['HTTP_IDEMPOTENCY_KEY' => $key], ['offer' => $this->offer->getId(), 'price' => 4900, 'currency' => 'EUR'], 201);
        self::assertSame('requires_action', $bought['status']);
        self::assertSame('https://hooks.stripe.com/3d_secure_2/hosted?pi=pi_forge', $bought['url']);
        self::assertSame([4900, 'EUR', $this->package->getUpmName()], [$bought['price'], $bought['currency'], $bought['package']]);
        self::assertArrayNotHasKey('key', $bought);
        [, , $intent, $headers] = $this->sent('POST', '/v1/payment_intents');
        self::assertSame(['4900', 'eur', 'cus_forge', 'pm_forge', 'true', 'true'], [$intent['amount'], $intent['currency'], $intent['customer'], $intent['payment_method'], $intent['off_session'], $intent['confirm']]);
        self::assertSame($bought['id'], $intent['metadata']['hub_purchase']);
        self::assertNotEmpty(preg_grep('/^Idempotency-Key: /', $headers), 'Stripe makes the charge once');
        [, , $confirm] = $this->sent('POST', '/v1/payment_intents/pi_forge/confirm');
        self::assertSame('https://localhost/hub/purchases/'.$bought['id'].'/done', $confirm['return_url'], 'the bank sends its buyer back to the purchase\'s page');

        // The same request again (a retry, a double click): the same purchase, nothing charged.
        $again = $this->request('POST', '/upm/-/hub/purchases', $auth + ['HTTP_IDEMPOTENCY_KEY' => $key, 'CONTENT_TYPE' => 'application/json'], json_encode(['offer' => $this->offer->getId(), 'price' => 4900, 'currency' => 'EUR']));
        self::assertSame(200, $again->getStatusCode());
        self::assertSame('true', $again->headers->get('Idempotent-Replayed'));
        self::assertSame($bought['id'], json_decode((string) $again->getContent(), true)['id']);
        self::assertSame('idempotency_key_reused', $this->json('POST', '/upm/-/hub/purchases', $auth + ['HTTP_IDEMPOTENCY_KEY' => $key], ['offer' => $this->offer->getId(), 'price' => 5900, 'currency' => 'EUR'], 422)['error']);
        self::assertCount(1, $this->sentAll('POST', '/v1/payment_intents'), 'one charge');

        // Stripe reports the off-session attempt as failed while the buyer is at the bank: still waiting.
        $this->webhook('payment_intent.payment_failed', $this->intent('requires_payment_method', ['last_payment_error' => ['code' => 'authentication_required', 'decline_code' => 'authentication_required']]));
        self::assertSame('requires_action', $this->json('GET', '/upm/-/hub/purchases/'.$bought['id'], $auth)['status']);

        // The buyer confirms at the bank; Stripe's signed webhook says paid: the order is, the licence issued.
        $this->intentStatus = 'succeeded';
        $this->webhook('payment_intent.succeeded', $this->intent('succeeded'));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $order = $em->getRepository(Order::class)->findOneBy(['reference' => $bought['order']]);
        self::assertNotNull($order->getPaidAt(), 'the webhook confirmed the order');
        $license = $em->getRepository(License::class)->findOneBy(['orderReference' => $bought['order']]);
        self::assertInstanceOf(License::class, $license);
        self::assertSame($this->package->getId(), $license->getSoftware()->getId());

        // The editor, polling, gets the licence key once - then installs.
        $paid = $this->json('GET', '/upm/-/hub/purchases/'.$bought['id'], $auth);
        self::assertSame('paid', $paid['status']);
        self::assertMatchesRegularExpression('/^glk_/', $paid['key']);
        self::assertSame([$this->buyer->getEmail(), 'https://localhost/upm'], [$paid['email'], $paid['registry']]);
        self::assertArrayNotHasKey('key', $this->json('GET', '/upm/-/hub/purchases/'.$bought['id'], $auth), 'handed over once');

        // A v2 token activated with that key verifies with the key the manifest publishes, bound to the registry and the package.
        $manifest = $this->json('GET', '/upm/-/hub.json');
        $activated = $this->json('POST', '/api/licenses/activate', [], ['key' => $paid['key'], 'software' => $this->package->getSlug(), 'machine' => 'editor-machine-01']);
        $payload = LicenseToken::verify($activated['token'], $manifest['licensing']['publicKey']);
        self::assertNotNull($payload, 'verified with the published public key');
        self::assertSame(2, $payload['v']);
        self::assertSame($manifest['licensing']['registry'], $payload['registry']);
        self::assertSame($this->package->getUpmName(), $payload['package']);
        self::assertSame([$this->package->getSlug(), $this->buyer->getEmail()], [$payload['software'], $payload['email']]);

        self::assertSame(200, $this->request('GET', '/hub/purchases/'.$bought['id'].'/done')->getStatusCode(), 'the page the bank sends the buyer back to');
    }

    public function testAPurchasePaidAtOnceHandsTheKeyInItsAnswer(): void
    {
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->link()];
        $this->json('POST', '/upm/-/hub/payment-methods', $auth, null, 201);
        $this->card = 'paid';

        $bought = $this->json('POST', '/upm/-/hub/purchases', $auth + ['HTTP_IDEMPOTENCY_KEY' => 'buy-'.bin2hex(random_bytes(8))], ['offer' => $this->offer->getId(), 'price' => 4900, 'currency' => 'EUR', 'method' => 'pm_forge'], 201);

        self::assertSame('paid', $bought['status']);
        self::assertMatchesRegularExpression('/^glk_/', $bought['key'], 'the licence, issued as the order was confirmed');
        self::assertCount(1, $this->sentAll('POST', '/v1/payment_intents'));
        self::assertSame([], $this->sentAll('POST', '/v1/payment_intents/pi_forge/confirm'), 'no bank page');
    }

    public function testAKeyLostWithItsAnswerIsAskedAgainAsANewKeyOfTheSameSeat(): void
    {
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->link()];
        $this->json('POST', '/upm/-/hub/payment-methods', $auth, null, 201);
        $this->card = 'paid';
        $idempotency = 'buy-'.bin2hex(random_bytes(8));
        $body = ['offer' => $this->offer->getId(), 'price' => 4900, 'currency' => 'EUR'];

        // Paid at once: the key in the answer - which the client never received.
        $lost = $this->json('POST', '/upm/-/hub/purchases', $auth + ['HTTP_IDEMPOTENCY_KEY' => $idempotency], $body, 201);
        self::assertSame(['paid', true], [$lost['status'], $lost['key_handed']]);
        self::assertSame(200, $this->request('GET', '/upm/-/hub/catalog.json', ['HTTP_AUTHORIZATION' => 'Bearer '.$lost['key']])->getStatusCode());

        // The client sends the purchase again with its key: the purchase, its id, its key gone out - no key, nothing charged.
        $replayed = $this->json('POST', '/upm/-/hub/purchases', $auth + ['HTTP_IDEMPOTENCY_KEY' => $idempotency], $body);
        self::assertSame([$lost['id'], 'paid', true], [$replayed['id'], $replayed['status'], $replayed['key_handed']]);
        self::assertArrayNotHasKey('key', $replayed);
        self::assertCount(1, $this->sentAll('POST', '/v1/payment_intents'), 'charged once');

        // Its key asked again: a new key of the same seat; the one lost stops working.
        $again = $this->json('POST', '/upm/-/hub/purchases/'.$lost['id'].'/key', $auth);
        self::assertSame([$lost['id'], 'paid', $this->package->getUpmName(), true], [$again['id'], $again['status'], $again['package'], $again['key_handed']]);
        self::assertMatchesRegularExpression('/^glk_/', $again['key']);
        self::assertNotSame($lost['key'], $again['key']);
        self::assertSame([$this->buyer->getEmail(), 'https://localhost/upm'], [$again['email'], $again['registry']]);
        self::assertSame(200, $this->request('GET', '/upm/-/hub/catalog.json', ['HTTP_AUTHORIZATION' => 'Bearer '.$again['key']])->getStatusCode(), 'the new key opens the registry');
        self::assertSame(401, $this->request('GET', '/upm/-/hub/catalog.json', ['HTTP_AUTHORIZATION' => 'Bearer '.$lost['key']])->getStatusCode(), 'the lost one no longer does');
        self::assertArrayNotHasKey('key', $this->json('GET', '/upm/-/hub/purchases/'.$lost['id'], $auth), 'the status hands none');
        $logs = static::getContainer()->getParameter('kernel.logs_dir');
        foreach (glob($logs.'/*.log') ?: [] as $log) {
            self::assertStringNotContainsString($again['key'], (string) file_get_contents($log), 'no key in '.basename($log));
        }

        // Its owner only: another account is told no such purchase, and the seat's key is left alone.
        $this->buyer = $this->user('purchase-other');
        $this->em->flush();
        $other = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->link()];
        self::assertSame(['error' => 'unknown_purchase'], $this->json('POST', '/upm/-/hub/purchases/'.$lost['id'].'/key', $other, null, 404));
        self::assertSame(['error' => 'unknown_purchase'], $this->json('POST', '/upm/-/hub/purchases/'.str_repeat('0', 32).'/key', $auth, null, 404));
        self::assertSame(200, $this->request('GET', '/upm/-/hub/catalog.json', ['HTTP_AUTHORIZATION' => 'Bearer '.$again['key']])->getStatusCode(), 'still the key');

        // A licence no longer valid: no key made.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getRepository(License::class)->findOneBy(['orderReference' => $lost['order']])->setStatus(LicenseStatus::REVOKED);
        $em->flush();
        self::assertSame(['error' => 'license_unavailable'], $this->json('POST', '/upm/-/hub/purchases/'.$lost['id'].'/key', $auth, null, 409));
    }

    public function testAPurchaseNotPaidYetHasNoKeyToAskAgain(): void
    {
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->link()];
        $this->json('POST', '/upm/-/hub/payment-methods', $auth, null, 201);

        // The bank asks its buyer (3-D Secure): not paid yet.
        $waiting = $this->json('POST', '/upm/-/hub/purchases', $auth + ['HTTP_IDEMPOTENCY_KEY' => 'buy-'.bin2hex(random_bytes(8))], ['offer' => $this->offer->getId(), 'price' => 4900, 'currency' => 'EUR'], 201);
        self::assertSame('requires_action', $waiting['status']);
        self::assertArrayNotHasKey('key_handed', $waiting);
        self::assertSame(['error' => 'purchase_not_paid', 'status' => 'requires_action'], $this->json('POST', '/upm/-/hub/purchases/'.$waiting['id'].'/key', $auth, null, 409));

        // Paid meanwhile at the bank: asked, the provider says so, and the first ask hands the key, the status then none.
        $this->intentStatus = 'succeeded';
        $first = $this->json('POST', '/upm/-/hub/purchases/'.$waiting['id'].'/key', $auth);
        self::assertSame(['paid', true], [$first['status'], $first['key_handed']]);
        self::assertMatchesRegularExpression('/^glk_/', $first['key']);
        self::assertArrayNotHasKey('key', $this->json('GET', '/upm/-/hub/purchases/'.$waiting['id'], $auth));
        self::assertSame(401, $this->request('POST', '/upm/-/hub/purchases/'.$waiting['id'].'/key')->getStatusCode(), 'the account\'s token is needed');
    }

    public function testARefusedCardChargesNothingAndLeavesNoCart(): void
    {
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->link()];
        $this->json('POST', '/upm/-/hub/payment-methods', $auth, null, 201);
        $this->card = 'declined';

        $refused = $this->json('POST', '/upm/-/hub/purchases', $auth + ['HTTP_IDEMPOTENCY_KEY' => 'buy-'.bin2hex(random_bytes(8))], ['offer' => $this->offer->getId(), 'price' => 4900, 'currency' => 'EUR'], 402);

        self::assertSame(['payment_refused', 'refused', 'Your card has insufficient funds.'], [$refused['error'], $refused['status'], $refused['reason']]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $order = $em->getRepository(Order::class)->findOneBy(['reference' => $refused['order']]);
        self::assertSame(OrderState::ABANDON, $order->getState(), 'not left in the shop as the user\'s cart');
        self::assertNull($em->getRepository(License::class)->findOneBy(['orderReference' => $refused['order']]));
        $other = $this->json('POST', '/upm/-/hub/purchases', $auth + ['HTTP_IDEMPOTENCY_KEY' => 'buy-'.bin2hex(random_bytes(8))], ['offer' => $this->offer->getId(), 'price' => 4900, 'currency' => 'EUR', 'method' => 'pm_someone_else'], 422);
        self::assertSame('unknown_payment_method', $other['error'], 'a method that is not one of theirs');
    }

    public function testAPurchaseWaitsForTheUsersPurchaseBeforeItAndIsNeverChargedTwice(): void
    {
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->link()];
        $this->json('POST', '/upm/-/hub/payment-methods', $auth, null, 201);
        $this->card = 'paid';
        $container = static::getContainer();
        $purchases = new HubPurchases(
            $container->get(EntityManagerInterface::class), $container->get(HubPayments::class), $container->get(Checkout::class), $container->get(ShopPricing::class),
            $container->get(RegionResolver::class), $container->get(LicenseIssuer::class), $container->get(UpmRegistry::class), $container->get(LockFactory::class),
            $container->get(HubPurchaseCharges::class), null, 0.3, $container->get(Consents::class),
        );
        $buyer = $container->get(EntityManagerInterface::class)->find($this->buyer::class, $this->buyer->getId());
        $request = new PurchaseRequest($this->offer->getId(), 4900, 'EUR', null, $this->consent);

        // Another request of this user holds their purchase lock (another process, as flock sees it).
        $held = $container->get(LockFactory::class)->createLock('forge-hub-purchase-'.$buyer->getId(), 120);
        self::assertTrue($held->acquire());
        try {
            $purchases->buy($buyer, $request, 'buy-held-1');
            self::fail('a purchase does not run beside the user\'s other one');
        } catch (HubPurchaseException $e) {
            self::assertSame(['purchase_in_progress', 409], [$e->error, $e->status]);
        } finally {
            $held->release();
        }
        self::assertSame([], $this->sentAll('POST', '/v1/payment_intents'), 'nothing sent while it waited');

        // Once free: bought, then the same key finds the purchase made, whatever the process.
        $first = $purchases->buy($buyer, $request, 'buy-held-1');
        $second = $purchases->buy($buyer, $request, 'buy-held-1');
        self::assertSame(HubPurchase::PAID, $first->getStatus());
        self::assertSame($first->getToken(), $second->getToken());
        self::assertTrue($second->isReplayed());
        self::assertCount(1, $this->sentAll('POST', '/v1/payment_intents'), 'charged once');
    }

    public function testMethodsAreRemovedOnlyTheirOwnersAndTheAccountIsNeeded(): void
    {
        self::assertSame(401, $this->request('GET', '/upm/-/hub/payment-methods')->getStatusCode());
        self::assertSame(401, $this->request('GET', '/upm/-/hub/payment-methods', ['HTTP_AUTHORIZATION' => 'Bearer gla_0000-0000-0000-0000-0000-0000-0000-0000'])->getStatusCode());
        self::assertSame('Bearer realm="Forge account"', $this->request('POST', '/upm/-/hub/purchases')->headers->get('WWW-Authenticate'));

        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->link()];
        self::assertSame(404, $this->request('DELETE', '/upm/-/hub/payment-methods/pm_forge', $auth)->getStatusCode(), 'no customer yet: nothing of theirs');
        $this->json('POST', '/upm/-/hub/payment-methods', $auth, null, 201);

        self::assertSame(404, $this->request('DELETE', '/upm/-/hub/payment-methods/pm_someone_else', $auth)->getStatusCode());
        self::assertSame([], $this->sentAll('POST', '/v1/payment_methods/pm_someone_else/detach'), 'never detached');
        self::assertSame(204, $this->request('DELETE', '/upm/-/hub/payment-methods/pm_forge', $auth)->getStatusCode());
        self::assertCount(1, $this->sentAll('POST', '/v1/payment_methods/pm_forge/detach'));
        self::assertSame(['error' => 'unknown_offer'], $this->json('GET', '/upm/-/hub/quote?offer=999999999', $auth, null, 404));
    }

    public function testAPurchaseCarriesItsStatementsInTheVersionTheQuoteShowed(): void
    {
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->link()];
        $this->json('POST', '/upm/-/hub/payment-methods', $auth, null, 201);
        $this->card = 'paid';

        // The quote: the texts to show, their version, required. A licence is digital content supplied at once.
        $consent = $this->json('GET', '/upm/-/hub/quote?offer='.$this->offer->getId().'&locale=en', $auth)['consent'];
        $text = static::getContainer()->get(TranslatorInterface::class)->trans('@marketplace.consent.immediate_supply', [], null, 'en');
        self::assertTrue($consent['required']);
        self::assertSame('en', $consent['locale']);
        self::assertSame([['id' => 'immediate_supply', 'text' => $text, 'hash' => hash('sha256', $text)]], $consent['statements']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $consent['version']);
        $buy = fn (?array $sent, int $status) => $this->json('POST', '/upm/-/hub/purchases', $auth + ['HTTP_IDEMPOTENCY_KEY' => 'buy-'.bin2hex(random_bytes(8))], ['offer' => $this->offer->getId(), 'price' => 4900, 'currency' => 'EUR', 'consent' => $sent], $status);

        // Without its statements (a client that does not know them), one not true, or another version: refused, nothing charged.
        $none = $buy(null, 422);
        self::assertSame(['consent_required', ['immediate_supply']], [$none['error'], $none['missing']]);
        self::assertSame('immediate_supply', $none['consent']['statements'][0]['id'], 'the texts to show come with the refusal');
        $declined = $buy(['immediate_supply' => false] + self::accepted($consent), 422);
        self::assertSame(['consent_required', ['immediate_supply']], [$declined['error'], $declined['missing']]);
        $changed = $buy(['version' => str_repeat('0', 64)] + self::accepted($consent), 409);
        self::assertSame('consent_changed', $changed['error']);
        self::assertSame($consent['version'], $changed['consent']['version'], 'the current texts, to show again');
        self::assertSame([], $this->sentAll('POST', '/v1/payment_intents'), 'nothing charged');
        self::assertNull(static::getContainer()->get(EntityManagerInterface::class)->getRepository(HubPurchase::class)->findOneBy(['user' => $this->buyer]), 'no purchase made');

        // Accepted as shown: paid, the statement recorded on the order, word for word, and confirmed by e-mail.
        $bought = $buy(self::accepted($consent), 201);
        self::assertSame('paid', $bought['status']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $order = $em->getRepository(Order::class)->findOneBy(['reference' => $bought['order']]);
        $records = $em->getRepository(Consent::class)->findBy(['order' => $order]);
        self::assertCount(1, $records);
        self::assertSame(['immediate_supply', $text, hash('sha256', $text), 'en', Consent::HUB], [$records[0]->getStatement(), $records[0]->getText(), $records[0]->getHash(), $records[0]->getLocale(), $records[0]->getChannel()]);
        $mails = array_values(array_filter(self::getMailerMessages(), static fn ($m) => $m instanceof Email && str_contains((string) $m->getSubject(), $bought['order'])));
        self::assertCount(1, $mails, 'the order\'s confirmation, the durable medium');
        self::assertSame($this->buyer->getEmail(), $mails[0]->getTo()[0]->getAddress());
        self::assertStringContainsString($text, html_entity_decode((string) $mails[0]->getHtmlBody(), \ENT_QUOTES));
    }

    public function testWithTheRequirementOffAnOlderClientBuysAsBeforeAndTheTermsAreAskedWhenTheHostNamesThem(): void
    {
        $this->json('POST', '/upm/-/hub/payment-methods', ['HTTP_AUTHORIZATION' => 'Bearer '.$this->link()], null, 201);
        $this->card = 'paid';
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        // The host names its terms (marketplace.consent.terms_route): any route stands for them here.
        $consents = new Consents($em, $container->get(TranslatorInterface::class), $container->get(RouterInterface::class), 'forge_hub_quote', [], '2026-11-14');
        $purchases = fn (bool $required) => new HubPurchases(
            $em, $container->get(HubPayments::class), $container->get(Checkout::class), $container->get(ShopPricing::class),
            $container->get(RegionResolver::class), $container->get(LicenseIssuer::class), $container->get(UpmRegistry::class), $container->get(LockFactory::class),
            $container->get(HubPurchaseCharges::class), null, 0.3, $consents, $required,
        );
        $buyer = $em->find($this->buyer::class, $this->buyer->getId());
        $offer = $em->find(LicenseOffer::class, $this->offer->getId());

        $consent = $purchases(true)->consent($buyer, $offer, 'fr');
        self::assertSame(['terms', 'immediate_supply'], array_column($consent['statements'], 'id'));
        self::assertSame($consent['terms_url'], $consent['statements'][0]['url']);
        try {
            $purchases(true)->buy($buyer, new PurchaseRequest($offer->getId(), 4900, 'EUR', null, ['immediate_supply' => false] + self::accepted($consent)), 'buy-terms-1');
            self::fail('both statements are required');
        } catch (HubPurchaseException $e) {
            self::assertSame(['consent_required', 422, ['immediate_supply']], [$e->error, $e->status, $e->data['missing']]);
        }

        // forge.hub.consent_required off: a purchase without statements goes through, nothing recorded.
        $old = $purchases(false)->buy($buyer, new PurchaseRequest($offer->getId(), 4900, 'EUR'), 'buy-old-client-1');
        self::assertSame(HubPurchase::PAID, $old->getStatus());
        $order = $em->getRepository(Order::class)->findOneBy(['reference' => $old->getOrderReference()]);
        self::assertSame([], $em->getRepository(Consent::class)->findBy(['order' => $order]));
        self::assertFalse($purchases(false)->consent($buyer, $offer)['required'], 'the quote says so');

        // A newer client still sends them: recorded, terms' version included.
        $new = $purchases(false)->buy($buyer, new PurchaseRequest($offer->getId(), 4900, 'EUR', null, self::accepted($consent)), 'buy-new-client-1');
        $order = $em->getRepository(Order::class)->findOneBy(['reference' => $new->getOrderReference()]);
        $records = $em->getRepository(Consent::class)->findBy(['order' => $order], ['id' => 'ASC']);
        self::assertSame(['terms', 'immediate_supply'], array_map(static fn (Consent $c) => $c->getStatement(), $records));
        self::assertSame(['2026-11-14', 'fr'], [$records[0]->getTermsVersion(), $records[0]->getLocale()]);
    }

    /** The device flow, as the editor and its user go through it: the account's token. */
    private function link(): string
    {
        $device = $this->json('POST', '/upm/-/hub/device', [], ['item' => (string) $this->package->getUpmName()]);
        $this->signIn($this->buyer);
        $page = $this->xpath($this->request('GET', '/hub/link?code='.$device['user_code']));
        $csrf = $page->evaluate('string(//form[@method="post"]//input[@name="_token"]/@value)');
        self::assertSame(200, $this->request('POST', '/hub/link', [], null, ['code' => $device['user_code'], '_token' => $csrf])->getStatusCode());
        $this->cookies = [];

        $token = $this->json('POST', '/upm/-/hub/token', [], ['device_code' => $device['device_code']])['account_token'];
        $this->consent = self::accepted($this->json('GET', '/upm/-/hub/quote?offer='.$this->offer->getId(), ['HTTP_AUTHORIZATION' => 'Bearer '.$token])['consent']);

        return $token;
    }

    /**
     * What a client sends back once its user ticked every statement the quote listed.
     *
     * @param array<string, mixed> $consent the quote's consent
     *
     * @return array<string, mixed>
     */
    private static function accepted(array $consent): array
    {
        $sent = ['version' => $consent['version'], 'locale' => $consent['locale']];
        foreach ($consent['statements'] as $statement) {
            $sent[$statement['id']] = true;
        }

        return $sent;
    }

    /** A licensed Unity package sold in the shop, a buyer, and the shop's card method on the omnitrade gateway "stripe". */
    private function shop(): void
    {
        $owner = $this->user('purchase-owner');
        $this->buyer = $this->user('purchase-buyer');
        $this->em->flush();
        $suffix = bin2hex(random_bytes(3));

        $store = new Store();
        $store->setTitle('Software '.$suffix);
        $store->setSlug('software-'.$suffix);
        $store->setCurrency('EUR');
        $region = new Region();
        $region->setLabel('France');
        $region->setSlug('fr-'.$suffix);
        $region->setCurrency('EUR');
        $region->setCountries(['FR']);
        $region->setEnabled(true);
        $store->addRegion($region);
        $this->em->persist($store);
        $this->em->persist($region);

        $this->package = (new Software($owner, 'Paid '.$suffix, 'paid-'.$suffix))->setKind(SoftwareKind::PACKAGE)->setCategory('tool')->setPricing(Pricing::LICENSED)->setUpmName('com.example.paid-'.$suffix)->publish();
        $this->em->persist($this->package);
        $this->offer = (new LicenseOffer(null, $store, 4900, 'EUR'))->setSoftware($this->package)->setSeats(1)->setUpdatesMonths(12);
        $this->offer->setStore($store);
        $this->offer->setTitle('One seat '.$suffix);
        $this->package->getOffers()->add($this->offer);
        $this->em->persist($this->offer);

        $method = new PaymentMethod();
        $method->setSlug('card-'.$suffix);
        $method->setLabel('Card');
        $method->setGatewayFactory('stripe');
        $this->em->persist($method);
        $this->em->flush();
        $this->methods[] = $method->getId();
    }

    /** Stripe, as its API answers (2020-08-27), through a recorded client: the omnitrade gateway "stripe" of the marketplace. */
    private function stripeAnswers(): void
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            parse_str((string) ($options['body'] ?? ''), $form);
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->stripe[] = [$method, $path, $form, array_values(array_map('strval', $options['headers'] ?? []))];
            $json = static fn (array $data, int $status = 200) => new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
            $card = ['id' => 'pm_forge', 'object' => 'payment_method', 'type' => 'card', 'customer' => 'cus_forge', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030]];

            return match (true) {
                'POST /v1/customers' === "$method $path" => $json(['id' => 'cus_forge', 'object' => 'customer']),
                'POST /v1/checkout/sessions' === "$method $path" => $json(['id' => 'cs_test_setup', 'object' => 'checkout.session', 'mode' => 'setup', 'customer' => $form['customer'] ?? null, 'url' => 'https://checkout.stripe.com/c/pay/cs_test_setup']),
                'GET /v1/payment_methods' === "$method $path" => $json(['object' => 'list', 'data' => [$card]]),
                'GET /v1/payment_methods/pm_forge' === "$method $path" => $json($card),
                'GET /v1/payment_methods/pm_someone_else' === "$method $path" => $json(['customer' => 'cus_other'] + $card),
                'POST /v1/payment_methods/pm_forge/detach' === "$method $path" => $json(['customer' => null] + $card),
                'POST /v1/payment_intents' === "$method $path" => match ($this->card) {
                    'paid' => $json($this->intent('succeeded')),
                    'declined' => $json(['error' => ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => 'Your card has insufficient funds.', 'payment_intent' => $this->intent('requires_payment_method', ['last_payment_error' => ['code' => 'card_declined']])]], 402),
                    default => $json(['error' => ['type' => 'card_error', 'code' => 'authentication_required', 'decline_code' => 'authentication_required', 'message' => 'This transaction requires authentication.', 'payment_intent' => $this->intent('requires_payment_method', ['last_payment_error' => ['code' => 'authentication_required']])]], 402),
                },
                'POST /v1/payment_intents/pi_forge/confirm' === "$method $path" => $json($this->intent('requires_action', ['next_action' => ['type' => 'redirect_to_url', 'redirect_to_url' => ['url' => 'https://hooks.stripe.com/3d_secure_2/hosted?pi=pi_forge', 'return_url' => $form['return_url'] ?? null]]])),
                'GET /v1/payment_intents/pi_forge' === "$method $path" => $json($this->intent($this->intentStatus)),
                default => $json(['error' => ['message' => 'No such resource: '.$method.' '.$path, 'code' => 'resource_missing']], 404),
            };
        });
        $registry = new Registry([new StripeGatewayFactory($http)], ['stripe' => ['factory' => 'stripe', 'options' => ['api_key' => 'sk_test_recorded', 'webhook_secret' => self::WEBHOOK_SECRET, 'tags' => ['site' => 'forge']]]]);
        static::getContainer()->set(OmnitradeGateways::class, new OmnitradeGateways($registry, static::getContainer()->get('router'), null, static::getContainer()->get('event_dispatcher'), 'test'));
    }

    /** @return array<string, mixed> */
    private function intent(string $status, array $more = []): array
    {
        $purchase = static::getContainer()->get(EntityManagerInterface::class)->getRepository(HubPurchase::class)->findOneBy([], ['id' => 'DESC']);

        return $more + ['id' => 'pi_forge', 'object' => 'payment_intent', 'status' => $status, 'amount' => 4900, 'currency' => 'eur', 'customer' => 'cus_forge', 'payment_method' => 'pm_forge', 'payment_method_types' => ['card'], 'metadata' => ['site' => 'forge', 'hub_purchase' => $purchase?->getToken()]];
    }

    /** A Stripe event, signed with the endpoint's secret, posted to the shop's webhook. */
    private function webhook(string $type, array $object): void
    {
        $body = json_encode(['id' => 'evt_'.bin2hex(random_bytes(6)), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]], \JSON_THROW_ON_ERROR);
        $time = time();
        $response = $this->request('POST', '/marketplace/stripe/webhook', ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => sprintf('t=%d,v1=%s', $time, hash_hmac('sha256', $time.'.'.$body, self::WEBHOOK_SECRET))], $body);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringNotContainsString('ignored', (string) $response->getContent(), 'the shop found the order\'s payment');
    }

    /** @return array{string, string, array<string, mixed>, list<string>} the last call to Stripe with that method and path */
    private function sent(string $method, string $path): array
    {
        $calls = $this->sentAll($method, $path);
        self::assertNotEmpty($calls, "$method $path was not sent");

        return end($calls);
    }

    /** @return list<array{string, string, array<string, mixed>, list<string>}> */
    private function sentAll(string $method, string $path): array
    {
        return array_values(array_filter($this->stripe, static fn (array $call) => $call[0] === $method && $call[1] === $path));
    }

    /** @param array<string, mixed>|null $body */
    private function json(string $method, string $uri, array $server = [], ?array $body = null, int $status = Response::HTTP_OK): array
    {
        // A purchase as a client that shows the statements sends it; 'consent' => null: as an older client (none).
        if ('POST' === $method && '/upm/-/hub/purchases' === $uri && null !== $body) {
            $body = \array_key_exists('consent', $body) ? array_filter($body, static fn ($value) => null !== $value) : $body + ['consent' => $this->consent];
        }
        $response = $this->request($method, $uri, $server + (null !== $body ? ['CONTENT_TYPE' => 'application/json'] : []), null !== $body ? json_encode($body) : null);
        self::assertSame($status, $response->getStatusCode(), $method.' '.$uri.': '.substr((string) $response->getContent(), 0, 2000));

        return json_decode((string) $response->getContent(), true) ?? [];
    }
}
