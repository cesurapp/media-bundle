## 1. Media Types

MEDIA-BUNDLE determines media type from MIME type automatically during upload.

**Supported detection:**
- Images: `image/jpeg`, `image/png`, `image/gif`, etc.
- Videos: `video/mp4`, `video/mpeg`, etc.
- Audio: `audio/mpeg`, `audio/wav`, etc.
- Documents: `application/pdf`, `text/*`, etc.

**Type determination:**
- MIME type detected via `finfo_buffer()` during upload
- Extension extracted from MIME type using Symfony MimeTypes
- No manual type selection required

**Example:**
```php
$media = $mediaManager->createMedia(
    'image/png',
    'png',
    $content,
    strlen($content)
);
// Media type is implicitly "image" based on MIME
```

## 2. Media Creation & Upload

**Entry points:**
- `uploadFile()` - HTTP file uploads
- `uploadBase64()` - Base64-encoded content
- `uploadLink()` - Remote URL download
- `createMedia()` - Direct content creation

**Accepted inputs:**
- `uploadFile()`: `Request` object with files, optional key filter
- `uploadBase64()`: `Request` with base64 strings, required keys, optional MIME validation
- `uploadLink()`: `Request` with URLs, required keys, optional MIME validation
- `createMedia()`: Raw content string, MIME type, extension, size

**Required parameters:**
- All methods require valid content
- Base64/Link methods require key array and optional MIME whitelist
- `createMedia()` requires: `$mimeType`, `$extension`, `$content`, `$size`

**Example:**
```php
// HTTP upload
$medias = $mediaManager->uploadFile($request, ['avatar', 'photos']);

// Base64 upload with MIME validation
$medias = $mediaManager->uploadBase64(
    $request,
    ['image'],
    ['image' => ['image/png', 'image/jpeg']]
);

// Direct creation
$media = $mediaManager->createMedia('image/png', 'png', $content, strlen($content), true);
```

## 3. Media Collections

**Purpose:**
- Associate multiple media files with entity properties
- Track media usage via reference counting
- Enable automatic cleanup when entities are deleted

**Naming conventions:**
- Column name matches trait property name (e.g., `media`, `logo`, `logoCover`)
- Trait defines `getMediaColumns()` returning column names
- Use camelCase for column names

**Single vs multiple media:**
- All columns store arrays of `Media` entities
- Single media: use `getLogoFirst()` helper or access `$logo[0]`
- Multiple media: iterate over array
- Empty collections: `null` or `[]`

**Example:**
```php
use Cesurapp\MediaBundle\Entity\{MediaInterface, MediaTrait};

class User implements MediaInterface {
    use MediaTrait;

    // For multiple columns, override:
    public function getMediaColumns(): array {
        return ['media', 'avatar'];
    }
}

// Usage
$user->addMedia($media);
$user->setMedia([$media1, $media2]);
$firstMedia = $user->getMedia()[0] ?? null;
```

## 4. Media Conversions

**What conversions are:**
- Automatic transformations applied during upload
- Configured via `MediaManager` method chaining
- Applied synchronously before storage write

**When conversions run:**
- During `uploadFile()`, `uploadBase64()`, `uploadLink()`, `createMedia()`
- Before persistence to database
- Conversions are NOT retroactive

**Where conversions are defined:**
- `setImageCompress(bool)` - Enable/disable compression
- `setImageConvertJPG(bool)` - PNG/JPEG → JPG conversion
- `setImageQuality(int)` - JPEG quality (0-100)
- `setImageSize(int $height, int $width)` - Max dimensions with aspect ratio

**Example:**
```php
$medias = $mediaManager
    ->setImageCompress(true)
    ->setImageConvertJPG(true)
    ->setImageQuality(75)
    ->setImageSize(1280, 720)
    ->uploadFile($request);
```

## 5. Media Metadata

**Available metadata fields:**
- `id` (UuidV7)
- `path` (string, storage path)
- `mime` (string, MIME type)
- `size` (int, bytes)
- `approved` (bool, default `true`)
- `counter` (int, reference count, default `1`)
- `data` (array, JSON field for custom metadata)
- `storage` (string, storage provider key)
- `owner` (string|null, owner identifier)
- `createdAt` (DateTimeImmutable)

**How metadata is accessed:**
```php
$media->getId()->toBase32();
$media->getPath();
$media->getMime();
$media->getSize();
$media->getData();
$media->getExtension(); // Extracted from path
```

**Auto-generated vs custom:**
- Auto: `id`, `path`, `mime`, `size`, `storage`, `createdAt`, `counter`
- Custom: `data` (JSON), `owner`, `approved`

**Example:**
```php
$media->setData(['width' => 1920, 'height' => 1080, 'dominant_color' => '#ff5733']);
$metadata = $media->getData();

$media->setOwner($user->getId());
$media->setApproved(false);
```

## 6. Media Deletion & Cleanup

**Deletion behavior:**
- Removing `Media` entity triggers storage file deletion
- Uses Doctrine `postRemove` event listener
- Automatic cleanup via `MediaRemovedListener`

**Handling of variants:**
- No variant system; single file per Media entity
- Deleting Media deletes one storage file

**Orphan cleanup rules:**
- Reference counting via `counter` field
- When entity with media column is updated/deleted, counter decrements
- Counter reaches `0` → Media entity auto-deleted
- Auto-deletion triggers storage cleanup

**Example:**
```php
// Manual deletion
$em->remove($media);
$em->flush(); // File deleted from storage automatically

// Automatic via counter
$user->removeMedia($media); // Decrements counter
$em->flush(); // If counter = 0, media deleted

// Entity deletion
$em->remove($user); // All associated media counters decremented
$em->flush(); // Orphaned media auto-deleted
```

## 7. Conventions & Rules

**Naming conventions:**
- Trait properties: camelCase (e.g., `$media`, `$logo`, `$logoCover`)
- Trait method pattern: `add{Column}()`, `remove{Column}()`, `set{Column}()`, `get{Column}()`
- Copy `MediaTrait` for each new column; rename methods/properties

**Do:**
- Implement `MediaInterface` on entities with media columns
- Duplicate trait for each media column
- Configure conversions before upload
- Use `addMedia()` for incremental additions
- Use `setMedia()` for full replacement
- Flush `EntityManager` after uploads to persist

**Don't:**
- Don't share trait across multiple columns
- Don't modify media path after creation
- Don't manually delete storage files (use entity removal)
- Don't assume conversions apply retroactively
- Don't bypass counter system (manual counter edits break cleanup)

**Performance considerations:**
- Image compression/conversion adds upload latency
- Large images: Set `imageSize` limit to avoid memory exhaustion
- Base64 uploads: Validate MIME types to prevent processing invalid data
- Link uploads: Network latency depends on remote server

**Common mistakes:**
- Using single trait for multiple columns (causes method name conflicts)
- Forgetting to override `getMediaColumns()` for multi-column entities
- Removing media entity without decrementing counter (orphaned files)
- Expecting URL generation (MEDIA-BUNDLE stores paths, not URLs)
