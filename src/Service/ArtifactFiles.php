<?php

namespace Base\Forge\Service;

use Base\Entity\User;
use Base\Forge\Entity\Artifact;
use Base\Forge\Entity\Download;
use Base\Forge\Entity\License;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An artifact's file handed out, once whoever asks was let through: one
 * Download row for each new download (not a HEAD, not the rest of a
 * download resumed), then the file - by nginx (X-Accel-Redirect to
 * forge.accel_prefix) or, without it, by PHP. The signed links
 * (DownloadController) and the Unity registry's tarballs (UpmController)
 * both end here.
 */
class ArtifactFiles
{
    public function __construct(
        private readonly ArtifactBuilder $builder,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%forge.accel_prefix%')] private readonly ?string $accelPrefix = null,
    ) {
    }

    /** @param string $channel web, composer or upm: where the download came from */
    public function respond(Request $request, Artifact $artifact, ?License $license, mixed $user, string $channel): Response
    {
        $path = $this->builder->absolutePath($artifact);
        if (!is_file($path)) {
            throw new NotFoundHttpException('The archive is missing from the storage: rebuild the release.');
        }

        if ($this->isNewDownload($request)) {
            $ip = $request->getClientIp();
            $this->entityManager->persist(new Download(
                $artifact,
                // Composer's and Unity's downloads come with no session: whose seat it was is not known.
                $user instanceof User ? $user : null,
                $license,
                $ip ? hash('sha256', $ip.$this->secret) : null,
                $channel,
            ));
            $this->entityManager->flush();
        }

        if ($this->accelPrefix) {
            return new Response('', Response::HTTP_OK, [
                'X-Accel-Redirect' => rtrim($this->accelPrefix, '/').'/'.$artifact->getPath(),
                'Content-Type' => $artifact->getContentType(),
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $artifact->getFilename()),
                'Cache-Control' => 'private, no-store',
            ]);
        }

        $response = new BinaryFileResponse($path, Response::HTTP_OK, ['Content-Type' => $artifact->getContentType(), 'Cache-Control' => 'private, no-store']);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $artifact->getFilename());

        return $response;
    }

    private function isNewDownload(Request $request): bool
    {
        if ($request->isMethod('HEAD')) {
            return false;
        }
        $range = (string) $request->headers->get('Range');

        return '' === $range || (bool) preg_match('/^bytes=0-/', $range);
    }
}
