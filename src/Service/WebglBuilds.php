<?php

namespace Base\Forge\Service;

use Base\Forge\Entity\Artifact;
use Base\Forge\Enum\Platform;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mime\MimeTypes;

/**
 * A game's WebGL build, played in its page: its zip unpacked once, beside
 * it in the storage (<software>/<version>/webgl/play/), and each file served
 * with the headers Unity's loader needs.
 *
 * A build compressed with Brotli or gzip (Player Settings > Publishing
 * Settings > Compression Format, Unity's default) names its files
 * Build/<name>.wasm.br, .data.br, .framework.js.br: they are served as they
 * are, with Content-Encoding: br (or gzip) and the type of what they hold -
 * application/wasm for the .wasm, so that the browser compiles it as it
 * streams in. The browser decompresses them, not Unity's JavaScript: no
 * "Decompression Fallback" is needed, and none is used by the builds made
 * for the forge. A build made with the fallback (its files end in
 * .unityweb) is served too, without Content-Encoding: its loader
 * decompresses it.
 */
class WebglBuilds
{
    public function __construct(
        private readonly ArtifactBuilder $builder,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * The file at that path of the build, unpacked if it was not yet, with
     * the headers to serve it with; null when the build has no such file.
     *
     * @return array{0: string, 1: array<string, string>}|null its absolute path and its headers
     */
    public function file(Artifact $build, string $path): ?array
    {
        if (Platform::WEBGL !== $build->getPlatform()) {
            throw new \InvalidArgumentException(sprintf('%s is no WebGL build.', $build));
        }
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ('' === $path || str_ends_with($path, '/')) {
            $path .= 'index.html';
        }
        if (preg_match('~(^|/)\.\.?(/|$)~', $path)) {
            return null;
        }

        $file = $this->unpack($build).'/'.$path;
        if (!is_file($file)) {
            return null;
        }

        return [$file, self::headers($path)];
    }

    /** Where the build is unpacked: made the first time it is asked for. */
    public function unpack(Artifact $build): string
    {
        $zipFile = $this->builder->absolutePath($build);
        $target = \dirname($zipFile).'/play';
        if (is_dir($target)) {
            return $target;
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($zipFile, \ZipArchive::RDONLY)) {
            throw new \RuntimeException(sprintf('The WebGL build "%s" cannot be opened.', $build->getFilename()));
        }
        $root = ArtifactBuilder::webglRoot($zip) ?? throw new \RuntimeException(sprintf('"%s" holds no index.html beside a Build/ folder.', $build->getFilename()));

        // Unpacked aside, then moved in one rename: two first visitors at once
        // never see half a build.
        $temporary = $target.'.'.bin2hex(random_bytes(4)).'.part';
        try {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);
                if (!str_starts_with($name, $root) || str_ends_with($name, '/')) {
                    continue;
                }
                $relative = substr($name, \strlen($root));
                // Nothing outside the folder: no absolute path, no "..".
                if ('' === $relative || str_starts_with($relative, '/') || preg_match('~(^|/)\.\.(/|$)~', $relative)) {
                    continue;
                }
                $stream = $zip->getStream($name) ?: throw new \RuntimeException(sprintf('Cannot read "%s" in "%s".', $name, $build->getFilename()));
                $this->filesystem->mkdir(\dirname($temporary.'/'.$relative));
                file_put_contents($temporary.'/'.$relative, $stream);
                fclose($stream);
            }
        } finally {
            $zip->close();
        }

        if (!@rename($temporary, $target)) {
            // Another request unpacked it first.
            $this->filesystem->remove($temporary);
        }

        return $target;
    }

    /** @return array<string, string> */
    public static function headers(string $path): array
    {
        $name = strtolower(basename($path));
        $headers = [];
        if (str_ends_with($name, '.br')) {
            $headers['Content-Encoding'] = 'br';
            $name = substr($name, 0, -3);
        } elseif (str_ends_with($name, '.gz')) {
            $headers['Content-Encoding'] = 'gzip';
            $name = substr($name, 0, -3);
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $headers['Content-Type'] = match ($extension) {
            'wasm' => 'application/wasm',
            'js' => 'application/javascript',
            'data', 'unityweb', 'bundle', 'mem' => 'application/octet-stream',
            'html' => 'text/html; charset=UTF-8',
            'css' => 'text/css; charset=UTF-8',
            'json' => 'application/json',
            default => MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? 'application/octet-stream',
        };

        return $headers;
    }
}
