<?php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/course/lib.php');

$courses = $DB->get_records_select('course', 'id > 1');
foreach ($courses as $c) {
    delete_course($c->id, false);
    echo "Deleted: {$c->shortname} (ID={$c->id})\n";
}
echo "Done.\n";
