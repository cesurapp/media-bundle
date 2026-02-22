<?php

namespace Cesurapp\MediaBundle\Tests;

use Cesurapp\MediaBundle\Entity\Media;
use Cesurapp\StorageBundle\Driver\Local;
use Cesurapp\StorageBundle\Storage\Storage;
use Symfony\Component\Uid\UuidV7;
use PHPUnit\Framework\TestCase;

class MediaEntityTest extends TestCase
{
    public function testOwnerIsUuidV7(): void
    {
        $media = new Media();
        $uuid = new UuidV7();

        $media->setOwner($uuid);

        $this->assertInstanceOf(UuidV7::class, $media->getOwner());
        $this->assertEquals($uuid, $media->getOwner());
    }

    public function testOwnerCanBeNull(): void
    {
        $media = new Media();

        $this->assertNull($media->getOwner());

        $media->setOwner(null);

        $this->assertNull($media->getOwner());
    }

    public function testToStringPublic(): void
    {
        $media = new Media()
            ->setStorage('local')
            ->setPath('test/path/file.jpg')
            ->setPublic();

        $storage = new Storage('local', ['local' => new Local(__DIR__.'/../var/cache')]);
        $result = $media->toString($storage);

        $this->assertEquals($result, $media->getId()->toRfc4122().'.jpg');
    }

    public function testToStringWithoutSignature(): void
    {
        $media = new Media()
            ->setStorage('local')
            ->setPath('test/path/file.jpg');

        $storage = new Storage('local', ['local' => new Local(__DIR__.'/../var/cache')]);
        $result = $media->toString($storage);

        $this->assertStringContainsString('.jpg', $result);
        $this->assertStringNotContainsString('?t=', $result);
        $this->assertStringNotContainsString('&s=', $result);
    }

    public function testToStringWithSignature(): void
    {
        $media = new Media()
            ->setStorage('local')
            ->setPath('test/path/file.jpg');

        $storage = new Storage('local', ['local' => new Local(__DIR__.'/../var/cache', 'https://example.com')]);
        $result = $media->toString($storage, null, true);
        $this->assertStringContainsString('.jpg?t=', $result);
        $this->assertStringContainsString('&s=', $result);
    }

    public function testValidateSignedUrlWithValidSignature(): void
    {
        $media = new Media()
            ->setStorage('local')
            ->setPath('test/path/file.jpg');

        $storage = new Storage('local', ['local' => new Local(__DIR__.'/../var/cache')]);
        $signedUrl = $media->toString($storage, null, true);
        $isValid = $media->validateSignature($storage, $signedUrl);

        $this->assertTrue($isValid);
    }

    public function testValidateSignedUrlWithInvalidSignature(): void
    {
        $media = new Media()
            ->setStorage('local')
            ->setPath('test/path/file.jpg');

        $storage = new Storage('local', ['local' => new Local(__DIR__.'/../var/cache')]);
        $signedUrl = $media->toString($storage, null, true);
        $tamperedUrl = str_replace('&s=', '&s=invalid', $signedUrl);
        $isValid = $media->validateSignature($storage, $tamperedUrl);

        $this->assertFalse($isValid);
    }

    public function testLocalStorageToStringUrl(): void
    {
        $media = new Media()
            ->setStorage('local')
            ->setPath('test/path/file.jpg');

        $storage = new Storage('local', ['local' => new Local(__DIR__.'/../var/cache', 'https://example.com')]);
        $signedUrl = $media->toString($storage, null, false);
        $this->assertStringContainsString('https://example.com', $signedUrl);
    }

    public function testLocalStorageToStringUrlSigned(): void
    {
        $media = new Media()
            ->setStorage('local')
            ->setPath('test/path/file.jpg');

        $storage = new Storage('local', ['local' => new Local(__DIR__.'/../var/cache', 'https://example.com')]);
        $signedUrl = $media->toString($storage, null, true);
        $this->assertStringContainsString('https://example.com', $signedUrl);
        $this->assertStringContainsString('.jpg?t=', $signedUrl);
        $this->assertStringContainsString('&s=', $signedUrl);
    }
}
