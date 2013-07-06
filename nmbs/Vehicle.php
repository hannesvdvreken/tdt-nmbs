<?php

use tdt\core\model\resources\read\AReader;

class NMBSVehicle extends AReader{

	public static function getParameters(){
		return ['tid'=>'vehicle id, according to nmbs, without the type (\'L\' or \'P\' or \'IC\').',
				'date'=>'date in \'Ymd\'.',
		       ];
	}

	public static function getRequiredParameters(){
		return ['tid'];
	}

	public function setParameter($key,$val){
		$this->$key = $val;
	}

	public function read(){
		/* prepare db connection */
		$config = require_once( __DIR__ .'/config.php');

		$hosts = implode(',',$config['db_hosts']);
		if (isset($config['db_username']) && isset($config['db_passwd']))
		{
			$credentials = $config['db_username'] . ":" . $config['db_passwd'] . "@";
		}
		else
		{
			$credentials = "";
		}

		$moniker = "mongodb://$credentials$hosts/" . $config['db_name'];
		
		$m = new \Mongo($moniker);
		$db = $m->{$config['db_name']};

		/* build query */
		$this->date = (isset($this->date) ? $this->date : date('Ymd',time())) ;

		$result = $db->trips->aggregate([['$match'=>['date'=> (integer)$this->date, 'tid'=> (integer)$this->tid]],['$sort'=>['sequence'=>1]]])['result'];

		foreach ($result as &$stop) {
			unset($stop['_id']);
		}
		/* return */
		return $result;
	}

	public static function getDoc(){
		return "this is not empty";
	}
}