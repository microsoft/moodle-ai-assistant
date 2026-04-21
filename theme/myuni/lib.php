<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * Custom Moodle Theme - Library functions
 *
 * Provides SCSS callbacks consumed by config.php.
 *
 * @package   theme_myuni
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Inject SCSS variable overrides BEFORE Boost compiles its main stylesheet.
 * This is the correct place to override Bootstrap / Moodle SCSS variables
 * (e.g. $primary, $font-family-sans-serif).
 *
 * @param  theme_config $theme Current theme object.
 * @return string              SCSS snippet prepended to the compile pipeline.
 */
function theme_myuni_get_pre_scss($theme) {
    // Start with Boost's own pre-SCSS (handles admin-set background image etc.)
    $scss = theme_boost_get_pre_scss($theme);

    // Append our variable overrides from scss/pre.scss.
    $prescssfile = __DIR__ . '/scss/pre.scss';
    if (is_readable($prescssfile)) {
        $scss .= "\n" . file_get_contents($prescssfile);
    }

    return $scss;
}

/**
 * Inject additional CSS rules AFTER Boost compiles its main stylesheet.
 * This is the correct place for component-level overrides that reference
 * compiled selectors (e.g. .navbar, #page-footer).
 *
 * @param  theme_config $theme Current theme object.
 * @return string              CSS/SCSS appended after main compile.
 */
function theme_myuni_get_extra_scss($theme) {
    // Start with Boost's extra SCSS (handles admin-set background colour etc.)
    $content = theme_boost_get_extra_scss($theme);

    // Append our component overrides from scss/post.scss.
    $postscssfile = __DIR__ . '/scss/post.scss';
    if (is_readable($postscssfile)) {
        $content .= "\n" . file_get_contents($postscssfile);
    }

    return $content;
}

/**
 * Return a pre-compiled CSS fallback when SCSS cannot be compiled on the fly.
 * Returning an empty string forces Moodle to attempt SCSS compilation instead
 * of serving stale CSS.
 *
 * @return string Pre-compiled CSS or empty string.
 */
function theme_myuni_get_precompiled_css() {
    return '';
}
