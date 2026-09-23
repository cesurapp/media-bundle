<?php

namespace Cesurapp\MediaBundle\Manager;

use Cesurapp\MediaBundle\Exception\FileValidationException;
use Cesurapp\StorageBundle\Storage\Storage;
use claviska\SimpleImage;
use Cesurapp\MediaBundle\Entity\Media;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

readonly class MediaManager
{
    /**
     * Types a browser executes or renders as a document. Stored on a public bucket or served
     * inline they turn an upload into stored XSS (html, svg) or, on a web-served disk, code
     * execution (php). They are refused unless the caller opts in with the 'allowUnsafe' option.
     */
    public const array UNSAFE_MIMES = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/xml', 'application/xml',
        'text/javascript', 'application/javascript', 'application/x-javascript', 'application/ecmascript',
        'text/x-php', 'application/x-php', 'application/x-httpd-php', 'application/x-httpd-php-source',
        'application/x-sh', 'text/x-shellscript', 'application/x-msdownload', 'application/x-dosexec',
    ];

    public const array UNSAFE_EXTENSIONS = [
        'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'xsl', 'js', 'mjs',
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps', 'pht',
        'sh', 'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp', 'exe', 'dll', 'bat', 'cmd', 'htaccess',
    ];

    public function __construct(private Storage $storage, protected LoggerInterface $logger, private HttpClientInterface $httpClient)
    {
    }

    /**
     * Upload HTTP File Request.
     *
     * @param array<string, string[]>|null $allowedMimes allowed types per request key
     *
     * @return Media[]
     */
    public function uploadHttpFile(Request $request, ?array $keys = null, array $options = [], ?array $allowedMimes = null): array
    {
        $data = $keys ? array_intersect_key($request->files->all(), array_flip($keys)) : $request->files->all();

        // Convert to Media Entity
        foreach ($data as $key => $files) {
            $items = is_array($files) ? $files : [$files];
            $this->assertMaxFiles($items, $options, (string) $key);

            array_walk_recursive($items, function (&$item) use ($key, $allowedMimes, $options) {
                if (!$item instanceof UploadedFile || UPLOAD_ERR_OK !== $item->getError()) {
                    throw new FileValidationException(code: 422, errors: [$key => ['Invalid file content.']]);
                }

                $mimeType = $item->getMimeType() ?? 'application/octet-stream';
                $this->assertAllowedMime($mimeType, $allowedMimes[$key] ?? null, (string) $key);

                $item = $this->createMedia($item->getContent(), $mimeType, $item->guessExtension() ?? 'bin', $item->getSize(), $options, (string) $key);
            });

            $data[$key] = is_array($files) ? $items : $items[0];
        }

        return $data;
    }

    /**
     * Upload HTTP Base64 Request.
     *
     * @return Media[][]
     */
    public function uploadHttpBase64(Request $request, array $keys, ?array $allowedMimes = null, array $options = []): array
    {
        $data = array_intersect_key($request->request->all(), array_flip($keys));

        // Convert to Media Entity
        array_walk($data, function (&$files, $key) use ($allowedMimes, $options) {
            $items = !is_array($files) ? [$files] : $files;
            $this->assertMaxFiles($items, $options, $key);

            foreach ($items as $index => $item) {
                if (!is_string($item)) {
                    throw new FileValidationException(code: 422, errors: [$key => ['Invalid file content.']]);
                }

                $header = $this->findHeaderBase64($item, $allowedMimes[$key] ?? null, $key);
                $items[$index] = $this->createMedia($header['content'], $header['mimeType'], $header['extension'], $header['size'], $options, $key);
            }

            $files = $items;
        });

        return $data;
    }

    /**
     * Upload HTTP Link Request.
     *
     * Links that fail to download or have a disallowed type are logged and left out of the result.
     *
     * @return Media[][]
     */
    public function uploadHttpLink(Request $request, array $keys, ?array $allowedMimes = null, array $options = []): array
    {
        $data = array_intersect_key($request->request->all(), array_flip($keys));

        // Convert to Media Entity
        array_walk($data, function (&$files, $key) use ($allowedMimes, $options) {
            $items = !is_array($files) ? [$files] : $files;
            $this->assertMaxFiles($items, $options, $key);

            // Downloads run concurrently; a failed one only drops its own item.
            $contents = $this->download(array_filter($items, 'is_string'), $options);
            foreach (array_keys($items) as $index) {
                try {
                    $content = $contents[$index] ?? throw new \RuntimeException('Invalid link.');
                    $header = $this->findHeader($content, $allowedMimes[$key] ?? null, $key);
                    $items[$index] = $this->createMedia($content, $header['mimeType'], $header['extension'], $header['size'], $options, $key);
                } catch (\Exception $exception) {
                    $this->logger->error('Link File Upload Failed: '.$exception->getMessage());
                    unset($items[$index]);
                }
            }

            $files = $items;
        });

        return $data;
    }

    /**
     * Upload from Base64 string.
     */
    public function uploadFromBase64(string $base64, ?array $allowedMimes = null, array $options = []): Media
    {
        $header = $this->findHeaderBase64($base64, $allowedMimes);

        return $this->createMedia($header['content'], $header['mimeType'], $header['extension'], $header['size'], $options);
    }

    /**
     * Upload from UploadedFile.
     */
    public function uploadFromUploadedFile(UploadedFile $file, ?array $allowedMimes = null, array $options = []): Media
    {
        // The detected type and its extension are trusted, never the client-supplied ones.
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        $this->assertAllowedMime($mimeType, $allowedMimes);

        return $this->createMedia($file->getContent(), $mimeType, $file->guessExtension() ?? 'bin', $file->getSize(), $options);
    }

    /**
     * Upload from URL.
     */
    public function uploadFromUrl(string $fileUrl, ?array $allowedMimes = null, array $options = []): Media
    {
        $file = $this->download([$fileUrl], $options, true)[0];
        $header = $this->findHeader($file, $allowedMimes);

        return $this->createMedia($file, $header['mimeType'], $header['extension'], $header['size'], $options);
    }

    /**
     * Upload from raw content.
     */
    public function uploadFromContent(string $content, string $mimeType, string $extension, ?array $allowedMimes = null, array $options = []): Media
    {
        $this->assertAllowedMime($mimeType, $allowedMimes);

        return $this->createMedia($content, $mimeType, $extension, strlen($content), $options);
    }

    /**
     * Upload from validated data array.
     *
     * @param array{content: string, mimeType: string, extension: string, size: int} $data
     */
    public function uploadFromData(array $data, ?array $allowedMimes = null, array $options = []): Media
    {
        $this->assertAllowedMime($data['mimeType'], $allowedMimes);

        return $this->createMedia($data['content'], $data['mimeType'], $data['extension'], $data['size'], $options);
    }

    /**
     * Options:
     *  - imageCompress, imageConvertJPG, imageQuality, imageWidth, imageHeight: image processing
     *  - imageMaxPixels: images above this pixel count are refused before decoding (decompression bombs)
     *  - private: write to the device's private bucket
     *  - storage: device key, falls back to the default device when unknown
     *  - maxSize: refuse content larger than this many bytes (null = no limit)
     *  - allowUnsafe: accept {@see self::UNSAFE_MIMES} / {@see self::UNSAFE_EXTENSIONS}
     *
     * The stored size is the byte count of what lands in storage, after compression; $size is
     * kept for backward compatibility and no longer used.
     */
    public function createMedia(string $content, string $mimeType, string $extension, int $size, array $options = [], ?string $reqKey = null): Media
    {
        // Set default options
        $options = array_merge([
            'imageCompress' => true,
            'imageConvertJPG' => true,
            'imageQuality' => 75,
            'imageHeight' => 1280,
            'imageWidth' => 720,
            'imageMaxPixels' => 40_000_000,
            'private' => false,
            'maxSize' => null,
            'allowUnsafe' => false,
        ], $options);

        $mimeType = strtolower(trim($mimeType));
        $extension = $this->normalizeExtension($extension);

        if (!$options['allowUnsafe'] && (in_array($mimeType, self::UNSAFE_MIMES, true) || in_array($extension, self::UNSAFE_EXTENSIONS, true))) {
            throw $this->validationError('Invalid file type.', $reqKey);
        }

        if (null !== $options['maxSize'] && strlen($content) > $options['maxSize']) {
            throw $this->validationError('File size exceeds the maximum allowed size.', $reqKey);
        }

        // Convert JPG
        if ($options['imageConvertJPG']) {
            $extension = match ($extension) {
                'png', 'jpeg' => 'jpg',
                default => $extension,
            };
            $mimeType = match ($extension) {
                'jpg' => 'image/jpeg',
                default => $mimeType,
            };
        }

        // Compress
        if ($options['imageCompress']) {
            try {
                $content = $this->compress($content, $extension, $mimeType, $options);
            } catch (\Throwable) {
                throw $this->validationError('Invalid file content.', $reqKey);
            }
        }

        // Write Storage. An optional 'storage' option routes the object to a specific
        // device (e.g. an org's region storage); an unknown device falls back to the
        // default so callers can pass a region name that isn't configured everywhere.
        $deviceKey = $this->storage->getStorageKey();
        if (!empty($options['storage']) && $this->storage->hasDevice($options['storage'])) {
            $deviceKey = $options['storage'];
        }
        $path = strtolower(date('Y/m').'/'.Ulid::generate().'.'.$extension);
        $device = $this->storage->device($deviceKey);
        $metadata = [];
        if ($options['private']) {
            $device = $device->private();
        } else {
            $metadata['CacheControl'] = 'public, max-age=31536000, immutable';
        }
        $device->write($content, $path, $mimeType, $metadata);

        // Create Media
        return new Media()
            ->setMime($mimeType)
            ->setSize(strlen($content))
            ->setPath($path)
            ->setStorage($deviceKey)
            ->setPrivate((bool) $options['private']);
    }

    public function compress(string $data, string $extension, string $mimeType, array $options = []): string
    {
        if (!in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            return $data;
        }

        // GD allocates width*height*4 bytes outside PHP's memory_limit; a few hundred KB of PNG
        // can claim gigabytes. Read the dimensions from the header and refuse before decoding.
        $info = getimagesizefromstring($data);
        if (false === $info) {
            throw new \InvalidArgumentException('Invalid image.');
        }
        if ($info[0] * $info[1] > ($options['imageMaxPixels'] ?? 40_000_000)) {
            throw new \InvalidArgumentException('Image dimensions are too large.');
        }

        return new SimpleImage()->fromString($data)->autoOrient()
            ->bestFit($options['imageWidth'] ?? 720, $options['imageHeight'] ?? 1280)
            ->toString($mimeType, $options['imageQuality'] ?? 75);
    }

    /**
     * Find file header information from content.
     *
     * @return array{mimeType: string, extension: string, size: int}
     */
    public function findHeader(string $content, ?array $allowedMimes = null, ?string $key = null): array
    {
        $mimeType = finfo_buffer(finfo_open(), $content, FILEINFO_MIME_TYPE) ?: 'application/octet-stream';
        $this->assertAllowedMime($mimeType, $allowedMimes, $key);

        return [
            'mimeType' => $mimeType,
            'extension' => MimeTypes::getDefault()->getExtensions($mimeType)[0] ?? 'bin',
            'size' => strlen($content),
        ];
    }

    /**
     * Find file header information from base64 string.
     *
     * @return array{mimeType: string, extension: string, size: int, content: string}
     */
    public function findHeaderBase64(string $base64, ?array $allowedMimes = null, ?string $key = null): array
    {
        $data = explode(',', $base64, 2);
        $content = base64_decode($data[1] ?? $data[0], true);
        if (false === $content || '' === $content) {
            throw $this->validationError('Invalid file content.', $key);
        }

        $header = $this->findHeader($content, $allowedMimes, $key);
        $header['content'] = $content;

        return $header;
    }

    public function save(Media|array $media, EntityManagerInterface|ObjectManager $entityManager): void
    {
        $items = is_array($media) ? $media : [$media];

        array_walk_recursive($items, fn ($item) => $entityManager->persist($item));

        $entityManager->flush();
    }

    /**
     * Download remote files concurrently.
     *
     * The client refuses private, loopback and link-local targets, redirects included, unless the
     * 'allowPrivateNetwork' option is set. Each body is capped at 'maxSize' (default 20 MB).
     *
     * @param array<int|string, string> $urls
     *
     * @return array<int|string, string> contents keyed like $urls; failed downloads are missing
     */
    private function download(array $urls, array $options, bool $throw = false): array
    {
        $maxSize = $options['maxSize'] ?? 20 * 1024 * 1024;
        $client = empty($options['allowPrivateNetwork']) ? new NoPrivateNetworkHttpClient($this->httpClient) : $this->httpClient;

        $responses = [];
        foreach ($urls as $index => $url) {
            try {
                if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                    throw new \InvalidArgumentException('Only http and https links are supported.');
                }

                $responses[$index] = $client->request('GET', $url, [
                    'timeout' => $options['downloadTimeout'] ?? 10,
                    'max_duration' => $options['downloadMaxDuration'] ?? 30,
                    'max_redirects' => 3,
                    'user_data' => $index,
                ]);
            } catch (\Throwable $exception) {
                $this->downloadFailed($url, $exception, $throw);
            }
        }

        $contents = [];
        $buffers = [];
        $failed = [];
        foreach ($client->stream($responses) as $response => $chunk) {
            /** @var ResponseInterface $response */
            $index = $response->getInfo('user_data');
            if (isset($failed[$index])) {
                continue;
            }

            try {
                if ($chunk->isFirst()) {
                    if (200 !== $response->getStatusCode()) {
                        throw new \RuntimeException('HTTP '.$response->getStatusCode());
                    }
                    $length = $response->getHeaders()['content-length'][0] ?? null;
                    if (null !== $length && (int) $length > $maxSize) {
                        throw new \RuntimeException('File size exceeds the maximum allowed size.');
                    }
                    $buffers[$index] = '';
                }

                $buffers[$index] .= $chunk->getContent();
                if (strlen($buffers[$index]) > $maxSize) {
                    throw new \RuntimeException('File size exceeds the maximum allowed size.');
                }

                if ($chunk->isLast()) {
                    $contents[$index] = $buffers[$index];
                    unset($buffers[$index]);
                }
            } catch (\Throwable $exception) {
                $response->cancel();
                $failed[$index] = true;
                unset($buffers[$index]);
                $this->downloadFailed($urls[$index], $exception, $throw);
            }
        }

        return $contents;
    }

    private function downloadFailed(string $url, \Throwable $exception, bool $throw): void
    {
        if ($throw) {
            throw new FileValidationException(code: 422, errors: ['Link could not be downloaded.'], previous: $exception);
        }

        $this->logger->error('Link File Download Failed: '.$url.' '.$exception->getMessage());
    }

    private function assertAllowedMime(string $mimeType, ?array $allowedMimes, ?string $key = null): void
    {
        if ($allowedMimes && !in_array($mimeType, $allowedMimes, true)) {
            throw $this->validationError('Invalid file type.', $key);
        }
    }

    private function assertMaxFiles(array $items, array $options, string $key): void
    {
        $count = 0;
        array_walk_recursive($items, static function () use (&$count) {
            ++$count;
        });

        if ($count > ($options['maxFiles'] ?? 20)) {
            throw $this->validationError('Too many files.', $key);
        }
    }

    /**
     * Only the bare extension reaches the storage key: no dots, separators or other characters.
     */
    private function normalizeExtension(string $extension): string
    {
        $extension = strtolower(preg_replace('/[^a-z0-9]/i', '', $extension) ?? '');

        return '' === $extension ? 'bin' : substr($extension, 0, 10);
    }

    private function validationError(string $message, ?string $key = null): FileValidationException
    {
        return new FileValidationException(code: 422, errors: $key ? [$key => [$message]] : [$message]);
    }
}
