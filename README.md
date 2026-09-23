# Media Bundle

[![App Tester](https://github.com/cesurapp/media-bundle/actions/workflows/testing.yaml/badge.svg)](https://github.com/cesurapp/media-bundle/actions/workflows/testing.yaml)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?logo=Unlicense)](LICENSE.md)

Media management bundle for Symfony, built on top of `cesurapp/storage-bundle`.

**Features:**
- Uploads from HTTP files, base64, remote URLs and raw content
- Image compression, resizing and PNG → JPG conversion
- Media columns stored as JSONB id lists on your entities
- Storage object deleted when the `Media` row is deleted (after commit)
- Safe defaults: active content (html, svg, php…) refused, SSRF-protected downloads, image size limits

## Installation

**Requirements:** PHP 8.4+, Symfony 8.1+, `ext-gd`, `ext-fileinfo`

```bash
composer require cesurapp/media-bundle
```

## Quick Start

### 1. Add a Media Column to an Entity

```php
use Cesurapp\MediaBundle\Entity\MediaSuperClass;
use Cesurapp\MediaBundle\Entity\Traits\MediaTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class Post extends MediaSuperClass
{
    use MediaTrait;

    // Columns whose media are removed together with the entity
    public function getMediaColumns(): array
    {
        return ['media'];
    }
}
```

`MediaTrait`, `AvatarTrait` and `LogoTrait` ship with the bundle. Copy one and rename it for another column.

### 2. Upload

```php
use Cesurapp\MediaBundle\Manager\MediaManager;

// HTTP multipart files, optional key filter and allowed types per key
$medias = $manager->uploadHttpFile($request, ['photos'], [], ['photos' => ['image/jpeg', 'image/png']]);

// Base64 fields
$medias = $manager->uploadHttpBase64($request, ['image'], ['image' => ['image/png', 'image/jpeg']]);

// Remote links (private networks refused, 20 MB default limit)
$medias = $manager->uploadHttpLink($request, ['imageUrl'], ['imageUrl' => ['image/png']]);

// Single sources
$media = $manager->uploadFromUploadedFile($file, ['image/png']);
$media = $manager->uploadFromBase64($base64, ['image/png']);
$media = $manager->uploadFromUrl($url, ['image/png']);
$media = $manager->uploadFromContent($content, 'image/png', 'png');
$media = $manager->uploadFromData($dto->validated('avatar')); // from Base64FileValidator

$manager->save($medias, $em); // persist + flush
$post->addMedia($medias);
```

### 3. Options

Every upload method takes an `$options` array:

| Option | Default | |
|---|---|---|
| `imageCompress` | `true` | Re-encode jpg/png |
| `imageConvertJPG` | `true` | Convert png/jpeg to jpg |
| `imageQuality` | `75` | Encoder quality |
| `imageWidth` / `imageHeight` | `720` / `1280` | Best-fit box |
| `imageMaxPixels` | `40000000` | Images above this pixel count are refused before decoding |
| `private` | `false` | Write to the device's private bucket |
| `storage` | default device | Device key; unknown keys fall back to the default |
| `maxSize` | `null` (links: 20 MB) | Max bytes |
| `maxFiles` | `20` | Max files per request key (HTTP helpers) |
| `allowUnsafe` | `false` | Accept html, svg, xml, js, php, executables |
| `allowPrivateNetwork` | `false` | Let link downloads reach private/loopback addresses |
| `downloadTimeout` / `downloadMaxDuration` | `10` / `30` | Seconds |

### 4. Access

```php
$media = $post->getMedia();          // array<string, Media>
$first = $user->getAvatarFirst();

$media->toString($storage);          // public or signed URL
$media->getResponse($storage);       // HTTP response (nosniff, private files never shared-cached)
$media->getContent($storage);

// Media columns hold lazy references: load a whole list in one query
$mediaRepository->preload(array_map(fn (User $u) => $u->getAvatar(), $users));
```

### 5. Delete

```php
$em->remove($media);
$em->flush(); // object deleted from storage once the transaction commits

$em->remove($post);
$em->flush(); // MediaSuperClass removes the post's media in the same flush
```

There is no reference counting. `removeMedia()`, `setMedia()` and `clearMedia()` only change the column;
remove the replaced `Media` yourself or it stays in storage.

## Commands

```bash
bin/console media:status   # file count and total size
```

## Upgrading

- `media.mime` is now `VARCHAR(255)` (was 40, too short for Office types): generate a migration.
- `size` is the stored byte count (after compression). Rows written before keep the original upload size.
- `uploadHttpFile()` throws `FileValidationException` for invalid files instead of logging and returning the raw `UploadedFile`.
- `uploadHttpLink()` leaves failed links out of the result instead of returning the URL string.
- `MediaSuperClass::postRemoveMedia()` is now `preRemoveMedia()` and no longer flushes.

See [GUIDELINES.md](GUIDELINES.md) for details.
