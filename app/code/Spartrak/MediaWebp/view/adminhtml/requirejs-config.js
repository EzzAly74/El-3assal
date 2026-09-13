/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 *
 * The admin has TWO independent client-side image-type gates, each with its own
 * hard-coded list, and both have to be widened or WebP is rejected in the
 * browser before any server config is consulted:
 *
 *   base-image-uploader - product form, "Images And Videos"
 *   media-uploader      - media browser / WYSIWYG description "Upload Images"
 */
var config = {
    config: {
        mixins: {
            'Magento_Catalog/catalog/base-image-uploader': {
                'Spartrak_MediaWebp/js/base-image-uploader-file-types-mixin': true
            },
            'Magento_Backend/js/media-uploader': {
                'Spartrak_MediaWebp/js/media-uploader-file-types-mixin': true
            }
        }
    }
};
