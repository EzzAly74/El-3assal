# Spartrak_ProductVideo

Product video for the storefront: hosted MP4/WebM, direct CDN URLs, YouTube and
Vimeo, with posters, per-video playback settings and chapters — and a product
page that downloads none of it until a shopper presses play.

## The one architectural decision

**A video is a media gallery entry. There is no separate video entity.**

Magento already stores product video as an `external-video` media-gallery row,
and that row already carries everything a "product video" table would have
needed:

| What a video needs | Where it already lives |
|---|---|
| product association | `catalog_product_entity_media_gallery_value_to_entity` |
| sort order | `..._media_gallery_value.position` (admin drag-reorder) |
| enabled / disabled | `..._media_gallery_value.disabled` |
| store visibility | `..._media_gallery_value.store_id` |
| URL, provider, title, description | `..._media_gallery_value_video` |
| **the poster** | the gallery row itself, with Magento's whole image-resize, derivative and cache pipeline |

So this module adds two small tables for the things Magento has nowhere to put,
both keyed on the gallery entry's own `value_id` with `ON DELETE CASCADE`:

| Table | Holds |
|---|---|
| `spartrak_product_video` | source type, provider id, uploaded path, MIME, and the five playback overrides + featured flag |
| `spartrak_product_video_chapter` | timecode, `title_en` / `title_ar`, order |

Building a parallel entity would have meant a parallel admin UI, a parallel
poster uploader, a parallel ordering control and a second gallery on the product
page — and it would have had to re-solve product duplicate, import/export,
store-scope fallback and cache invalidation, all of which the media gallery
already gets right.

## Query cost

**Settings cost nothing.** `Plugin\Catalog\Gallery\JoinVideoSettings` adds one
LEFT JOIN to `Gallery::createBatchBaseSelect()` — the single select the media
gallery is built from, and the one that BOTH load paths go through:

```
PDP     ReadHandler::execute() → loadProductGalleryByAttributeId()
                              → createBaseLoadSelect() → createBatchBaseSelect()
rails   Collection::addMediaGalleryData()             → createBatchBaseSelect()
```

So a product page and a twelve-product homepage rail both get the data with no
extra round trip.

**Chapters cost one query, and only when they can be used.**
`Plugin\Catalog\Gallery\LoadVideoChapters` issues a single
`WHERE value_id IN (...)` and skips it entirely unless the product has at least
one *native* video. A product with no videos, or only YouTube videos, adds
nothing. Ten videos with ten chapters each is still one query.

There is no N+1 anywhere by construction: no query in this module is inside a
loop, and none of the counts scale with the number of videos or chapters.

## What it does to the product page

It **removes more than it adds.** Magento_ProductVideo puts four requests and
36.8 KB of parsed JavaScript — including the entire Vimeo Player SDK — on every
product page, whether or not the product has a video. Measured on this store on
a product with none:

| | transfer | parsed |
|---|---|---|
| `fotorama-add-video-events.min.js` | 3.5 KB | 12.7 KB |
| `vimeo/player.min.js` | 5.8 KB | 19.4 KB |
| `vimeo/vimeo-wrapper.min.js` | 0.4 KB | 0.1 KB |
| `load-player.min.js` | 1.6 KB | 4.6 KB |

`view/frontend/layout/catalog_product_view.xml` removes that block. In its place
a product with **no** video renders nothing at all, and a product **with** video
renders one JSON array and one `data-mage-init`.

Before a shopper clicks: no video file requested, no provider contacted, no
iframe, no player. The poster is the frame Fotorama is already drawing.

Magento_ProductVideo stays enabled — its admin dialog, entry converter, save and
read handlers and table are all still doing their jobs. Only the storefront
player is replaced.

## Admin

**A fieldset on the product form. No modal.**

*Product Videos* sits under Images And Videos as a collapsible fieldset of rows.
Each row is one video: source (URL or upload), the URL or the file, a preview
image, title, description, the five playback overrides, featured, hidden, and
chapters. Add, edit and delete all happen on the page — Magento's *Add Video*
popup is not used, not extended and not opened.

Structure is declared in `view/adminhtml/ui_component/product_form.xml`;
`Ui/DataProvider/Product/Form/Modifier/Videos` only supplies the data. Building
form structure in PHP is how a form becomes unreadable.

### But a video is still a gallery entry

`Plugin\Catalog\Gallery\BuildVideoEntries` runs *before* Magento's gallery
handler and translates the posted rows into `media_gallery` entries — exactly
the shape the modal would have produced. Everything downstream is then core's,
unmodified: the move out of tmp, the `value_id`, the video table, cache
invalidation, product duplicate, import/export.

That is what keeps the storefront promise. A video appears among the product's
gallery thumbnails, ordered against the photographs, with its poster resized by
Magento's own image pipeline — none of which this module re-implements.

It **merges** into `media_gallery.images` rather than replacing it; overwriting
would delete every product photo the first time anyone touched a video. Rows
are matched by `value_id`, and the split of ownership is deliberate:

| Owned by the gallery above | Owned by this fieldset |
|---|---|
| position (drag to reorder against the photos) | everything else about the video |

### Two upload endpoints, and only one is ours

The **poster** posts to Magento's own `catalog/product_gallery/upload`, so it is
staged where the media gallery expects it and is moved, resized, cached and
cleaned up by Magento. The **video file** posts to this module's endpoint,
because Magento has no concept of a non-image media upload and its own uploader
would try to thumbnail an MP4.

**Chapters** are one textarea per language, in YouTube's own convention
(`1:24 Fitting the pump`). `Model\Video\ChapterParser` converts them to seconds
on save; storage is relational, so a future CSV/CLI import replaces the parser
and nothing else.

## Security

* A provider video is stored as a **validated id**, never a URL. Both players
  compose the embed address from a hardcoded prefix, so no merchant-supplied
  string can reach an iframe `src`.
* A direct URL must be `http(s)` and must end in `.mp4` or `.webm`.
  `javascript:` and friends are refused at save.
* Uploads are checked by extension **and** by the file's real bytes
  (`checkMimeType`), capped at 100 MB, and written outside the catalog media
  tree so Magento's media synchroniser never tries to thumbnail a video.
* All of it runs once, on save — `Model\Video\SourceNormalizer` is never on a
  storefront request.

## Where it renders

| Surface | Block | Player |
|---|---|---|
| Product page gallery | `Block\Product\View\Video` | `js/spartrak-pdp-video.js`, a layer over Fotorama's stage |
| Homepage showcase | `Spartrak\Homepage\Block\Section\ProductCarousel` | `js/spartrak-home-video.js` |

Both ask `Model\Video\DescriptorBuilder` the same question and only lay the
answer out differently, so the two cannot disagree about what a video is or how
it should play.

## Deliberately not built

* **Google Drive** — no stable embed contract without fragile frontend probing.
* **Transcoding** — never in a web request. The uploader stores the file a queue
  consumer would read if this is ever wanted.
* **Chapters for YouTube/Vimeo** — seeking an embed needs that provider's player
  API loaded and driven, which is the third-party weight this module exists to
  keep off the page. Both already ship chapter UI of their own.
* **A bulk-import UI** — the upload, normalise and chapter-parse services are
  plain classes with no Magento entry point of their own, so a CLI can reuse
  them rather than re-implementing the validation.
