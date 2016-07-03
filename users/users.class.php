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

namespace tool_sync;

defined('MOODLE_INTERNAL') || die();
/**
 * @package   tool_sync
 * @category  tool
 * @author Funck Thibaut
 * @copyright 2010 Valery Fremaux <valery.fremaux@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** The following flags are set in the configuration
 * @author Funck Thibaut
 */

require_once($CFG->dirroot.'/admin/tool/sync/lib.php');
require_once($CFG->dirroot.'/user/profile/lib.php');
require_once($CFG->dirroot.'/admin/tool/sync/sync_manager.class.php');
require_once($CFG->dirroot.'/user/lib.php');

class users_sync_manager extends sync_manager {

    protected $manualfilerec;

    public function __construct($manualfilerec = null) {
        $this->manualfilerec = $manualfilerec;
    }

    /**
     * Configure elements for the tool configuration form
     */
    function form_elements(&$frm) {
        global $CFG;

        $frm->addElement('text', 'tool_sync/users_filelocation', get_string('usersfile', 'tool_sync'));
        $frm->setType('tool_sync/users_filelocation', PARAM_TEXT);

        $frm->addElement('static', 'usersst1', '<hr>');

        $frm->addElement('checkbox', 'tool_sync/sendpasswordtousers', get_string('sendpasswordtousers', 'tool_sync'));

        $identifieroptions = $this->get_userfields();
        $frm->addElement('select', 'tool_sync/primaryidentity', get_string('primaryidentity', 'tool_sync'), $identifieroptions);
        $frm->setDefault('tool_sync/primaryidentity', 'idnumber');
        $frm->setType('tool_sync/primaryidentity', PARAM_TEXT);

        $params = array('onclick' => 'document.location.href= \''.$CFG->wwwroot.'/admin/tool/sync/users/execcron.php\'');
        $frm->addElement('button', 'manualusers', get_string('manualuserrun', 'tool_sync'), $params);

    }

    function get_userfields() {
        return array('id' => 'id',
                     'idnumber' => 'idnumber',
                     'username' => 'username',
                     'email' => 'email');
    }

    // Override the get_access_icons() function.
    /*
    function get_access_icons($course) {
    }
    */

    /**
     * Executes this manager main task
     */
    function cron($syncconfig) {
        global $CFG, $USER, $DB;

        $systemcontext = \context_system::instance();

        // Internal process controls
        $createpassword = false;
        $updateaccounts = true;
        $allowrenames   = false;
        $keepexistingemailsafe = true;
        $notifypasswordstousers = @$syncconfig->sendpasswordtousers;

        if (!$adminuser = get_admin()) {
            return;
        }

        if (empty($this->manualfilerec)) {
            $filerec = $this->get_input_file(@$syncconfig->users_filelocation, 'userimport.csv');
        } else {
            $filerec = $this->manualfilerec;
        }

        // We have no file to process. Probably because never setup
        if (!($filereader = $this->open_input_file($filerec))) {
            return;
        }

        $csv_encode = '/\&\#44/';
        if (isset($syncconfig->csvseparator)) {
            $csv_delimiter = '\\' . $syncconfig->csvseparator;
            $csv_delimiter2 = $syncconfig->csvseparator;

            if (isset($CFG->CSV_ENCODE)) {
                $csv_encode = '/\&\#' . $CFG->CSV_ENCODE . '/';
            }
        } else {
            $csv_delimiter = "\;";
            $csv_delimiter2 = ";";
        }

        /*
         * File that is used is currently hardcoded here!
         * Large files are likely to take their time and memory. Let PHP know
         * that we'll take longer, and that the process should be recycled soon
         * to free up memory.
         */
        @set_time_limit(0);
        @raise_memory_limit("256M");
        if (function_exists('apache_child_terminate')) {
            @apache_child_terminate();
        }

        $defaultcountry = (empty($CFG->country)) ? 'NZ' : $CFG->country;
        $timezone = (empty($CFG->timezone)) ? '99' : $CFG->timezone;
        $lang = (empty($CFG->lang)) ? 'en' : $CFG->lang;

        // Make arrays of valid fields for error checking.
        $required = array('username' => 1,
                //'password' => !$createpassword,  //*NT* as we use LDAP and Moodle does not maintain passwords...OUT!
                'firstname' => 1,
                'lastname' => 1);

        $optionalDefaults = array(
                'mnethostid' => 1,
                'institution' => '',
                'department' => '',
                'city' => $CFG->defaultcity,
                'country' => $defaultcountry,
                'lang' => $lang,
                'maildisplay' => 1,
                'maildigest' => 0,
                'timezone' => $timezone);

        $optional = array('idnumber' => 1,
                'email' => 1,               //*NT* email is optional on upload to clear open ones  and reset at the beginning of the year!
                'auth' => 1,
                'icq' => 1,
                'phone1' => 1,
                'phone2' => 1,
                'address' => 1,
                'url' => 1,
                'description' => 1,
                'mailformat' => 1,
                'maildisplay' => 1,
                'maildigest' => 1,
                'htmleditor' => 1,
                'autosubscribe' => 1,
                'trackforums' => 1,
                'cohort' => 1,
                'cohortid' => 1,
                'course1' => 1,
                'group1' => 1,
                'type1' => 1,
                'role1' => 1,
                'enrol1' => 1,
                'start1' => 1,
                'end1' => 1,
                'wwwroot1' => 1, // Allows MNET propagation to remote node.
                'password' => $createpassword,
                'oldusername' => $allowrenames);

            $patterns = array('course', // Patternized items are iterative items with indexing integer appended.
                'group',
                'type',
                'role',
                'enrol',
                'start',
                'end',
                'wwwroot');
            $metas = array(
                'profile_field_.*');

        // --- get header (field names) ---

        $textlib = new \core_text();

        // Jump any empty or comment line.
        $text = fgets($filereader, 1024);
        $i = 0;
        while (tool_sync_is_empty_line_or_format($text, $i == 0)) {
            $text = tool_sync_read($filereader, 1024, $syncconfig);
            $i++;
        }

        $headers = explode($csv_delimiter2, $text);

        // Check for valid field names.
        foreach ($headers as $h) {
            $header[] = trim($h);
            $patternized = implode('|', $patterns) . "\\d+";
            $metapattern = implode('|', $metas);
            if (!(isset($required[$h]) or isset($optionalDefaults[$h]) or isset($optional[$h]) or preg_match("/$patternized/", $h) or preg_match("/$metapattern/", $h))) {
                $this->report(get_string('invalidfieldname', 'error', $h));
                return;
            }

            if (isset($required[$h])) {
                $required[$h] = 0;
            }
        }

        // Check for required fields.
        foreach ($required as $key => $value) {
            if ($value) {
                // Required field missing.
                $this->report(get_string('fieldrequired', 'error', $key));
                return;
            }
        }
        $linenum = 2; // Since header is line 1.

        // Header is validated.
        $this->init_tryback($headers);

        $usersnew     = 0;
        $usersupdated = 0;
        $userserrors  = 0;
        $renames      = 0;
        $renameerrors = 0;

        /*
         * Will use this course array a lot
         * so fetch it early and keep it in memory
         */
        $courses = get_courses('all', 'c.sortorder','c.id,c.shortname,c.idnumber,c.fullname,c.sortorder,c.visible');

        // Take some from admin profile, other fixed by hardcoded defaults.
        while (!feof($filereader)) {

            // Make a new base record.
            $user = new \StdClass;
            foreach ($optionalDefaults as $key => $value) {
                if ($value == 'adminvalue'){
                    $user->$key = $adminuser->$key;
                } else {
                    $user->$key = $value;
                }
            }

            /*
             * Note: commas within a field should be encoded as &#44 (for comma separated csv files)
             * Note: semicolon within a field should be encoded as &#59 (for semicolon separated csv files)
             */
            $text = tool_sync_read($filereader, 1024, $syncconfig);
            if (tool_sync_is_empty_line_or_format($text, false)) {
                $i++;
                continue;
            }
            $valueset = explode($csv_delimiter2, $text);
            $record = array();

            $tobegenerated = false;

            foreach ($valueset as $key => $value) {
                // Decode encoded commas.
                $record[$header[$key]] = preg_replace($csv_encode, $csv_delimiter2, trim($value));
            }
            if ($record[$header[0]]) {

                // Add a new user to the database.
                // Add fields to object $user.
                foreach ($record as $name => $value) {
                    if ($name == 'wwwroot') {
                        // Process later.
                        continue;
                    }

                    // Check for required values.
                    if (isset($required[$name]) and !$value) {
                        $errormessage = get_string('missingfield', 'error', $name)." ".get_string('erroronline', 'error', $linenum).". ".get_string('missingfield', 'error', $name);
                        $this->report($errormessage);
                        return;
                    } elseif ($name == 'password') {

                        if (empty($value)) {
                            $user->password = 'to be generated';
                            $tobegenerated = true;
                        }

                        // Password needs to be encrypted.
                        elseif ($value != '*NOPASS*') {
                            $user->password = hash_internal_user_password($value);
                            if ($notifypasswordstousers) {
                                if (!empty($user->email) && (!preg_match('/NO MAIL|NOMAIL/', $user->email))) {
                                    // If we can send mail to user, let's notfy with the moodle password notification mail.
                                    sync_notify_new_user_password($user, $value);
                                }
                            }
                        } else {
                            // Mark user having no password.
                            $user->password = '*NOPASS*';
                        }
                    } elseif ($name == 'username') {
                        $user->username = \core_text::strtolower($value);
                    } else {
                        // Normal entry.
                        $user->{$name} = $value;
                    }
                }
                if (isset($user->country)) $user->country = strtoupper($user->country);
                if (isset($user->lang)) $user->lang = str_replace('_utf8', '', strtolower($user->lang));
                $user->confirmed = 1;
                $user->timemodified = time();
                $linenum++;
                $username = $user->username;
                $firstname = $user->firstname;
                $lastname = $user->lastname;
                $idnumber = @$user->idnumber;

                $ci = 1;
                $courseix = 'course'.$ci;
                $groupix = 'group'.$ci;
                $typeix = 'type'.$ci;
                $roleix = 'role'.$ci;
                $enrolix = 'enrol'.$ci;
                $startix = 'start'.$ci;
                $endix = 'end'.$ci;
                $wwwrootix = 'wwwroot'.$ci;
                $addcourses = array();
                while (isset($user->$courseix)) {
                    $coursetoadd = new \StdClass;
                    $coursetoadd->idnumber = $user->$courseix;
                    $coursetoadd->group = isset($user->$groupix) ? $user->$groupix : NULL;
                    $coursetoadd->type = isset($user->$typeix) ? $user->$typeix : NULL;  // Deprecated. Not more used.
                    $coursetoadd->role = isset($user->$roleix) ? $user->$roleix : NULL;
                    $coursetoadd->enrol = isset($user->$enrolix) ? $user->$enrolix : NULL;
                    $coursetoadd->start = isset($user->$startix) ? $user->$startix : 0;
                    $coursetoadd->end = isset($user->$endix) ? $user->$endix : 0;
                    $coursetoadd->wwwroot = isset($user->$wwwrootix) ? $user->$wwwrootix : 0;
                    $addcourses[] = $coursetoadd;
                    $ci++;
                    $courseix = 'course'.$ci;
                    $groupix = 'group'.$ci;
                    $typeix = 'type'.$ci;
                    $roleix = 'role'.$ci;
                    $startix = 'start'.$ci;
                    $endix = 'end'.$ci;
                    $wwwrootix = 'wwwroot'.$ci;
                }

                /*
                 * Before insert/update, check whether we should be updating
                 * an old record instead
                 */
                if ($allowrenames && !empty($user->oldusername) ) {
                    $user->oldusername = moodle_strtolower($user->oldusername);
                    if ($olduser = $DB->get_record('user', array('username' => $user->oldusername, 'mnethostid' => $user->mnethostid))) {
                        if ($DB->set_field('user', 'username', $user->username, array('username' => $user->oldusername))) {
                            $this->report(get_string('userrenamed', 'admin')." : $user->oldusername $user->username");
                            $renames++;
                        } else {
                            $this->report(get_string('usernotrenamedexists', 'tool_sync')." : $user->oldusername $user->username");
                            $renameerrors++;
                            continue;
                        }
                    } else {
                        $this->report(get_string('usernotrenamedmissing', 'tool_sync')." : $user->oldusername $user->username");
                        $renameerrors++;
                        continue;
                    }
                }

                // Set some default.
                if (empty($syncconfig->primaryidentity)) {
                    if (!isset($CFG->primaryidentity)) {
                        set_config('primaryidentity', 'idnumber', 'tool_sync');
                        $syncconfig->primaryidentity = 'idnumber';
                    } else {
                        set_config('primaryidentity', $CFG->primaryidentity, 'tool_sync');
                        $syncconfig->primaryidentity = $CFG->primaryidentity;
                    }
                }

                if (empty($user->mnethostid)) {
                    $user->mnethostid = $CFG->mnet_localhost_id;
                }

                if (($syncconfig->primaryidentity == 'idnumber') && !empty($idnumber)) {
                    $olduser = $DB->get_record('user', array('idnumber' => $idnumber, 'mnethostid' => $user->mnethostid));
                } elseif (($syncconfig->primaryidentity == 'email') && !empty($user->email)) {
                    $olduser = $DB->get_record('user', array('email' => $user->email, 'mnethostid' => $user->mnethostid));
                } else {
                    $olduser = $DB->get_record('user', array('username' => $username, 'mnethostid' => $user->mnethostid));
                }
                if ($olduser) {
                    if ($updateaccounts) {
                        // Record is being updated.
                        $user->id = $olduser->id;
                        if ($olduser->deleted) {
                            // Revive old deleted users if they already exist.
                            $this->report(get_string('userrevived', 'tool_sync', "$user->username ($idnumber)"));
                            $user->deleted = 0;
                        }
                        if ($keepexistingemailsafe) {
                            unset($user->email);
                        }
                        try {
                            // This triggers event as required.
                            if (!$syncconfig->simulate) {
                                user_update_user($user);
                                $this->report(get_string('useraccountupdated', 'tool_sync', "$user->firstname $user->lastname as [$user->username] ($idnumber)"));
                            } else {
                                $this->report('SIMULATION : '.get_string('useraccountupdated', 'tool_sync', "$user->firstname $user->lastname as [$user->username] ($idnumber)"));
                            }

                            $usersupdated++;
                        } catch(Exception $e) {
                            if (!empty($syncconfig->filefailed)) {
                                $this->feed_tryback($text);
                            }
                            $this->report(get_string('usernotupdatederror', 'tool_sync', "[$username] $lastname $firstname ($idnumber)"));
                            $userserrors++;
                            continue;
                        }

                        // Save custom profile fields data from csv file.
                        if (!$syncconfig->simulate) {
                            profile_save_data($user);
                        }
                    } else {
                        /*
                         * Record not added - user is already registered
                         * In this case, output userid from previous registration
                         * This can be used to obtain a list of userids for existing users
                         */
                        $this->report("$olduser->id ".get_string('usernotaddedregistered', 'error', "[$username] $lastname $firstname ($user->idnumber)"));
                        $userserrors++;
                    }
                } else {
                    // New user.
                    // Pre check we have no username collision.
                    if ($olduser = $DB->get_record('user', array('mnethostid' => $user->mnethostid, 'username' => $user->username))){
                        $this->report(get_string('usercollision', 'tool_sync', "$olduser->id , $user->username , $user->idnumber, $user->firstname, $user->lastname "));
                        continue;
                    }
                    
                    try {
                        // This will also trigger the event.
                        if (!$syncconfig->simulate) {
                            $user->id = user_create_user($user);
                            $this->report(get_string('useraccountadded', 'tool_sync', "$user->id , $user->username "));
                            $usersnew++;
                            if (empty($user->password) && $createpassword) {
                                // Passwords will be created and sent out on cron.
                                $pref = new \StdClass();
                                $pref->userid = $newuser->id;
                                $pref->name = 'create_password';
                                $pref->value = 1;
                                $DB->insert_record('user_preferences', $pref);
    
                                $pref = new \StdClass();
                                $pref->userid = $newuser->id;
                                $pref->name = 'auth_forcepasswordchange';
                                $pref->value = $forcepasswordchange;
                                $DB->insert_record('user_preferences', $pref);
                            }
    
                            // Save custom profile fields data from csv file.
                            profile_save_data($user);
                        } else {
                            $this->report('SIMULATION : '.get_string('useraccountadded', 'tool_sync', "$user->id , $user->username "));
                            $usersnew++;
                        }

                    } catch(Exception $e) {
                        // Record not added -- possibly some other error.
                        if (!empty($syncconfig->filefailed)) {
                            $this->feed_tryback($text);
                        }
                        $this->report(get_string('usernotaddederror', 'tool_sync', "[$username] $lastname $firstname ($idnumber)"));
                        $userserrors++;
                        continue;
                    }
                }

                // Post create check password handling. We need ID of the user !
                if ($tobegenerated && !$syncconfig->simulate) {
                    set_user_preference('create_password', 1, $user);
                }

                // Cohort (only system level) binding management.
                if (@$user->cohort) {
                    $t = time();
                    if (!$cohort = $DB->get_record('cohort', array('name' => $user->cohort))) {
                        $cohort = new \StdClass();
                        $cohort->name = $user->cohort;
                        $cohort->idnumber = @$user->cohortid;
                        $cohort->descriptionformat = FORMAT_MOODLE;
                        $cohort->contextid = $systemcontext->id;
                        $cohort->timecreated = $t;
                        $cohort->timemodified = $t;
                        if (!$syncconfig->simulate) {
                            $cohort->id = $DB->insert_record('cohort', $cohort);
                        } else {
                            $this->report('SIMULATION : '.get_string('creatingcohort', 'tool_sync', $cohort->name));
                        }
                    }

                    // Bind user to cohort.
                    if (!$cohortmembership = $DB->get_record('cohort_members', array('userid' => $user->id, 'cohortid' => $cohort->id))) {
                        $cohortmembership = new \StdClass();
                        $cohortmembership->userid = $user->id;
                        $cohortmembership->cohortid = ''.@$cohort->id;
                        $cohortmembership->timeadded = $t;
                        if (!$syncconfig->simulate) {
                            $cohortmembership->id = $DB->insert_record('cohort_members', $cohortmembership);
                        } else {
                            $this->report('SIMULATION : '.get_string('registeringincohort', 'tool_sync', $cohort->name));
                        }
                    }
                }

                // Course binding management.
                if (!empty($addcourses)) {
                    foreach ($addcourses as $c) {

                        if (empty($c->idnumber)) {
                            // empty course sets should be ignored.
                            continue;
                        }

                        if (empty($c->wwwroot)) {
                            // Course binding is local.

                            if (!$crec = $DB->get_record('course', array('idnumber' => $c->idnumber))) {
                                $this->report(get_string('unknowncourse', 'error', $c->idnumber));
                                continue;
                            }

                            if (!empty($c->enrol)) {
                                $enrol = enrol_get_plugin('manual');
                                if (!$enrols = $DB->get_records('enrol', array('enrol' => $c->enrol, 'courseid' => $crec->id, 'status' => ENROL_INSTANCE_ENABLED), 'sortorder ASC')) {
                                    $this->report(get_string('errornomanualenrol', 'tool_sync'));
                                    $c->enrol = '';
                                } else {
                                    $enrol = reset($enrols);
                                    $enrolplugin = enrol_get_plugin($c->enrol);
                                }
                            }

                            $coursecontext = \context_course::instance($crec->id);
                            if (!empty($c->role)) {
                                $role = $DB->get_record('role', array('shortname' => $c->role));
                                if (!empty($c->enrol)) {

                                    $e = new \StdClass();
                                    $e->myuser = $user->username; // user identifier
                                    $e->mycourse = $crec->idnumber; // course identifier

                                    try {
                                        if (!$syncconfig->simulate) {
                                            $enrolplugin->enrol_user($enrol, $user->id, $role->id, time(), 0, ENROL_USER_ACTIVE);
                                            $this->report(get_string('enrolled', 'tool_sync', $e));
                                        } else {
                                            $this->report('SIMULATION : '.get_string('enrolled', 'tool_sync', $e));
                                        }
                                        $ret = true;
                                    } catch (Exception $exc) {
                                        $this->report(get_string('errorenrol', 'tool_sync', $e));
                                    }
                                } else {
                                    if (!user_can_assign($coursecontext, $c->role)) {
                                        //notify('--> Can not assign role in course'); //TODO: localize
                                    }
                                    if (!$syncconfig->simulate) {
                                        $ret = role_assign($role->id, $user->id, $coursecontext->id);
                                        $e = new \StdClass();
                                        $e->contextid = $coursecontext->id;
                                        $e->rolename = $c->role;
                                        $this->report(get_string('roleadded', 'tool_sync', $e));
                                    } else {
                                        $e = new \StdClass();
                                        $e->contextid = $coursecontext->id;
                                        $e->rolename = $c->role;
                                        $this->report('SIMULATION : '.get_string('roleadded', 'tool_sync', $e));
                                    }
                                }
                            } else {
                                if (!empty($c->enrol)) {
                                    $role = $DB->get_record('role', array('shortname' => 'student'));
                                    if (!$syncconfig->simulate) {
                                        $enrolplugin->enrol_user($enrol, $user->id, $role->id, time(), 0, ENROL_USER_ACTIVE);
                                        $this->report(get_string('enrolledincourse', 'tool_sync', $c->idnumber));
                                    } else {
                                        $this->report('SIMULATION : '.get_string('enrolledincourse', 'tool_sync', $c->idnumber));
                                    }
                                }
                            }
                            if (!@$ret) {
                                // OK.
                                $this->report(get_string('enrolledincoursenot', 'tool_sync', $c->idnumber));
                            }

                            // we only can manage groups for successful enrollments

                            if (@$ret) {   // OK
                                // check group existance and try to create
                                if (!empty($c->group)) {
                                    if (!$gid = groups_get_group_by_name($crec->id, $c->group)) {
                                        $groupsettings = new \StdClass();
                                        $groupsettings->name = $c->group;
                                        $groupsettings->courseid = $crec->id;
                                        if (!$syncconfig->simulate) {
                                            if (!$gid = groups_create_group($groupsettings)) {
                                                $this->report(get_string('groupnotaddederror', 'tool_sync', $c->group));
                                            }
                                        } else {
                                            $this->report('SIMULATION : '.get_string('groupadded', 'tool_sync', $c->group));
                                        }
                                    }

                                    if ($gid) {
                                        if (count(get_user_roles($coursecontext, $user->id))) {
                                            if (!$syncconfig->simulate) {
                                                if (add_user_to_group($gid, $user->id)) {
                                                    $this->report(get_string('addedtogroup', '',$c->group));
                                                } else {
                                                    $this->report(get_string('addedtogroupnot', '',$c->group));
                                                }
                                            } else {
                                                $this->report('SIMULATION : '.get_string('addedtogroup', '',$c->group));
                                            }
                                        } else {
                                            $this->report(get_string('addedtogroupnotenrolled', '', $c->group));
                                        }
                                    }
                                }
                            }
                        }

                        /*
                         * if we can propagate user to designates wwwroot let's do it
                         * only if the VMoodle block is installed.
                         */
                        if (!$syncconfig->simulate) {
                            if (!empty($c->wwwroot) && $DB->get_record('block', array('name' => 'vmoodle'))) {
                                if (!file_exists($CFG->dirroot.'/blocks/vmoodle/rpclib.php')) {
                                    echo $OUTPUT->notification('This feature works with VMoodle Virtual Moodle Implementation');
                                    continue;
                                }
                                include_once($CFG->dirroot.'/blocks/vmoodle/rpclib.php');
                                include_once($CFG->dirroot.'/mnet/xmlrpc/client.php');
    
                                // Imagine we never did it before.
                                global $MNET;
                                $MNET = new \mnet_environment();
                                $MNET->init();
    
                                $this->report(get_string('propagating', 'vmoodle', fullname($user)));
                                $caller = new \StdClass();
                                $caller->username = 'admin';
                                $caller->remoteuserhostroot = $CFG->wwwroot;
                                $caller->remotehostroot = $CFG->wwwroot;
    
                                // Check if exists.
                                $exists = false;
                                if ($return = mnetadmin_rpc_user_exists($caller, $user->username, $c->wwwroot, true)) {
                                    $response = json_decode($return);
                                    if (empty($response)) {
                                        if (debugging()) {
                                            print_object($return);
                                        }
                                        continue;
                                    }
                                    if ($response->status == RPC_FAILURE_DATA) {
                                        $this->report(get_string('errorrpcparams', 'tool_sync', implode("\n", $response->errors)));
                                        continue;
                                    } elseif ($response->status == RPC_FAILURE) {
                                        $this->report(get_string('rpcmajorerror', 'tool_sync'));
                                        continue;
                                    } elseif ($response->status == RPC_SUCCESS) {
                                        if (!$response->user) {
                                            $this->report(get_string('userunknownremotely', 'tool_sync', fullname($user)));
                                            $exists = false;
                                        } else {
                                            $this->report(get_string('userexistsremotely', 'tool_sync', fullname($user)));
                                            $exists = true;
                                        }
                                    }
                                }
                                $created = false;
                                if (!$exists) {
                                    if ($return = mnetadmin_rpc_create_user($caller, $user->username, $user, '', $c->wwwroot, false)) {
                                        $response = json_decode($return);
                                        if (empty($response)) {
                                            if (debugging()) {
                                                print_object($return);
                                            }
                                            $this->report(get_string('remoteserviceerror', 'tool_sync'));
                                            continue;
                                        }
                                        if ($response->status != RPC_SUCCESS) {
                                            // print_object($response);
                                            $this->report(get_string('communicationerror', 'tool_sync'));
                                        } else {
                                            $u = new \StdClass();
                                            $u->username = $user->username;
                                            $u->wwwroot = $c->wwwroot;
                                            $this->report(get_string('usercreatedremotely', 'tool_sync', $u));
                                            $created = true;
                                        }
                                    }
                                }
    
                                // Process remote course enrolment.
                                if (!empty($c->role)) {
                                    $response = mnetadmin_rpc_remote_enrol($caller, $user->username, $c->role, $c->wwwroot, 'shortname', $c->idnumber, $c->start, $c->end, false);
                                    if (empty($response)) {
                                        if (debugging()) {
                                            print_object($response);
                                        }
                                        $this->report(get_string('remoteserviceerror', 'tool_sync'));
                                        continue;
                                    }
                                    if ($response->status != RPC_SUCCESS) {
                                        // print_object($response);
                                        $this->report(get_string('communicationerror', 'tool_sync', implode("\n", $response->errors)));
                                    } else {
                                        // In case this block is installed, mark access authorisations in the user's profile.
                                        if (file_exists($CFG->dirroot.'/blocks/user_mnet_hosts/xlib.php')) {
                                            include_once($CFG->dirroot.'/blocks/user_mnet_hosts/xlib.php');
                                            if ($result = user_mnet_hosts_add_access($user, $c->wwwroot)) {
                                                if (preg_match('/error/', $result)) {
                                                    $this->report(get_string('errorsettingremoteaccess', 'tool_sync', $result));
                                                } else {
                                                    $this->report($result);
                                                }
                                            }
                                        }
                                        $e = new \StdClass();
                                        $e->username = $user->username;
                                        $e->rolename = $c->role;
                                        $e->coursename = $c->idnumber;
                                        $e->wwwroot = $c->wwwroot;
                                        $this->report(get_string('remoteenrolled', 'tool_sync', $e));
                                    }
                                }
                            }
                        }
                    }
                }
                unset ($user);
            }
        }
        fclose($filereader);

        if (!empty($syncconfig->storereport)) {
            $this->store_report_file($filerec);
        }
        if (!empty($syncconfig->filearchive)) {
            $this->archive_input_file($filerec);
        }
        if (!empty($syncconfig->filecleanup)) {
            $this->cleanup_input_file($filerec);
        }

        /*
        if (!empty($syncconfig->eventcleanup)) {
            $admin = get_admin();

            $sql = "
                DELETE FROM
                {logstore_standard_log}
                WHERE
                origin = 'cli' AND
                userid = ? AND
                eventname LIKE '%user_updated'
            ";
            $DB->execute($sql, array($admin->id));

            $sql = "
                DELETE FROM
                {logstore_standard_log}
                WHERE
                origin = 'cli' AND
                userid = ? AND
                eventname LIKE '%user_created'
            ";
            $DB->execute($sql, array($admin->id));

            $sql = "
                DELETE FROM
                {logstore_standard_log}
                WHERE
                origin = 'cli' AND
                userid = ? AND
                eventname LIKE '%user_deleted'
            ";
            $DB->execute($sql, array($admin->id));
        }
        */
        return true;
    }
}
