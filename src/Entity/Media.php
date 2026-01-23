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

    public function toString(bool $signed = true, ?string $secret = null, ?int $now = null): string
    {
        $base = sprintf('%s.%s', $this->getId()->toString(), $this->getExtension());

        if (!$signed || $this->isPublic() || $this->isAuth()) {
            return $base;
        }

        $secret ??= ($_ENV['APP_SECRET'] ?? 'default_secret');

        $now ??= time();
        $timestamp = (int) (floor(($now + 600) / 3600) * 3600);
        $signature = $this->generateSignature($timestamp, $secret);

        return $base.'?'.http_build_query(['t' => $timestamp, 's' => $signature]);
    }

    public function validateSignedUrl(string $url, ?string $secret = null, int $ttl = 3600, ?int $now = null): bool
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!$query) {
            return false;
        }

        parse_str($query, $params);
        $t = (int) ($params['t'] ?? 0);
        $s = $params['s'] ?? '';
        if (!$t || !$s) {
            return false;
        }

        $now ??= time();
        if ($now > ($t + $ttl) || $now < ($t - 3600)) {
            return false;
        }

        $secret ??= ($_ENV['APP_SECRET'] ?? 'default_secret');

        return hash_equals($this->generateSignature($t, $secret), $s);
    }

    private function generateSignature(int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $this->getId()->toString().':'.$this->getExtension().':'.$timestamp, $secret);
    }
}
