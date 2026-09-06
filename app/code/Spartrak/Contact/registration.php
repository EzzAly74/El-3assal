<?php
/**
 * Spartrak_Contact — the storefront's contact details, and the removal of the
 * contact PAGE in favour of a dialog.
 *
 * Figma (1179:24007) replaces Magento's /contact/ form page with a small modal
 * that carries no form at all: it lists the ways to reach the merchant —
 * WhatsApp, hotline, showroom lines, email, address with a directions link,
 * and opening hours — and nothing else.
 *
 * Every one of those values is merchant content, not markup, so this module
 * owns them as store-view-scoped configuration and exposes them to the theme
 * through one ViewModel. The theme owns how the dialog looks
 * (web/css/source/components/_dialog.less) and how it opens
 * (web/js/spartrak-dialog.js); this module owns only the data and the
 * interception of the old page route.
 *
 * WHY NOT general/store_information. Magento's native store-information group
 * holds a phone, an address and an hours string, and it was the first
 * candidate. It cannot carry this panel: there is no home in it for a WhatsApp
 * number, a hotline distinct from the showroom lines, a second showroom line,
 * a directions URL or a contact email — five of the eight values the design
 * lists. Splitting the panel across two admin screens so that three of its
 * rows live somewhere else would make the merchant hunt for half of one
 * dialog. One group, one screen, all of it.
 */

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'Spartrak_Contact',
    __DIR__
);
