<?php
header('Content-type: text/plain');
require_once 'config.inc.php';

ob_start();
function handle_error($errno, $errstr, $errfile, $errline, $errcontext)
{
	global $email;
	$string = ob_get_contents();
	$string .= "\n\n";
	$string .= $errstr . ' in ' . $errfile . ':' . $errline;
	$string .= "\n\n";
	if (isset($_SERVER['HTTPS']))
		$string .= 'https://';
	else
		$string .= 'http://';
	$string .= $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
	mail($email, $errstr, $string);
	
	die($errstr);
}
set_error_handler("handle_error");
function handle_exception($exception)
{
	handle_error(0, $exception->getMessage(), $exception->getFile(), $exception->getLine(), NULL);
}
set_exception_handler("handle_exception");

$hostname = $_GET['name']; // ${DS_HOSTNAME}
$mac = $_GET['mac']; // ${DS_PRIMARY_MAC_ADDRESS}
$domainname = $_GET['domain']; // ${DS_ASSIGNED_DOMAIN}
$ip6addr = $_GET['ip6'];

if ($hostname == '')
	$hostname = str_replace(':', '', $hostname);

// get $ipaddr
$iplist1 = file('ip.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$iplist = array();
foreach ($iplist1 as $line)
{
	$line = explode(';', $line);
	$iplist[$line[0]] = $line[1];
}
if (!isset($iplist[$hostname]))
{
	if (!defined('allow_v6_only') || !allow_v6_only)
		trigger_error("No IP in list for $hostname. Aborting.", E_USER_ERROR);
	else
		echo "No IP in list for " . $hostname . ". Proceeding v6-only.\n";
	$ipaddr = NULL;
}
else
{
	$ipaddr = $iplist[$hostname];
}

// fill up $ip6addr
$colons = substr_count($ip6addr, ':');
if ($colons <= 7)
	$ip6addr = str_replace('::', str_repeat(':0', 8-$colons) . ':', $ip6addr);
$prefix = implode(':', array_slice(explode(':', $ip6addr), 0,4));

function mac2slaac($mac, $prefix)
{
	$mac = str_replace('-', ':', $mac);
	$mac = explode(':', $mac);
	return strtoupper($prefix . ':' . str_pad(dechex(hexdec($mac[0]) ^2),2,'0',STR_PAD_LEFT) . str_pad($mac[1],2,'0',STR_PAD_LEFT) . ':' . str_pad($mac[2],2,'0',STR_PAD_LEFT) . 'ff:fe' . str_pad($mac[3],2,'0',STR_PAD_LEFT) . ':' . str_pad($mac[4],2,'0',STR_PAD_LEFT) . str_pad($mac[5],2,'0',STR_PAD_LEFT));
}

function prop2array($properties)
{
	$properties = explode('|', $properties);
	$result = array();
	foreach ($properties as $prop)
	{
		if (trim($prop) == '')
			continue;
		$prop = explode('=', $prop);
		$result[$prop[0]] = $prop[1];
	}
	return $result;
}

$ip6addr = mac2slaac($mac, $prefix);

error_reporting(E_ALL);
ini_set('display_errors', 1);

// set up SOAP client
$client = new SoapClient(ipam_server . '/Services/API?wsdl');
$client->__setLocation(ipam_server . '/Services/API');

// load cookie if we have one
if (file_exists('/tmp/ipamcookie.txt'))
{
	foreach (unserialize(file_get_contents('/tmp/ipamcookie.txt')) as $cookiename => $cookieval)
		$client->__setCookie($cookiename, $cookieval[0]);
}

// see if we're authenticated
try
{
	$info = prop2array($client->getSystemInfo());
}
// authenticate
catch (SoapFault $e)
{
	if ($e->faultstring == "Not logged in")
	{
		echo "Logging in\n";
		$client->login(ipam_username, ipam_password);
		file_put_contents('/tmp/ipamcookie.txt', serialize($client->_cookies));
		$info = prop2array($client->getSystemInfo());
	}
	else
		throw $e;
}

// get Configuration ID
$configuration = $client->getEntityByName(0, ipam_configuration, 'Configuration');
if ($configuration === NULL)
	die('Could not find Configuration');
$configurationId = $configuration->id;

// get DNS View ID
$view = $client->getEntityByName($configurationId, ipam_viewName, 'View');
if ($view === NULL)
	die('Could not find View');
$viewId = $view->id;

// loop through zones to get zone ID
$zoneId = $viewId;
foreach (array_reverse(explode('.', $domainname)) as $d)
{
	$zone = $client->getEntityByName($zoneId, $d, 'Zone');
	if ($zone === NULL)
		die("Could not find zone");
	$zoneId = $zone->id;
}

// get IPv6 network ID
$network6 = $client->getEntityByPrefix($configurationId, $prefix . '::/64', 'IP6Network');
$network6Id = $network6->id;

// get all existing records for this MAC
/*
$macObj = $client->getMACAddress($configurationId, $mac);
if ($macObj != NULL)
{
	var_dump($macObj);
	$macId = $macObj->id;
	$macLinkedObj = $client->getLinkedEntities($macId, 'HostRecord',0,20);
	print_r($macLinkedObj);
}
*/

// delete address if exists
if ($ipaddr !== NULL)
{
	$oldIP = $client->getIP4Address($configurationId, $ipaddr);
	if ($oldIP !== NULL && $oldIP->id != 0)
	{
		echo "Deleting address\n";
		$client->delete($oldIP->id);
	}
}

// delete IP6 address if exists
$oldIP6 = $client->getIP6Address($configurationId, $ip6addr);
if ($oldIP6 !== NULL && $oldIP6->id != 0)
{
	echo "Deleting IP6 address\n";
	$client->delete($oldIP6->id);
}

// delete host record if exists
$oldA = $client->getEntityByName($zoneId, $hostname, 'HostRecord');
if ($oldA !== NULL && $oldA->id != 0)
{
	echo "Deleting host\n";
	$client->delete($oldA->id);
}

// create address
if ($ipaddr !== NULL)
{
	echo "Creating new address\n";
	$newIP = $client->assignIP4Address($configurationId, $ipaddr, $mac, '', 'MAKE_DHCP_RESERVED', '');
}

// create IP6 address
echo "Creating new IP6 address\n";
$address6Id = $client->addIP6Address($network6Id, $mac, 'macAddress', '', '');
$ip6addrEntity = $client->getEntityById($address6Id);
$ip6rec = prop2array($ip6addrEntity->properties);
$ip6addr = $ip6rec['address'];
if (version_compare($info['version'], '4.0') < 0)
	$client->assignIP6Address($network6Id, $address6Id, 'MAKE_STATIC', $mac, '', '');
else // Proteus 4.0 wants us to specify the IPv6address, not its ID
	$client->assignIP6Address($network6Id, $ip6addr, 'MAKE_STATIC', $mac, '', '');

// create host record
if ($ipaddr !== NULL)
	$hostrec = $ipaddr . ',' . $ip6addr;
else
	$hostrec = $ip6addr;
echo "Creating new host record: $hostrec\n";
$newA = $client->addHostRecord($viewId, $hostname . '.' . $domainname, $hostrec, -1, '');
?>
