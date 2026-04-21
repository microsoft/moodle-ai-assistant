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
 * AI Chat - Embedding client
 *
 * Calls the Azure OpenAI Embeddings API.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat\rag;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * Azure OpenAI Embeddings API client.
 */
class embedding_client {

    /** @var int Maximum texts per batch API call. */
    private const BATCH_SIZE = 16;

    /** @var int Maximum retry attempts for rate limiting. */
    private const MAX_RETRIES = 3;

    /**
     * Embed a single text string.
     *
     * @param string $text The text to embed.
     * @return array Float vector.
     * @throws \moodle_exception On API failure.
     */
    public static function embed(string $text): array {
        $results = self::call_api([$text]);
        return $results[0];
    }

    /**
     * Embed a batch of texts.
     *
     * @param array $texts Array of strings to embed.
     * @return array Array of float vectors.
     * @throws \moodle_exception On API failure.
     */
    public static function embed_batch(array $texts): array {
        if (empty($texts)) {
            return [];
        }

        $results = [];
        // Split into batches of BATCH_SIZE.
        $batches = array_chunk($texts, self::BATCH_SIZE);
        foreach ($batches as $batch) {
            $batchresults = self::call_api($batch);
            $results = array_merge($results, $batchresults);
        }
        return $results;
    }

    /**
     * Call the Azure OpenAI Embeddings API.
     *
     * @param array $inputs Array of input strings.
     * @return array Array of float vectors.
     * @throws \moodle_exception On failure.
     */
    private static function call_api(array $inputs): array {
        $endpoint = get_config('local_aichat', 'endpoint');
        $apikey = get_config('local_aichat', 'apikey');
        $deployment = get_config('local_aichat', 'embeddingdeployment');
        $apiversion = get_config('local_aichat', 'apiversion') ?: '2024-08-01-preview';

        if (empty($endpoint) || empty($apikey) || empty($deployment)) {
            throw new \moodle_exception('azurenotconfigured', 'local_aichat');
        }

        // Validate endpoint is a trusted Azure OpenAI domain.
        if (!preg_match('#^https://[a-z0-9\-]+\.openai\.azure\.com/?$#i', $endpoint)) {
            throw new \moodle_exception('invalidazureendpoint', 'local_aichat');
        }

        $url = rtrim($endpoint, '/') . '/openai/deployments/' . urlencode($deployment)
             . '/embeddings?api-version=' . urlencode($apiversion);

        $payload = json_encode([
            'input' => $inputs,
        ], JSON_THROW_ON_ERROR);

        $retries = 0;
        while ($retries <= self::MAX_RETRIES) {
            $curl = new \curl(['ignoresecurity' => false]);
            $curl->setHeader([
                'Content-Type: application/json',
                'api-key: ' . $apikey,
            ]);
            $response = $curl->post($url, $payload);
            $httpcode = $curl->get_info()['http_code'] ?? 0;

            if ($httpcode === 200) {
                $data = json_decode($response, true);
                if (!isset($data['data']) || !is_array($data['data'])) {
                    throw new \moodle_exception('embeddinginvalidresponse', 'local_aichat');
                }
                // Sort by index to match input order.
                usort($data['data'], fn($a, $b) => $a['index'] <=> $b['index']);
                return array_map(fn($item) => $item['embedding'], $data['data']);
            }

            if ($httpcode === 429 && $retries < self::MAX_RETRIES) {
                // Rate limited — exponential backoff.
                $retries++;
                $wait = pow(2, $retries);
                sleep($wait);
                continue;
            }

            throw new \moodle_exception('embeddingapierror', 'local_aichat', '', $httpcode);
        }

        throw new \moodle_exception('embeddingapierror', 'local_aichat', '', 'max retries');
    }
}
