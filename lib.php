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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

require_once('courses/courses.class.php');
require_once('users/users.class.php');
require_once('enrol/enrols.class.php');
require_once('userpictures/userpictures.class.php');

define('SYNC_COURSE_CHECK', 0x001);
define('SYNC_COURSE_CREATE', 0x002);
define('SYNC_COURSE_DELETE', 0x004);
define('SYNC_COURSE_RESET', 0x008);
define('SYNC_COURSE_CREATE_DELETE', 0x006);

/**
 * prints a report to a log stream and output ir also to screen if required
 *
 */
function tool_sync_report(&$report, $message, $onscreen = true){
    if (empty($report)) {
        $report = '';
    }
    if ($onscreen) {
        mtrace($message);
    }
    $report .= $message."\n";
}

/**
 * Check a CSV input line format for empty or commented lines
 * Ensures compatbility to UTF-8 BOM or unBOM formats
 */
function tool_sync_is_empty_line_or_format(&$text, $resetfirst = false) {
    static $textlib;
    static $first = true;

    $config = get_config('tool_sync');

    // we may have a risk the BOM is present on first line
    if ($resetfirst) $first = true;
    if (!isset($textlib)) $textlib = new core_text(); // singleton
    if ($first && $config->encoding == 'UTF-8'){
        $text = $textlib->trim_utf8_bom($text);
        $first = false;
    }

    $text = preg_replace("/\n?\r?/", '', $text);

    if ($config->encoding != 'UTF-8') {
        $text = utf8_encode($text);
    }

    return preg_match('/^$/', $text) || preg_match('/^(\(|\[|-|#|\/| )/', $text);
}

/**
 * Get course and role assignations summary
 * TODO : Rework for PostGre compatibility.
 */
function tool_sync_get_all_courses($orderby = 'shortname') {
    global $DB;

    $sql = "
        SELECT
            IF(ass.roleid IS NOT NULL , CONCAT( c.id, '_', ass.roleid ) , CONCAT( c.id, '_', '0' ) ) AS recid, 
            c.id,
            c.shortname, 
            c.fullname, 
            c.idnumber,
            count( DISTINCT ass.userid ) AS people, 
            ass.rolename
        FROM
            {course} c
        LEFT JOIN
            (SELECT
                co.instanceid,
                ra.userid, 
                r.name as rolename,
                r.id as roleid
             FROM
                {context} co,
                {role_assignments} ra,
                {role} r
             WHERE
                co.contextlevel = 50 AND
                co.id = ra.contextid AND
                ra.roleid = r.id) ass
        ON
            ass.instanceid = c.id
        GROUP BY
            recid
        ORDER BY
            c.$orderby
    ";
    $results = $DB->get_records_sql($sql);
    return $results;
}

/**
 * Standard cron function
 */
function tool_sync_cron() {
    // No more cron action. Everything is handled using scheduled tasks
}

/**
* parses a YYYY-MM-DD hh:ii:ss
*
*
*/
function tool_sync_parsetime($time, $default = 0) {

    if (preg_match('/(\d\d\d\d)-(\d\d)-(\d\d)\s+(\d\d):(\d\d):(\d\d)/', $time, $matches)){
        $Y = $matches[1];
        $M = $matches[2];
        $D = $matches[3];
        $h = $matches[4];
        $i = $matches[5];
        $s = $matches[6];
        return mktime($h , $i, $s, $M, $D, $Y);
    } else {
        return $default;
    }
}

/**
 * Captures input files from a system administrator accessible location
 * and store them into tool_sync filearea
 * Synchronisation checks for a lock.txt file NOT being present. A readlock.txt
 * file is written as weak semaphore process. Readlock.txt signal will avoid
 * twice concurrent execution of file retireval. 
 */
function tool_sync_capture_input_files($interactive = false) {
    global $CFG;

    // Ensures input directory exists
    $syncinputdir = $CFG->dataroot.'/sync';
    if (!is_dir($syncinputdir)) {
        mkdir($syncinputdir, 0777);
    }

    $lockfile = $CFG->dataroot.'/sync/lock.txt';

    if (file_exists($lockfile)) {
        $fileinfo = stat($lockfile);
        if ($fileinfo['timecreated'] < (time() - HOURSEC * 3)) {
            // This is a too old file. May denote a remote feeder issue. Notify admin.
            if ($interactive) {
                mtrace('Too old write lock file. Resuming sync input capture.');
            } else {
                email_to_user(get_admin(), get_admin(), $SITE->shortname." : Too old write lock file.", 'Possible remote writer process issue.');
            }
        }
        return;
    }

    $readlockfile = $CFG->dataroot.'/sync/readlock.txt';
    if (file_exists($readlockfile)) {
        $fileinfo = stat($lockfile);
        if ($fileinfo['timecreated'] < (time() - HOURSEC * 3)) {
            // This is a too old file. May denote a remote feeder issue. Notify admin.
            if ($interactive) {
                mtrace('Too old read lock file. this miht affect remote end, but continue capture.');
            } else {
                email_to_user(get_admin(), get_admin(), $SITE->shortname." : Too old read lock file.", 'Possible local sync process issue.');
            }
        }
    }

    if ($FILE = fopen($readlockfile, 'w')) {
        fputs($FILE, time());
        fclose($FILE);
    } else {
        // Something wrong in sync input dir. Notify admin.
        if ($interactive) {
            mtrace('Could not create readlock file. Possible severe issue in storage. Resuming sync input capture.');
        } else {
            email_to_user(get_admin(), get_admin(), $SITE->shortname." : Could not create readlock file.", 'Possible local sync process issue.');
        }
        return;
    }

    $DIR = opendir($syncinputdir);

    $fs = get_file_storage();

    while ($entry = readdir($DIR)) {
        if (preg_match('/^\./', $entry)) {
            continue;
        }
        // Ignore dirs. Supposed to be a flat storage.
        if (is_dir($syncinputdir.'/'.$entry)) {
            continue;
        }
        // Forget any locking file.
        if (preg_match('/lock/', $entry)) {
            continue;
        }

        $filerec = new StdClass();
        $filerec->contextid = context_system::instance()->id;
        $filerec->component = 'tool_sync';
        $filerec->filearea = 'syncfiles';
        $filerec->itemid = 0;
        $filerec->filepath = '/';
        $filerec->filename = $entry;

        // Delete previous version and avoid file collision.
        if ($oldfile = $fs->get_file($filerec->contextid, $filerec->component, $filerec->filearea, $filerec->itemid, $filerec->filepath, $filerec->filename)) {
            $oldfile->delete();
        }

        $fs->create_file_from_pathname($filerec, $syncinputdir.'/'.$entry);
        @unlink($syncinputdir.'/'.$entry);
    }

    closedir($DIR);

    @unlink($readlockfile);
}

/** 
 * TODO write notification code
 */
function sync_notify_new_user_password($user, $value) {
}