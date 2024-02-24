<?php
// Somfy Controller v0.5
// Date: 2024-02-24
// Author: Lukas Hämmerle <lukas@haemmerle.net>

// Load configuration
include('config.php');

// Set timezone
date_default_timezone_set(TIMEZONE);

// Get command options
$longOpts = ['identify:','name:','value:', 'weather', 'temperature:', 'wind:', 'gust:', 'rain:', 'radiation:', 'execute', 'cache', 'debug'];
$opts = getopt('d:u:m:i::h', $longOpts);

// Set debug constanttemperature
define('DEBUG', isset($opts['debug']));
define('CACHED', isset($opts['cache']));
define('EXECUTE', isset($opts['execute']));

// Print input argument
printValueIfOnDebug("Options = ".print_r($opts, true));

// How help
if (!defined('TAHOMA_BASE_URL') || isset($opts['h'])){

    // Check if config was loaded
    if (!defined('TAHOMA_BASE_URL')){
        echo "ERROR: No configuration file config.php found in this directory!\n\n";
    }

    showHelp(TAHOMA_DEVICES);
} else if (isset($opts['i'])){
    if (isInteger($opts['i'])){
        $result = getDeviceData(TAHOMA_DEVICES[$opts['i']]['id'], CACHED);
    } else {
        $result = getDeviceData('', CACHED);
    }
    $jsonObject = ['devices' => $result];
    echo json_encode($jsonObject, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
} else if (isset($opts['r']) && isInteger($opts['r'])){
    $result = sendCommand(TAHOMA_DEVICES[$opts['r']]['id'], 'up');
    printValueIfOnDebug($result);
    exit;
} else if (isset($opts['e']) && isInteger($opts['e'])){
    $result = sendCommand(TAHOMA_DEVICES[$opts['e']]['id'], 'down');
    printValueIfOnDebug($result);
    exit;
} else if (isset($opts['m']) && isInteger($opts['m'])){
    $result = sendCommand(TAHOMA_DEVICES[$opts['m']]['id'], 'my');
    printValueIfOnDebug($result);
    exit;
} else if (isset($opts['identify']) && isInteger($opts['identify'])){
    $result = sendCommand(TAHOMA_DEVICES[$opts['identify']]['id'], 'identify');
    printValueIfOnDebug($result);
    exit;
} else if (isset($opts['name']) && isInteger($opts['name']) && isset($opts['value'])){
    $value = $opts['value'];
    $result = sendCommand(TAHOMA_DEVICES[$opts['name']]['id'], 'setName', $value);
    printValueIfOnDebug($result);
    exit;
} else if (isset($opts['weather'])){

    // Get weather data
    list($temperature, $rain, $radiation, $wind, $gust) = getWeatherData(CACHED);
    echo getWeatherDataInfo($temperature, $rain, $radiation, $wind, $gust);
    exit;
} else {
    // Automatically control blinds

    // Get device information
    $devices = getDeviceData('', CACHED);

    // Get weather data
    list($temperature, $rain, $radiation, $wind, $gust) = getWeatherData(CACHED);

    // Overwrite weather data
    if (isset($opts['temperature']) && is_numeric($opts['temperature'])){
        $temperature = $opts['temperature'];   
    }
    if (isset($opts['rain']) && is_numeric($opts['rain'])){
        $rain = $opts['rain'];   
    }
    if (isset($opts['radiation']) && is_numeric($opts['radiation'])){
        $radiation = $opts['radiation'];   
    }
    if (isset($opts['wind']) && is_numeric($opts['wind'])){
        $wind = $opts['wind'];   
    }
    if (isset($opts['gust']) && is_numeric($opts['gust'])){
        $gust = $opts['gust'];   
    }

    // Compose weather debug information
    printValueIfOnDebug(getWeatherDataInfo($temperature, $rain, $radiation, $wind, $gust));

    // Get state information
    if (is_file(CACHE_DIR.'/sotaco-state-data.json')){
        $stateDataJSON = file_get_contents(CACHE_DIR.'/sotaco-state-data.json');
        $stateData = json_decode($stateDataJSON, true);
    } else {
        $stateData = [];
    }

    $infrastructure = TAHOMA_DEVICES;
    foreach ($devices as $device){
        foreach($infrastructure as $i => $configuredDevice){
            if ($configuredDevice['id'] == $device['id']){
                $infrastructure[$i]['up'] = $device['up'];
                $infrastructure[$i]['down'] = $device['down'];

                // Set moved to true if it was moved today
                if (isset($stateData[$i]['lastMoved']) && $stateData[$i]['lastMoved'] == date('Ymd')){
                    $infrastructure[$i]['moved'] = true;
                } else {
                    $infrastructure[$i]['moved'] = false;
                }

                $stateData[$i]['name'] = $device['name'];
                $stateData[$i]['id'] = $device['id'];
            }
        }
    }

    // Set time variables
    $hour = date('H');
    $minute = date('i');
    $day = (date('w') + 1);
    $month = date('n');
    $week = preg_replace('/0/', '', date('W'));
    $yearday = (date('z') + 1);

    // Process all device rules
    foreach ($infrastructure as $i => $device){
        // Skip devices with no rules
        if (!isset($device['rules']) || empty($device['rules'])){
            continue;
        }

        // Check and execute rules
        foreach($device['rules'] as $condition => $action){
            // Check config
            checkCondition($condition);
            checkAction($action);

            // Set device specific variables
            $up = $device['up'];
            $down = $device['down'];
            $moved = $device['moved'];

            $expresssion = getSanitizedExpression($condition);

            printValueIfOnDebug("Condition to test for '{$device['name']}' to set action '{$action}': ".$condition);

            // Execute expression
            $conditionEvaluationResult = eval($expresssion);
            printValueIfOnDebug("- Result: ".($conditionEvaluationResult ? 'true' : 'false'));

            // Condition is not met
            if (!$conditionEvaluationResult){
                continue;
            }

            if (($action == 'up' && $device['up']) || ($action == 'down' && $device['down'])){
                printValueIfOnDebug("Ignoring action '{$action}' for '{$device['name']}' because no change needed");
            } else if (EXECUTE){

                // Execute command
                $result = sendCommand($device['id'], $action);
                printValueIfOnDebug($result);

                // Wait for some time before issueing next command
                // Not sure if this is needed
                usleep(500000);

                // @todo: Set moved if blind was moved by this program
                $stateData[$i]['lastMoved'] = date('Ymd');
            } else {
                echo date('Y-m-d H:i:s')." Dry Run: Would execute action '{$action}' for blind '{$device['name']}'\n";
            }

            // Execute only the first matching rule to prevent contradicting rules
            break;
        }
    }

    // Store state information
    file_put_contents(CACHE_DIR.'/sotaco-state-data.json', json_encode($stateData, JSON_PRETTY_PRINT));
}

/**
 * Gets device information for all or one specific device identified by its device ID
 * @param string $deviceID
 * @param boolean $cached
 */
function getDeviceData($deviceID = '', $cached = false){
    
    // Get cached data or fetch from remote
    if ($cached){
        $return = file_get_contents(CACHE_DIR.'/sotaco-devices-data-cache.json');
    } else {
        // Compose request URL
        $requestURL = TAHOMA_BASE_URL.'/enduser-mobile-web/1/enduserAPI/setup/devices';			

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Authorization: Bearer '.TAHOMA_TOKEN));
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_URL, $requestURL);
        $return = curl_exec($curl);

        // Check for errors
        if (!$return || curl_errno($curl)){
            $errorMessage = curl_error($curl);
            printErrorAndExit('Could not retrieve tahoma device information from URL '.$requestURL.'. Message: '.$errorMessage);
        }

        // Close connection
        curl_close($curl);
    
        // Store cache
        file_put_contents(CACHE_DIR.'/sotaco-devices-data-cache.json', $return);    
    }
    

    // Output returned content
    printValueIfOnDebug("Tahoma devices JSON data:\n".json_encode(json_decode($return), JSON_PRETTY_PRINT)."\n");

    // Decode JSON
	$result = json_decode($return);

    // Extract relevant information
	$devices = [];
	for( $i = 0; $i < count($result); $i++){
        // Skip devices not matching deviceID
        if (!empty($deviceID) && $deviceID != $result[$i]->deviceURL){
            continue;
        }

        $device = [];
		$device['name'] = $result[$i]->label;
		$device['id'] = $result[$i]->deviceURL;		
		$device['type'] = $result[$i]->definition->widgetName;		
		
		foreach ($result[$i]->states as $state){
			if ($state->name == 'core:ManufacturerSettingsState'){
				$device['position'] = $state->value->current_position;		
                if (isset($state->value->current_tilt)){
				    $device['tilt'] = $state->value->current_tilt;
                } else {
                    $device['tilt'] = null;
                }

                // Everything smaller than 1000 (from 51200) is up
                $device['up'] = ($device['position'] < 1000);

                // Extended means down and tilted or down
                $device['down'] = ($device['position'] > 50000 && ($device['tilt'] == null || $device['tilt'] > 40000));
			}
		}

        // Add to device list
        $devices[] = $device;
	}

	return($devices);
}

/**
 * Send a command with optional parameters to device with given deviceID
 * @param string $deviceID
 * @param string $command
 * @param string $parameter
 * @return string 
 */
function sendCommand($deviceID, $command, $parameter = ''){
	$requestURL = TAHOMA_BASE_URL.'/enduser-mobile-web/1/enduserAPI/exec/apply';

    $command = ['name' => $command];
    if($parameter !== ''){
        $command['parameters'] = [$parameter];
    }

    $request = [
        'label' => 'myAction',
        'actions' => [
            [
                'commands' => [
                    $command
                ],
                'deviceURL' => $deviceID,
            ],
        ],
    ];

    $httpPayload = json_encode($request, JSON_PRETTY_PRINT);
    printValueIfOnDebug($httpPayload);

	$curl = curl_init();
    $httpHeaders = [
        'Content-Type: application/json',
        'Content-Length: '.strlen($httpPayload),
        'Authorization: Bearer '.TAHOMA_TOKEN
    ];
	curl_setopt($curl, CURLOPT_POST, true);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
	curl_setopt($curl, CURLOPT_HTTPHEADER, $httpHeaders);
	curl_setopt($curl, CURLOPT_POSTFIELDS,$httpPayload);
	curl_setopt($curl, CURLOPT_URL, $requestURL);

	$result = curl_exec($curl);
	curl_close($curl);
	
	return $result;	
}

/**
 * Return temperature, rain, global radiation, wind, gust as numbers.
 * Use cached version if available
 * 
 * @param boolean $cached
 * @return float[]
 */
function getWeatherData($cached = false){

    // Get data from cache or fetch
    if ($cached){
        $return = file_get_contents(CACHE_DIR.'/sotaco-weather-data-cache.csv');
    } else {
        $requestURL = WEATHER_DATA_URL;

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_URL, $requestURL);
    
        /*
        Field format:
        0  station        string         Abbreviation of weather station
        1  timestamp      string         Timestamp in format YYYYMMDDHHMM
        2  tre200s0       °C             Air temperature 2 m above ground; current value
        3  rre150z0       mm             Precipitation; ten minutes total
        4  sre000z0       min            Sunshine duration; ten minutes total
        5  gre000z0       W/m²           Global radiation; ten minutes mean
        6  ure200s0       %              Relative air humidity 2 m above ground; current value
        7  tde200s0       °C             Dew point 2 m above ground; current value
        8  dkl010z0       °              wind direction; ten minutes mean
        9  fu3010z0       km/h           Wind speed; ten minutes mean
        10 fu3010z1       km/h           Gust peak (one second); maximum
        11 prestas0       hPa            Pressure at station level (QFE); current value
        12 pp0qffs0       hPa            Pressure reduced to sea level (QFF); current value
        13 pp0qnhs0       hPa            Pressure reduced to sea level according to standard atmosphere (QNH); current value
        14 ppz850s0       gpm            geopotential height of the 850 hPa-surface; current value
        15 ppz700s0       gpm            geopotential height of the 700 hPa-surface; current value
        16 dv1towz0       °              wind direction vectorial, average of 10 min; instrument 1
        17 fu3towz0       km/h           Wind speed tower; ten minutes mean
        18 fu3towz1       km/h           Gust peak (one second) tower; maximum
        19 ta1tows0       °C             Air temperature tool 1
        20 uretows0       %              Relative air humidity tower; current value
        21 tdetows0       °C             Dew point tower
        */
    
        $return = curl_exec($curl);
    
        // Check for errors
        if (!$return || curl_errno($curl)){
            $errorMessage = curl_error($curl);
            printErrorAndExit('Could not retrieve weather data from URL '.$requestURL.'. Message: '.$errorMessage);
        }

        // Close connection
        curl_close($curl);

        // Store cache
        file_put_contents(CACHE_DIR.'/sotaco-weather-data-cache.csv', $return);
    }

    foreach (explode("\n", $return) as $line){
        $components = explode(';', $line);
        if (strcmp($components[0], WEATHER_STATION_ID) == 0){
            // Return sanitized float values
            return [
                filter_var($components[2], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
                filter_var($components[3], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
                filter_var($components[5], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
                filter_var($components[9], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
                filter_var($components[10], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION)
            ];
        }

    }

    // Return default values
    return [0, 0, 0, 0, 0];
}

/**
 * Show help information and prints configured device list
 */
function  showHelp(){
    $deviceList = '';
    foreach(TAHOMA_DEVICES as $i => $info){
        $deviceList .= $i.' '.$info['name']."\n";
    }

    echo <<<MAN
Description:
Controls blinds manually or automatically and retrieve information from Tahoma about blinds.

Options:
-h                  Show help page
-i[<deviceNumber>]  Output most relevant infrastructure information as JSON data
-d <deviceNumber>   Extend/close blind with given deviceNumber 
-u <deviceNumber>   Retract/open blind with given deviceNumber
-m <deviceNumber>   Set blind with given deviceNumber to My position

Advanced options:
--debug
Show debug information

--cache
Use cached weather and tahoma devices data instead of downloading them

--weather
Get and output current weather data

--temperature <float>
Set temperature instead of reading it from weather data

--rain <float>
Set rain in mm/10min instead of reading it from weather data

--wind <float>
Set wind speed in km/h instead of reading it from weather data

--gust <float>
Set wind (gust) in km/h instead of reading it from weather data

--radation <float>
Set radiation in W/m² instead of reading it from weather data

--execute
Actually move blinds. If this parameter is not set all actions
are executed only in dry-run mode that does not move any blinds.

--identify <deviceNumber>
Identify given device (i.e. make it wink)

--name <deviceNumber> --value <string> 
Set name for device. Apparently limit is 17 characters

Device Numbers:
{$deviceList}

Usage:
php controlBlinds.php -h
Show help

php controlBlinds.php -i [--debug]
Get information about all devices

php controlBlinds.php -e|-r|-m <device number> [--debug]
Control blinds manually

php controlBlinds.php --identify <device number> [--debug]
Identify device by sending it a command to wink

php controlBlinds.php --name <device number> --value <string> [--debug]
Set name for device

php controlBlinds.php \
    [--temperature <float>] [--wind <float>] [--gust <float>] [--rain <float>] \
    [--radiation <float>] [-temperature <float>] [--execute] [--debug]
Control blinds automatically, overwrite weather data with given weather values if available.
The blinds are only moved for real if the parameter --execute is added.

MAN;
    exit;
}

/**
 * Returns true if given value is an integer
 * 
 * @param string $value
 * @return boolean
 */
function isInteger($value){
    return preg_match('/^\d+$/', $value);
}

/**
 * Prints value if debug mode is enabled 
 */
function printValueIfOnDebug($value){
    if (DEBUG) {
        print_r($value);
        echo "\n";
    }
}

/**
 * Performs a basic check for the given condition
 * 
 * @param string $action
 */
function checkCondition($condition){
    // Allowed operators
    $operators = '\&\&|\|\||\!|\(|\)|\>|\<|=';

    // Split by operators, values and white space
    $components = preg_split('/\s*('.$operators.'|[\d+\.])\s*/', $condition, 0, PREG_SPLIT_NO_EMPTY);

    // Set allowed variables
    $allowedVariables = getAlloweVariables();

    // Check all condition components
    foreach ($components as $component){

        // Check if all components are allowed variables
        if (!in_array($component, $allowedVariables)){
            printErrorAndExit('Invalid condition: Variable '.$component.' not allowed in condition '.$condition);
        }
    }
}

/**
 * Checks an rule action
 * 
 * @param string $action
 */
function checkAction($action){
    if (!preg_match('/up|down|my/', $action)){
        printErrorAndExit('Invalid action: '.$action);
    }
}

/**
 * Prints an error and exits
 * 
 * @param string $message
 */
function printErrorAndExit($message){
    echo '###ERROR: '.$message."\n";
    exit;
}

/**
 * Return array of allowed variables
 * 
 * @return string[]
 */
function getAlloweVariables(){
    $allowedVariables = [];
    $allowedVariables[] = 'hour';
    $allowedVariables[] = 'minute';
    $allowedVariables[] = 'day';
    $allowedVariables[] = 'month';
    $allowedVariables[] = 'week';
    $allowedVariables[] = 'yearday';
    $allowedVariables[] = 'temperature';
    $allowedVariables[] = 'wind';
    $allowedVariables[] = 'gust';
    $allowedVariables[] = 'radiation';
    $allowedVariables[] = 'rain';
    $allowedVariables[] = 'moved';
    $allowedVariables[] = 'down';
    $allowedVariables[] = 'up';

    return $allowedVariables;
}

/**
 * Return string of given condition that can be executed with eval
 * 
 * @return string
 * @return string
 */
function getSanitizedExpression($condition){

    // Replace variables
    $expression = $condition;
    $variables = getAlloweVariables();
    foreach ($variables as $variable){
        $expression = preg_replace('/'.$variable.'/', '$'.$variable, $expression);
    }

    return 'return ('.$expression.');';
}

/**
 * Print weather data
 * 
 * @param float $temperature
 * @param float $rain
 * @param float $radiation
 * @param float $wind
 * @param float $gust
 */
function getWeatherDataInfo($temperature, $rain, $radiation, $wind, $gust){
    $txt = "Current weather data:\n";
    $txt .=  "- Temperature: $temperature °C\n";
    $txt .=  "- Rainfall: $rain mm/10min\n";
    $txt .=  "- Global sun radiation: $radiation W/m²\n";
    $txt .=  "- Wind speed: $wind km/h\n";
    $txt .=  "- Gust speed: $gust km/h\n";
    return $txt;
}