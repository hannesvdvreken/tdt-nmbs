<?php

use tdt\core\model\resources\read\AReader;

class NMBSLiveboard extends AReader{

	public static function getParameters(){
		return [
			"stop_id" => "stop id",
			"date" => "date in 'Ymd' format (default: today)",
			"after" => "hour in 'H:i' format (default: now)",
			"limit" => "limit number of trips returned",
			"da" => "departing or arriving ('D' or 'A'), default: 'D'"
		];
	}

	public static function getRequiredParameters(){
		return ["stop_id"];
	}

	public function setParameter($key,$val){
		$this->$key = $val;
	}

	public function read(){
		/* default parameters */
		/* after */
		if (!isset($this->date) && !isset($this->after))
		{
			$this->after = date('H:i',time());
		}

		/* date */
		$this->date = isset($this->date) ? $this->date : date('Ymd',time());

		/* after */
		$this->after = isset($this->after) ? $this->after : date('H:i',time());

		/* direction: default 'D' (departures) */
		$this->da = isset($this->da) ? $this->da : 'D';

		/* limit: default 10 */
		$this->limit = isset($this->limit) ? intval($this->limit) : 10;

		/* time parameters */
		$d = preg_replace('/^(.{10})/', substr($this->date, 0, 4) . '-' . substr($this->date, 4, 2) . '-' . substr($this->date, 6) , date('c', time()));

		if (isset($this->after))
			$this->after = preg_replace('/T\d\d:\d\d:\d\d/', "T$this->after:00", $d);

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

		/* criteria */

		$criteria['date']   = (integer)$this->date;
		$criteria['sid']    = (integer)$this->stop_id;
		$criteria['agency'] = 'NMBS-SNCB';

		if (isset($this->after))
		{
			if (preg_match('/^d$/i', $this->da))
				$criteria['departure_time'] = array('$gte'=> $this->after);
			else
				$criteria['arrival_time'] = array('$gte'=> $this->after);
		}

		/* query */
		$pipeline[] = ['$match' => $criteria ];
		
		/* sort on the right key */
		if (preg_match('/^d$/i', $this->da))
			$sort_key = 'departure_time';
		else
			$sort_key = 'arrival_time';
		$pipeline[] = ['$sort' => [ $sort_key => 1 ] ];

		/* after sort comes limit */
		$pipeline[] = ['$limit' => $this->limit ];
		
		$result = $db->trips->aggregate($pipeline)['result'];

		foreach ($result as &$trip)
		{
			unset($trip['_id']);
			unset($trip['sequence']);
		}

		return $result;
	}

	public static function getDoc(){
		return "this is not empty";
	}
}