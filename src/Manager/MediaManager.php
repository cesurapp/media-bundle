<?php

namespace Cesurapp\MediaBundle\Manager;

use Cesurapp\MediaBundle\Exception\FileValidationException;
use Cesurapp\StorageBundle\Storage\Storage;
use Doctrine\ORM\EntityManagerInterface;
use Cesurapp\MediaBundle\Compressor\Image;
use Cesurapp\MediaBundle\Entity\Media;
use Psr\Log\LoggerInterface;
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
     * Upload HTTP File Request.
     *
     * @return Media[]
     */
    public function uploadFile(Request $request, ?array $keys = null): array
    {
        $data = $keys ? array_intersect_key($request->files->all(), array_flip($keys)) : $request->files->all();

        // Convert to Media Entity
        array_walk_recursive($data, function (&$item, $key) {
            try {
                $item = $this->createMedia(
                    $item->getMimeType(),
                    $item->getExtension(),
                    $item->getContent(),
                    $item->getSize(),
                    true,
                    $key
                );
            } catch (\Exception $exception) {
                $this->logger->error('HTTP File Upload Failed: '.$exception->getMessage());
            }
        });

        // Save
        $this->em->flush();

        return $data;
    }

    /**
     * @return Media[][]
     *
     * @throws FileValidationException
     */
    public function uploadBase64(Request $request, array $keys, ?array $allowedMimes = null): array
    {
        $data = array_intersect_key($request->request->all(), array_flip($keys));

        // Convert to Media Entity
        array_walk_recursive($data, function (&$item, $key) use ($allowedMimes) {
            $data = explode(',', $item);
            $file = base64_decode($data[1] ?? $data[0]);
            $mimeType = finfo_buffer(finfo_open(), $file, FILEINFO_MIME_TYPE);
            if ($allowedMimes && isset($allowedMimes[$key]) && !in_array($mimeType, $allowedMimes[$key], true)) {
                throw new FileValidationException(code: 422, errors: [$key => ['Invalid file type.']]);
            }
            $extension = (new MimeTypes())->getExtensions($mimeType)[0];
            $item = $this->createMedia($mimeType, $extension, $file, strlen($file), true, $key);
        });
        // Save
        $this->em->flush();

        return $data ?? []; // @phpstan-ignore-line
    }

    /**
     * @return Media[][]
     */
    public function uploadLink(Request $request, array $keys, ?array $allowedMimes = null): array
    {
        $data = array_intersect_key($request->request->all(), array_flip($keys));

        // Convert to Media Entity
        array_walk_recursive($data, function (&$item, $key) use ($allowedMimes) {
            try {
                $file = $this->httpClient->request('GET', $item)->getContent();
                $mimeType = finfo_buffer(finfo_open(), $file, FILEINFO_MIME_TYPE);
                if ($allowedMimes && isset($allowedMimes[$key]) && !in_array($mimeType, $allowedMimes[$key], true)) {
                    throw new FileValidationException(code: 422, errors: [$key => ['Invalid file type.']]);
                }
                $extension = (new MimeTypes())->getExtensions($mimeType)[0];
                $item = $this->createMedia($mimeType, $extension, $file, strlen($file), true, $key);
            } catch (\Exception $exception) {
                $this->logger->error('Link File Upload Failed: '.$exception->getMessage());
            }
        });


        // Save
        $this->em->flush();

        return $data;
    }

    public function createMedia(string $mimeType, string $extension, string $content, int $size, bool $persist = true, ?string $reqKey = null): Media
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
                    throw new FileValidationException(code: 422, errors: [$reqKey => 'Invalid file content.']);
                }

                throw new FileValidationException(code: 422, errors: ['Invalid file content.']);
            }
        }

        // Create Media
        $media = (new Media())
            ->setMime($mimeType)
            ->setStorage($this->storage->getStorageKey())
            ->setSize($size)
            ->setPath($this->getPath(Ulid::generate(), $extension));

        if ($persist) {
            $this->em->persist($media);
        }

        // Write Storage
        $this->storage->write($content, $media->getPath(), $media->getMime());

        return $media;
    }

    protected function getPath(string $fileName, string $extension): string
    {
        return strtolower(date('Y/m/d').'/'.$fileName.'.'.$extension);
    }

    protected function compress(string $data, string $extension): string
    {
        return match ($extension) {
            'jpg', 'jpeg', 'png' => Image::create($data)
                ->resize($this->imageHeight, $this->imageWidth)
                ->output($extension, $this->imageQuality),
            default => $data,
        };
    }
}
