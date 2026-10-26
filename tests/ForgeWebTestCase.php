<?php

namespace Tests\Base\Forge;

use Base\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\TestBrowserToken;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For the tests that meet the bundle's pages as a visitor does, in a host application (skipped
 * in a bare checkout). The requests go straight to the kernel - a host need not have
 * symfony/browser-kit nor symfony/dom-crawler - with the session a browser would carry, and the
 * pages are read with DOMXPath.
 */
abstract class ForgeWebTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    /** @var array<string, string> the cookies of the visitor: their session */
    protected array $cookies = [];

    protected function setUp(): void
    {
        $_SERVER['KERNEL_CLASS'] ??= $_ENV['KERNEL_CLASS'] ?? 'App\\Kernel';
        if (!class_exists($_SERVER['KERNEL_CLASS'])) {
            self::markTestSkipped('Needs a host application kernel.');
        }

        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->cookies = [];
    }

    /** One request to the kernel, with the visitor's cookies; the ones it sets are kept. */
    /** @param array<string, string> $parameters a form's fields (POST) */
    protected function request(string $method, string $uri, array $server = [], ?string $body = null, array $parameters = []): Response
    {
        $request = Request::create('https://localhost'.$uri, $method, $parameters, $this->cookies, [], $server + ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'PHPUnit'], $body);
        $response = static::$kernel->handle($request);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = (string) $cookie->getValue();
        }

        return $response;
    }

    /** As KernelBrowser::loginUser() does: the token in a session, the session's cookie on the visitor. */
    protected function signIn(User $user): void
    {
        $context = $this->firewall();
        $session = static::getContainer()->get('session.factory')->createSession();
        $session->set('_security_'.$context, serialize(new TestBrowserToken($user->getRoles(), $user, $context)));
        $session->save();
        $this->cookies = [$session->getName() => $session->getId()];
    }

    protected function xpath(Response $response): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());

        return new \DOMXPath($document);
    }

    protected function user(string $name, array $roles = ['ROLE_USER']): User
    {
        $class = class_exists('App\\Entity\\User') ? 'App\\Entity\\User' : User::class;
        $name .= '-'.bin2hex(random_bytes(3));
        $user = new $class();
        $user->setUsername($name);
        $user->setEmail($name.'@example.org');
        $user->setPlainPassword($name);
        $user->setRoles($roles);
        if (method_exists($user, 'verify')) {
            $user->verify();
        }
        $this->em->persist($user);

        return $user;
    }

    /** The context of the firewall the pages are behind ("main", or the one the host shares between its firewalls). */
    protected function firewall(): string
    {
        try {
            return static::getContainer()->get('security.firewall.map.config.main')->getContext() ?: 'main';
        } catch (\Throwable) {
            return 'main';
        }
    }
}
