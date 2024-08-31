<?php

namespace Cesurapp\MediaBundle\Entity;

use Cesurapp\StorageBundle\Storage\Storage;
use Cesurapp\MediaBundle\Repository\MediaRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: MediaRepository::class)]
class Media
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id;

    #[ORM\Column(type: 'string')]
    private string $path;

    #[ORM\Column(type: 'string', length: 40)]
    private string $mime;

    #[ORM\Column(type: 'integer')]
    private int $size;

    #[ORM\Column(type: 'boolean')]
    private bool $approved = true;

    #[ORM\Column(type: 'integer')]
    private int $counter = 1;

    #[ORM\Column(type: 'json', nullable: true)]
    private array $data = [];

    #[ORM\Column(type: 'string', length: 25)]
    private string $storage;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $owner;

    #[ORM\Column(type: 'datetime_immutable', nullable: false)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Ulid
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

    public function isApproved(): bool
    {
        return $this->approved;
    }

    public function setApproved(bool $approved): self
    {
        $this->approved = $approved;

        return $this;
    }

    public function getCounter(): int
    {
        return $this->counter;
    }

    public function incrCounter(): self
    {
        ++$this->counter;

        return $this;
    }

    public function decrCounter(): self
    {
        --$this->counter;

        return $this;
    }

    public function setCounter(int $counter): self
    {
        $this->counter = $counter;

        return $this;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
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

    public function getOwner(): ?string
    {
        return $this->owner;
    }

    public function setOwner(string $ownerId): self
    {
        $this->owner = $ownerId;

        return $this;
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

    /**
     * Read Content.
     */
    public function getContent(Storage $storage): string
    {
        return $storage->device($this->getStorage())->download($this->getPath());
    }

    public function setContent(Storage $storage, string $data, string $mime): bool
    {
        return $storage->write($this->getPath(), $data, $mime);
    }

    public function getResponse(Storage $storage): Response
    {
        return (new Response($this->getContent($storage), 200, [
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, basename($this->getPath())),
            'Content-Type' => $this->getMime(),
            'Content-Length' => $this->getSize(),
        ]))
            ->setPublic()
            ->setMaxAge(86400);
    }

    public function __toString(): string
    {
        return $this->id->toBase32();
    }
}
