<?php

# Copyright (c) 2006,2010 Chris Kuethe <chris.kuethe@gmail.com>
# Modifed for CVP by Callum Walker (02/04/2016)
#
# Permission to use, copy, modify, and distribute this software for any
# purpose with or without fee is hereby granted, provided that the above
# copyright notice and this permission notice appear in all copies.
#
# THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
# WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
# MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
# ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
# WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
# ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
# OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.

global $head, $blurb, $title, $showmap, $autorefresh, $footer, $gmap_key;
global $server, $advertise, $port, $open, $swap_ew, $testmode;
$testmode = 1; # leave this set to 1

# Public script parameters:
#   host: host name or address where GPSd runs. Default: from config file
#   port: port of GPSd. Default: from config file
#   op=view: show just the skyview image instead of the whole HTML page
#     sz=small: used with op=view, display a small (240x240px) skyview
#   op=json: respond with the GPSd POLL JSON structure
#     jsonp=prefix: used with op=json, wrap the POLL JSON in parentheses
#                   and prepend prefix

# If you're running PHP with the Suhosin patch (like the Debian PHP5 package),
# it may be necessary to increase the value of the
# suhosin.get.max_value_length parameter to 2048. The imgdata parameter used
# for displaying the skyview is longer than the default 512 allowed by Suhosin.
# Debian has the config file at /etc/php5/conf.d/suhosin.ini.

# this script shouldn't take more than a few seconds to run
set_time_limit(3);
ini_set('max_execution_time', 3);

if (!file_exists("gpsd_config.inc"))
	write_config();

require_once("gpsd_config.inc");

# sample data
$resp = <<<EOF
{"class":"POLL","time":"2010-04-05T21:27:54.84Z","active":1,
 "tpv":[{"class":"TPV","tag":"MID41","device":"/dev/ttyUSB0",
           "time":1270517264.240,"ept":0.005,"lat":40.035093060,
           "lon":-75.519748733,"alt":31.1,"track":99.4319,
           "speed":0.123,"mode":3}],
 "sky":[{"class":"SKY","tag":"MID41","device":"/dev/ttyUSB0",
              "time":"2010-04-05T21:27:44.84Z","hdop":9.20,"vdop":12.1,
              "satellites":[{"PRN":16,"el":55,"az":42,"ss":36,"used":true},
                            {"PRN":19,"el":25,"az":177,"ss":0,"used":false},
                            {"PRN":7,"el":13,"az":295,"ss":0,"used":false},
                            {"PRN":6,"el":56,"az":135,"ss":32,"used":true},
                            {"PRN":13,"el":47,"az":304,"ss":0,"used":false},
                            {"PRN":23,"el":66,"az":259,"ss":40,"used":true},
                            {"PRN":20,"el":7,"az":226,"ss":0,"used":false},
                            {"PRN":3,"el":52,"az":163,"ss":32,"used":true},
                            {"PRN":31,"el":16,"az":102,"ss":0,"used":false}
                           ]
             }
            ]
}
EOF;



# if we're passing in a query, let's unpack and use it
$op = isset($_GET['op']) ? $_GET['op'] : '';
if (isset($_GET['imgdata']) && $op == 'view'){
	$resp = base64_decode($_GET['imgdata']);
	if ($resp){	
		exit(0);
	}
} else {
	if (isset($_GET['host']))
		if (!preg_match('/[^a-zA-Z0-9\.-]/', $_GET['host']))
			$server = $_GET['host'];
	if (isset($_GET['port']))
		if (!preg_match('/\D/', $_GET['port']) && ($port>0) && ($port<65536))
			$port = $_GET['port'];

	if ($testmode){
		$sock = @fsockopen($server, $port, $errno, $errstr, 2);
		@fwrite($sock, "?WATCH={\"enable\":true}\n");
		usleep(100);
		@fwrite($sock, "?POLL;\n");
		for($tries = 0; $tries < 10; $tries++){
			$resp = @fread($sock, 2000); # SKY can be pretty big
			if (preg_match('/{"class":"POLL".+}/i', $resp, $m)){
				$resp = $m[0];
				break;
			}
		}
		@fclose($sock);
		if (!$resp)
			$resp = '{"class":"ERROR","message":"no response from GPS daemon"}';
	}
}

if ($op == 'view')
	gen_image($resp);
else if ($op == 'json')
	write_json($resp);
else
	write_html($resp);

exit(0);

###########################################################################

function write_html($resp){
	global $sock, $errstr, $errno, $server, $port, $head, $body, $open;
	global $blurb, $title, $autorefresh, $showmap, $gmap_key, $footer;
	global $testmode, $advertise;

	$GPS = json_decode($resp, true);
	if ($GPS['class'] != 'POLL'){
		die("json_decode error: $resp");
	}

	header("Content-type: text/html; charset=UTF-8");

	global $lat, $lon;
	$lat = (float)$GPS['tpv'][0]['lat'];
	$lon = (float)$GPS['tpv'][0]['lon'];
	$x = $server; $y = $port;
	$imgdata = base64_encode($resp);
	$server = $x; $port = $y;

	if ($autorefresh > 0)
		$autorefresh = "<meta http-equiv='Refresh' content='$autorefresh'/>";
	else
		$autorefresh = '';

	$map_head = $map_body = $map_code = '';
	if ($showmap == 1) {
		$map_head = gen_gmap_head();
		$map_body = 'onload="Load()" onunload="GUnload()"';
		$map_code = gen_map_code();
	} else if ($showmap == 2) {
		$map_head = gen_osm_head();
		$map_body = 'onload="Load()"';
		$map_code = gen_map_code();
	}
	$part1 = <<<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
{$head}
{$map_head}
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
<meta http-equiv="Content-Language" content="en,en-us"/>
<title>{$title}</title>
{$autorefresh}
<style>
.warning {
    color: #FF0000;
    font-size: large;
	font-family: verdana, sans-serif;
	margin: 1ex 3em 1ex 10em; /* top right bottom left */;
}

.fixed {
    font-family: mono-space;
}

.caption {
    text-align: left;
    margin: 1ex 3em 1ex 3em; /* top right bottom left */
}
.heading {
	text-align: left;
    margin: 1ex 3em 1ex 1em; /* top right bottom left */;
    font-size: large;
	font-family: verdana, sans-serif;
}

.administrivia {
    font-size: small;
    font-family: verdana, sans-serif;
}
</style>
</head>

<body {$body} {$map_body}>
<p class="heading">CVP Truck Tracker</p>
<table border="0">
<tr><td align="justify">
{$blurb}
</td>
EOF;

	if ($testmode && !$sock)
		$part2 = "";
	else
		$part2 = <<<EOF
<!-- ------------------------------------------------------------ -->

</tr>
EOF;

	if (!$open)
		$part3 = '';
	else
		$part3 = <<<EOF
<!-- ------------------------------------------------------------ -->

EOF;
	$fix = $GPS['tpv'][0];
	if ($testmode && !$sock){
		$part4 = "<tr><td class='warning'>The gpsd instance that this page monitors is not running.</td></tr>";}
	elseif ($fix['mode']=='1') {
		$part4 = "<tr><td><p class='warning'>Unable to obtain a GPS Fix!<br />If the TrucksBerry pi was recently turned on, it can take up to 10 minutes to obtain a fix.</P><br /> <br /><i>There should be a coin cell battery in the GPS module to prevent this</i><br />Fix mode: {$fix['mode']}</td></tr>";}
	else {
		$fix = $GPS['tpv'][0];
		$sky = $GPS['sky'][0];
		$sats = $sky['satellites'];
		$nsv = count($sats);
        $ts = $fix['time'];
        $part4 = <<<EOF

<!-- ------------------------------------------------------------ -->
<td rowspan="4" align="center" valign="top">
{$map_code}</td>
<tr><td align=center valign=top>
    <table border=1>
        <tr><th colspan=2 align=center>GPS Information</th></tr>
        <tr><td>Time (UTC)</td><td>{$ts}</td></tr>
        <tr><td>Latitude</td><td>{$fix['lat']}</td></tr>
        <tr><td>Longitude</td><td>{$fix['lon']}</td></tr>
        <tr><td>Altitude</td><td>{$fix['alt']}</td></tr>
        <tr><td>Map with</td><td><a href="https://maps.google.com/maps?ll={$fix['lat']},{$fix['lon']}&q=loc:{$fix['lat']},{$fix['lon']}&hl=en" target="_new">Google</a></td></tr>
        <tr><td>Fix Type</td><td>{$fix['mode']}</td></tr>
        <tr><td>Satellites</td><td>{$nsv}</td></tr>
        <tr><td>HDOP</td><td>{$sky['hdop']}</td></tr>
        <tr><td>VDOP</td><td>{$sky['vdop']}</td></tr>
    </table>
    <br/>
   
</td></tr>

<!-- raw response:
{$resp}
-->
EOF;
	}

	$part5 = <<<EOF

</table>
{$footer}
<hr/>

</body>
</html>
EOF;

print $part1 . $part2 . $part3 . $part4 . $part5;

}

function write_json($resp){
	header('Content-Type: text/javascript');
	if (isset($_GET['jsonp']))
		print "{$_GET['jsonp']}({$resp})";
	else
		print $resp;
}

function write_config(){
	$f = fopen("gpsd_config.inc", "a");
	if (!$f)
		die("can't generate prototype config file. try running this script as root in DOCUMENT_ROOT");

	$buf = <<<EOB
<?PHP
\$title = 'CVP Truck locator';
\$server = 'localhost';
#\$advertise = 'localhost';
\$port = 2947;
\$autorefresh = 0; # number of seconds after which to refresh
\$showmap = 0; # set to 1 if you want to have a google map, set it to 2 if you want a map based on openstreetmap
\$gmap_key = 'GetYourOwnGoogleKey'; # your google API key goes here
\$swap_ew = 0; # set to 1 if you don't understand projections
\$open = 0; # set to 1 to show the form to change the GPSd server

## You can read the header, footer and blurb from a file...
# \$head = file_get_contents('/path/to/header.inc');
# \$body = file_get_contents('/path/to/body.inc');
# \$footer = file_get_contents('/path/to/footer.hinc');
# \$blurb = file_get_contents('/path/to/blurb.inc');

## ... or you can just define them here
\$head = '';
\$body = '';
\$footer = '<P>Created by Callum Walker for CVP</P><P>This page will auto-refresh every 5 seconds</P>';
\$blurb = <<<EOT
This is a
<a href="@WEBSITE@">gpsd</a>
server <blink><font color="red">located someplace</font></blink>.

The hardware is a
<blink><font color="red">hardware description and link</font></blink>.

This machine is maintained by
<a href="mailto:you@example.com">Your Name Goes Here</a>.<br/>
EOT;

?>

EOB;
	fwrite($f, $buf);
	fclose($f);
}

function gen_gmap_head() {
	global $lat, $lon;

	global $gmap_key;
	return <<<EOT
	<script>

      function initMap() {
        var myLatLng = {lat: {$lat}, lng: {$lon}};

        var map = new google.maps.Map(document.getElementById('map'), {
          zoom: 13,
          center: myLatLng
        });

        var marker = new google.maps.Marker({
          position: myLatLng,
          map: map,
          title: 'Truck Location'
        });
      }
    </script>
    	<script async defer
    src="https://maps.googleapis.com/maps/api/js?key={$gmap_key}&callback=initMap">
    </script>
EOT;

}

function gen_map_code() {
return <<<EOT
<div id="map" style="width: 550px; height: 400px; border:1px; border-style: solid;">
    Loading...
    <noscript>
        <span class='warning'>Sorry: you must enable javascript to view our maps.</span><br/>
    </noscript>
</div>

EOT;
}

?>
