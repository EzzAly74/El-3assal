<?php
/**
 * SpareTrak storefront theme (RTL / Arabic — primary locale).
 *
 * CHILD of Spartrak/spartrak (changed 2026-08-24 from Smartwave/porto_rtl —
 * see theme.xml for the full rationale). This theme deliberately contains
 * ONLY genuinely direction-specific overrides; every shared template, layout,
 * JS and component stylesheet is inherited from Spartrak/spartrak so there is
 * exactly one source of truth.
 *
 * Chain: Spartrak/spartrak_rtl -> Spartrak/spartrak -> Smartwave/porto
 *        -> Magento/blank
 */

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::THEME,
    'frontend/Spartrak/spartrak_rtl',
    __DIR__
);
