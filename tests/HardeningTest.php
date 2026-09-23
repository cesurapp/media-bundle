<?php

namespace Cesurapp\MediaBundle\Tests;

use Cesurapp\MediaBundle\Entity\Media;
use Cesurapp\MediaBundle\EventListener\MediaRemovedListener;
use Cesurapp\MediaBundle\Exception\FileValidationException;
use Cesurapp\MediaBundle\Manager\MediaManager;
use Cesurapp\MediaBundle\Repository\MediaRepository;
use Cesurapp\MediaBundle\Tests\Entity\TestEntity;
use Cesurapp\MediaBundle\Type\MediaType;
use Cesurapp\StorageBundle\Storage\Storage;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

class HardeningTest extends KernelTestCase
{
    private MediaManager $manager;
    private Storage $storage;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        static::bootKernel();

        $this->manager = self::getContainer()->get(MediaManager::class);
        $this->storage = self::getContainer()->get(Storage::class);
        $this->em = self::getContainer()->get('doctrine')->getManager();

        $metaData = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropSchema($metaData);
        $schemaTool->updateSchema($metaData);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        restore_exception_handler();
    }

    public function testPhpUploadIsRejectedAndClientExtensionIgnored(): void
    {
        $file = $this->tempFile('<?php system($_GET["c"]); ?>');

        $this->expectException(FileValidationException::class);
        $this->manager->uploadFromUploadedFile(new UploadedFile($file, 'shell.php', null, null, true));
    }

    public function testHtmlAndSvgAreRejectedUnlessAllowed(): void
    {
        $request = new Request();
        $request->files->add(['doc' => new UploadedFile($this->tempFile('<html><script>alert(1)</script></html>'), 'x.png', 'image/png', null, true)]);

        try {
            $this->manager->uploadHttpFile($request);
            $this->fail('HTML upload must be rejected.');
        } catch (FileValidationException $exception) {
            $this->assertSame(['doc' => ['Invalid file type.']], $exception->getErrors());
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"></svg>';
        $media = $this->manager->uploadFromContent($svg, 'image/svg+xml', 'svg', null, ['allowUnsafe' => true]);
        $this->assertSame('image/svg+xml', $media->getMime());
    }

    public function testExtensionIsSanitized(): void
    {
        $media = $this->manager->uploadFromContent('plain', 'text/plain', '../../.txt', null);

        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[0-9a-z]+\.txt$#', $media->getPath());
    }

    public function testHttpFileFromTempPathIsCompressedAndSizeIsStoredBytes(): void
    {
        // Real uploads live in extension-less temp files like /tmp/phpA1b2C3
        $path = $this->tempFile(file_get_contents(__DIR__.'/resources/image.png'));
        $request = new Request();
        $request->files->add(['img' => new UploadedFile($path, 'photo.png', null, null, true)]);

        $media = $this->manager->uploadHttpFile($request)['img'];
        $stored = $this->storage->device($media->getStorage())->download($media->getPath());

        $this->assertStringEndsWith('.jpg', $media->getPath());
        $this->assertSame('image/jpeg', $media->getMime());
        $this->assertSame(strlen($stored), $media->getSize());
        $this->assertLessThan(filesize(__DIR__.'/resources/image.png'), $media->getSize());
    }

    public function testHttpFileAllowedMimesPerKey(): void
    {
        $request = new Request();
        $request->files->add(['photos' => [new UploadedFile($this->tempFile(file_get_contents(__DIR__.'/resources/image.png')), 'a.png', null, null, true)]]);

        $this->expectException(FileValidationException::class);
        $this->manager->uploadHttpFile($request, null, [], ['photos' => ['image/jpeg']]);
    }

    public function testDecompressionBombIsRejectedBeforeDecoding(): void
    {
        // A PNG header claiming 30000x30000 pixels: 3.6 GB once decoded by GD
        $ihdr = pack('NNCCCCC', 30000, 30000, 8, 6, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));

        try {
            $this->manager->uploadFromContent($png, 'image/png', 'png');
            $this->fail('An oversized image must be rejected.');
        } catch (FileValidationException) {
            $this->addToAssertionCount(1);
        }

        // Refused from the header alone, not by a failing decode
        $this->expectExceptionMessage('Image dimensions are too large.');
        $this->manager->compress($png, 'png', 'image/png');
    }

    public function testUrlUploadRefusesPrivateNetworkAndOtherSchemes(): void
    {
        $client = new MockHttpClient(fn () => $this->fail('A private address must never be requested.'));
        $manager = new MediaManager($this->storage, new NullLogger(), $client);

        foreach (['http://127.0.0.1/a.png', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/a.png', 'file:///etc/passwd'] as $url) {
            try {
                $manager->uploadFromUrl($url);
                $this->fail('Expected '.$url.' to be refused.');
            } catch (FileValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUrlUploadEnforcesMaxSize(): void
    {
        $body = file_get_contents(__DIR__.'/resources/image.png');
        $manager = new MediaManager($this->storage, new NullLogger(), new MockHttpClient(new MockResponse($body)));

        $this->expectException(FileValidationException::class);
        $manager->uploadFromUrl('http://10.0.0.1/a.png', null, ['allowPrivateNetwork' => true, 'maxSize' => 1024]);
    }

    public function testHttpLinkDropsFailedItems(): void
    {
        $body = file_get_contents(__DIR__.'/resources/image.png');
        $client = new MockHttpClient(static fn (string $method, string $url) => str_contains($url, 'ok') ? new MockResponse($body) : new MockResponse('', ['http_code' => 404]));
        $manager = new MediaManager($this->storage, new NullLogger(), $client);

        $request = new Request();
        $request->request->add(['links' => ['http://10.0.0.1/ok.png', 'http://10.0.0.1/missing.png', 'http://10.0.0.1/ok2.png']]);
        $result = $manager->uploadHttpLink($request, ['links'], ['links' => ['image/png']], ['allowPrivateNetwork' => true]);

        $this->assertCount(2, $result['links']);
        $this->assertContainsOnlyInstancesOf(Media::class, $result['links']);
    }

    public function testTooManyFilesIsRejected(): void
    {
        $request = new Request();
        $request->request->add(['images' => array_fill(0, 3, 'aGVsbG8=')]);

        $this->expectException(FileValidationException::class);
        $this->manager->uploadHttpBase64($request, ['images'], null, ['maxFiles' => 2]);
    }

    public function testResponseHeaders(): void
    {
        $media = $this->manager->uploadFromContent(file_get_contents(__DIR__.'/resources/image.png'), 'image/png', 'png', null, ['private' => true]);
        $media->addFileName('görüşme/kaydı.png');
        $response = $media->getResponse($this->storage);

        $this->assertSame((string) strlen($response->getContent()), $response->headers->get('Content-Length'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('s-maxage', $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));

        $binary = $this->manager->uploadFromContent(random_bytes(64), 'application/octet-stream', 'bin');
        $this->assertStringStartsWith('attachment', $binary->getResponse($this->storage)->headers->get('Content-Disposition'));
        $this->assertStringContainsString('public', $binary->getResponse($this->storage)->headers->get('Cache-Control'));
    }

    public function testHasOwnerWithoutOwner(): void
    {
        $this->assertFalse(new Media()->hasOwner('01890000-0000-7000-8000-000000000000'));
    }

    public function testMediaColumnIsAlwaysJsonList(): void
    {
        $type = new MediaType();
        $first = new Media();
        $second = new Media();

        $json = $type->convertToDatabaseValue([0 => $first, 2 => $second], new SQLitePlatform());

        $this->assertSame(json_encode([$first->getId(), $second->getId()]), $json);
    }

    public function testFileSurvivesRolledBackRemoval(): void
    {
        $media = $this->manager->uploadFromContent('keep me', 'text/plain', 'txt');
        $this->manager->save($media, $this->em);
        $device = $this->storage->device($media->getStorage());

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $this->em->remove($media);
        $this->em->flush();
        $this->assertTrue($device->exists($media->getPath()), 'Nothing is deleted before commit.');
        $connection->rollBack();

        self::getContainer()->get(MediaRemovedListener::class)->reset();
        $this->assertTrue($device->exists($media->getPath()), 'A rolled-back removal keeps the file.');
    }

    public function testFileIsDeletedAfterCommittedExplicitTransaction(): void
    {
        $media = $this->manager->uploadFromContent('drop me', 'text/plain', 'txt');
        $this->manager->save($media, $this->em);
        $device = $this->storage->device($media->getStorage());

        $this->em->wrapInTransaction(function () use ($media) {
            $this->em->remove($media);
            $this->em->flush();
        });
        $this->assertTrue($device->exists($media->getPath()));

        self::getContainer()->get(MediaRemovedListener::class)->reset();
        $this->assertFalse($device->exists($media->getPath()));
    }

    public function testOwnerRemovalDeletesMediaInSameFlush(): void
    {
        $media = $this->manager->uploadFromContent('owned', 'text/plain', 'txt');
        $entity = new TestEntity()->setMedia([$media]);
        $this->em->persist($media);
        $this->em->persist($entity);
        $this->em->flush();

        $this->em->remove($entity);
        $this->em->flush();

        $this->assertSame([], $this->em->getRepository(Media::class)->findAll());
        $this->assertFalse($this->storage->device($media->getStorage())->exists($media->getPath()));
    }

    public function testRemovingUnloadedReferenceDeletesItsFile(): void
    {
        $media = $this->manager->uploadFromContent('lazy', 'text/plain', 'txt');
        $entity = new TestEntity()->setMedia([$media]);
        $this->em->persist($media);
        $this->em->persist($entity);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->find(TestEntity::class, $entity->getId());
        $references = $loaded->getMedia();
        $this->assertTrue($this->em->getUnitOfWork()->isUninitializedObject(reset($references)));

        $this->em->remove($loaded);
        $this->em->flush();

        $this->assertSame([], $this->em->getRepository(Media::class)->findAll());
        $this->assertFalse($this->storage->device($media->getStorage())->exists($media->getPath()));
    }

    public function testPreloadInitializesReferencesInOneQuery(): void
    {
        $medias = array_map(fn () => $this->manager->uploadFromContent('x', 'text/plain', 'txt'), range(1, 3));
        $entity = new TestEntity()->setMedia($medias);
        array_walk($medias, $this->em->persist(...));
        $this->em->persist($entity);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->em->find(TestEntity::class, $entity->getId());
        $uow = $this->em->getUnitOfWork();
        foreach ($loaded->getMedia() as $reference) {
            $this->assertTrue($uow->isUninitializedObject($reference));
        }

        self::getContainer()->get(MediaRepository::class)->preload([$loaded->getMedia()]);

        foreach ($loaded->getMedia() as $reference) {
            $this->assertFalse($uow->isUninitializedObject($reference));
        }
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'php');
        file_put_contents($path, $content);

        return $path;
    }
}
