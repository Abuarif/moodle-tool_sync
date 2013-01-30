<?php
/**
* @author Funck Thibaut
* @package tool_sync
*
**/

require_once $CFG->dirroot.'/admin/tool/sync/lib.php';

class enrol_plugin_manager {

    var $log;    

	/// Override the base config_form() function
	function config_form($frm) {
	    global $CFG, $DB;
	    
	    $vars = array('enrol_flatfilelocation', 'enrol_mailadmins', 'enrol_defaultcmd');

	    foreach ($vars as $var) {
	        if (!isset($frm->$var)) {
	            $frm->$var = '';
	        } 	
	    }
	    $roles = $DB->get_records('role', null, '', 'id, name, shortname');
	    $ffconfig = get_config('tool_sync_enrol_flatfile');
	    $frm->tool_sync_enrol_flatfilemapping = array();
	    foreach($roles as $id => $record) {
	    	$mapkey = "map_{$record->shortname}";
	        $frm->enrol_flatfilemapping[$id] = array(
	            $record->name,
	            isset($ffconfig->$mapkey) ? $ffconfig->$mapkey : $record->shortname
	        );
	    }	    
	    include ($CFG->dirroot.'/admin/tool/sync/enrol/config.html');    
	}

	/// Override the base process_config() function
	function process_config($config) {
	
	    if (!isset($config->tool_sync_enrol_filelocation)) {
	        $config->tool_sync_enrol_filelocation = '';
	    }
	    set_config('tool_sync_enrol_filelocation', $config->tool_sync_enrol_filelocation);

	    if (!isset($config->tool_sync_enrol_courseidentifier)) {
	        $config->tool_sync_enrol_courseidentifier = '';
	    }
	    set_config('tool_sync_enrol_courseidentifier', $config->tool_sync_enrol_courseidentifier);

	    if (!isset($config->tool_sync_enrol_useridentifier)) {
	        $config->tool_sync_enrol_useridentifier = '';
	    }
	    set_config('tool_sync_enrol_useridentifier', $config->tool_sync_enrol_useridentifier);

	    if (!isset($config->tool_sync_enrol_mailadmins)) {
	        $config->tool_sync_enrol_mailadmins = '';
	    }
	    set_config('tool_sync_enrol_mailadmins', $config->tool_sync_enrol_mailadmins);
		
	    if (!isset($config->tool_sync_enrol_defaultcmd)) {
	        $config->tool_sync_enrol_defaultcmd = '';
	    }
	    set_config('tool_sync_enrol_defaultcmd', $config->tool_sync_enrol_defaultcmd);
	    return true;
	}

    function cron() {
        global $CFG, $USER, $DB;
        
		$csv_encode = '/\&\#44/';
		if (isset($CFG->tool_sync_csvseparator)) {
			$csv_delimiter = '\\' . $CFG->tool_sync_csvseparator;
			$csv_delimiter2 = $CFG->tool_sync_csvseparator;

			if (isset($CFG->CSV_ENCODE)) {
				$csv_encode = '/\&\#' . $CFG->CSV_ENCODE . '/';
			}
		} else {
			$csv_delimiter = "\;";
			$csv_delimiter2 = ";";
		}
		
        if (empty($CFG->tool_sync_enrol_filelocation)) {
            $filename = $CFG->dataroot.'/sync/enrolments.txt';  // Default location
        } else {
            $filename = $CFG->dataroot.'/'.$CFG->tool_sync_enrol_filelocation;
        }

        if (!file_exists($filename) ) {
			tool_sync_report($CFG->tool_sync_enrollog, get_string('filenotfound', 'tool_sync', "$filename"));		
			return;
        }
        
		tool_sync_report($CFG->tool_sync_enrollog, get_string('flatfilefoundforenrols', 'tool_sync').$filename."\n");
		
		$required = array(
				'rolename' => 1,
				'cid' => 1,
				'uid' => 1);
		$optional = array(
				'hidden' => 1,
				'cmd' => 1,
				'enrol' => 1,
				'gcmd' => 1,
				'g1' => 1,
				'g2' => 1,
				'g3' => 1,
				'g4' => 1,
				'g5' => 1,
				'g6' => 1,
				'g7' => 1,
				'g8' => 1,
				'g9' => 1);
		
		$fp = fopen($filename, 'rb');
		
		// jump any empty or comment line
		$text = fgets($fp, 1024);
		
		$i = 0;
		
		while(sync_is_empty_line_or_format($text, $i == 0)){
			$text = fgets($fp, 1024);
			$i++;
		}

		$headers = split($csv_delimiter, $text);
		
		function trim_fields(&$e){
			$e = trim($e);
		}
		
		array_walk($headers, 'trim_fields');
		
		foreach ($headers as $h) {				
			$header[] = trim($h); // remove whitespace			
			if (!(isset($required[$h]) or isset($optional[$h]))) {
				tool_sync_report($CFG->tool_sync_enrollog, get_string('errorinvalidcolumnname', 'tool_sync', $h));
				return;
			}
			if (isset($required[$h])) {
				$required[$h] = 0;
			}
		}			
		foreach ($required as $key => $value) {
			if ($value) { //required field missing
				tool_sync_report($CFG->tool_sync_enrollog, get_string('errorrequiredcolumn', 'tool_sync', $key));
				return;
			}
		}
		
		// Starting processing lines
		$i = 2;
		while (!feof ($fp)) {

			$text = fgets($fp, 1024);
			if (sync_is_empty_line_or_format($text, false)) {
				$i++;
				continue;
			}
			$line = explode($CFG->tool_sync_csvseparator, $text);

			foreach ($line as $key => $value) {
				//decode encoded commas
				$record[$header[$key]] = trim($value);
			}	

			$e->i = $i;
			$e->mycmd = $record['cmd'];
			$e->myrole = $record['rolename'];

			$cidentifieroptions = array('idnumber', 'shortname', 'id');
			$cidentifiername = $cidentifieroptions[0 + @$CFG->tool_sync_enrol_courseidentifier];

			$uidentifieroptions = array('idnumber', 'username', 'email', 'id');
			$uidentifiername = $uidentifieroptions[0 + @$CFG->tool_sync_enrol_useridentifier];

			$e->myuser = $record['uid']; // user identifier
			$e->mycourse = $record['cid']; // course identifier

			if (!$user = $DB->get_record('user', array($uidentifiername => $record['uid'])) ) {
				tool_sync_report($CFG->tool_sync_enrollog, get_string('errornouser', 'tool_sync', $e));
				$i++;
				if (!empty($CFG->tool_sync_filefailed)) sync_feed_tryback_file($filename, $text, $headers);
				continue;
			}

			$e->myuser = $user->username.' ('.$e->myuser.')'; // complete idnumber with real username

			if(empty($record['cid'])){
				tool_sync_report($CFG->tool_sync_enrollog, get_string('errornullcourseidentifier', 'tool_sync', $i));
				$i++;
				if (!empty($CFG->tool_sync_filefailed)) sync_feed_tryback_file($filename, $text, $headers);
				continue;
			}

			if (!$course = $DB->get_record('course', array($cidentifiername => $record['cid'])) ) {
				tool_sync_report($CFG->tool_sync_enrollog, get_string('errornocourse', 'tool_sync', $e));
				$i++;
				if (!empty($CFG->tool_sync_filefailed)) sync_feed_tryback_file($filename, $text, $headers);
				continue;
			}

			$CFG->tool_sync_coursesg[$i - 1] = $course->id;
			$context = context_course::instance($course->id);

			if(isset($record['cmd'])){				
				if($record['cmd'] == 'del'){
					if($role = $DB->get_record('role', array('shortname' => $record['rolename']))){
						if(!role_unassign($role->id, $user->id, null, $context->id)){
							tool_sync_report($CFG->tool_sync_enrollog, get_string('errorunassign', 'tool_sync', $e));				
						} else {
							tool_sync_report($CFG->tool_sync_enrollog, get_string('unassign', 'tool_sync', $e));
						}
					} else {
						if(!role_unassign(null, $user->id, null, $context->id)){
							tool_sync_report($CFG->tool_sync_enrollog, get_string('errorunassign', 'tool_sync', $e));
						} else {
							tool_sync_report($CFG->tool_sync_enrollog, get_string('unassignall', 'tool_sync', $e));
						}									
					}
				} elseif ($record['cmd'] == 'add'){
					if ($role = $DB->get_record('role', array('shortname' => $record['rolename']))){

						if(!$DB->get_record('role_assignments', array('roleid' => $role->id, 'contextid' => $context->id, 'userid' => $user->id))){
							if (@$record['enrol'] == 'manual'){
								// Uses manual enrolment plugin to enrol AND assign role properly
								enrol_try_internal_enrol($context->instance, $user->id, $role->id, time(), 0);
								tool_sync_report($CFG->tool_sync_enrollog, get_string('enrolled', 'tool_sync', $e));
							} else {
								if(!role_assign($role->id, $user->id, null, $context->id)){
									if (!empty($CFG->tool_sync_filefailed)) sync_feed_tryback_file($filename, $text, $headers);
									tool_sync_report($CFG->tool_sync_enrollog, get_string('errorline', 'tool_sync')." $i : $mycmd $myrole $myuser $mycourse : $user->lastname $user->firstname == $role->shortname ==> $course->shortname");
								} else {
									tool_sync_report($CFG->tool_sync_enrollog, get_string('assign', 'tool_sync', $e));
								}
							}
						} else {
							tool_sync_report($CFG->tool_sync_enrollog, get_string('alreadyassigned', 'tool_sync', $e));
						}
					} else {
						if (!empty($CFG->tool_sync_filefailed)) sync_feed_tryback_file($filename, $text, $headers);
						tool_sync_report($CFG->tool_sync_enrollog, get_string('errornorole', 'tool_sync', $e));
					}
				} elseif ($record['cmd'] == 'shift'){
					// check this r�le exists in this moodle
					if ($role = $DB->get_record('role', array('shortname' => $record['rolename']))){
						if ($roles = get_user_roles($context, $user->id)) {
							foreach ($roles as $r){
								if (!role_unassign($r->roleid, $user->id, null, $context->id)){
									tool_sync_report($CFG->tool_sync_enrollog, get_string('unassignerror', 'tool_sync', $e));
								} else {
									tool_sync_report($CFG->tool_sync_enrollog, get_string('unassign', 'tool_sync', $e));
								}
							}
						}
						// maybe we need enrol this user (if first time in shift list)
						if (@$record['enrol'] == 'manual'){
							// Uses manual enrolment plugin to enrol AND assign role properly
							enrol_try_internal_enrol($context->instance, $user->id, $role->id, time(), 0);
							tool_sync_report($CFG->tool_sync_enrollog, get_string('enrolled', 'tool_sync', $e));
						} else {
							if (!role_assign($role->id, $user->id, null, $context->id)){
								if (!empty($CFG->tool_sync_filefailed)) sync_feed_tryback_file($filename, $text, $headers);
								tool_sync_report($CFG->tool_sync_enrollog, get_string('errorassign', 'tool_sync', $e));
								$i++;
								continue;
							} else {
								tool_sync_report($CFG->tool_sync_enrollog, get_string('assign', 'tool_sync', $e));
							}
						}
					} else {
						if (!empty($CFG->tool_sync_filefailed)) sync_feed_tryback_file($filename, $text, $headers);
						tool_sync_report($CFG->tool_sync_enrollog, get_string('errornorole', 'tool_sync', $e));
						$i++;
						continue;
					}
				} else {
					if (!empty($CFG->tool_sync_filefailed)) sync_feed_tryback_file($filename, $text, $headers);
					tool_sync_report($CFG->tool_sync_enrollog, get_string('errorbadcmd', 'tool_sync', $e));
				}
			} else {
				if (empty($CFG->tool_sync_enrol_defaultcmd)) {
					tool_sync_report($CFG->tool_sync_enrollog, get_string('errorcritical', 'tool_sync', $e));
				} else {
					$cmd = $CFG->tool_sync_enrol_defaultcmd;
					if($cmd == 'del'){
						if($role = $DB->get_record('role', array('shortname' => $record['rolename']))){
							if(!role_unassign($role->id,$user->id,null,$context->id)){
								if (!empty($CFG->tool_sync_filefailed)) sync_feed_tryback_file($filename, $text, $headers);
								tool_sync_report($CFG->tool_sync_enrollog, get_string('errorunassign', 'tool_sync', $e));
							} else {
								tool_sync_report($CFG->tool_sync_enrollog, get_string('unassign', 'tool_sync', $e));
							}										

						} else {
							if(!role_unassign(null,$user->id,null,$context->id)){
								if (!empty($CFG->tool_sync_filefailed)) sync_feed_tryback_file($filename, $text, $headers);
								tool_sync_report($CFG->tool_sync_enrollog, get_string('errorunassign', 'tool_sync', $e));
							} else {
								tool_sync_report($CFG->tool_sync_enrollog, get_string('unassign', 'tool_sync', $e));
							}									
						}
					} elseif ($cmd == 'add') {
						if($role = $DB->get_record('role', array('shortname' => $record['rolename']))){
							if (@$record['enrol'] == 'manual'){
								// Uses manual enrolment plugin to enrol AND assign role properly
								enrol_try_internal_enrol($context->instance, $user->id, $role->id, time(), 0);
								tool_sync_report($CFG->tool_sync_enrollog, get_string('enrolled', 'tool_sync', $e));
							} else {
								// elsewhere just assign role (other users)
								if(!role_assign($role->id, $user->id, null, $context->id)){
									if (!empty($CFG->tool_sync_filefailed)) sync_feed_tryback_file($filename, $text, $headers);
									tool_sync_report($CFG->tool_sync_enrollog, get_string('errorhiddenroleadded', 'tool_sync'). $context->id);
								} else {
									tool_sync_report($CFG->tool_sync_enrollog, get_string('hiddenroleadded', 'tool_sync'). $context->id);
								}
							}
						} else {
							if (!empty($CFG->tool_sync_filefailed)) sync_feed_tryback_file($filename, $text, $headers);
							tool_sync_report($CFG->tool_sync_enrollog, get_string('erroremptyrole', 'tool_sync', $e));
							$i++;
							continue;
						}	
					} else {
						if (!empty($CFG->tool_sync_filefailed)) sync_feed_tryback_file($filename, $text, $headers);
						tool_sync_report($CFG->tool_sync_enrollog, get_string('erroremptycommand', 'tool_sync', $e));
						$i++;
						continue;
					}
				}
			}
			
			if (!empty($record['gcmd'])){
				if ($record['gcmd'] == 'gadd' || $record['gcmd'] == 'gaddcreate'){
					for ($i = 1 ; $i < 10 ; $i++){
						if(!empty($record['g'.$i])){
							if ($gid = groups_get_group_by_name($course->id, $record['g'.$i])) {
								$groupid[$i] = $gid;
							} else {
								if ($record['gcmd'] == 'gaddcreate'){
									$groupsettings->name = $record['g'.$i];
									$groupsettings->courseid = $course->id;
									if ($gid = groups_create_group($groupsettings)) {
										$groupid[$i] = $gid;
										$e->group = $record['g'.$i];
										tool_sync_report($CFG->tool_sync_enrollog, get_string('groupcreated', 'tool_sync', $e));
									} else {
										$e->group = $record['g'.$i];
										tool_sync_report($CFG->tool_sync_enrollog, get_string('errorgroupnotacreated', 'tool_sync', $e));
									}
								} else {
									$e->group = $record['g'.$i];
									tool_sync_report($CFG->tool_sync_enrollog, get_string('groupunknown','tool_sync',$e));
									continue;
								}
							}

							$e->group = $record['g'.$i];
							
							if (count(get_user_roles($context, $user->id))) {
								if (add_user_to_group($groupid[$i], $user->id)) {
									tool_sync_report($CFG->tool_sync_enrollog, get_string('addedtogroup','tool_sync',$e));
								} else {
									tool_sync_report($CFG->tool_sync_enrollog, get_string('addedtogroupnot','tool_sync',$e));
								}
							} else {
								tool_sync_report($CFG->tool_sync_enrollog, get_string('addedtogroupnotenrolled','',$record['g'.$i]));
							}
						}
					}
				} elseif ($record['gcmd'] == 'greplace' || $record['gcmd'] == 'greplacecreate'){
					groups_delete_group_members($course->id, $user->id); 
					tool_sync_report($CFG->tool_sync_enrollog, get_string('groupassigndeleted', 'tool_sync', $e));
					for ($i = 1 ; $i < 10 ; $i++){
						if (!empty($record['g'.$i])){
							if ($gid = groups_get_group_by_name($course->id, $record['g'.$i])) {
								$groupid[$i] = $gid;
							} else {
								if ($record['gcmd'] == 'greplacecreate'){
									$groupsettings->name = $record['g'.$i];
									$groupsettings->courseid = $course->id;
									if ($gid = groups_create_group($groupsettings)) {
										$groupid[$i] = $gid;
										$e->group = $record['g'.$i];
										tool_sync_report($CFG->tool_sync_enrollog, get_string('groupcreated', 'tool_sync', $e));
									} else {
										$e->group = $record['g'.$i];
										tool_sync_report($CFG->tool_sync_enrollog, get_string('errorgroupnotacreated', 'tool_sync', $e));
									}
								} else {
									$e->group = $record['g'.$i];
									tool_sync_report($CFG->tool_sync_enrollog, get_string('groupunknown','tool_sync',$e));
								}
							}
							
							if (count(get_user_roles($context, $user->id))) {
								if (add_user_to_group($groupid[$i], $user->id)) {
									tool_sync_report($CFG->tool_sync_enrollog, get_string('addedtogroup','tool_sync',$e));
								} else {
									tool_sync_report($CFG->tool_sync_enrollog, get_string('addedtogroupnot','tool_sync',$e));
								}
							} else {
								tool_sync_report($CFG->tool_sync_enrollog, get_string('addedtogroupnotenrolled','',$record['g'.$i]));
							}
						}
					}								
				} else {
					tool_sync_report($CFG->enrollog, get_string('errorgcmdvalue', 'tool_sync', $e));
				}
			}							
			//echo "\n";
			$i++;
		}
		
		if (!empty($CFG->tool_sync_filearchive)){
			$archivename = basename($filename);
			$now = date('Ymd-hi', time());
			$archivename = $CFG->dataroot."/sync/archives/{$now}_enrolments_$archivename";
			copy($filename, $archivename);
		}
		
		if (!empty($CFG->tool_sync_filecleanup)){
			@unlink($filename);
		}		
		
		tool_sync_report($CFG->tool_sync_enrollog, "\n".get_string('endofreport', 'tool_sync'));
		
		return true;
    }
}

?>