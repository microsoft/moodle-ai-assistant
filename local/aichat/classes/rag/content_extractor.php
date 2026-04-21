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
 * AI Chat - Content extractor
 *
 * Extracts course content using Moodle internal APIs for RAG indexing.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat\rag;

defined('MOODLE_INTERNAL') || die();

/**
 * Extracts textual content from course activities and sections.
 */
class content_extractor {

    /** @var int Approximate max tokens per chunk (~4 chars per token). */
    private const MAX_CHUNK_CHARS = 6000;

    /**
     * Extract all content chunks from a course.
     *
     * @param int $courseid The course ID.
     * @return array Array of chunks: ['chunk_type', 'chunk_id', 'chunk_title', 'content_text']
     */
    public static function extract_course_content(int $courseid): array {
        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($course);
        $chunks = [];

        // Extract section content.
        foreach ($modinfo->get_section_info_all() as $section) {
            $text = self::extract_section($section);
            if (!empty(trim($text))) {
                $title = !empty($section->name) ? $section->name : get_string('section') . ' ' . $section->section;
                $subchunks = self::split_chunk($text, 'section', $section->id, $title);
                $chunks = array_merge($chunks, $subchunks);
            }
        }

        // Extract activity content.
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $text = self::extract_activity($cm, $course);
            if (!empty(trim($text))) {
                $subchunks = self::split_chunk($text, $cm->modname, $cm->id, $cm->name);
                $chunks = array_merge($chunks, $subchunks);
            }
        }

        return $chunks;
    }

    /**
     * Extract text from a course section.
     *
     * @param \section_info $section
     * @return string
     */
    private static function extract_section(\section_info $section): string {
        $parts = [];
        if (!empty($section->name)) {
            $parts[] = $section->name;
        }
        if (!empty($section->summary)) {
            $parts[] = html_to_text($section->summary, 0, false);
        }
        return implode("\n", $parts);
    }

    /**
     * Extract text from a course activity using mod-specific APIs.
     *
     * @param \cm_info $cm
     * @param \stdClass $course
     * @return string
     */
    private static function extract_activity(\cm_info $cm, \stdClass $course): string {
        global $DB;

        $modname = $cm->modname;
        $parts = [$cm->name];

        switch ($modname) {
            case 'page':
                $record = $DB->get_record('page', ['id' => $cm->instance], 'content, intro');
                if ($record) {
                    if (!empty($record->intro)) {
                        $parts[] = html_to_text($record->intro, 0, false);
                    }
                    if (!empty($record->content)) {
                        $parts[] = html_to_text($record->content, 0, false);
                    }
                }
                break;

            case 'label':
                $record = $DB->get_record('label', ['id' => $cm->instance], 'intro');
                if ($record && !empty($record->intro)) {
                    $parts[] = html_to_text($record->intro, 0, false);
                }
                break;

            case 'assign':
                $record = $DB->get_record('assign', ['id' => $cm->instance],
                    'intro, activity, duedate, grade');
                if ($record) {
                    if (!empty($record->intro)) {
                        $parts[] = html_to_text($record->intro, 0, false);
                    }
                    if (!empty($record->activity)) {
                        $parts[] = html_to_text($record->activity, 0, false);
                    }
                    if ($record->duedate > 0) {
                        $parts[] = 'Due date: ' . userdate($record->duedate);
                    }
                    if ($record->grade > 0) {
                        $parts[] = 'Max grade: ' . $record->grade;
                    }
                }
                break;

            case 'quiz':
                $record = $DB->get_record('quiz', ['id' => $cm->instance], 'intro, timeopen, timeclose');
                if ($record) {
                    if (!empty($record->intro)) {
                        $parts[] = html_to_text($record->intro, 0, false);
                    }
                    if ($record->timeopen > 0) {
                        $parts[] = 'Opens: ' . userdate($record->timeopen);
                    }
                    if ($record->timeclose > 0) {
                        $parts[] = 'Closes: ' . userdate($record->timeclose);
                    }
                }
                break;

            case 'forum':
                $record = $DB->get_record('forum', ['id' => $cm->instance], 'intro, type');
                if ($record && !empty($record->intro)) {
                    $parts[] = html_to_text($record->intro, 0, false);
                }
                break;

            case 'glossary':
                $entries = $DB->get_records('glossary_entries',
                    ['glossaryid' => $cm->instance], 'concept ASC', 'concept, definition', 0, 100);
                foreach ($entries as $entry) {
                    $def = html_to_text($entry->definition, 0, false);
                    $parts[] = $entry->concept . ': ' . $def;
                }
                break;

            case 'book':
                $chapters = $DB->get_records('book_chapters',
                    ['bookid' => $cm->instance, 'hidden' => 0], 'pagenum ASC', 'title, content');
                foreach ($chapters as $ch) {
                    $parts[] = $ch->title . "\n" . html_to_text($ch->content, 0, false);
                }
                break;

            case 'wiki':
                // Get the latest version of each page.
                $sql = "SELECT p.title, v.content
                          FROM {wiki_pages} p
                          JOIN {wiki_versions} v ON v.pageid = p.id
                          JOIN {wiki_subwikis} sw ON sw.id = p.subwikiid
                         WHERE sw.wikiid = :wikiid
                           AND v.id = (
                               SELECT MAX(v2.id)
                                 FROM {wiki_versions} v2
                                WHERE v2.pageid = p.id
                           )
                      ORDER BY p.title";
                $pages = $DB->get_records_sql($sql, ['wikiid' => $cm->instance]);
                foreach ($pages as $page) {
                    $parts[] = $page->title . "\n" . html_to_text($page->content, 0, false);
                }
                break;

            case 'lesson':
                $pages = $DB->get_records('lesson_pages',
                    ['lessonid' => $cm->instance], 'ordering ASC', 'title, contents');
                foreach ($pages as $page) {
                    if (!empty($page->contents)) {
                        $parts[] = $page->title . "\n" . html_to_text($page->contents, 0, false);
                    }
                }
                break;

            case 'url':
                // Does NOT fetch external content (SSRF prevention).
                $record = $DB->get_record('url', ['id' => $cm->instance], 'externalurl, intro');
                if ($record) {
                    $parts[] = 'URL: ' . $record->externalurl;
                    if (!empty($record->intro)) {
                        $parts[] = html_to_text($record->intro, 0, false);
                    }
                }
                break;

            case 'resource':
                $record = $DB->get_record('resource', ['id' => $cm->instance], 'intro');
                if ($record && !empty($record->intro)) {
                    $parts[] = html_to_text($record->intro, 0, false);
                }
                break;

            default:
                // Generic fallback: try to get the intro field.
                $record = $DB->get_record($modname, ['id' => $cm->instance], 'intro', IGNORE_MISSING);
                if ($record && !empty($record->intro)) {
                    $parts[] = html_to_text($record->intro, 0, false);
                }
                break;
        }

        return implode("\n", $parts);
    }

    /**
     * Extract content for a single course module by cmid.
     *
     * Returns the live content directly from the database — used to build the
     * "Current Page" context block on every request.
     *
     * @param int $courseid The course ID.
     * @param int $cmid The course module ID.
     * @return array|null Array {title, type, content} or null if not visible/found.
     */
    public static function get_activity_content(int $courseid, int $cmid): ?array {
        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($course);
        try {
            $cm = $modinfo->get_cm($cmid);
        } catch (\Exception $e) {
            return null;
        }
        if (!$cm->uservisible) {
            return null;
        }
        $text = self::extract_activity($cm, $course);
        if (empty(trim($text))) {
            return null;
        }
        return [
            'title'   => $cm->name,
            'type'    => $cm->modname,
            'content' => $text,
        ];
    }

    /**
     * Extract content for a single course section by its DB id.
     *
     * @param int $courseid The course ID.
     * @param int $sectionid The section DB id (section_info->id).
     * @return array|null Array {title, number, content} or null if not found.
     */
    public static function get_section_content(int $courseid, int $sectionid): ?array {
        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($course);
        foreach ($modinfo->get_section_info_all() as $section) {
            if ((int) $section->id === $sectionid) {
                $text = self::extract_section($section);
                if (empty(trim($text))) {
                    return null;
                }
                $title = !empty($section->name)
                    ? $section->name
                    : get_string('section') . ' ' . $section->section;
                return [
                    'title'   => $title,
                    'number'  => (int) $section->section,
                    'content' => $text,
                ];
            }
        }
        return null;
    }

    /**
     * Split text into sub-chunks if it exceeds the max size.
     *
     * @param string $text The full text.
     * @param string $chunktype The chunk type identifier.
     * @param int $chunkid The source ID.
     * @param string $title The source title.
     * @return array Array of chunk arrays.
     */
    private static function split_chunk(string $text, string $chunktype, int $chunkid, string $title): array {
        $text = trim($text);
        if (empty($text)) {
            return [];
        }

        if (\core_text::strlen($text) <= self::MAX_CHUNK_CHARS) {
            return [[
                'chunk_type' => $chunktype,
                'chunk_id' => $chunkid,
                'chunk_title' => \core_text::substr($title, 0, 255),
                'content_text' => $text,
            ]];
        }

        // Split on paragraph boundaries, then by sentence.
        $paragraphs = preg_split('/\n{2,}/', $text);
        $chunks = [];
        $current = '';

        foreach ($paragraphs as $para) {
            if (\core_text::strlen($current . "\n\n" . $para) > self::MAX_CHUNK_CHARS && !empty($current)) {
                $chunks[] = [
                    'chunk_type' => $chunktype,
                    'chunk_id' => $chunkid,
                    'chunk_title' => \core_text::substr($title . ' (part ' . (count($chunks) + 1) . ')', 0, 255),
                    'content_text' => trim($current),
                ];
                $current = $para;
            } else {
                $current .= (empty($current) ? '' : "\n\n") . $para;
            }
        }

        if (!empty(trim($current))) {
            $chunks[] = [
                'chunk_type' => $chunktype,
                'chunk_id' => $chunkid,
                'chunk_title' => \core_text::substr(
                    $title . (count($chunks) > 0 ? ' (part ' . (count($chunks) + 1) . ')' : ''),
                    0, 255
                ),
                'content_text' => trim($current),
            ];
        }

        return $chunks;
    }
}
