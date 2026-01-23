<?php

namespace Cesurapp\MediaBundle\Manager;

use Cesurapp\MediaBundle\Exception\FileValidationException;
use Cesurapp\StorageBundle\Storage\Storage;
use claviska\SimpleImage;
use Doctrine\ORM\EntityManagerInterface;
use Cesurapp\MediaBundle\Entity\Media;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MediaManager
{
    private bool $imageCompress = true;
    private bool $imageConvertJPG = true;
    private int $imageQuality = 75;
    private int $imageHeight = 1280;
    private int $imageWidth = 720;

    public function __construct(
        private readonly Storage $storage,
        private readonly EntityManagerInterface $em,
        protected readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Enable|Disable Image Compressor.
     */
    public function setImageCompress(bool $compress): self
    {
        $this->imageCompress = $compress;

        return $this;
    }

    /**
     * PNG to JPG Converter Enable.
     */
    public function setImageConvertJPG(bool $convertJPG): self
    {
        $this->imageConvertJPG = $convertJPG;

        return $this;
    }

    /**
     * Change Image Quality.
     */
    public function setImageQuality(int $quality): self
    {
        $this->imageQuality = $quality;

        return $this;
    }

    /**
     * Change Max Image Size.
     */
    public function setImageSize(int $height, int $width): self
    {
        $this->imageHeight = $height;
        $this->imageWidth = $width;

        return $this;
    }

    /**
     * Save Media entity or array of Media entities to a database.
     *
     * @param Media|Media[] $media
     */
    public function save(Media|array $media): void
    {
        $items = is_array($media) ? $media : [$media];

        foreach ($items as $item) {
            if (is_array($item)) { // @phpstan-ignore-line
                foreach ($item as $mediaItem) {
                    $this->em->persist($mediaItem);
                }
                continue;
            }
            $this->em->persist($item);
        }

        $this->em->flush();
    }

    /**
     * Upload HTTP File Request.
     *
     * @return Media[]
     */
    public function uploadHttpFile(Request $request, ?array $keys = null): array
    {
        $data = $keys ? array_intersect_key($request->files->all(), array_flip($keys)) : $request->files->all();

        // Convert to Media Entity
        array_walk_recursive($data, function (&$item, $key) {
            try {
                $item = $this->createMedia($item->getMimeType(), $item->getExtension(), $item->getContent(), $item->getSize(), $key);
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
    public function uploadHttpBase64(Request $request, array $keys, ?array $allowedMimes = null): array
    {
        $data = array_intersect_key($request->request->all(), array_flip($keys));

        // Convert to Media Entity
        array_walk($data, function (&$files, $key) use ($allowedMimes) {
            $items = !is_array($files) ? [$files] : $files;

            foreach ($items as $index => $item) {
                $data = explode(',', $item);
                $file = base64_decode($data[1] ?? $data[0]);
                $mimeType = finfo_buffer(finfo_open(), $file, FILEINFO_MIME_TYPE);
                if ($allowedMimes && isset($allowedMimes[$key]) && !in_array($mimeType, $allowedMimes[$key], true)) {
                    throw new FileValidationException(code: 422, errors: [$key => ['Invalid file type.']]);
                }
                $extension = (new MimeTypes())->getExtensions($mimeType)[0];
                $items[$index] = $this->createMedia($mimeType, $extension, $file, strlen($file), $key);
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
    public function uploadHttpLink(Request $request, array $keys, ?array $allowedMimes = null): array
    {
        $data = array_intersect_key($request->request->all(), array_flip($keys));

        // Convert to Media Entity
        array_walk($data, function (&$files, $key) use ($allowedMimes) {
            $items = !is_array($files) ? [$files] : $files;

            foreach ($items as $index => $item) {
                try {
                    $file = $this->httpClient->request('GET', $item)->getContent();
                    $mimeType = finfo_buffer(finfo_open(), $file, FILEINFO_MIME_TYPE);
                    if ($allowedMimes && isset($allowedMimes[$key]) && !in_array($mimeType, $allowedMimes[$key], true)) {
                        throw new FileValidationException(code: 422, errors: [$key => ['Invalid file type.']]);
                    }
                    $extension = (new MimeTypes())->getExtensions($mimeType)[0];
                    $items[$index] = $this->createMedia($mimeType, $extension, $file, strlen($file), $key);
                } catch (\Exception $exception) {
                    $this->logger->error('Link File Upload Failed: '.$exception->getMessage());
                }
            }

            $files = $items;
        });

        return $data;
    }

    /**
     * Upload from Base64 string.
     */
    public function uploadFromBase64(string $base64, ?array $allowedMimes = null): Media
    {
        $data = explode(',', $base64);
        $file = base64_decode($data[1] ?? $data[0]);
        $mimeType = finfo_buffer(finfo_open(), $file, FILEINFO_MIME_TYPE);

        if ($allowedMimes && !in_array($mimeType, $allowedMimes, true)) {
            throw new FileValidationException(code: 422, errors: ['Invalid file type.']);
        }

        $extension = (new MimeTypes())->getExtensions($mimeType)[0];

        return $this->createMedia($mimeType, $extension, $file, strlen($file));
    }

    /**
     * Upload from UploadedFile.
     */
    public function uploadFromUploadedFile(UploadedFile $file, ?array $allowedMimes = null): Media
    {
        $mimeType = $file->getMimeType();

        if ($allowedMimes && !in_array($mimeType, $allowedMimes, true)) {
            throw new FileValidationException(code: 422, errors: ['Invalid file type.']);
        }

        return $this->createMedia($mimeType, $file->getExtension(), $file->getContent(), $file->getSize());
    }

    /**
     * Upload from URL.
     */
    public function uploadFromUrl(string $fileUrl, ?array $allowedMimes = null): Media
    {
        $file = $this->httpClient->request('GET', $fileUrl)->getContent();
        $mimeType = finfo_buffer(finfo_open(), $file, FILEINFO_MIME_TYPE);

        if ($allowedMimes && !in_array($mimeType, $allowedMimes, true)) {
            throw new FileValidationException(code: 422, errors: ['Invalid file type.']);
        }

        $extension = new MimeTypes()->getExtensions($mimeType)[0];

        return $this->createMedia($mimeType, $extension, $file, strlen($file));
    }

    /**
     * Upload from raw content.
     */
    public function uploadFromContent(string $content, string $mimeType, string $extension, ?array $allowedMimes = null): Media
    {
        if ($allowedMimes && !in_array($mimeType, $allowedMimes, true)) {
            throw new FileValidationException(code: 422, errors: ['Invalid file type.']);
        }

        return $this->createMedia($mimeType, $extension, $content, strlen($content));
    }

    public function createMedia(string $mimeType, string $extension, string $content, int $size, ?string $reqKey = null): Media
    {
        // Convert JPG
        if ($this->imageConvertJPG) {
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
        if ($this->imageCompress) {
            try {
                $content = $this->compress($content, $extension);
            } catch (\Throwable) {
                if ($reqKey) {
                    throw new FileValidationException(code: 422, errors: [$reqKey => ['Invalid file content.']]);
                }

                throw new FileValidationException(code: 422, errors: ['Invalid file content.']);
            }
        }

        // Write Storage
        $path = $this->getPath(Ulid::generate(), strtolower($extension));
        $this->storage->write($content, $path, strtolower($mimeType));

        // Create Media
        return new Media()
            ->setMime(strtolower($mimeType))
            ->setSize($size)
            ->setPath($path)
            ->setStorage($this->storage->getStorageKey());
    }

    protected function getPath(string $fileName, string $extension): string
    {
        return strtolower(date('Y/m/d').'/'.$fileName.'.'.$extension);
    }

    protected function compress(string $data, string $extension): string
    {
        return match ($extension) {
            'jpg', 'jpeg', 'png' => new SimpleImage()
                ->fromString($data)
                ->autoOrient()
                ->bestFit($this->imageWidth, $this->imageHeight)
                ->toString(null, $this->imageQuality),
            default => $data,
        };
    }
}
