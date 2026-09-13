# Spartrak_MediaWebp

Allows WebP images to be uploaded and processed everywhere the Magento admin
already accepts JPEG/PNG/GIF.

## Why the module exists

Magento 2.4.8 blocks WebP at two independent layers, and fixing either one alone
leaves the upload broken:

1. **Allow-lists.** Every upload endpoint carries its own jpg/jpeg/gif/png list.
2. **The image adapter.** `Magento\Framework\Image\Adapter\Gd2` maps each
   `IMAGETYPE_*` to a GD create/output callback, and WebP is absent from that
   map. The map and its accessor are both `private`, so no amount of DI or
   subclassing widens it — a WebP reaches `open()` and throws
   *"Unsupported image format"* during upload validation, thumbnailing and
   frontend cache generation alike.

## What it changes

| Surface | Where | How |
| --- | --- | --- |
| GD2 image processing (decode, resize, encode) | `Image/Adapter/Gd2.php` | `preference` — WebP branch added to `open`/`resize`/`save`/`getImage`, every other format delegated to core untouched |
| Product gallery upload — server | `Controller/Adminhtml/Product/Gallery/Upload.php` | `preference` — same `execute()` as core, allowed types moved into DI |
| Product gallery upload — browser | `js/base-image-uploader-file-types-mixin.js` | RequireJS mixin widening the core widget's Uppy `restrictions.allowedFileTypes` |
| Media browser / description upload — browser | `js/media-uploader-file-types-mixin.js` | RequireJS mixin; this widget ignores Uppy restrictions and gates on its own hard-coded `allowedExt`, raising *"Disallowed file type."* client-side |
| Description / WYSIWYG media browser (upload, listing, thumbnails) | `etc/di.xml` | `Magento\Cms\Model\Wysiwyg\Images\Storage` `allowed` + `image_allowed` |
| Category image upload | `etc/di.xml` | `Magento\Catalog\CategoryImageUpload` virtual type |
| Media Gallery grid indexing | `etc/di.xml` | `SaveImageInformation` (on upload) and `FetchMediaStorageFileBatches` (on `media-gallery:sync`) |
| Media Gallery renditions | `etc/di.xml` | `FetchRenditionPathsBatches` — without it a WebP inserted into WYSIWYG content falls back to the full-size original |
| Upload-time decode check | `Plugin/MediaStorage/ValidateWebpIsDecodable.php` | `after` plugin — core's validator only opens formats in a private map, so a corrupt WebP would otherwise reach `pub/media` and fail later, at thumbnail time |

Nothing in `vendor/` or Magento core is modified, and no core list is
redeclared — every DI array here adds one `webp` item and inherits the rest.

## The two client-side gates

The admin has **two** independent hard-coded image-type lists in JavaScript, and
they are not the same file or even the same mechanism. Both must be widened, or
WebP is rejected in the browser before any server config is read:

| Widget | Where it appears | Gate |
| --- | --- | --- |
| `Magento_Catalog/catalog/base-image-uploader` | Product form → Images And Videos | Uppy `restrictions.allowedFileTypes` |
| `Magento_Backend/js/media-uploader` | Media browser → Upload Images (WYSIWYG description, admin media browser) | its own `allowedExt` local, checked in `onBeforeFileAdded` |

Neither exposes a widget option, and neither keeps the Uppy instance anywhere
reachable.

The `Uppy` global is **not** a usable seam: Magento ships Uppy 4.1 as an esbuild
bundle whose namespace properties are defined with
`Object.defineProperty(ns, name, { get, enumerable: true })` — getter-only and
non-configurable. Assigning `window.Uppy.Uppy` throws
*"Cannot set property Uppy of #<Object> which has only a getter"*.

What is writable is the `Uppy` class prototype. Both widgets call `uppy.use()`
to install their first plugin immediately after constructing the instance, so
`js/additional-file-types.js` patches `Uppy.prototype.use` for the duration of
`_create()`, hands each new instance to a decorator before its first plugin is
installed, and restores `use` in a `finally`. The decorators then reconfigure
the instance through Uppy's own public `setOptions()` — no private state is
touched, and if a future Uppy build moves the seam the shim degrades to core
behaviour instead of throwing.

## Requirements

PHP's `gd` extension must be built with WebP support (`imagecreatefromwebp()` /
`imagewebp()` available). It is part of the default `--with-webp` build on PHP
7.4+ and is present on this project's PHP 8.3 runtime. Without it the module's
adapter throws the same "unsupported format" error core does.

## Deliberately out of scope

These paths keep core's jpg/jpeg/gif/png behaviour. Each would need a long core
method copied wholesale, for a surface the admin dashboard does not use:

- **REST/GraphQL base64 media** — `Magento\Catalog\Model\Product\Gallery\Processor::addImage()`
  re-checks extensions inside a ~100-line method, and
  `MimeTypeExtensionMap` names the file. Both would have to be overridden
  together to be of any use.
- **CSV product import** — `Magento\CatalogImportExport` runs its own uploader
  with its own allow-list.
- **Attribute swatch images** — `Magento\Swatches\Controller\Adminhtml\Iframe\Show`
  calls `setAllowedExtensions(['jpg','jpeg','gif','png'])` inline (Stores >
  Attributes, not the product form).
- **Legacy image-attribute upload** — `Magento\Catalog\Model\ResourceModel\Product\Attribute\Backend\Image`
  does the same on direct `$_FILES` product saves; the admin form does not use it.
- **WebP watermark assets** — `Magento\Config\Model\Config\Backend\Image` keeps a
  separate allow-list, so a WebP watermark cannot be uploaded to begin with.
  A WebP *product* image watermarked with a JPEG/PNG works normally.
- **`Gd2::crop()` alpha** — core builds an opaque canvas for anything that is not
  a PNG. `crop()` is not part of the catalog image pipeline; a transparent WebP
  put through `Magento\Framework\Image::crop()` directly would flatten to black.

## After deploying

```bash
bin/magento module:enable Spartrak_MediaWebp
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```
