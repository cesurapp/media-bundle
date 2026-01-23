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

    public function isPublic(bool $default = false): bool
    {
        return $this->data['public'] ?? $default;
    }

    public function setAuth(bool $auth = true): self
    {
        $this->addData('auth', $auth);

        return $this;
    }

    public function isAuth(bool $default = false): bool
    {
        return $this->data['auth'] ?? $default;
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

    public function toString(bool $signed = false, ?string $secret = null): string
    {
        $base = sprintf('%s.%s', $this->getId()->toString(), $this->getExtension());
        if ($this->isPublic() || $this->isAuth() || !$signed) {
            return $base;
        }

        // Generate signature for signed URL
        $secret = $secret ?? ($_ENV['APP_SECRET'] ?? 'default_secret');
        $timestamp = time();
        $signature = $this->generateSignature($timestamp, $secret);

        return sprintf('%s?t=%d&s=%s', $base, $timestamp, $signature);
    }

    /**
     * Generate HMAC signature for signed URL.
     */
    private function generateSignature(int $timestamp, string $secret): string
    {
        return hash_hmac(
            'sha256',
            sprintf('%s:%s:%d', $this->getId()->toString(), $this->getExtension(), $timestamp),
            $secret
        );
    }

    /**
     * Validate signed URL.
     */
    public function validateSignedUrl(string $url, ?string $secret = null, int $ttl = 3600): bool
    {
        // Parse query string from URL
        $parts = parse_url($url);
        if (!isset($parts['query'])) {
            return false;
        }

        parse_str($parts['query'], $params);

        if (!isset($params['t']) || !isset($params['s'])) {
            return false;
        }

        $timestamp = (int)$params['t'];
        $providedSignature = $params['s'];

        // Check if signature has expired
        if (time() - $timestamp > $ttl) {
            return false;
        }

        return hash_equals(
            $this->generateSignature($timestamp, $secret ?? ($_ENV['APP_SECRET'] ?? 'default_secret')),
            $providedSignature
        );
    }
}
