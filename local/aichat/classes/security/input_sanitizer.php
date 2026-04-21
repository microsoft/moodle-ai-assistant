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
 * AI Chat - Input sanitizer
 *
 * Validates and sanitizes user messages before processing.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat\security;

defined('MOODLE_INTERNAL') || die();

/**
 * Input validation and sanitization for user messages.
 */
class input_sanitizer {

    /**
     * Sanitize a user message by stripping control characters and trimming whitespace.
     *
     * @param string $message The raw user message.
     * @return string The sanitized message.
     */
    public static function sanitize_message(string $message): string {
        // Strip control characters (U+0000-U+001F, U+007F) except newline (U+000A) and carriage return (U+000D).
        $message = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $message);
        // Normalize line endings.
        $message = str_replace("\r\n", "\n", $message);
        $message = str_replace("\r", "\n", $message);
        // Trim whitespace.
        $message = trim($message);
        return $message;
    }

    /**
     * Validate message length against the configured maximum.
     *
     * @param string $message The sanitized message.
     * @param int $maxlength Maximum allowed length (0 = no limit).
     * @throws \moodle_exception If the message exceeds the maximum length.
     */
    public static function validate_message_length(string $message, int $maxlength = 2000): void {
        if ($maxlength > 0 && \core_text::strlen($message) > $maxlength) {
            throw new \moodle_exception('messagetoolong', 'local_aichat', '', $maxlength);
        }
    }

    /**
     * Strip known prompt injection patterns from a message.
     *
     * Logs suspicious input via the Moodle event system.
     *
     * @param string $message The sanitized message.
     * @param int $userid The user ID (for logging).
     * @param int $courseid The course ID (for logging).
     * @return string The cleaned message.
     */
    public static function strip_prompt_injection(string $message, int $userid = 0, int $courseid = 0): string {
        $patterns = [
            '/ignore\s+(all\s+)?previous\s+instructions/i',
            '/ignore\s+(all\s+)?prior\s+instructions/i',
            '/disregard\s+(all\s+)?previous/i',
            '/you\s+are\s+now\s+(?:a|an)\s+/i',
            '/\bsystem\s*:\s*/i',
            '/\bassistant\s*:\s*/i',
            '/\buser\s*:\s*/i',
            '/^###\s*/m',
            '/\[INST\]/i',
            '/\[\/INST\]/i',
            '/<<SYS>>/i',
            '/<<\/SYS>>/i',
            '/reveal\s+(?:your\s+)?(?:system\s+)?(?:prompt|instructions)/i',
            '/what\s+(?:are|is)\s+your\s+(?:system\s+)?(?:prompt|instructions)/i',
        ];

        $original = $message;
        foreach ($patterns as $pattern) {
            $message = preg_replace($pattern, '', $message);
        }
        $message = trim($message);

        // Log if the message was modified (suspicious input detected).
        if ($message !== $original && $userid > 0 && $courseid > 0) {
            // Fire event only if the event class exists (avoid errors during early phases).
            if (class_exists('\local_aichat\event\chat_message_sent')) {
                // Logging is handled by the calling code; here we just clean.
            }
            debugging('AI Chat: Prompt injection pattern detected from user ' . $userid, DEBUG_DEVELOPER);
        }

        return $message;
    }
}
