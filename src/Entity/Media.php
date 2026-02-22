<?php

namespace Cesurapp\MediaBundle\Entity;

use Cesurapp\StorageBundle\Storage\Storage;
use Cesurapp\MediaBundle\Repository\MediaRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: MediaRepository::class)]
class Media
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private UuidV7 $id;

    #[ORM\Column(type: 'string')]
    private string $path;

    #[ORM\Column(type: 'string', length: 40)]
    private string $mime;

    #[ORM\Column(type: 'integer')]
    private int $size;

    #[ORM\Column(type: Types::JSONB, nullable: true)]
    private array $data = [];

    #[ORM\Column(type: 'string', length: 25)]
    private string $storage;

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
        return $this->owner->toString() === $ownerId;
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
        return $storage->device($this->getStorage())->download($this->getPath());
    }

    public function setContent(Storage $storage, string $data, string $mime): bool
    {
        return $storage->write($this->getPath(), $data, $mime);
    }

    public function getResponse(Storage $storage, int $maxAgeMinute = 1440): Response
    {
        return new Response($this->getContent($storage), 200, [
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $this->getFileName()),
            'Content-Type' => $this->getMime(),
            'Content-Length' => $this->getSize(),
        ])
            ->setPublic()
            ->setSharedMaxAge($maxAgeMinute * 60)
            ->setMaxAge($maxAgeMinute * 60);
    }

    public function getUrl(Storage $storage): string
    {
        return $storage->device($this->getStorage())->getUrl($this->getPath());
    }

    public function getPresignedUrl(Storage $storage, ?\DateTimeImmutable $expires = null): string
    {
        return $storage->device($this->getStorage())->getPresignedUrl($this->getPath(), $expires);
    }

    public function toString(Storage $storage, ?\DateTimeImmutable $expires = null, bool $signed = false): string
    {
        $domain = rtrim((string) $storage->getDomain(), '/');
        if ($domain) {
            $domain .= '/';
        }

        // Public URL
        if ($this->isPublic() && !$signed) {
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
    }

    public function validateSignature(Storage $storage, string $signature): bool
    {
        if ('local' !== $this->getStorage()) {
            return false;
        }

        return $storage->device($this->getStorage())->validateSignedUrl($signature, $this->getPath()); // @phpstan-ignore-line
    }
}
