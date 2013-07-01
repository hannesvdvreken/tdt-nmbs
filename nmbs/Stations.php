<?php

use tdt\core\model\resources\read\AReader;

class NMBSStations extends AReader{

	public static function getParameters(){
		return [];
	}

	public static function getRequiredParameters(){
		return [];
	}

	public function setParameter($key,$val){
		$this->$key = $val;
	}

	public function read(){
		/* prepare db connection */
		$config = require_once( __DIR__ .'/config.php');

		$hosts = implode(',',$config['db_hosts']);
		$moniker = "mongodb://" . $config['db_username'] . ":" . $config['db_passwd'] . "@$hosts/" . $config['db_name'];
		
		$m = new \Mongo($moniker);
		$db = $m->{$config['db_name']};

		$stops = array();
		$result = $db->stops->find();
		foreach ($result as $stop) {
			$stop['long'] = $stop['lon'];
			unset($stop['_id']);
			unset($stop['nmbs_sid']);
			unset($stop['lon']);
			$stops[] = $stop;
		}
		return $stops;
	}

	public static function getDoc(){
		return "this is not empty";
	}
}