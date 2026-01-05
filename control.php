<?php
// Somfy Controller v1.1.1
// Date: 2024-09-17
// Author: Lukas Hämmerle <lukas@haemmerle.net>

// Get command options
$longOpts = [
    'identify:','name:','value:', 'weather', 'temperature:', 'wind:', 
    'gust:', 'rain:', 'radiation:', 'sunshine:', 'hour:', 'minute:', 'execute', 
    'command:', 'device:', 'parameter1:', 'parameter2:', 'cache', 'debug'
];
$opts = getopt('c:d:u:m:i::h', $longOpts);

// Load configuration
$configFile = isset($opts['c']) ? $opts['c'] : __DIR__ . '/config.php';
if (!file_exists($configFile) || !is_readable($configFile)){
    printErrorAndExit("Cannot load configuration file $configFile!");
} else {
    // Load default file
    require($configFile);
}

// Check config
checkConfig();

// Set timezone
date_default_timezone_set(TIMEZONE);

// Set debug constanttemperature
define('DEBUG', isset($opts['debug']));
define('CACHED', isset($opts['cache']));
define('EXECUTE', isset($opts['execute']));

// Print input argument
printValueIfOnDebug("Options = ".print_r($opts, true));

// Set time variables
$hour = date('H');
$minute = date('i');
$day = (date('w') + 1);
$month = date('n');
$week = preg_replace('/0/', '', date('W'));
$yearday = (date('z') + 1);
$sunInfo = date_sun_info(time(), LATITUDE, LONGITUDE);
$sunriseTimestamp = $sunInfo['sunrise'];
$sunsetTimestamp = $sunInfo['sunset'];
$sunrise = (time() > $sunriseTimestamp);
$sunset = (time() > $sunsetTimestamp);
$today = date('Ymd');

// How help
if (!defined('TAHOMA_BASE_URL') || isset($opts['h'])){

    // Check if config was loaded
    if (!defined('TAHOMA_BASE_URL')){
	    printErrorAndExit("TAHOMA_BASE_URL is not set in configuration file $configFile! Is this a valid configuration file?");
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
} else if (isset($opts['u']) && isInteger($opts['u'])){
    $result = sendCommand(TAHOMA_DEVICES[$opts['u']]['id'], 'up');
    printValueIfOnDebug($result);
    exit;
} else if (isset($opts['d']) && isInteger($opts['d'])){
    $result = sendCommand(TAHOMA_DEVICES[$opts['d']]['id'], 'down');
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
} else if (isset($opts['command']) && is_string($opts['command'])){
    // Ensure device number is present
    if (!isset($opts['device'])){
        printErrorAndExit("Parameter --device <deviceNumber> is missing");
    }

    // Get parameters
    $param1  = $opts['parameter1'] ?? null;
    $param2  = $opts['parameter2'] ?? null;

    // Send command
    $result = sendCommand(TAHOMA_DEVICES[$opts['device']]['id'], $opts['command'], $param1, $param2);
    printValueIfOnDebug($result);
    exit;
} else if (isset($opts['name']) && isInteger($opts['name']) && isset($opts['value'])){
    $value = $opts['value'];
    $result = sendCommand(TAHOMA_DEVICES[$opts['name']]['id'], 'setName', $value);
    printValueIfOnDebug($result);
    exit;
} else if (isset($opts['weather'])){

    // Get state information
    $stateData = getStateData();

    // Get weather data
    list($temperature, $rain, $radiation, $sunshine, $wind, $gust) = getWeatherData(CACHED);

    // Add at least one value
    addWeatherValues($stateData, $temperature, $radiation, $sunshine);

    list($averagetemp, $averagerad, $averagesun) = getWheaterAverage($stateData);
    echo getWeatherDataInfo($temperature, $averagetemp, $rain, $radiation, $averagerad, $sunshine, $averagesun, $wind, $gust, $hour, $minute);

    exit;
} else {
    // Automatically control blinds

    // Get device information
    $devices = getDeviceData('', CACHED);

    // Get weather data
    list($temperature, $rain, $radiation, $sunshine, $wind, $gust) = getWeatherData(CACHED);

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
    if (isset($opts['sunshine']) && is_numeric($opts['sunshine'])){
        $radiation = $opts['sunshine'];   
    }
    if (isset($opts['wind']) && is_numeric($opts['wind'])){
        $wind = $opts['wind'];   
    }
    if (isset($opts['gust']) && is_numeric($opts['gust'])){
        $gust = $opts['gust'];   
    }

    if (isset($opts['hour']) && is_numeric($opts['hour'])){
        $hour = $opts['hour'];
    }

    if (isset($opts['minute']) && is_numeric($opts['minute'])){
        $minute = $opts['minute'];
    }

    // Get state information
    $stateData = getStateData();

    // Store temperature and radation for past few days
    addWeatherValues($stateData, $temperature, $radiation, $sunshine);

    // Get state data
    list($averagetemp, $averagerad, $averagesun) = getWheaterAverage($stateData);

    // Compose weather debug information
    printValueIfOnDebug(getWeatherDataInfo($temperature, $averagetemp, $rain, $radiation, $averagerad, $sunshine, $averagesun, $wind, $gust, $hour, $minute));

    // Store device data
    $infrastructure = TAHOMA_DEVICES;
    foreach ($devices as $device){
        foreach($infrastructure as $i => $configuredDevice){
            if ($configuredDevice['id'] == $device['id']){
                $infrastructure[$i]['up'] = $device['up'];
                $infrastructure[$i]['down'] = $device['down'];
                $infrastructure[$i]['my'] = $device['my'];
                $stateData['devices'][$i]['name'] = $device['name'];
                $stateData['devices'][$i]['id'] = $device['id'];
            }
        }
    }

    // Set device varname states
    $eligibleStates = ['up', 'my', 'down'];
    foreach($infrastructure as $device){
        foreach($eligibleStates as $eligibleState){
            ${$device['varname'].'_'.$eligibleState} = $device[$eligibleState]; 
        }
    }

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
            list($moved, $executed) = getRuleCounters($stateData['devices'][$i], $condition, $today);

            // Create expression of condition
            $expresssion = getSanitizedExpression($condition);

            printValueIfOnDebug("Condition to test for '{$device['name']}' ({$device['varname']}) to set action '{$action}': ".$condition);

            // Execute expression
            try {
                $conditionEvaluationResult = @eval($expresssion);
            }  catch (Throwable $t) {
                echo $expresssion;
                printError("Invalid condition ('".$condition."') in rule for device '{$device['name']}' ({$device['varname']})");
                continue;
            }

            printValueIfOnDebug("- Result: ".($conditionEvaluationResult ? 'true' : 'false'));

            // Condition is not met
            if (!$conditionEvaluationResult){
                continue;
            }

            if (($action == 'up' && $device['up']) || ($action == 'down' && $device['down'])){
                printValueIfOnDebug("Ignoring action '{$action}' for '{$device['name']}' ({$device['varname']}) because no change needed");
            } else if (EXECUTE){

                // Execute command
                $result = sendCommand($device['id'], $action);
                printValueIfOnDebug($result);

                echo date('Y-m-d H:i:s')." Executed action '{$action}' for blind '{$device['name']}' ({$device['varname']}) due to rule: $condition\n";

                // Wait for some time before issueing next command
                // Not sure if this is needed
                usleep(500000);

                // Clean old state of past days
                if (!isset($stateData['devices'][$i]['executedRules'][$today])){
                    $stateData['devices'][$i]['executedRules'] = [];
                }

                // Increase counter of rules executed
                if (isset($stateData['devices'][$i]['executedRules'][$today][$condition])){
                    $stateData['devices'][$i]['executedRules'][$today][$condition]++;
                } else {
                    $stateData['devices'][$i]['executedRules'][$today][$condition] = 1;
                }
            } else {
                echo date('Y-m-d H:i:s')." Dry Run: Would execute action '{$action}' for blind '{$device['name']}' ({$device['varname']}) due to rule: $condition\n";
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
        $device['deviceNumber'] = getDeviceNumber($result[$i]->deviceURL) ?? null;
		$device['name'] = $result[$i]->label;
		$device['id'] = $result[$i]->deviceURL;		
		$device['type'] = $result[$i]->definition->widgetName;	
        $device['positionPercentage'] = null;
        $device['tiltPercentage'] = null;
        $device['myPositionPercentage'] = null;
        $device['myTiltPercentage'] = null;

		foreach ($result[$i]->states as $state){
            // Set position in percent
			if ($state->name == 'core:ClosureState' || $state->name == 'core:DeploymentState'){
				$device['positionPercentage'] = $state->value;
			}

            // Set tilt 
            if ($state->name == 'core:SlateOrientationState'){
                $device['tiltPercentage'] = $state->value;
            }

            // Set my position
            if ($state->name == 'core:Memorized1PositionState'){
                $device['myPositionPercentage'] = $state->value;
            }

            // Set my tilt position
            if ($state->name == 'core:Memorized1OrientationState'){
                $device['myTiltPercentage'] = $state->value;
            }
        }

        // Set states
        $device['up'] = ($device['positionPercentage'] < 3);
        $device['down'] = ($device['positionPercentage'] > 95 && ($device['tiltPercentage'] == null || $device['tiltPercentage'] > 50));
        $device['my'] = ($device['positionPercentage'] == $device['myPositionPercentage'] && $device['tiltPercentage'] == $device['myTiltPercentage']);

        // Add to device list
        $devices[] = $device;
	}

	return($devices);
}

/**
 * Send a command with optional parameters to device with given deviceID
 * @param string $deviceID
 * @param string $command
 * @param string $parameter1
 * @param string $parameter2
 * @return string 
 */
function sendCommand($deviceID, $command, $parameter1 = '', $parameter2 = ''){
	$requestURL = TAHOMA_BASE_URL.'/enduser-mobile-web/1/enduserAPI/exec/apply';

    // Set command name
    $commandObj = ['name' => $command];

    // Set parameters
    if($parameter1 !== '' && $parameter2 !== ''){
        $commandObj['parameters'] = [$parameter1, $parameter2];
    } else if($parameter1 !== ''){
        $commandObj['parameters'] = [$parameter1];
    }

    // Compose object as associative array
    $request = [
        'label' => 'myAction',
        'actions' => [
            [
                'commands' => [
                    $commandObj
                ],
                'deviceURL' => $deviceID,
            ],
        ],
    ];

    // Convert to JSON
    $httpPayload = json_encode($request, JSON_PRETTY_PRINT | JSON_NUMERIC_CHECK);
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
 * Return temperature, rain, global radiation, sunshine, wind, gust as numbers.
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
                filter_var($components[4], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
                filter_var($components[9], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
                filter_var($components[10], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION)
            ];
        }

    }

    // Return default values
    return [0, 0, 0, 0, 0, 0];
}

/**
 * Show help information and prints configured device list
 */
function  showHelp(){
    $deviceList = '';
    foreach(TAHOMA_DEVICES as $i => $info){
        $deviceList .= $i.' '.$info['name']." (".$info['varname'].")\n";
    }

    echo <<<MAN
Description:
Controls blinds manually or automatically and retrieve information from Tahoma about blinds.

Options:
-h                  Show help page
-c <file>           Path to config file. Default is config.php in same directory
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

--sunshine <float>
Set sunshine duration in minutes within last 10 min period instead of reading it from weather data

--hour <int>
Set current hour of today

--minute <int>
Set current minute of today

--execute
Actually move blinds. If this parameter is not set all actions
are executed only in dry-run mode that does not move any blinds.

--identify <deviceNumber>
Identify given device (i.e. make it wink)

--name <deviceNumber> --value <string> 
Set name for device. Apparently limit is 17 characters

--command <string> --device <deviceNumber>  [--parameter1 <value> [--parameter2 <value>]]
Send an execute command directly for a device. 

###########################################
#### BE CAREFUL. USE AT YOUR OWN RISK! ####
###########################################

Device Numbers:
{$deviceList}

Usage:
php controlBlinds.php -h
Show help

php controlBlinds.php -i [--debug]
Get information about all devices

php controlBlinds.php -d|-u|-m <device number> [--debug]
Control blinds manually

php controlBlinds.php --identify <device number> [--debug]
Identify device by sending it a command to wink

php controlBlinds.php --name <device number> --value <string> [--debug]
Set name for device

php controlBlinds.php \
    [--temperature <float>] [--wind <float>] [--gust <float>] [--rain <float>] \
    [--radiation <float>] [--sunshine <float>] [--temperature <float>] \
    [--hour <int>]  [--hour <int>] [--execute] [--debug]
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
    $components = preg_split('/\s*('.$operators.'|\-?[\d+\.])\s*/', $condition, 0, PREG_SPLIT_NO_EMPTY);

    // Set allowed variables
    $allowedVariables = getAllowedVariables();

    // Check all condition components
    foreach ($components as $component){

        // Check if all components are allowed variables
        if (!in_array(trim($component), $allowedVariables)){
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
 * Prints an error
 * 
 * @param string $message
 */
function printError($message){
    echo '###ERROR: '.date('d. m. Y H:i:s').' - '.$message."\n";
}

/**
 * Prints an error and exits
 * 
 * @param string $message
 */
function printErrorAndExit($message){
    printError($message);
    exit(1);
}

/**
 * Return array of allowed variables
 * 
 * @return string[]
 */
function getAllowedVariables(){
    $allowedVariables = [];

    // Add infrastructure variables
    foreach(TAHOMA_DEVICES as $i => $info){
        $allowedVariables[] = $info['varname'].'_up';
        $allowedVariables[] = $info['varname'].'_my';
        $allowedVariables[] = $info['varname'].'_down';
    }
    
    // Add other variables
    $allowedVariables[] = 'averagetemp';
    $allowedVariables[] = 'averagerad';
    $allowedVariables[] = 'averagesun';
    $allowedVariables[] = 'hour';
    $allowedVariables[] = 'minute';
    $allowedVariables[] = 'day';
    $allowedVariables[] = 'month';
    $allowedVariables[] = 'week';
    $allowedVariables[] = 'yearday';
    $allowedVariables[] = 'today';
    $allowedVariables[] = 'temperature';
    $allowedVariables[] = 'wind';
    $allowedVariables[] = 'gust';
    $allowedVariables[] = 'radiation';
    $allowedVariables[] = 'sunshine';
    $allowedVariables[] = 'rain';
    $allowedVariables[] = 'sunset';
    $allowedVariables[] = 'sunrise';
    $allowedVariables[] = 'moved';
    $allowedVariables[] = 'executed';
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
    $variables = getAllowedVariables();
    foreach ($variables as $variable){
        $expression = preg_replace('/(^|[^_])'.$variable.'/', '$1\$'.$variable.'', $expression);
    }

    return 'return ('.$expression.');';
}

/**
 * Stores temperature and radiation weather data for past few days 
 * in a rolling array to calculate average.
 * 
 * @param array $stateData
 * @param float $temperature
 * @param float $radiation
 * @param float $sunshine
 */
function addWeatherValues(&$stateData, $temperature, $radiation, $sunshine){

    // Abort if values are not numbers
    if (!is_numeric($temperature) || !is_numeric($radiation) || !is_numeric($sunshine)){
        return;
    }

    // Add current values for current hour if no value exists yet
    $timestamp = date('YmdH');
    if (!isset($stateData['weather'][$timestamp])){
       $stateData['weather'][$timestamp] = [$temperature, $radiation, $sunshine];
    }

    // Remove elements older than 3 days
    foreach ($stateData['weather'] as $timestamp => $data){
        if ($timestamp < date('YmdH', time() - 3*86400)){
           unset($stateData['weather'][$timestamp]);
        }
    }
}

/**
 * Retrives average weather values of last hours 
 * 
 * @param array $stateData
 * @param int $hours
 * @return array
 */
function getWheaterAverage($stateData, $hours = 72){
    $sumTemperperature = 0;
    $sumRadiation = 0;
    $sumSunshine = 0;
    $temperatureValues = [];
    $radiationValues = [];
    $sunshineValues = [];

    foreach ($stateData['weather'] as $timestamp => $data){
        if ($timestamp < date('YmdH', time() - $hours*3600)){
            continue;
        }

        list($temperature, $radiation, $sunshine) = $data;

        $sumTemperperature += $temperature;
        $sumRadiation += $radiation;
        $sumSunshine += $sunshine;
        $temperatureValues[] = $temperature;
        $radiationValues[] = $radiation;
        $sunshineValues[] = $sunshine;
    
    }

    return [
        round($sumTemperperature/count($temperatureValues),1), 
        round($sumRadiation/count($radiationValues),1),
        round($sumSunshine/count($sunshineValues),1)
    ];

}

/**
 * Print weather data
 * 
 * @param float $temperature
 * @param float $rain
 * @param float $radiation
 * @param float $sunshine
 * @param float $wind
 * @param float $gust
 * @param int $hour
 * @param int $minute
 */
function getWeatherDataInfo($temperature, $averagetemp, $rain, $radiation, $averagerad, $sunshine, $averagesun, $wind, $gust, $hour, $minute){
    $txt = "Time: $hour:$minute\n";
    $txt .= "Current weather data:\n";
    $txt .=  "- Temperature: $temperature °C\n";
    $txt .=  "- 3-day average temperature: $averagetemp °C\n";
    $txt .=  "- Rainfall: $rain mm/10min\n";
    $txt .=  "- Global sun radiation: $radiation W/m²\n";
    $txt .=  "- 3-day average  global sun radiation: $averagerad W/m²\n";
    $txt .=  "- Sunshine: $sunshine min duration within last 10min\n";
    $txt .=  "- 3-day average sunshine: $averagesun min duration within last 10min\n";
    $txt .=  "- Wind speed: $wind km/h\n";
    $txt .=  "- Gust speed: $gust km/h\n";
    return $txt;
}


/**
 * Return counter values for moved and executed
 * 
 * @param array State data
 * @param string $currentCondition Current rule that is processed
 * @param string $date YYYYMMDD current 
 * @return array
 */
function getRuleCounters($stateData, $currentCondition, $date){

    // Set defaults
    $moved = 0;
    $executed = 0;

    // Return default value if no state data exists
    if (!$stateData || empty($stateData)){
        return [$moved, $executed];
    }

    // Return default value if no state data exists for given date
    if (!isset($stateData['executedRules'][$date])){
        return [$moved, $executed];
    }

    $executedRulesCounters = $stateData['executedRules'][$date];
    foreach ($executedRulesCounters as $condition => $counter){
        // Add all moves
        $moved += $counter;

        // Get counter of current condition
        if ($condition == $currentCondition){
            $executed = $counter;
        }

    }

    return [$moved, $executed];
}

/**
 * Find device number
 * @param string $deviceURL
 * @return int
 */
function getDeviceNumber($deviceURL){
    foreach(TAHOMA_DEVICES as $k => $info){
        if ($deviceURL == $info['id']){
            return $k;
        }
    }
}

/**
 * Returns state data
 * 
 * @return array
 */
function getStateData(){
    if (is_file(CACHE_DIR.'/sotaco-state-data.json')){
        $stateDataJSON = file_get_contents(CACHE_DIR.'/sotaco-state-data.json');
        $stateData = json_decode($stateDataJSON, true);
    } else {
        $stateData = [];
    }

    return $stateData;
}

/**
 * Check configuration to see if it is valid
 */
function checkConfig(){
    $requiredKeys = ['name', 'id', 'rules', 'varname'];
    $optionalKeys = [];
    $entry = 1;
    foreach(TAHOMA_DEVICES as $k => $info){
        foreach ($requiredKeys as $requiredKey){
            if (!isset($info[$requiredKey])){
                printErrorAndExit('Configuration check failed: Missing '.$requiredKey.' in '.$entry.'. configuration entry');
            }

            if ($requiredKey == 'varname' && !preg_match('/^[0-9a-zA-Z]+$/', $info[$requiredKey])){
                printErrorAndExit('Configuration check failed: Entry '.$requiredKey.' must be a string only containing letters and numbers for entry with name '.$info['name']);
            }

            if ($requiredKey == 'rules' && !is_array($info[$requiredKey])){
                printErrorAndExit('Configuration check failed: Entry '.$requiredKey.' must be an array for entry with name '.$info['name']);
            }
        }

        foreach ($optionalKeys as $optionalKey){

        }
        $entry++;
    }
}