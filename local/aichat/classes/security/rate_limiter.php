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
 * AI Chat - Rate limiter
 *
 * Enforces burst and daily message rate limits.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat\security;

defined('MOODLE_INTERNAL') || die();

/**
 * Burst and daily rate limiting for user messages.
 */
class rate_limiter {

    /** @var string Cache definition name. */
    private const CACHE_AREA = 'burst_rate';

    /**
     * Check the burst rate limit (messages per minute).
     *
     * @param int $userid The user ID.
     * @param int $limit Max messages per minute (0 = unlimited).
     * @throws \moodle_exception If the burst limit is exceeded.
     */
    public static function check_burst_limit(int $userid, int $limit = 0): void {
        if ($limit <= 0) {
            $limit = (int) get_config('local_aichat', 'burstlimit');
        }
        if ($limit <= 0) {
            return; // Unlimited.
        }

        $cache = \cache::make('local_aichat', self::CACHE_AREA);
        $key = 'burst_' . $userid;
        $data = $cache->get($key);

        $now = time();
        if ($data === false) {
            $data = ['count' => 0, 'window_start' => $now];
        }

        // Reset window if more than 60 seconds have passed.
        if ($now - $data['window_start'] >= 60) {
            $data = ['count' => 0, 'window_start' => $now];
        }

        if ($data['count'] >= $limit) {
            $retryin = 60 - ($now - $data['window_start']);
            throw new \moodle_exception('burstwait', 'local_aichat', '', max(1, $retryin));
        }

        $data['count']++;
        $cache->set($key, $data);
    }

    /**
     * Check the daily message limit.
     *
     * @param int $userid The user ID.
     * @param int $limit Max messages per day (0 = unlimited).
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_in' => int seconds]
     */
    public static function check_daily_limit(int $userid, int $limit = 0): array {
        global $DB;

        if ($limit <= 0) {
            $limit = (int) get_config('local_aichat', 'dailylimit');
        }
        if ($limit <= 0) {
            return ['allowed' => true, 'remaining' => -1, 'reset_in' => 0]; // Unlimited.
        }

        // Count user messages sent today (role = 'user').
        $todaystart = mktime(0, 0, 0);
        $sql = "SELECT COUNT(m.id)
                  FROM {local_aichat_messages} m
                  JOIN {local_aichat_threads} t ON t.id = m.threadid
                 WHERE t.userid = :userid
                   AND m.role = 'user'
                   AND m.timecreated >= :todaystart";
        $count = (int) $DB->count_records_sql($sql, [
            'userid' => $userid,
            'todaystart' => $todaystart,
        ]);

        $remaining = max(0, $limit - $count);
        $allowed = $remaining > 0;

        // Seconds until midnight.
        $tomorrowstart = mktime(0, 0, 0) + 86400;
        $resetin = $tomorrowstart - time();

        if (!$allowed) {
            $hours = ceil($resetin / 3600);
            throw new \moodle_exception('dailylimitreached', 'local_aichat', '', $hours);
        }

        return ['allowed' => true, 'remaining' => $remaining, 'reset_in' => $resetin];
    }
}
