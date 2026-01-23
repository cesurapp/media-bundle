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

    public function testToStringPublic(): void
    {
        $media = new Media()
            ->setPath('test/path/file.jpg')
            ->setPublic();

        $result = $media->toString();

        $this->assertEquals($result, $media->getId()->toRfc4122().'.jpg');
    }

    public function testToStringAuth(): void
    {
        $media = new Media()
            ->setPath('test/path/file.jpg')
            ->setAuth();

        $result = $media->toString();
        $this->assertTrue($media->isAuth());
        $this->assertEquals($result, $media->getId()->toRfc4122().'.jpg');
    }

    public function testToStringWithoutPublicAuth(): void
    {
        $media = new Media()->setPath('test/path/file.jpg');

        $result = $media->toString();
        $this->assertFalse($media->isPublic());
        $this->assertFalse($media->isAuth());

        $this->assertStringContainsString('.jpg', $result);
        $this->assertStringContainsString('.jpg?t=', $result);
        $this->assertStringContainsString('&s=', $result);
    }

    public function testToStringWithoutSignature(): void
    {
        $media = new Media();
        $media->setPath('test/path/file.jpg');

        $result = $media->toString(false);

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
        $media = new Media()->setPath('test/path/file.jpg');

        $signedUrl = $media->toString(true, 'secret-1');
        $isValid = $media->validateSignedUrl($signedUrl, 'secret-2');

        $this->assertFalse($isValid);
    }

    public function testToStringBackwardCompatibility(): void
    {
        $media = new Media()->setPath('test/path/file.jpg');

        $result = $media->toString(false);

        $this->assertStringContainsString('.jpg', $result);
        $this->assertStringNotContainsString('?', $result);
    }

    public function testSignedUrlAtLast8thMinuteOfHour(): void
    {
        $media = new Media()->setPath('test/path/file.jpg');
        $secret = 'test-secret';

        $simulatedNow = strtotime('2024-01-01 14:52:00');
        $expectedTimestamp = strtotime('2024-01-01 15:00:00');

        $signedUrl = $media->toString(true, $secret, $simulatedNow);

        $query = parse_url($signedUrl, PHP_URL_QUERY);
        parse_str($query, $params);

        $this->assertEquals($expectedTimestamp, (int) $params['t']);

        $isValid = $media->validateSignedUrl($signedUrl, $secret, 3600, $simulatedNow);
        $this->assertTrue($isValid);
    }

    public function testSignedUrlTTLHandling(): void
    {
        $media = new Media()->setPath('test/path/file.jpg');
        $secret = 'test-secret';

        $generationTime = strtotime('2024-01-01 14:45:00');
        $signedUrl = $media->toString(true, $secret, $generationTime);

        $validationTime = strtotime('2024-01-01 15:05:00');

        $this->assertFalse($media->validateSignedUrl($signedUrl, $secret, 3600, $validationTime));

        $this->assertTrue($media->validateSignedUrl($signedUrl, $secret, 7200, $validationTime));
    }
}
