<?php
/**
 * Spartrak_Catalog — shared frontend view code used by BOTH Spartrak
 * storefront themes (Spartrak/spartrak and Spartrak/spartrak_rtl).
 *
 * Why this module exists at all: Spartrak/spartrak_rtl's parent is
 * Smartwave/porto_rtl (not Spartrak/spartrak) — a deliberate choice that
 * preserves Porto's own RTL treatment of everything Spartrak doesn't
 * touch. That means the two Spartrak themes are SIBLINGS, not
 * parent/child, so Magento's theme-fallback resolution never lets one
 * see the other's files. Module view files, by contrast, are collected
 * for every theme independently of theme parentage (confirmed via
 * Magento\Framework\RequireJs\Config\File\Collector\Aggregated and the
 * equivalent CSS/layout collectors) — so genuinely NEW, shared Spartrak
 * functionality belongs here, once, rather than duplicated per theme.
 *
 * See .claude/docs/10-THEME-ARCHITECTURE.md's "Theme vs. Module
 * boundary" section for the full rule on what belongs here vs. in a
 * theme.
 */

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'Spartrak_Catalog',
    __DIR__
);
