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
 * AI Chat - Course context builder (legacy fallback)
 *
 * Builds a full course content dump when no RAG index is available.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat;

defined('MOODLE_INTERNAL') || die();

/**
 * Legacy course context builder — full dump fallback for RAG.
 */
class course_context_builder {

    /**
     * Build a text dump of course content.
     *
     * @param int $courseid The course ID.
     * @param int|null $cmid The current activity module ID (highlighted if provided).
     * @return string The course content text.
     */
    public static function build(int $courseid, ?int $cmid = null): string {
        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($course);
        $parts = [];

        foreach ($modinfo->get_section_info_all() as $section) {
            if (!$section->uservisible) {
                continue;
            }
            $sectionname = !empty($section->name) ? $section->name : get_string('section') . ' ' . $section->section;
            $parts[] = "### Section: " . $sectionname;

            if (!empty($section->summary)) {
                $parts[] = html_to_text($section->summary, 0, false);
            }

            // List activities in this section.
            if (!empty($modinfo->sections[$section->section])) {
                foreach ($modinfo->sections[$section->section] as $cmidinsection) {
                    $cm = $modinfo->cms[$cmidinsection];
                    if (!$cm->uservisible) {
                        continue;
                    }
                    $highlight = ($cmid > 0 && $cm->id == $cmid) ? ' [CURRENT ACTIVITY]' : '';
                    $parts[] = "- " . $cm->name . " (" . $cm->modname . ")" . $highlight;
                    if (!empty($cm->content)) {
                        $parts[] = "  " . \core_text::substr(html_to_text($cm->content, 0, false), 0, 300);
                    }
                }
            }
        }

        // Truncate to ~3000 tokens (~12000 chars).
        $text = implode("\n", $parts);
        if (\core_text::strlen($text) > 12000) {
            $text = \core_text::substr($text, 0, 12000) . "\n... (content truncated)";
        }

        return $text;
    }
}
