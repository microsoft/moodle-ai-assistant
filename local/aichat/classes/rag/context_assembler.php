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
 * AI Chat - Context assembler
 *
 * Builds the system prompt context from RAG search results.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat\rag;

defined('MOODLE_INTERNAL') || die();

/**
 * Assembles RAG-based context for the AI system prompt.
 */
class context_assembler {

    /**
     * Build the context string for the AI system prompt.
     *
     * Always injects a "Current Page" block for the resource the user is currently
     * viewing (fetched live), then supplements with RAG-retrieved chunks.
     *
     * @param int $courseid The course ID.
     * @param string $userquery The user's query text.
     * @param int|null $cmid The current activity module ID.
     * @param int|null $sectionid The current section DB id.
     * @return string The assembled context text.
     */
    public static function build_context(int $courseid, string $userquery, ?int $cmid = null, ?int $sectionid = null): string {
        global $DB;

        $course = get_course($courseid);
        $tokenbudget = (int) get_config('local_aichat', 'ragtokenbudget') ?: 3000;

        // --- Current page block (live extract, always present) ---
        // This ensures the AI always knows what page the user is on, regardless of
        // whether the RAG index contains it or the query is semantically related.
        $currentpageblock = '';
        if ($cmid > 0) {
            $pagedata = content_extractor::get_activity_content($courseid, $cmid);
            if ($pagedata) {
                $currentpageblock = "## Current Page Being Viewed\n"
                    . "Type: " . $pagedata['type'] . "\n"
                    . "Title: " . $pagedata['title'] . "\n"
                    . $pagedata['content'] . "\n\n";
            }
        } else if ($sectionid > 0) {
            $sectiondata = content_extractor::get_section_content($courseid, $sectionid);
            if ($sectiondata) {
                $currentpageblock = "## Current Section Being Viewed\n"
                    . "Section " . $sectiondata['number'] . ": " . $sectiondata['title'] . "\n"
                    . $sectiondata['content'] . "\n\n";
            }
        }

        // Static header always included.
        $header = "## Course: " . format_string($course->fullname) . "\n";
        $header .= "Short name: " . $course->shortname . "\n";
        if (!empty($course->summary)) {
            $summary = html_to_text($course->summary, 0, false);
            $header .= "Summary: " . \core_text::substr($summary, 0, 500) . "\n";
        }
        if ($course->startdate > 0) {
            $header .= "Start date: " . userdate($course->startdate) . "\n";
        }
        if ($course->enddate > 0) {
            $header .= "End date: " . userdate($course->enddate) . "\n";
        }

        // Check if RAG index exists.
        $indexstats = vector_store::get_index_stats($courseid);
        if ($indexstats['chunk_count'] === 0) {
            // Lazy indexing: trigger first index synchronously.
            try {
                vector_store::index_course($courseid);
                $indexstats = vector_store::get_index_stats($courseid);
            } catch (\Exception $e) {
                debugging('AI Chat: RAG indexing failed for course ' . $courseid . ': ' . $e->getMessage(),
                    DEBUG_DEVELOPER);
            }
        }

        // If still no index, use legacy fallback.
        if ($indexstats['chunk_count'] === 0) {
            $fallback = \local_aichat\course_context_builder::build($courseid, $cmid);
            return $currentpageblock . $header . "\n" . $fallback;
        }

        // RAG search for relevant chunks.
        $topk = (int) get_config('local_aichat', 'ragtopk') ?: 5;
        $chunks = vector_store::search($courseid, $userquery, $topk);

        // If cmid provided, ensure that activity's chunk is included.
        if ($cmid > 0) {
            $cmfound = false;
            foreach ($chunks as $chunk) {
                if ($chunk['chunk_id'] == $cmid) {
                    $cmfound = true;
                    break;
                }
            }
            if (!$cmfound) {
                // Load the specific activity's embedding — only if user can see it.
                $modinfo = get_fast_modinfo($course);
                try {
                    $cm = $modinfo->get_cm($cmid);
                    if ($cm->uservisible) {
                        $record = $DB->get_record('local_aichat_embeddings', [
                            'courseid' => $courseid,
                            'chunk_type' => $cm->modname,
                            'chunk_id' => $cmid,
                        ]);
                        if ($record) {
                            array_unshift($chunks, [
                                'chunk_title' => $record->chunk_title,
                                'content_text' => $record->content_text,
                                'chunk_type' => $record->chunk_type,
                                'chunk_id' => $record->chunk_id,
                                'similarity_score' => 1.0,
                                'token_count' => $record->token_count,
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    // Activity not found or no embedding; skip.
                }
            }
        }

        // Assemble context within token budget.
        // Current page block is prepended before the RAG results.
        $context = $currentpageblock . $header . "\n## Relevant Course Content:\n";
        $tokensused = (int) ceil(\core_text::strlen($context) / 4);

        foreach ($chunks as $chunk) {
            $chunktext = "### " . $chunk['chunk_title'] . " (" . $chunk['chunk_type'] . ")\n"
                       . $chunk['content_text'] . "\n\n";
            $chunktokens = (int) ceil(\core_text::strlen($chunktext) / 4);

            if ($tokensused + $chunktokens > $tokenbudget) {
                // Truncate this chunk to fit remaining budget.
                $remaining = $tokenbudget - $tokensused;
                if ($remaining > 50) {
                    $maxchars = $remaining * 4;
                    $chunktext = \core_text::substr($chunktext, 0, $maxchars) . "...\n\n";
                    $context .= $chunktext;
                }
                break;
            }

            $context .= $chunktext;
            $tokensused += $chunktokens;
        }

        return $context;
    }
}
