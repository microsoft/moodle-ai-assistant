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
 * Custom Moodle Theme - Configuration
 *
 * Generic Moodle theme based on Boost that can be customized via
 * scss/pre.scss (variable overrides) and scss/post.scss (component styles).
 *
 * @package   theme_myuni
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Inherit from Boost (Bootstrap 4/5 based).
$THEME->name    = 'myuni';
$THEME->parents = ['boost'];

// No separate CSS sheets – everything is compiled from SCSS.
$THEME->sheets        = [];
$THEME->editor_sheets = [];
$THEME->editor_scss   = ['editor'];

// Delegate main SCSS content to Boost (which includes Bootstrap + Moodle styles).
$THEME->scss = function ($theme) {
    return theme_boost_get_main_scss_content($theme);
};

// Called before main SCSS is compiled: inject variable overrides.
$THEME->prescsscallback = 'theme_myuni_get_pre_scss';

// Called after main SCSS is compiled: inject additional rules.
$THEME->extrascsscallback = 'theme_myuni_get_extra_scss';

// Fallback to pre-compiled CSS when SCSS compilation is unavailable.
$THEME->precompiledcsscallback = 'theme_myuni_get_precompiled_css';

$THEME->yuicssmodules    = [];
$THEME->requiredblocks   = '';
$THEME->enable_dock      = false;

// Use FontAwesome icon set (same as Boost).
$THEME->iconsystem = \core\output\icon_system::FONTAWESOME;

// Moodle 4.x features.
$THEME->addblockposition  = BLOCK_ADDBLOCK_POSITION_FLATNAV;
$THEME->haseditswitch     = true;
$THEME->usescourseindex   = true;
$THEME->activityheaderconfig = ['notitle' => false];
