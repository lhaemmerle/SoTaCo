<?php
// Set timezone where the Tahoma device is located
define('TIMEZONE', 'Europe/Zurich');

// Set geographical longitude of the home to control, needed to calculat sunrise and sunset
define('LONGITUDE', '8.5391');

// Set geographical latitude of the home to control, needed to calculat sunrise and sunset
define('LATITUDE', '47.3686');

// Meteo Schweiz CSV file updated every 10min
define('WEATHER_DATA_URL', 'https://data.geo.admin.ch/ch.meteoschweiz.messwerte-aktuell/VQHA80.csv');

// Meteo Schweiz abbreviation for weather station
// Get the abbreviation from map from https://www.meteoswiss.admin.ch/services-and-publications/applications/measurement-values-and-measuring-networks.html
// The current example is "Aadorf / Tänikon" = TAE
define('WEATHER_STATION_ID', 'TAE');

// Base URL to the local Tahoma API 	
define('TAHOMA_BASE_URL', 'https://gateway-#TAHOMA-PIN#.lan:8443');

// Tahoma API token
// Retrieve with instructions from https://github.com/Somfy-Developer/Somfy-TaHoma-Developer-Mode
define('TAHOMA_TOKEN', '01234567890123456789');

// Define zero or more rules that are executed in the automatic control mode.
// The following condition parameters can be used:
// hour        Current hour (0-23)
// minute      Current minute (0-59)
// day         Current weekday (1 = sunday, 7 = saturday)
// month       Current month  (1-12)
// week        Current week (1-52)
// yearday     Current day of the year (1-366)
// temperature Current temperature
// wind        Current wind speed in km/h
// gust        Current gust (max. wind speed) in km/h
// radiation   Current sun radiation in W/m2
// rain        Current rainfall in mm/10min
// moved       Number of times blind was moved by either rule (0-100) today
// executed    Number of times current rule was executed (0-100) today
//
// The following boolean (true or false) parameters can be use:
// down        Blind is extended
// up          Blind is retracted
// sunrise     Current time is after local sunrise
// sunset      Current time is after local sunset
//
// the following operators can be used:
// >           Greater than
// =>          Equal or greater than
// <           Smaller than
// <=          Equal or smaller than
// ==          Equal
// !=          Not equal
// &&          Boolean AND
// ||          Boolean OR
// !           Negation
// ( )         Parentheses to encapsulate conditions
//
// the following actions can be used:
// up          Retract blind
// down        Extend blind
// my          Move blind to My position
//
// Note: Only the first of potentially serveral rules is executed even if 
//       the conditions of several rules are met 
define('TAHOMA_DEVICES',  [
    [
		'name' => 'Children',
		'id' => 'io://0000-0000-0000/0000001',
        'rules' => [
			'wind > 80' => 'up',
		 ],
	],
	[
		'name' => 'Kitchen',
		'id' => 'io://0000-0000-0000/0000002',
        'rules' => [
			'gust > 90' => 'up',
			'!moved && hour >= 9 && temperature > 20' => 'down',
			'moved && hour >= 16 && temperature < 20' => 'my',
		 ],
	],
	[
		'name' => 'Parents',
		'id' => 'io://0000-0000-0000/0000003',
        'rules' => [
			'wind > 80' => 'up',
			'hour >= 16 && temperature > 5' => 'up',
		],
	],
    [
		'name' => 'Balcony',
		'id' => 'io://0000-0000-0000/0000004',
        'rules' => [
			'wind > 80' => 'up',
		],
	],
    [
		'name' => 'Guestroom',
		'id' => 'io://0000-0000-0000/0000005',
        'rules' => [
			'wind > 80' => 'up',
			'temperature > 20 && radiation > 300' => 'down',
			'(hour >= 13 && hour < 20) || temperature > 20 && radiation > 400' => 'down',
		],
	],
]);

// Directory to store cached files. 
// Write permission needed
define('CACHE_DIR', '/tmp');


