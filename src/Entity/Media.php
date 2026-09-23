<?php

namespace Cesurapp\MediaBundle\Entity;

use Cesurapp\StorageBundle\Client\DriverInterface;
use Cesurapp\StorageBundle\Storage\Storage;
use Cesurapp\MediaBundle\Repository\MediaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: MediaRepository::class)]
#[ORM\Index(columns: ['owner'])]
#[ORM\Index(columns: ['status', 'created_at'])]
class Media
{
    /**
     * The row exists but its object has not landed in storage yet — a presigned upload was handed
     * out and never confirmed. Such media are not servable and are swept once they go stale.
     */
    public const string STATUS_PENDING = 'pending';

    /**
     * The object is in storage. Media written through MediaManager are born ready, which is also
     * the column default, so writers that predate this column keep producing valid rows.
     */
    public const string STATUS_READY = 'ready';

    /**
     * Types a browser may render in place without running scripts in the serving origin. Anything
     * else is served as an attachment so a stored html/svg/xml file cannot execute as the app.
     */
    public const array INLINE_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp', 'image/x-icon',
        'image/vnd.microsoft.icon', 'application/pdf', 'text/plain',
    ];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private UuidV7 $id;

    #[ORM\Column(type: 'string')]
    private string $path;

    #[ORM\Column(type: 'string', length: 255)]
    private string $mime;

    #[ORM\Column(type: 'bigint')]
    private int $size;

    #[ORM\Column(type: Types::JSONB, nullable: true)]
    private array $data = [];

    #[ORM\Column(type: 'string', length: 25)]
    private string $storage;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $private = false;

    #[ORM\Column(type: 'string', length: 20, options: ['default' => self::STATUS_READY])]
    private string $status = self::STATUS_READY;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?UuidV7 $owner = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: false)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = UuidV7::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): UuidV7
    {
        return $this->id;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function getMime(): string
    {
        return $this->mime;
    }

    public function setMime(string $mime): self
    {
        $this->mime = $mime;

        return $this;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function getData(?string $key = null, mixed $default = null): mixed
    {
        return $key ? ($this->data[$key] ?? $default) : $this->data;
    }

    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function addData(string $key, mixed $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }

    public function removeData(string $key): self
    {
        unset($this->data[$key]);

        return $this;
    }

    public function getFileName(): string
    {
        return $this->getData('filename', basename($this->getPath()));
    }

    public function addFileName(string $name): self
    {
        return $this->addData('filename', $name);
    }

    public function setPublic(bool $public = true): self
    {
        $this->addData('public', $public);

        return $this;
    }

    public function isPublic(bool $default = true): bool
    {
        return $this->data['public'] ?? $default;
    }

    public function getStorage(): string
    {
        return $this->storage;
    }

    public function setStorage(string $storage): self
    {
        $this->storage = $storage;

        return $this;
    }

    public function isPrivate(): bool
    {
        return $this->private;
    }

    public function setPrivate(bool $private = true): self
    {
        $this->private = $private;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isReady(): bool
    {
        return self::STATUS_READY === $this->status;
    }

    public function markReady(): self
    {
        $this->status = self::STATUS_READY;

        return $this;
    }

    public function markPending(): self
    {
        $this->status = self::STATUS_PENDING;

        return $this;
    }

    private function resolveDevice(Storage $storage): DriverInterface
    {
        $device = $storage->device($this->getStorage());

        return $this->private ? $device->private() : $device;
    }

    public function getOwner(): ?UuidV7
    {
        return $this->owner;
    }

    public function setOwner(?UuidV7 $ownerId): self
    {
        $this->owner = $ownerId;

        return $this;
    }

    public function hasOwner(string $ownerId): bool
    {
        return null !== $this->owner && $this->owner->toString() === $ownerId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getExtension(): string
    {
        return pathinfo($this->path, PATHINFO_EXTENSION);
    }

    public function getContent(Storage $storage): string
    {
        return $this->resolveDevice($storage)->download($this->getPath());
    }

    public function setContent(Storage $storage, string $data, string $mime): bool
    {
        return $this->resolveDevice($storage)->write($data, $this->getPath(), $mime);
    }

    public function getResponse(Storage $storage, int $maxAgeMinute = 1440): Response
    {
        $content = $this->getContent($storage);
        $inline = in_array($this->getMime(), self::INLINE_MIMES, true)
            || str_starts_with($this->getMime(), 'audio/')
            || str_starts_with($this->getMime(), 'video/');
        $fileName = str_replace(['/', '\\'], '_', $this->getFileName());

        $response = new Response($content, 200, [
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $fileName,
                $this->asciiFileName($fileName)
            ),
            'Content-Type' => $this->getMime(),
            'Content-Length' => (string) strlen($content),
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // A private file must never land in a shared cache (CDN, reverse proxy).
        if ($this->isPrivate() || !$this->isPublic()) {
            return $response->setPrivate()->setMaxAge($maxAgeMinute * 60);
        }

        return $response
            ->setPublic()
            ->setSharedMaxAge($maxAgeMinute * 60)
            ->setMaxAge($maxAgeMinute * 60);
    }

    private function asciiFileName(string $fileName): string
    {
        $ascii = preg_replace('/[^\x20-\x7e]|%/', '_', $fileName);

        return '' === $ascii || null === $ascii ? 'file' : $ascii;
    }

    public function getUrl(Storage $storage): string
    {
        return $this->resolveDevice($storage)->getUrl($this->getPath());
    }

    public function getPresignedUrl(Storage $storage, ?\DateTimeImmutable $expires = null): string
    {
        return $this->resolveDevice($storage)->getPresignedUrl($this->getPath(), $expires);
    }

    /**
     * URL a third party can PUT this media's bytes to, without holding storage credentials.
     * Pair it with a pending row: mark the media ready once the upload is confirmed.
     */
    public function getPresignedPutUrl(Storage $storage, ?\DateTimeImmutable $expires = null): string
    {
        return $this->resolveDevice($storage)->getPresignedPutUrl($this->getPath(), $expires);
    }

    public function toString(Storage $storage, ?\DateTimeImmutable $expires = null, bool $signed = false): string
    {
        $domain = rtrim((string) $storage->getDomain(), '/');
        if ($domain) {
            $domain .= '/';
        }

        try {
            // Public URL
            if ($this->isPublic() && !$signed && !$this->isPrivate()) {
                if ('local' !== $this->getStorage()) {
                    return $this->getUrl($storage);
                }

                return sprintf('%s%s.%s', $domain, $this->getId()->toString(), $this->getExtension());
            }

            // Signed URL
            if ('local' !== $this->getStorage()) {
                return $this->getPresignedUrl($storage, $expires);
            }

            return sprintf(
                '%s%s.%s?%s',
                $domain,
                $this->getId()->toString(),
                $this->getExtension(),
                $this->getPresignedUrl($storage, $expires)
            );
        } catch (EntityNotFoundException) {
            return '';
        }
    }

    public function validateSignature(Storage $storage, string $signature): bool
    {
        if ('local' !== $this->getStorage()) {
            return false;
        }

        return $this->resolveDevice($storage)->validateSignedUrl($signature, $this->getPath()); // @phpstan-ignore-line
    }
}
