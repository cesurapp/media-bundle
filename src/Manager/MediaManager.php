<?php

namespace Cesurapp\MediaBundle\Manager;

use Cesurapp\MediaBundle\Exception\FileValidationException;
use Cesurapp\StorageBundle\Storage\Storage;
use claviska\SimpleImage;
use Cesurapp\MediaBundle\Entity\Media;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MediaManager
{
    public function __construct(private readonly Storage $storage, protected readonly LoggerInterface $logger, private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * Upload HTTP File Request.
     *
     * @return Media[]
     */
    public function uploadHttpFile(Request $request, ?array $keys = null, array $options = []): array
    {
        $data = $keys ? array_intersect_key($request->files->all(), array_flip($keys)) : $request->files->all();

        // Convert to Media Entity
        array_walk_recursive($data, function (&$item, $key) use ($options) {
            try {
                $item = $this->createMedia(
                    $item->getContent(),
                    $item->getMimeType(),
                    $item->getExtension(),
                    $item->getSize(),
                    $options,
                    $key,
                );
            } catch (\Exception $exception) {
                $this->logger->error('HTTP File Upload Failed: '.$exception->getMessage());
            }
        });

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

            foreach ($items as $index => $item) {
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
     * @return Media[][]
     */
    public function uploadHttpLink(Request $request, array $keys, ?array $allowedMimes = null, array $options = []): array
    {
        $data = array_intersect_key($request->request->all(), array_flip($keys));

        // Convert to Media Entity
        array_walk($data, function (&$files, $key) use ($allowedMimes, $options) {
            $items = !is_array($files) ? [$files] : $files;

            foreach ($items as $index => $item) {
                try {
                    $file = $this->httpClient->request('GET', $item)->getContent();
                    $header = $this->findHeader($file, $allowedMimes[$key] ?? null, $key);
                    $items[$index] = $this->createMedia($file, $header['mimeType'], $header['extension'], $header['size'], $options, $key);
                } catch (\Exception $exception) {
                    $this->logger->error('Link File Upload Failed: '.$exception->getMessage());
                }
            }

            $files = $items;
        });

        return $data;
    }

    /**
     * Find file header information from content.
     *
     * @return array{mimeType: string, extension: string, size: int}
     */
    private function findHeader(string $content, ?array $allowedMimes = null, ?string $key = null): array
    {
        $mimeType = finfo_buffer(finfo_open(), $content, FILEINFO_MIME_TYPE);

        if ($allowedMimes && !in_array($mimeType, $allowedMimes, true)) {
            if ($key) {
                throw new FileValidationException(code: 422, errors: [$key => ['Invalid file type.']]);
            }
            throw new FileValidationException(code: 422, errors: ['Invalid file type.']);
        }

        return [
            'mimeType' => $mimeType,
            'extension' => new MimeTypes()->getExtensions($mimeType)[0],
            'size' => strlen($content),
        ];
    }

    /**
     * Find file header information from base64 string.
     *
     * @return array{mimeType: string, extension: string, size: int, content: string}
     */
    private function findHeaderBase64(string $base64, ?array $allowedMimes = null, ?string $key = null): array
    {
        $data = explode(',', $base64);
        $content = base64_decode($data[1] ?? $data[0]);
        $header = $this->findHeader($content, $allowedMimes, $key);

        return array_merge($header, ['content' => $content]);
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
        if ($allowedMimes && !in_array($file->getMimeType(), $allowedMimes, true)) {
            throw new FileValidationException(code: 422, errors: ['Invalid file type.']);
        }

        return $this->createMedia($file->getContent(), $file->getMimeType(), $file->getExtension(), $file->getSize(), $options);
    }

    /**
     * Upload from URL.
     */
    public function uploadFromUrl(string $fileUrl, ?array $allowedMimes = null, array $options = []): Media
    {
        $file = $this->httpClient->request('GET', $fileUrl)->getContent();
        $header = $this->findHeader($file, $allowedMimes);

        return $this->createMedia($file, $header['mimeType'], $header['extension'], $header['size'], $options);
    }

    /**
     * Upload from raw content.
     */
    public function uploadFromContent(string $content, string $mimeType, string $extension, ?array $allowedMimes = null, array $options = []): Media
    {
        if ($allowedMimes && !in_array($mimeType, $allowedMimes, true)) {
            throw new FileValidationException(code: 422, errors: ['Invalid file type.']);
        }

        return $this->createMedia($content, $mimeType, $extension, strlen($content), $options);
    }

    public function createMedia(string $content, string $mimeType, string $extension, int $size, array $options = [], ?string $reqKey = null): Media
    {
        // Set default options
        $options = array_merge([
            'imageCompress' => true,
            'imageConvertJPG' => true,
            'imageQuality' => 75,
            'imageHeight' => 1280,
            'imageWidth' => 720,
        ], $options);

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
                $content = $this->compress($content, strtolower($extension), $mimeType, $options);
            } catch (\Throwable) {
                if ($reqKey) {
                    throw new FileValidationException(code: 422, errors: [$reqKey => ['Invalid file content.']]);
                }

                throw new FileValidationException(code: 422, errors: ['Invalid file content.']);
            }
        }

        // Write Storage
        $path = strtolower(date('Y/m/d').'/'.Ulid::generate().'.'.strtolower($extension));
        $this->storage->write($content, $path, strtolower($mimeType));

        // Create Media
        return new Media()
            ->setMime(strtolower($mimeType))
            ->setSize($size)
            ->setPath($path)
            ->setStorage($this->storage->getStorageKey());
    }

    public function compress(string $data, string $extension, string $mimeType, array $options = []): string
    {
        return match ($extension) {
            'jpg', 'jpeg', 'png' => new SimpleImage()->fromString($data)->autoOrient()
                ->bestFit($options['imageWidth'], $options['imageHeight'])
                ->toString($mimeType, $options['imageQuality']),
            default => $data,
        };
    }

    public function save(Media|array $media, EntityManagerInterface|ObjectManager $entityManager): void
    {
        $items = is_array($media) ? $media : [$media];

        array_walk_recursive($items, fn ($item) => $entityManager->persist($item));

        $entityManager->flush();
    }
}
