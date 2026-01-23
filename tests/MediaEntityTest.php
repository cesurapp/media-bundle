<?php

namespace Cesurapp\MediaBundle\Tests;

use Cesurapp\MediaBundle\Entity\Media;
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

    public function testOwnerSetterReturnsMedia(): void
    {
        $media = new Media();
        $uuid = new UuidV7();

        $result = $media->setOwner($uuid);

        $this->assertInstanceOf(Media::class, $result);
        $this->assertSame($media, $result);
    }

    public function testToStringWithoutSignature(): void
    {
        $media = new Media();
        $media->setPath('test/path/file.jpg');

        $result = $media->toString();

        $this->assertStringContainsString('.jpg', $result);
        $this->assertStringNotContainsString('?t=', $result);
        $this->assertStringNotContainsString('&s=', $result);
    }

    public function testToStringWithSignature(): void
    {
        $media = new Media();
        $media->setPath('test/path/file.jpg');

        $result = $media->toString(true, 'test-secret');
        $this->assertStringContainsString('.jpg?t=', $result);
        $this->assertStringContainsString('&s=', $result);
    }

    public function testValidateSignedUrlWithValidSignature(): void
    {
        $media = new Media();
        $media->setPath('test/path/file.jpg');

        $signedUrl = $media->toString(true, 'test-secret');
        $isValid = $media->validateSignedUrl($signedUrl, 'test-secret');

        $this->assertTrue($isValid);
    }

    public function testValidateSignedUrlWithInvalidSignature(): void
    {
        $media = new Media();
        $media->setPath('test/path/file.jpg');

        $signedUrl = $media->toString(true, 'test-secret');
        $tamperedUrl = str_replace('&s=', '&s=invalid', $signedUrl);
        $isValid = $media->validateSignedUrl($tamperedUrl, 'test-secret');

        $this->assertFalse($isValid);
    }

    public function testValidateSignedUrlWithExpiredTimestamp(): void
    {
        $media = new Media();
        $media->setPath('test/path/file.jpg');

        $signedUrl = $media->toString(true, 'test-secret');
        $isValid = $media->validateSignedUrl($signedUrl, 'test-secret', -1);

        $this->assertFalse($isValid);
    }

    public function testValidateSignedUrlWithMissingQueryParams(): void
    {
        $media = new Media();
        $media->setPath('test/path/file.jpg');

        $unsignedUrl = $media->toString();
        $isValid = $media->validateSignedUrl($unsignedUrl, 'test-secret');

        $this->assertFalse($isValid);
    }

    public function testValidateSignedUrlWithWrongSecret(): void
    {
        $media = new Media();
        $media->setPath('test/path/file.jpg');

        $signedUrl = $media->toString(true, 'secret-1');
        $isValid = $media->validateSignedUrl($signedUrl, 'secret-2');

        $this->assertFalse($isValid);
    }

    public function testToStringBackwardCompatibility(): void
    {
        $media = new Media();
        $media->setPath('test/path/file.jpg');

        $result = $media->toString(false);

        $this->assertStringContainsString('.jpg', $result);
        $this->assertStringNotContainsString('?', $result);
    }
}
