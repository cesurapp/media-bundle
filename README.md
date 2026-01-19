# Media Bundle

[![App Tester](https://github.com/cesurapp/media-bundle/actions/workflows/testing.yaml/badge.svg)](https://github.com/cesurapp/media-bundle/actions/workflows/testing.yaml)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?logo=Unlicense)](LICENSE.md)

Media management bundle for Symfony with automatic storage integration and reference counting.

**Features:**
- Automatic file tracking with reference counting
- Auto-cleanup when media is deleted or unused
- Image compression, resizing, and format conversion
- Multiple upload methods (HTTP, Base64, URL)
- Support for multiple media columns per entity
- Built on top of `cesurapp/storage-bundle`

## Installation

**Requirements:**
- PHP 8.4+
- Symfony 8.0+

```bash
composer require cesurapp/media-bundle
```

## Quick Start

### 1. Add Media Column to Entity

```php
use Cesurapp\MediaBundle\Entity\{MediaInterface, MediaTrait};

class User implements MediaInterface
{
    use MediaTrait;

    // For single column, getMediaColumns() is optional
    // For multiple columns, override:
    public function getMediaColumns(): array
    {
        return ['media', 'avatar'];
    }
}
```

**Note:** Copy and rename `MediaTrait` for each additional media column (e.g., `LogoTrait`, `AvatarTrait`).

### 2. Upload Media

```php
use Cesurapp\MediaBundle\Manager\MediaManager;
use Symfony\Component\HttpFoundation\Request;

class UploadController
{
    public function upload(Request $request, MediaManager $manager): void
    {
        // Configure image processing
        $medias = $manager
            ->setImageCompress(true)        // Enable compression
            ->setImageConvertJPG(true)      // Convert PNG/JPEG to JPG
            ->setImageQuality(75)           // JPEG quality (0-100)
            ->setImageSize(1280, 720)       // Max dimensions (aspect ratio preserved)
            ->uploadFile($request);         // Upload HTTP files

        // Attach to entity
        $user->addMedias($medias);
    }
}
```

### 3. Upload Methods

```php
// HTTP file upload
$medias = $manager->uploadFile($request, ['avatar', 'photos']);

// Base64 upload with MIME validation
$medias = $manager->uploadBase64(
    $request,
    ['image'],
    ['image' => ['image/png', 'image/jpeg']]
);

// Remote URL download
$medias = $manager->uploadLink(
    $request,
    ['imageUrl'],
    ['imageUrl' => ['image/png']]
);

// Direct content creation
$media = $manager->createMedia('image/png', 'png', $content, strlen($content));
```

### 4. Access Media

```php
// Get all media
$allMedia = $user->getMedia();

// Get first media
$firstMedia = $user->getMedia()[0] ?? null;

// Using helper method (available in LogoTrait, etc.)
$logo = $user->getLogoFirst();

// Serve as HTTP response
return $media->getResponse($storage);

// Get file content
$content = $media->getContent($storage);

// Get file path
$path = $media->getPath(); // e.g., "2025/01/20/01hmz3k4.jpg"
```

### 5. Delete Media

```php
// Manual deletion
$em->remove($media);
$em->flush(); // File automatically deleted from storage

// Remove from entity (auto-cleanup via counter)
$user->removeMedia($media);
$em->flush(); // Media deleted if no other references

// Entity deletion (cascading cleanup)
$em->remove($user);
$em->flush(); // All associated media auto-deleted when counter reaches 0
```

## Commands

```bash
# View media storage statistics
bin/console media:status
```

## Documentation

For detailed usage, see [GUIDELINES.md](GUIDELINES.md)
