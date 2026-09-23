## 1. Media Types

The type of an upload is detected on the server, never taken from the client.

- MIME type: `finfo` on the content (`UploadedFile::getMimeType()` for multipart files)
- Extension: derived from the detected type with Symfony MimeTypes, `bin` when unknown
- The client's file name and extension are never used in the storage key

**Refused by default** (`MediaManager::UNSAFE_MIMES`, `MediaManager::UNSAFE_EXTENSIONS`): html, xhtml, svg,
xml, javascript, php, shell scripts and executables. A browser renders or runs these, so a stored copy is
stored XSS on a public bucket and code execution on a web-served disk. Pass `'allowUnsafe' => true` only
when the files are never served from a domain that shares cookies with the app.

Always pass an `$allowedMimes` list when the caller knows what it expects.

## 2. Media Creation & Upload

| Method | Input |
|---|---|
| `uploadHttpFile(Request, ?array $keys, array $options, ?array $allowedMimes)` | multipart files; `$allowedMimes` is keyed by request key |
| `uploadHttpBase64(Request, array $keys, ?array $allowedMimes, array $options)` | base64 fields, keyed allow list |
| `uploadHttpLink(Request, array $keys, ?array $allowedMimes, array $options)` | URL fields, downloaded concurrently |
| `uploadFromUploadedFile(UploadedFile, ?array $allowedMimes, array $options)` | one file |
| `uploadFromBase64(string, ?array $allowedMimes, array $options)` | one base64 string or data URI |
| `uploadFromUrl(string, ?array $allowedMimes, array $options)` | one URL |
| `uploadFromContent(string $content, string $mime, string $extension, ?array $allowedMimes, array $options)` | raw bytes |
| `uploadFromData(array, ?array $allowedMimes, array $options)` | array produced by `Base64FileValidator` |
| `createMedia(string $content, string $mime, string $extension, int $size, array $options)` | low level, used by all of the above |

**Errors:** validation problems throw `FileValidationException` (code 422); `getErrors()` returns
`[key => [message]]` for the HTTP helpers and `[message]` otherwise. `uploadHttpLink()` is the exception:
a link that fails to download or has a disallowed type is logged and left out of the result.

**Limits:** `maxFiles` (20 per key), `maxSize` (bytes; link downloads default to 20 MB),
`imageMaxPixels` (40 MP). Link downloads refuse private, loopback and link-local addresses, redirects
included, unless `allowPrivateNetwork` is set; they time out after `downloadTimeout` seconds idle and
`downloadMaxDuration` seconds total.

**Storage write happens before the database row exists.** If the request fails after the upload, the
object is orphaned. Persist and flush as soon as the upload succeeds.

## 3. Media Collections

A media column is a JSONB list of `Media` ids (`#[ORM\Column(type: 'media')]`). Reading it returns an
array of lazy references keyed by id.

```php
use Cesurapp\MediaBundle\Entity\MediaSuperClass;
use Cesurapp\MediaBundle\Entity\Traits\{MediaTrait, AvatarTrait};

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class User extends MediaSuperClass
{
    use MediaTrait;
    use AvatarTrait;

    public function getMediaColumns(): array
    {
        return ['media', 'avatar'];
    }
}

$user->addMedia($media);
$user->setMedia([$media1, $media2]);
$first = $user->getAvatarFirst();
```

**N+1:** every reference loads on first use, one query each. Before rendering a list call
`MediaRepository::preload()` with the columns you are about to read:

```php
$mediaRepository->preload(array_map(fn (User $u) => $u->getAvatar(), $users));
```

## 4. Media Conversions

Applied synchronously by `createMedia()` before the storage write, never retroactively.

- `imageCompress` (`true`): re-encode jpg/png
- `imageConvertJPG` (`true`): png/jpeg → jpg (transparency is lost)
- `imageQuality` (`75`)
- `imageWidth` × `imageHeight` (`720` × `1280`): best-fit box, aspect ratio kept
- `imageMaxPixels` (`40000000`): dimensions are read from the header and larger images refused before
  GD decodes them; GD memory is not counted by `memory_limit`

```php
$media = $mediaManager->uploadFromUploadedFile($file, ['image/png', 'image/jpeg'], [
    'imageQuality' => 85,
    'imageWidth' => 1920,
    'imageHeight' => 1080,
]);
```

## 5. Media Metadata

| Field | |
|---|---|
| `id` | UuidV7 |
| `path` | storage key, `Y/m/<ulid>.<ext>` |
| `mime` | detected type, up to 255 chars |
| `size` | bytes stored (after compression) |
| `data` | JSONB for custom values (`filename`, `public`, …) |
| `storage` | device key |
| `private` | stored in the device's private bucket |
| `status` | `ready`, or `pending` for presigned uploads not yet confirmed |
| `owner` | optional UuidV7 |
| `createdAt` | |

```php
$media->setData(['width' => 1920, 'height' => 1080]);
$media->addFileName('report.pdf');   // used in Content-Disposition
$media->setOwner($organization->getId());
$media->hasOwner($id);               // false when there is no owner
```

## 6. Serving

- `toString($storage)`: CDN URL for public media, presigned URL for private ones, signed app URL for the
  `local` driver
- `getResponse($storage)`: sends `X-Content-Type-Options: nosniff`; images, audio, video, pdf and plain
  text inline, everything else as an attachment; private or non-public media get `Cache-Control: private`
  so a CDN never shares them
- `validateSignature($storage, $uri)`: checks a signed `local` URL

## 7. Deletion & Cleanup

- Deleting a `Media` row deletes its storage object. `MediaRemovedListener` queues the object on
  `postRemove` and deletes it on `postFlush` once no transaction is open, only if the row is really gone.
  A rolled-back transaction keeps the file.
- A flush run inside an explicit transaction is handled by the next flush after commit, or on kernel reset.
- `MediaSuperClass` removes an entity's media in the same flush as the entity (`preRemove`, no nested flush).
  A stale reference whose row is already gone is skipped.
- **No reference counting.** Removing a media from a column (`removeMedia`, `setMedia`, `clearMedia`) does
  not delete it. Remove the replaced `Media` yourself. A `Media` shared by two entities is deleted with
  whichever is removed first.
- `pending` media whose upload never completed are not swept by the bundle; schedule a job that removes
  stale pending rows.

```php
$old = $user->getAvatarFirst();
$user->setAvatar([$new]);
if ($old) {
    $em->remove($old);
}
$em->flush();
```

## 8. Conventions

**Do:**
- Pass an `$allowedMimes` list to every upload
- Flush right after uploading
- Remove replaced media explicitly
- Call `preload()` before reading media for a list of entities

**Don't:**
- Enable `allowUnsafe` for user uploads served from your domain
- Enable `allowPrivateNetwork` for user-supplied URLs
- Share one `Media` between entities
- Change a media path after creation, or delete storage objects by hand
