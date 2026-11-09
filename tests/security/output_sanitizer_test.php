<?php
/**
 * Standalone URI regression tests; run with php tests/security/output_sanitizer_test.php.
 *
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('MOODLE_INTERNAL', true);
require_once __DIR__ . '/../../local/aichat/classes/security/output_sanitizer.php';

use local_aichat\security\output_sanitizer;

$cases = json_decode(file_get_contents(__DIR__ . '/uri_cases.json'), true, 512, JSON_THROW_ON_ERROR);
$checks = 0;

foreach ($cases as $case) {
    foreach (['"', "'", ''] as $quote) {
        if ($quote === '' && ($case['uri'] === '' || preg_match('/[\s\x00-\x20]/', $case['uri']))) {
            continue;
        }
        $input = '<p><a href=' . $quote . $case['uri'] . $quote . ' title="Course"><strong>Link</strong></a></p>';
        $output = output_sanitizer::sanitize_ai_response($input);
        $haslink = preg_match('/\bhref="([^"]*)"/', $output, $matches) === 1;
        if ($haslink !== $case['safe']) {
            throw new RuntimeException('Unexpected href for ' . json_encode($case) . ': ' . $output);
        }
        if ($haslink) {
            $actual = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($actual !== ($case['expected'] ?? $case['uri'])) {
                throw new RuntimeException('Safe URL changed: ' . json_encode($case));
            }
        }
        if (strpos($output, '<strong>Link</strong>') === false ||
                strpos($output, 'rel="noopener noreferrer" target="_blank"') === false ||
                strpos($output, 'title="Course"') === false) {
            throw new RuntimeException('Link formatting or protection lost: ' . $output);
        }
        if (output_sanitizer::sanitize_ai_response($output) !== $output) {
            throw new RuntimeException('Sanitization is not idempotent: ' . json_encode($case));
        }
        $checks++;
    }
}

echo "PASS: {$checks} server-side URI cases\n";
