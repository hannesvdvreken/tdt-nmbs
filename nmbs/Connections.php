<?php

use tdt\core\model\resources\read\AReader;

class NMBSConnections extends AReader{

	public static function getParameters(){
		return [
			"stop_id_dep" => "stop id",
			"stop_id_arr" => "stop_id",
			"hour" => "hour in 'H:i' format (default: now)",
			"date" => "date in 'Ymd' format (default: today)",
			"limit" => "limit number of trips returned",
			"da" => "departing or arriving ('D' or 'A'), default: 'D'",
		];
	}

	public static function getRequiredParameters(){
		return ["stop_id_dep", "stop_id_arr"];
	}

	public function setParameter($key,$val){
		$this->$key = $val;
	}

	public function read(){
		if (!$this->set_default_vars()) {
			return array();
		}

		// prepare db connection
		$config = require_once( __DIR__ .'/config.php');

		$hosts = implode(',',$config['db_hosts']);

		// set credentials
		if ($config['db_username'] && $config['db_passwd']) {
			$creds = $config['db_username'] . ":" . $config['db_passwd'] . '@';
		}else{
			$creds = '';
		}

		// build moniker
		$moniker = "mongodb://$creds$hosts/" . $config['db_name'];

		$m = new \Mongo($moniker);
		$db = $m->{$config['db_name']};
		
		// get data from mongo
		$dep = $db->stops->findOne(array('sid' => $this->stop_id_dep));
		$arr = $db->stops->findOne(array('sid' => $this->stop_id_arr));

		// not possible to make route if no nmbs_sid known
		if ((string)$dep['_id'] == (string)$arr['_id'] || !isset($dep['nmbs_sid']) || !isset($arr['nmbs_sid'])){
			return array();
		}

		$curl = new Curl();
		$url = 'http://hari.b-rail.be/Hafas/bin/extxml.exe';

		$b = $this->da == 'A' ? 0 : $this->limit ;
		$f = $this->da == 'D' ? 0 : $this->limit ;
		$a = $this->da == 'A' ? 0 : 1 ;

		$xml = '<?xml version="1.0 encoding="iso-8859-1"?>
				<ReqC ver="1.1" prod="iRail" lang="nl">
				  <ConReq>
				    <Start min="0">
				      <Station externalId="'.$dep['nmbs_sid'].'" distance="0">
				      </Station>
				      <Prod prod="0111111000000000">
				      </Prod>
				    </Start>
				    <Dest min="0">
				      <Station externalId="'.$arr['nmbs_sid'].'" distance="0">
				      </Station>
				    </Dest>
				    <Via>
				    </Via>
				    <ReqT time="'. $this->hour .'" date="'. $this->date .'" a="'. $a .'">
				    </ReqT>
				    <RFlags b="'. $b .'" f="'. $f .'">
				    </RFlags>
				    <GISParameters>
				      <Front>
				      </Front>
				      <Back>
				      </Back>
				    </GISParameters>
				  </ConReq>
				</ReqC>';

		// perform request
		$result = $curl->simple_post($url, $xml);

		// create xml
		$xml = new SimpleXMLElement($result);
		$journeys = array();
		$stop_data = array(
			$dep['nmbs_sid'] => $dep,
			$arr['nmbs_sid'] => $arr,
		);

		foreach ($xml->ConRes->ConnectionList->Connection as $journey_data) {
			$sections = array();

			foreach ($journey_data->ConSectionList->ConSection as $sections) {
				//
			}

			$departure = reset($journey->Departure->BasicStop->Station);
			$dep_nmbs_sid = $departure['externalStationNr'];
			$arrival = reset($journey->Arrival->BasicStop->Station);
			$arr_nmbs_sid = $arrival['externalStationNr'];
			
			return [$arr_nmbs_sid, $dep_nmbs_sid, $journey,];
		}

		// get route
		return $journeys;
	}

	private function set_default_vars() {
		// default parameters
		// hour & date
		if (!isset($this->date) && isset($this->hour))
		{
			$this->date = date('Ymd',time());
		}
		else if (!isset($this->date) && !isset($this->hour))
		{
			$this->date = date('Ymd',time());
			$this->hour = date('H:i',time());
		}
		else if (isset($this->date) && !isset($this->hour))
		{
			return false;
		}

		// direction: default 'D' (departures)
		$this->da = isset($this->da) ? $this->da : 'D';

		// limit: default 10
		$this->limit = isset($this->limit) ? intval($this->limit) : 10;

		return true;
	}

	public static function getDoc(){
		return "this is not empty";
	}
}