<?php
/*
** Zabbix
** Copyright (C) 2001-2016 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


class CControllerAcknowledgeCreate extends CController {

	protected function checkInput() {
		$fields = [
			'eventids' =>			'required|array_db acknowledges.eventid',
			'message' =>			'db acknowledges.message',
			'acknowledge_type' =>	'in '.ZBX_ACKNOWLEDGE_SELECTED.','.ZBX_ACKNOWLEDGE_PROBLEM.','.ZBX_ACKNOWLEDGE_ALL,
			'close_problem' =>		'db acknowledges.action|in '.
										ZBX_ACKNOWLEDGE_ACTION_NONE.','.ZBX_ACKNOWLEDGE_ACTION_CLOSE_PROBLEM,
			'backurl' =>			'string'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			switch ($this->GetValidationError()) {
				case self::VALIDATION_ERROR:
					$response = new CControllerResponseRedirect('zabbix.php?action=acknowledge.edit');
					$response->setFormData($this->getInputAll());
					$response->setMessageError(_('Cannot acknowledge event'));
					$this->setResponse($response);
					break;
				case self::VALIDATION_FATAL_ERROR:
					$this->setResponse(new CControllerResponseFatal());
					break;
			}
		}

		return $ret;
	}

	protected function checkPermissions() {
		$events = API::Event()->get([
			'eventids' => $this->getInput('eventids'),
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'countOutput' => true
		]);

		return ($events == count($this->getInput('eventids')));
	}

	protected function doAction() {
		$eventids = $this->getInput('eventids');
		$acknowledge_type = $this->getInput('acknowledge_type');

		$result = true;

		if ($acknowledge_type == ZBX_ACKNOWLEDGE_PROBLEM || $acknowledge_type == ZBX_ACKNOWLEDGE_ALL) {
			$events = API::Event()->get([
				'output' => ['objectid'],
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'eventids' => $eventids
			]);

			$triggerids = zbx_objectValues($events, 'objectid');

			$filter = [
				'acknowledged' => EVENT_NOT_ACKNOWLEDGED
			];

			if ($acknowledge_type == ZBX_ACKNOWLEDGE_PROBLEM) {
				$filter['value'] = TRIGGER_VALUE_TRUE;
			}

			while ($result) {
				$events = API::Event()->get([
					'output' => [],
					'source' => EVENT_SOURCE_TRIGGERS,
					'object' => EVENT_OBJECT_TRIGGER,
					'objectids' => $triggerids,
					'filter' => $filter,
					'preservekeys' => true,
					'limit' => ZBX_DB_MAX_INSERTS
				]);

				if ($events) {
					foreach ($eventids as $i => $eventid) {
						if (array_key_exists($eventid, $events)) {
							unset($eventids[$i]);
						}
					}

					$result = API::Event()->acknowledge([
						'eventids' => array_keys($events),
						'message' => $this->getInput('message', '')
					]);
				}
				else {
					break;
				}
			}
		}

		if ($result && $eventids) {
			// All given events can be acknowledged.
			$eventids_to_ack = $eventids;
			$eventids_to_close = [];

			$close_problem = $this->getInput('close_problem', ZBX_ACKNOWLEDGE_ACTION_NONE);

			if ($close_problem == ZBX_ACKNOWLEDGE_ACTION_CLOSE_PROBLEM) {
				// Get trigger ids for these events.
				$events = API::Event()->get([
					'output' => ['eventid', 'objectid'],
					'eventids' => $eventids,
					'source' => EVENT_SOURCE_TRIGGERS,
					'object' => EVENT_OBJECT_TRIGGER,
					'preservekeys' => true
				]);
				$triggerids = zbx_objectValues($events, 'objectid');

				// User should have read-write permissions to trigger and trigger must have "manual_close" set to "1".
				$triggers = API::Trigger()->get([
					'output' => [],
					'triggerids' => $triggerids,
					'filter' => ['manual_close' => ZBX_TRIGGER_MANUAL_CLOSE_ALLOWED],
					'editable' => true,
					'preservekeys' => true
				]);

				// Get problem events and check if they can be closed.
				$problem_events = API::Event()->get([
					'output' => ['eventid', 'objectid', 'r_eventid'],
					'select_acknowledges' => ['action'],
					'eventids' => array_keys($events),
					'source' => EVENT_SOURCE_TRIGGERS,
					'object' => EVENT_OBJECT_TRIGGER,
					'value' => TRIGGER_VALUE_TRUE,
					'preservekeys' => true
				]);

				// Collect event IDs that can be closed.
				foreach ($problem_events as $problem_event) {
					if (array_key_exists($problem_event['objectid'], $triggers)) {
						// Check if it was closed by event recovery. If so, skip to next event.
						if ($problem_event['r_eventid'] != 0) {
							continue;
						}

						$event_closed = false;

						// Check if it was manually closed.
						if ($problem_event['acknowledges']) {
							foreach ($problem_event['acknowledges'] as $acknowledge) {
								if ($acknowledge['action'] == ZBX_ACKNOWLEDGE_ACTION_CLOSE_PROBLEM) {
									$event_closed = true;
									break;
								}
							}
						}

						if (!$event_closed) {
							$eventids_to_close[$problem_event['eventid']] = $problem_event['eventid'];
						}
					}
				}

				// The remaining events can be acknowledged.
				$eventids_to_ack = array_diff($eventids, $eventids_to_close);

				// Acknowledge and close problems.
				if ($eventids_to_close) {
					$result = API::Event()->acknowledge([
						'eventids' => $eventids_to_close,
						'message' => $this->getInput('message', ''),
						'action' => $close_problem
					]);
				}
			}

			// There might be nothing more to acknowledge since previous action closed all the events.
			if ($result && $eventids_to_ack) {
				$result = API::Event()->acknowledge([
					'eventids' => $eventids_to_ack,
					'message' => $this->getInput('message', '')
				]);
			}
		}

		if ($result) {
			$response = new CControllerResponseRedirect($this->getInput('backurl', 'tr_status.php'));
			$response->setMessageOk(_n('Event acknowledged', 'Events acknowledged', count($eventids)));
		}
		else {
			$response = new CControllerResponseRedirect('zabbix.php?action=acknowledge.edit');
			$response->setFormData($this->getInputAll());
			$response->setMessageError(_n('Cannot acknowledge event', 'Cannot acknowledge events', count($eventids)));
		}
		$this->setResponse($response);
	}
}

