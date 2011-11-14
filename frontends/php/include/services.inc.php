<?php
/*
** Zabbix
** Copyright (C) 2000-2011 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY OR FITNESS FOR A PARTICULAR PURPOSE.  See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
**/
?>
<?php
	function add_service($name, $triggerid, $algorithm, $showsla, $goodsla, $sortorder, $service_times = array(), $parentid,$childs) {
		foreach ($childs as $id => $child) {
			if (bccomp($parentid , $child['serviceid']) == 0) {
				error(_('Service cannot be parent and child at the same time.'));
				return false;
			}
		}

		if (is_null($triggerid) || $triggerid == 0) {
			$triggerid = 'NULL';
		}

		$serviceid = get_dbid('services', 'serviceid');

		$result = DBexecute('INSERT INTO services (serviceid,name,status,triggerid,algorithm,showsla,goodsla,sortorder)'.
							' VALUES ('.$serviceid.','.zbx_dbstr($name).',0,'.$triggerid.','.zbx_dbstr($algorithm).
								','.$showsla.','.zbx_dbstr($goodsla).','.$sortorder.')');
		if (!$result) {
			return false;
		}

		// removes all links with current serviceid
		remove_service_links($serviceid);

		// add parent
		$result = ($parentid != 0) ? add_service_link($serviceid, $parentid, 0) : true;

		// add childs
		foreach ($childs as $id => $child) {
			if (!isset($child['soft']) || empty($child['soft'])) {
				$child['soft'] = 0;
			}
			$result = add_service_link($child['serviceid'], $serviceid, $child['soft']);
		}

		if (!$result) {
			return false;
		}

		// updating status to all services by the dependency
		update_services_status_all();

		DBExecute('DELETE FROM services_times WHERE serviceid='.$serviceid);

		foreach ($service_times as $val) {
			$timeid = get_dbid('services_times', 'timeid');
			$result = DBexecute('INSERT INTO services_times (timeid,serviceid,type,ts_from,ts_to,note)'.
				' values ('.$timeid.','.$serviceid.','.$val['type'].','.$val['from'].','.$val['to'].','.zbx_dbstr($val['note']).')');
			if (!$result) {
				delete_service($serviceid);
				return false;
			}
		}
		return $serviceid;
	}

	function update_service($serviceid, $name, $triggerid, $algorithm, $showsla, $goodsla, $sortorder, $service_times = array(), $parentid, $childs) {
		foreach ($childs as $id => $child) {
			if (bccomp($parentid, $child['serviceid']) == 0) {
				error(_('Service cannot be parent and child at the same time.'));
				return false;
			}
		}

		// removes all links with current serviceid
		remove_service_links($serviceid);

		// add parent
		$result = ($parentid != 0) ? add_service_link($serviceid, $parentid, 0) : true;

		// add childs
		foreach ($childs as $id => $child) {
			if (empty($child['soft']) || !isset($child['soft'])) {
				$child['soft'] = 0;
			}
			$result = add_service_link($child['serviceid'], $serviceid, $child['soft']);
		}

		if (!$result) {
			return false;
		}

		if (is_null($triggerid) || $triggerid == 0) {
			$triggerid = 'NULL';
		}

		$result = DBexecute('UPDATE services'.
							' SET name='.zbx_dbstr($name).
								',triggerid='.$triggerid.',status=0,algorithm='.$algorithm.
								',showsla='.$showsla.',goodsla='.$goodsla.',sortorder='.$sortorder.
							' WHERE serviceid='.$serviceid);

		// updating status to all services by the dependency
		update_services_status_all();

		DBexecute('DELETE FROM services_times WHERE serviceid='.$serviceid);

		foreach ($service_times as $val) {
			$timeid = get_dbid('services_times', 'timeid');
			DBexecute('INSERT INTO services_times (timeid,serviceid,type,ts_from,ts_to,note)'.
				' VALUES ('.$timeid.','.$serviceid.','.$val['type'].','.$val['from'].','.$val['to'].','.zbx_dbstr($val['note']).')');
		}

		return $result;
	}

	function add_host_to_services($hostid, $serviceid) {
		$result = DBselect('SELECT distinct h.host,t.triggerid,t.description,t.expression'.
							' FROM hosts h,items i,functions f,triggers t'.
							' WHERE h.hostid=i.hostid'.
								' AND i.itemid=f.itemid'.
								' AND f.triggerid=t.triggerid'.
								' AND h.hostid='.$hostid.
								' AND '.DBin_node('t.triggerid', false)
							);
		while ($row = DBfetch($result)) {
			$serviceid2 = add_service(expand_trigger_description_by_data($row), $row['triggerid'], 'on', 0, 'off', 99);
			add_service_link($serviceid2, $serviceid, 0);
		}
		return 1;
	}

	function is_service_hardlinked($serviceid) {
		$row = DBfetch(DBselect('SELECT COUNT(*) as cnt FROM services_links sl WHERE sl.servicedownid='.$serviceid.' and sl.soft=0'));
		if ($row && $row['cnt'] > 0) {
			return true;
		}
		return false;
	}

	/*
	 * Function: get_service_status
	 *
	 * Description:
	 *     Retrieve true status
	 *
	 * Author:
	 *     Aly
	 *
	 * Comments:
	 *		Don't forget to sync code with C!!!!
	 */
	function get_service_status($serviceid, $algorithm, $triggerid = null, $status = 0) {
		if (is_numeric($triggerid)) {
			$status = ($serv_status = get_service_status_of_trigger($triggerid)) ? $serv_status : $status;
		}

		if ($algorithm == SERVICE_ALGORITHM_MAX || $algorithm == SERVICE_ALGORITHM_MIN) {
			$sort_order = ($algorithm == SERVICE_ALGORITHM_MAX) ? 'DESC' : 'ASC';

			$result = DBselect('SELECT s.status'.
					' FROM services s,services_links l'.
					' WHERE l.serviceupid='.$serviceid.
						' AND s.serviceid=l.servicedownid'.
					' ORDER BY s.status '.$sort_order);
			if ($row = DBfetch($result)) {
				$status = $row['status'];
			}
		}
		return $status;
	}

	/******************************************************************************
	 *                                                                            *
	 * Comments: !!! Don't forget sync code with C !!!                            *
	 *                                                                            *
	 ******************************************************************************/
	function delete_service($serviceid) {
		$sql = 'DELETE FROM services_links WHERE servicedownid='.$serviceid.' OR serviceupid='.$serviceid;
		if (!$result = DBexecute($sql)) {
			return $result;
		}

		$sql = 'DELETE FROM services WHERE serviceid='.$serviceid;
		if (!$result = DBexecute($sql)) {
			return $result;
		}

		$sql = 'DELETE FROM service_alarms WHERE serviceid='.$serviceid;
		if (!$result = DBexecute($sql)) {
			return $result;
		}

		$sql = 'DELETE FROM services_times WHERE serviceid='.$serviceid;
		if (!$result = DBexecute($sql)) {
			return $result;
		}

		update_services_status_all();
		return $result;
	}

	/*
	 * Function: clear_parents_from_trigger
	 *
	 * Description:
	 *     removes any links between trigger and service if service is not leaf (treenode)
	 *
	 * Author:
	 *     Aly
	 *
	 * Comments:
	 *
	 */
	function clear_parents_from_trigger($serviceid = 0) {
		if ($serviceid != 0) {
			DBexecute('UPDATE services SET triggerid=NULL WHERE serviceid='.$serviceid);
			return null;
		}

		$result = DBselect('SELECT MAX(s.serviceid) as serviceid'.
							' FROM services s,services_links sl'.
							' WHERE s.serviceid=sl.serviceupid'.
								' AND NOT s.triggerid IS NULL'.
							' GROUP BY s.serviceid');
		while ($row = DBfetch($result)) {
			DBexecute('UPDATE services SET triggerid=NULL WHERE serviceid='.$row['serviceid']);
		}
	}

	# Return TRUE if triggerid is a reason why the service is not OK
	# Warning: recursive function
	function does_service_depend_on_the_service($serviceid, $serviceid2) {
		$service = get_service_by_serviceid($serviceid);
		if ($service['status'] == 0) {
			return false;
		}
		if (bccomp($serviceid, $serviceid2) == 0) {
			if ($service['status'] > 0) {
				return true;
			}
		}

		$result = DBselect('SELECT sl.serviceupid FROM services_links sl WHERE sl.servicedownid='.$serviceid2.' and sl.soft=0');
		while ($row = DBfetch($result)) {
			if (does_service_depend_on_the_service($serviceid, $row['serviceupid']) == true) {
				return true;
			}
		}
		return false;
	}

	function add_service_link($servicedownid, $serviceupid, $softlink) {
		if ($softlink == 0 && is_service_hardlinked($servicedownid) == true) {
			error(_('Cannot link hardlinked service.'));
			return false;
		}

		if (bccomp($servicedownid, $serviceupid) == 0) {
			error(_('Cannot link service to itself.'));
			return false;
		}

		$linkid = get_dbid('services_links', 'linkid');

		$result = DBexecute('INSERT INTO services_links (linkid,servicedownid,serviceupid,soft) values ('.$linkid.','.$servicedownid.','.$serviceupid.','.$softlink.')');

		if (!$result) {
			return $result;
		}

		return $linkid;
	}

	function remove_service_links($serviceid) {
		DBExecute('DELETE FROM services_links WHERE serviceupid='.$serviceid.' OR (servicedownid='.$serviceid.' AND soft<>1)');
	}

	function get_last_service_value($serviceid, $clock) {
		$row = DBfetch(DBselect('SELECT COUNT(*) as cnt,MAX(sa.clock) AS maxx FROM service_alarms sa WHERE sa.serviceid='.$serviceid.' AND sa.clock<='.$clock));
		if ($row && $row['cnt'] > 0) {
			$result2 = DBselect('SELECT sa.value FROM service_alarms sa WHERE sa.serviceid='.$serviceid.' AND sa.clock='.$row['maxx']);

			// assuring that we get very latest service value. There could be several with the same timestamp
			while ($row2 = DBfetch($result2)) {
				$value = $row2['value'];
			}
		}
		else {
			$value = 0;
		}
		return $value;
	}

	function expand_periodical_service_times(&$data, $period_start, $period_end, $ts_from, $ts_to, $type = 'ut') {
		// calculate period FROM '-1 week' to know period name for $period_start
		for ($curr = $period_start - SEC_PER_WEEK; $curr <= $period_end; $curr += SEC_PER_DAY) {
			$curr_date = getdate($curr);
			$from_date = getdate($ts_from);

			if ($curr_date['wday'] == $from_date['wday']) {
				$curr_from = mktime(
					$from_date['hours'], $from_date['minutes'], $from_date['seconds'],
					$curr_date['mon'], $curr_date['mday'], $curr_date['year']
				);

				$curr_from = max($curr_from, $period_start);
				$curr_from = min($curr_from, $period_end);
				$curr_to = $curr_from + ($ts_to - $ts_from);
				$curr_to = max($curr_to, $period_start);
				$curr_to = min($curr_to, $period_end);
				$curr = $curr_to;

				if (isset($data[$curr_from][$type.'_s'])) {
					$data[$curr_from][$type.'_s'] ++;
				}
				else {
					$data[$curr_from][$type.'_s'] = 1;
				}

				if (isset($data[$curr_to][$type.'_e'])) {
					$data[$curr_to][$type.'_e'] ++;
				}
				else {
					$data[$curr_to][$type.'_e'] = 1;
				}
			}
		}
	}

	function calculate_service_availability($serviceid, $period_start, $period_end) {
		/* structure of "$data"
		 *	key	- time stamp
		 *	alarm	- on/off status (0,1 - off; >1 - on)
		 *	dt_s	- count of downtime starts
		 *	dt_e	- count of downtime ends
		 *	ut_s	- count of uptime starts
		 *	ut_e	- count of uptime ends
		 */
		$data[$period_start]['alarm'] = get_last_service_value($serviceid, $period_start);

		// sort by time stamp
		$sql = 'SELECT sa.servicealarmid,sa.clock,sa.value'.
				' FROM service_alarms sa'.
				' WHERE sa.serviceid='.$serviceid.
					' AND sa.clock>='.$period_start.
					' AND sa.clock<='.$period_end.
				' ORDER BY sa.clock asc,sa.servicealarmid ASC';
		$service_alarms = DBselect($sql);
		while ($db_alarm_row = DBfetch($service_alarms)) {
			$data[$db_alarm_row['clock']]['alarm'] = $db_alarm_row['value'];
		}

		// add periodical uptimes
		$sql = 'SELECT st.ts_from,st.ts_to'.
				' FROM services_times st'.
				' WHERE st.type='.SERVICE_TIME_TYPE_UPTIME.
					' AND st.serviceid='.$serviceid;
		$service_times = DBselect($sql);
		if ($db_time_row = DBfetch($service_times)) {
			// if exist any uptime - unmarked time is downtime
			$unmarked_period_type = 'dt';
			do {
				expand_periodical_service_times($data, $period_start, $period_end, $db_time_row['ts_from'], $db_time_row['ts_to'], 'ut');
			} while ($db_time_row = DBfetch($service_times));
		}
		else {
			// if missed any uptime - unmarked time is uptime
			$unmarked_period_type = 'ut';
		}

		// add periodical downtimes
		$sql = 'SELECT st.ts_from,st.ts_to'.
				' FROM services_times st'.
				' WHERE st.type='.SERVICE_TIME_TYPE_DOWNTIME.
					' AND st.serviceid='.$serviceid;
		$service_times = DBselect($sql);
		while ($db_time_row = DBfetch($service_times)) {
			expand_periodical_service_times($data, $period_start, $period_end, $db_time_row['ts_from'], $db_time_row['ts_to'], 'dt');
		}

		// add one-time downtimes
		$sql = 'SELECT st.ts_from,st.ts_to'.
				' FROM services_times st'.
				' WHERE st.type='.SERVICE_TIME_TYPE_ONETIME_DOWNTIME.
					' AND st.serviceid='.$serviceid;
		$service_times = DBselect($sql);
		while ($db_time_row = DBfetch($service_times)) {
			if ($db_time_row['ts_to'] < $period_start || $db_time_row['ts_from'] > $period_end) {
				continue;
			}

			if ($db_time_row['ts_from'] < $period_start) {
				$db_time_row['ts_from'] = $period_start;
			}
			if ($db_time_row['ts_to'] > $period_end) {
				$db_time_row['ts_to'] = $period_end;
			}

			if (isset($data[$db_time_row['ts_from']]['dt_s'])) {
				$data[$db_time_row['ts_from']]['dt_s']++;
			}
			else {
				$data[$db_time_row['ts_from']]['dt_s'] = 1;
			}

			if (isset($data[$db_time_row['ts_to']]['dt_e'])) {
				$data[$db_time_row['ts_to']]['dt_e']++;
			}
			else {
				$data[$db_time_row['ts_to']]['dt_e'] = 1;
			}
		}

		if (!isset($data[$period_end])) {
			$data[$period_end] = array();
		}

		// sort by time stamp
		ksort($data);

		// calculate times
		$dt_cnt = 0;
		$ut_cnt = 0;
		$sla_time = array(
			'dt' => array('problem_time' => 0, 'ok_time' => 0),
			'ut' => array('problem_time' => 0, 'ok_time' => 0)
			);
		$prev_alarm = $data[$period_start]['alarm'];
		$prev_time = $period_start;

		if (isset($data[$period_start]['ut_s'])) $ut_cnt += $data[$period_start]['ut_s'];
		if (isset($data[$period_start]['ut_e'])) $ut_cnt -= $data[$period_start]['ut_e'];
		if (isset($data[$period_start]['dt_s'])) $dt_cnt += $data[$period_start]['dt_s'];
		if (isset($data[$period_start]['dt_e'])) $dt_cnt -= $data[$period_start]['dt_e'];
		foreach ($data as $ts => $val) {
			// skip first data [already readed]
			if ($ts == $period_start) {
				continue;
			}

			if ($dt_cnt > 0) {
				$period_type = 'dt';
			}
			elseif ($ut_cnt > 0) {
				$period_type = 'ut';
			}
			else {
				$period_type = $unmarked_period_type;
			}

			// state=0,1 [OK] (1 - information severity of trigger), >1 [PROBLEMS] (trigger severity)
			if ($prev_alarm > 1) {
				$sla_time[$period_type]['problem_time']	+= $ts - $prev_time;
			}
			else {
				$sla_time[$period_type]['ok_time'] += $ts - $prev_time;
			}

			if (isset($val['ut_s'])) $ut_cnt += $val['ut_s'];
			if (isset($val['ut_e'])) $ut_cnt -= $val['ut_e'];
			if (isset($val['dt_s'])) $dt_cnt += $val['dt_s'];
			if (isset($val['dt_e'])) $dt_cnt -= $val['dt_e'];
			if (isset($val['alarm'])) $prev_alarm = $val['alarm'];

			$prev_time = $ts;
		}

		$sla_time['problem_time'] = &$sla_time['ut']['problem_time'];
		$sla_time['ok_time'] = &$sla_time['ut']['ok_time'];
		$sla_time['downtime_time'] = $sla_time['dt']['ok_time'] + $sla_time['dt']['problem_time'];

		$full_time = $sla_time['problem_time'] + $sla_time['ok_time'];
		if ($full_time > 0) {
			$sla_time['problem'] = 100 * $sla_time['problem_time'] / $full_time;
			$sla_time['ok'] = 100 * $sla_time['ok_time'] / $full_time;
		}
		else {
			$sla_time['problem'] = 100;
			$sla_time['ok'] = 100;
		}

		return $sla_time;
	}

	function get_service_by_serviceid($serviceid) {
		$row = DBfetch(DBselect('SELECT s.* FROM services s WHERE s.serviceid='.$serviceid));
		if (!$row) {
			error(_('No service with').' serviceid=['.$serviceid.']');
			return false;
		}
		return $row;
	}

	function get_services_links_by_linkid($linkid) {
		$row = DBfetch(DBselect('SELECT sl.* FROM services_links sl WHERE sl.linkid='.$linkid));
		if (!$row) {
			error(_('No service linkage with').' linkid=['.$linkid.']');
			return false;
		}
		return $row;
	}

	function algorithm2str($algorithm) {
		if ($algorithm == SERVICE_ALGORITHM_NONE) {
			return _('Do not calculate');
		}
		elseif ($algorithm == SERVICE_ALGORITHM_MAX) {
			return _('Problem, if at least one child has a problem');
		}
		elseif ($algorithm == SERVICE_ALGORITHM_MIN) {
			return _('Problem, if all children have problems');
		}
		return _('Unknown');
	}

	function get_service_childs($serviceid, $soft = 0) {
		$childs = array();

		$query = 'SELECT sl.servicedownid'.
				' FROM services_links sl'.
				' WHERE sl.serviceupid='.$serviceid.($soft ? '' : ' AND sl.soft<>1');

		$result = DBselect($query);
		while ($row = DBfetch($result)) {
			$childs[] = $row['servicedownid'];
			$childs = zbx_array_merge($childs, get_service_childs($row['servicedownid']));
		}
		return $childs;
	}

	function createServiceTree(&$services, &$temp, $id = 0, $serviceupid = 0, $parentid = 0, $soft = 0, $linkid = '') {
		$rows = $services[$id];
		if ($rows['serviceid'] > 0 && $rows['caption'] != 'root') {
			$rows['algorithm'] = algorithm2str($rows['algorithm']);
		}

		$rows['parentid'] = $parentid;
		if ($soft == 0) {
			$caption_tmp = $rows['caption'];
			$rows['caption'] = new CSpan($rows['caption'], 'link');
			$rows['caption']->setAttribute('onclick', 'javascript: call_menu(event, '.zbx_jsvalue($rows['serviceid']).','.zbx_jsvalue($caption_tmp).');');
			$temp[$rows['serviceid']] = $rows;

			if (isset($rows['childs'])) {
				foreach ($rows['childs'] as $cid => $nodeid) {
					if (!isset($services[$nodeid['id']])) {
						continue;
					}
					if (isset($services[$nodeid['id']]['serviceupid'])) {
						createServiceTree($services, $temp, $nodeid['id'], $services[$nodeid['id']]['serviceupid'], $rows['serviceid'], $nodeid['soft'], $nodeid['linkid']);
					}
				}
			}
		}
		else {
			$rows['caption'] = new CSpan($rows['caption'], 'unknown');
			$temp[$rows['serviceid'].'.'.$linkid] = $rows;
		}
		return null;
	}

	function createShowServiceTree(&$services, &$temp, $id = 0, $serviceupid = 0, $parentid = 0, $soft = 0, $linkid = '') {
		$rows = $services[$id];
		$rows['parentid'] = $parentid;

		if ($soft == 0) {
			$temp[$rows['serviceid']] = $rows;

			if (isset($rows['childs'])) {
				foreach ($rows['childs'] as $cid => $nodeid) {
					if (!isset($services[$nodeid['id']])) {
						continue;
					}
					if (isset($services[$nodeid['id']]['serviceupid'])) {
						createShowServiceTree($services, $temp, $nodeid['id'], $services[$nodeid['id']]['serviceupid'], $rows['serviceid'], $nodeid['soft'], $nodeid['linkid']);
					}
				}
			}
		}
		else {
			$rows['caption'] = new CSpan($rows['caption']);
			$rows['caption']->setAttribute('style', 'color: #888888;');
			$temp[$rows['serviceid'].'.'.$linkid] = $rows;
		}
		return null;
	}

	function del_empty_nodes($services) {
		do {
			unset($retry);
			foreach ($services as $id => $data) {
				if (isset($data['serviceupid']) && !isset($services[$data['serviceupid']])) {
					unset($services[$id]);
					$retry = true;
				}
			}
		} while (isset($retry));

		return $services;
	}

	/******************************************************************************
	 *                                                                            *
	 * Function: update_services_rec                                              *
	 *                                                                            *
	 * Purpose: re-calculate and updates status of the service and its childs     *
	 *                                                                            *
	 * Parameters: serviceid - item to update services for                        *
	 *                                                                            *
	 * Return value:                                                              *
	 *                                                                            *
	 * Author: Alexei Vladishev   (PHP ver. by Aly)                               *
	 *                                                                            *
	 * Comments: recursive function   !!! Don't forget sync code with C !!!       *
	 *                                                                            *
	 ******************************************************************************/
	function update_services_rec($serviceid) {
		$result = DBselect('SELECT l.serviceupid,s.algorithm'.
							' FROM services_links l,services s'.
							' WHERE s.serviceid=l.serviceupid'.
								' AND l.servicedownid='.$serviceid);
		while ($row = DBfetch($result)) {
			$serviceupid = $row['serviceupid'];
			$algorithm = $row['algorithm'];

			if ($algorithm == SERVICE_ALGORITHM_MAX || $algorithm == SERVICE_ALGORITHM_MIN) {
				$status = get_service_status($serviceupid, $algorithm);
				add_service_alarm($serviceupid, $status, time());
				DBexecute('UPDATE services SET status='.$status.' WHERE serviceid='.$serviceupid);
			}
			elseif ($algorithm != SERVICE_ALGORITHM_NONE) {
				error(_('Unknown calculation algorithm of service status').SPACE.'['.$algorithm.']');
				return false;
			}
		}

		$result = DBselect('SELECT sl.serviceupid FROM services_links sl WHERE sl.servicedownid='.$serviceid);
		while ($row = DBfetch($result)) {
			$serviceupid = $row['serviceupid'];
			update_services_rec($serviceupid);	// ATTENTION: recursion!!!
		}
	}

	/******************************************************************************
	 *                                                                            *
	 * Function: update_services                                                  *
	 *                                                                            *
	 * Purpose: re-calculate and updates status of the service and its childs     *
	 * on trigger priority change                                                 *
	 *                                                                            *
	 * Parameters: serviceid - item to update services for                        *
	 *             status - new status of the service                             *
	 *                                                                            *
	 * Return value:                                                              *
	 *                                                                            *
	 * Author: Alexei Vladishev   (PHP ver. by Aly)                               *
	 *                                                                            *
	 * Comments: !!! Don't forget sync code with C !!!                            *
	 *                                                                            *
	 ******************************************************************************/
	function update_services($triggerid, $status) {
		DBexecute('UPDATE services SET status='.$status.' WHERE triggerid='.$triggerid);

		$result = DBselect('SELECT s.serviceid FROM services s WHERE s.triggerid='.$triggerid);
		while ($row = DBfetch($result)) {
			add_service_alarm($row['serviceid'], $status, time());
			update_services_rec($row['serviceid']);
		}
	}

	/*
	 * Function: update_services_status_all
	 *
	 * Description:
	 * Cleaning parent nodes from triggers, updating ALL services status.
	 *
	 * Comments: !!! Don't forget sync code with C !!!
	 *
	 */
	function update_services_status_all() {
		clear_parents_from_trigger();

		$result = DBselect('SELECT s.serviceid,s.algorithm,s.triggerid'.
							' FROM services s'.
							' WHERE s.serviceid NOT IN (select distinct sl.serviceupid from services_links sl)');
		while ($row = DBfetch($result)) {
			$status = get_service_status($row['serviceid'], $row['algorithm'], $row['triggerid']);
			DBexecute('UPDATE services SET status='.$status.' WHERE serviceid='.$row['serviceid']);
			add_service_alarm($row['serviceid'], $status, time());
		}

		$result = DBselect('SELECT MAX(sl.servicedownid) as serviceid,sl.serviceupid'.
							' FROM services_links sl'.
							' WHERE sl.servicedownid NOT IN (select distinct sl.serviceupid from services_links sl)'.
							' GROUP BY sl.serviceupid');
		while ($row = DBfetch($result)) {
			update_services_rec($row['serviceid']);
		}
	}

	/******************************************************************************
	 *                                                                            *
	 * Comments: !!! Don't forget sync code with C !!!                            *
	 *                                                                            *
	 ******************************************************************************/
	function latest_service_alarm($serviceid, $status) {
		$result = DBselect('SELECT sa.servicealarmid,sa.value'.
							' FROM service_alarms sa'.
							' WHERE sa.serviceid='.$serviceid.
							' ORDER BY sa.servicealarmid DESC', 1);
		$row = DBfetch($result);
		if ($row && $row['value'] == $status) {
			return true;
		}
		return false;
	}

	/******************************************************************************
	 *                                                                            *
	 * Comments: !!! Don't forget sync code with C !!!                            *
	 *                                                                            *
	 ******************************************************************************/
	function add_service_alarm($serviceid, $status, $clock) {
		if (latest_service_alarm($serviceid, $status)) {
			return true;
		}
		return DBexecute('INSERT INTO service_alarms (servicealarmid,serviceid,clock,value) VALUES ('.get_dbid('service_alarms', 'servicealarmid').','.$serviceid.','.$clock.','.$status.')');
	}
?>
