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
		$stops_data = iterator_to_array($db->stops->find(array('nmbs_sid' => array('$exists' => true))));
		
		// prepare
		$nmbs_stops = array();
		$railtime_stops = array();

		// convert
		foreach ($stops_data as &$s) {
			// build
			$railtime_stops[$s['sid']] = $s;
			$nmbs_stops[$s['nmbs_sid']] = $s;
		}

		$dep = $railtime_stops[$this->stop_id_dep];
		$arr = $railtime_stops[$this->stop_id_arr];

		// not possible to make route if no nmbs_sid known
		if ((string)$dep['_id'] == (string)$arr['_id'] || !isset($dep['nmbs_sid']) || !isset($arr['nmbs_sid'])){
			return array();
		}

		$curl = new Curl();
		$url = 'http://hari.b-rail.be/Hafas/bin/extxml.exe';

		$b = $this->da == 'D' ? 0 : $this->limit ;
		$f = $this->da == 'A' ? 0 : $this->limit ;
		$a = $this->da == 'D' ? 0 : 1 ;

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

			$date = (integer)$journey_data->Overview->Date;

			foreach ($journey_data->ConSectionList->ConSection as $section) 
			{
				// parse from tree
				$departure = reset($section->Departure->BasicStop->Station)['externalStationNr'];
				$arrival   = reset($section->Arrival->BasicStop->Station)['externalStationNr'];
				$tid       = (integer) reset($section->Journey->JourneyAttributeList->JourneyAttribute[2]->Attribute->AttributeVariant->Text);

				// look for date offset
				$date += (integer)substr($section->Departure->BasicStop->Dep->Time, 1, 1);

				// grab some data from datastore
				$departure = $nmbs_stops[$departure];
				$arrival   = $nmbs_stops[$arrival];

				// edit fields
				$departure['sid'] = (integer) $departure['sid'];
				$arrival['sid']   = (integer) $arrival['sid'];
				unset($departure['_id']);
				unset($departure['nmbs_sid']);
				unset($arrival['_id']);
				unset($arrival['nmbs_sid']);

				// build query
				$query = array(
					'date' => $date, 
					'tid' => $tid,
					'sid' => array('$in' => array($departure['sid'], $arrival['sid']))
				);

				// perform
				$trip_data = iterator_to_array($db->trips->find(
					$query
				));
				
				if (!$trip_data || empty($trip_data)) continue 2; // 2 levels


				if (!$trip_data || empty($trip_data)) continue 2; // 2 levels

				// prepare
				$trip = array();

				// get general trip info
				$s = reset($trip_data);
				
				$trip['date']     = $s['date'];
				$trip['origin']   = $s['origin'];
				$trip['headsign'] = $s['headsign'];
				$trip['tid']      = $s['tid'];
				$trip['type']     = $s['type'];
				$trip['route']    = $s['route'];
				$trip['agency']   = $s['agency'];

				// get data
				foreach ($trip_data as $s)
				{
					// enhance departure object
					if ($s['sid'] == $departure['sid'])
					{
						if (isset($s['departure_time']))
							$departure['departure_time']  = $s['departure_time'];
						if (isset($s['departure_delay']))
							$departure['departure_delay'] = $s['departure_delay'];
						if (isset($s['cancelled']))
							$departure['cancelled'] = $s['cancelled'];
						if (isset($s['platform']))
							$departure['platform']  = $s['platform'];
					}

					// enhance arrival object
					if ($s['sid'] == $arrival['sid'])
					{
						if (isset($s['arrival_time']))
							$arrival['arrival_time']  = $s['arrival_time'];
						if (isset($s['arrival_delay']))
							$arrival['arrival_delay'] = $s['arrival_delay'];
						if (isset($s['cancelled']))
							$arrival['cancelled'] = $s['cancelled'];
						if (isset($s['platform']))
							$arrival['platform']  = $s['platform'];
					}
				}

				$new_section = array();
				$new_section['departure'] = $departure;
				$new_section['trip'] = $trip;
				$new_section['arrival'] = $arrival;

				$sections[] = $new_section;
			}
			
			$journeys[] = $sections;
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
		$this->limit = isset($this->limit) ? max(intval($this->limit), 6) : 6;

		return true;
	}

	public static function getDoc(){
		return "this is not empty";
	}
}
