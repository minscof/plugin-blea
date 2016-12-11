<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */
require_once dirname(__FILE__) . "/../../../../core/php/core.inc.php";

if (!jeedom::apiAccess(init('apikey'), 'blea')) {
	echo 'Clef API non valide, vous n\'etes pas autorisé à effectuer cette action';
	die();
}

if (init('test') != '') {
	echo 'OK';
	die();
}
$result = json_decode(file_get_contents("php://input"), true);
if (!is_array($result)) {
	die();
}
if (isset($result['source'])){
	log::add('blea','debug','This is a message from antenna ' . $result['source']);
}
if (isset($result['learn_mode'])) {
	if ($result['learn_mode'] == 1) {
		config::save('include_mode', 1, 'blea');
		event::add('blea::includeState', array(
			'mode' => 'learn',
			'state' => 1)
		);
	} else {
		config::save('include_mode', 0, 'blea');
		event::add('blea::includeState', array(
			'mode' => 'learn',
			'state' => 0)
		);
	}
	die();
}

if (isset($result['exclude_mode'])) {
	if ($result['exclude_mode'] == 1) {
		config::save('exclude_mode', 1, 'blea');
		event::add('blea::includeState', array(
			'mode' => 'exclude',
			'state' => 1)
		);
	} else {
		config::save('exclude_mode', 0, 'blea');
		event::add('blea::includeState', array(
			'mode' => 'exclude',
			'state' => 0)
		);
		sleep(1);
		if (isset($result['deviceId'])) {
			event::add('jeedom::alert', array(
				'level' => 'warning',
				'page' => 'blea',
				'message' => __('Un device Blea est en cours d\'exclusion. Logical ID : ', __FILE__) . $result['deviceId'],
			));
			sleep(2);
			blea::excludedDevice($result['deviceId']);
		}
	}
	die();
}

if (isset($result['devices'])) {
	
	foreach ($result['devices'] as $key => $datas) {
		if (!isset($datas['id'])) {
			continue;
		}
		if (isset($datas['source'])){
			log::add('blea','info','This is a message from antenna ' . $datas['source']);
			if ($datas['source'] != 'local'){
				$remotes = blea_remote::all();
				foreach ($remotes as $remote){
					if ($remote->getRemoteName() == $datas['source']){
						$remote->setConfiguration('lastupdate',date("Y-m-d H:i:s"));
						$remote->save();
					}
				}
			}
		}
		$blea = blea::byLogicalId($datas['id'], 'blea');
		if (!is_object($blea)) {
			if ($datas['learn'] != 1) {
				continue;
			}
			$blea = blea::createFromDef($datas);
			if (!is_object($blea)) {
				log::add('blea', 'debug', __('Aucun équipement trouvé pour : ', __FILE__) . secureXSS($datas['id']));
				continue;
			}
			event::add('jeedom::alert', array(
				'level' => 'warning',
				'page' => 'blea',
				'message' => '',
			));
			event::add('blea::includeDevice', $blea->getId());
		}
		if (!$blea->getIsEnable()) {
			continue;
		}
		if (isset($datas['rssi'])) {
			$cmdremote = $blea->getCmd(null, 'rssi' . $datas['source']);
			if (!is_object($cmdremote)) {
				$cmdremote = new bleaCmd();
				$cmdremote->setLogicalId('rssi' . $datas['source']);
				$cmdremote->setIsVisible(0);
				$cmdremote->setName(__('Rssi '. $datas['source'], __FILE__));
				$cmdremote->setType('info');
				$cmdremote->setSubType('numeric');
				$cmdremote->setUnite('dbm');
				$cmdremote->setEqLogic_id($blea->getId());
				$cmdremote->save();
			}
			$cmdremote->event($datas['rssi']);
		}
		foreach ($blea->getCmd('info') as $cmd) {
			$logicalId = $cmd->getLogicalId();
			if ($logicalId == '') {
				continue;
			}
			$path = explode('::', $logicalId);
			$value = $datas;
			foreach ($path as $key) {
				if (!isset($value[$key])) {
					continue (2);
				}
				$value = $value[$key];
			}
			if ($logicalId == 'rssi' && $datas['source'] != 'local') {
				continue;
			}
			$antenna = 'local';
			$antennaId = $blea->getConfiguration('antennareceive','local');
			if ($antennaId != 'local'){
				$remote = blea_remote::byId($antennaId);
				$antenna = $remote->getRemoteName();
			}
			if ($logicalId != 'present' && $antenna != $datas['source']){
				log::add('blea','debug','Ignoring this antenna (' . $datas['source'] . ' only allowed ' . $antenna .') must not trigger events except for presence and rssi : ' . $logicalId );
				continue;
			}
			if (!is_array($value)) {
				$cmd->event($value);
			}
			if ($logicalId == 'battery') {
				$blea->batteryStatus($value);
			}
		}
	}
}
