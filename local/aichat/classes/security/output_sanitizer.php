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
 * AI Chat - Output sanitizer
 *
 * Sanitizes AI responses using a strict HTML whitelist.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat\security;

defined('MOODLE_INTERNAL') || die();

/**
 * AI output sanitization using HTML whitelist.
 */
class output_sanitizer {

    /** @var array Allowed HTML tags. */
    private const ALLOWED_TAGS = [
        'strong', 'em', 'b', 'i', 'u', 'code', 'pre', 'ul', 'ol', 'li', 'p', 'br',
        'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'span', 'div', 'hr', 'dl', 'dt', 'dd',
    ];

    /** @var array Allowed attributes per tag. */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title', 'rel', 'target'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
        'ol' => ['start', 'type'],
        'code' => ['class'],
    ];

    /**
     * Sanitize an AI response string.
     *
     * Strips dangerous tags, event handlers, and javascript URIs.
     * Adds rel="noopener noreferrer" and target="_blank" to all links.
     *
     * @param string $response The raw AI response.
     * @return string The sanitized response.
     */
    public static function sanitize_ai_response(string $response): string {
        if (empty($response)) {
            return '';
        }

        // First pass: strip event handler attributes (on*).
        $response = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $response);
        $response = preg_replace('/\s+on\w+\s*=\s*\S+/i', '', $response);

        // Strip javascript:, data:, and vbscript: URIs.
        $response = preg_replace('/href\s*=\s*["\']?\s*javascript\s*:/i', 'href="', $response);
        $response = preg_replace('/src\s*=\s*["\']?\s*javascript\s*:/i', 'src="', $response);
        $response = preg_replace('/href\s*=\s*["\']?\s*data\s*:/i', 'href="', $response);
        $response = preg_replace('/href\s*=\s*["\']?\s*vbscript\s*:/i', 'href="', $response);

        // Build the strip_tags allowed list.
        $allowedtagstr = implode('', array_map(fn($t) => "<{$t}>", self::ALLOWED_TAGS));
        $response = strip_tags($response, $allowedtagstr);

        // Process anchor tags: enforce rel="noopener noreferrer" target="_blank".
        $response = preg_replace_callback(
            '/<a\s([^>]*)>/i',
            function ($matches) {
                $attrs = $matches[1];
                // Remove existing rel and target.
                $attrs = preg_replace('/\s*(rel|target)\s*=\s*["\'][^"\']*["\']/i', '', $attrs);
                // Strip any disallowed attributes.
                $attrs = self::filter_attributes('a', $attrs);
                return '<a ' . trim($attrs) . ' rel="noopener noreferrer" target="_blank">';
            },
            $response
        );

        // Strip disallowed attributes from all other tags.
        foreach (self::ALLOWED_TAGS as $tag) {
            if ($tag === 'a') {
                continue; // Already handled.
            }
            $response = preg_replace_callback(
                '/<' . preg_quote($tag, '/') . '\s([^>]*)>/i',
                function ($matches) use ($tag) {
                    $attrs = self::filter_attributes($tag, $matches[1]);
                    if (empty(trim($attrs))) {
                        return '<' . $tag . '>';
                    }
                    return '<' . $tag . ' ' . trim($attrs) . '>';
                },
                $response
            );
        }

        return $response;
    }

    /**
     * Filter attributes for a tag, keeping only allowed ones.
     *
     * @param string $tag The tag name.
     * @param string $attrstring The raw attribute string.
     * @return string The filtered attribute string.
     */
    private static function filter_attributes(string $tag, string $attrstring): string {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];
        if (empty($allowed)) {
            return '';
        }

        $result = [];
        // Match attribute="value" or attribute='value' or attribute=value.
        preg_match_all('/(\w+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|(\S+))/', $attrstring, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $name = strtolower($m[1]);
            $value = $m[2] ?? $m[3] ?? $m[4] ?? '';
            if (in_array($name, $allowed, true)) {
                // Reject dangerous URI schemes in any attribute value (javascript:, data:, vbscript:).
                if (preg_match('/^\s*(javascript|data|vbscript)\s*:/i', $value)) {
                    continue;
                }
                $result[] = $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
            }
        }
        return implode(' ', $result);
    }
}
