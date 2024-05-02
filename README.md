SoTaCo
======
Author: Lukas Hämmerle <lukas@haemmerle.net>

Description
-------------
SoTaCo stands for "Somfy Tahoma Controller". It's a simple PHP application to send instructions to the Tahoma API.
The instructions can open or close blinds connected to the Tahoma device. SoTaCo allows defining fine-grained rules for specific weather scenarios. The main purpose to create this application was to implement automatic shading and some security features to protect the blinds in case of rain and strong winds without any (expensive) extra sensors. Instead the application relies on already existing weather data (currently Meteo Schweiz in Switzerland). 
This application should be run locally in your own network so it can connect to the API of Tahoma device, which is available only locally. Also, this code does not rely on any extra cloud service and it does not cost a monthly fee like IFTT or similar.

The weather data currently comes from Meteo Schweiz (https://www.meteoschweiz.admin.ch) via https://data.geo.admin.ch.
However, in future version retrieving data might be configurable more generic.

Disclaimer
----------
This code is provided "as-is" and without guaranteed support. Use this code at your own risk. The author takes no responsibility for any damages caused to your blinds that are in any way related to running this code.
Using weather data from a weather station might be less accurate than using a sensor close to the blinds. After all it could rain at your place while the weather station does not detect any rain.
Take this into account.


Prerequisites
-------------
* Somfy Tahoma device
* Developer mode must be enabled
* API Token to query the Tahoma API must be available

For instructions how to enable the developer mode and get a token, please read https://github.com/Somfy-Developer/Somfy-TaHoma-Developer-Mode


Requirements
------------
* PHP 7.2 with JSON and Curl modules
  These modules should be part of most PHP distributions out of the box.
  People running this on OpenWRT (https://openwrt.org/) install the packages: 
  - php7-cli 
  - php7-mod-json 
  - php7-mod-curl
  - zoneinfo-europe (for Europe)
* Cron or any service that allows executing the control.php periodically

Installation
------------
1. Copy the SoCaTo directory to some server that runs 24/7 and that meets the requirements above.
2. Create a copy of `config.dist.php` and name it `config.php`
3. Adapt config.php to suit reflect the environment that you want to control.
4. Install a cronjob like the following or otherwise ensure that the script executes periodically:
  ```
  */10 * * * * /path/to/php-cli /path/to/sotacol/control.php --execute
  ```
 5. Run `php-cli control.php -h` to see the help page to manually execute commands for testing and debugging.
