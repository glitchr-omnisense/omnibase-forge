<?php

namespace Base\Forge\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * The licence API and the hub clients' API (/upm/-/hub/: catalogue, payment
 * methods, purchases) answer their errors in JSON, with their own status - a
 * request it cannot read is a 422 with what is wrong -, never the site's
 * HTML error page: the one reading it is a game, an application or an editor.
 * Before base-bundle's error pages, which would pick their own status.
 */
final class LicenseApiErrorSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 256]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if (!str_starts_with($path, '/api/licenses/') && !str_starts_with($path, '/upm/-/hub/')) {
            return;
        }

        $exception = $event->getThrowable();
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $body = ['error' => 422 === $status ? 'invalid_request' : ($status < 500 ? 'bad_request' : 'server_error')];

        $violations = $exception->getPrevious() instanceof ValidationFailedException ? $exception->getPrevious()->getViolations() : [];
        foreach ($violations as $violation) {
            $body['violations'][$violation->getPropertyPath()] = $violation->getMessage();
        }

        $event->setResponse(new JsonResponse($body, $status, ['Cache-Control' => 'no-store']));
    }
}
