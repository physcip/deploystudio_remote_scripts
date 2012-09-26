<?php
	header('Content-type: text/plain');
	require_once 'config.inc.php';
	
	if (!array_key_exists('token', $_GET) || $_GET['token'] != $token)
		die('Invalid token');
	
	function read_yaml($dbConfig)
	{
		foreach ($dbConfig as $line)
		{
			if (substr(ltrim($line),0,1) == '#' || trim($line) == '') // comment or empty line
				continue;
			if (ltrim($line) == $line) // section header
			{
				$section = rtrim($line, ":\r\n\t ");
				continue;
			}
			$line = explode(':', $line);
			$db[$section][trim($line[0])] = trim($line[1]);
		}
		return $db;
	}
	
	// Connect to MunkiServer database
	$db = read_yaml(file($ms_path . '/config/database.yml'));
	$db_conn = mysql_connect($db['production']['host'], $db['production']['username'], $db['production']['password']);
	mysql_select_db($db['production']['database'], $db_conn);
	mysql_set_charset($db['production']['encoding'], $db_conn);
	
	// get MunkiServer groups
	$sql = 'SELECT * FROM computer_groups';
	$result = mysql_query($sql,$db_conn) or die(mysql_error());
	while ($newArray = mysql_fetch_array($result, MYSQL_ASSOC))
	{
		$munki_groups[$newArray['name']] = $newArray;
	}
	
	// Variables from DeployStudio
	$mac = $_GET['mac']; // ${DS_PRIMARY_MAC_ADDRESS}
	$domain = $_GET['domain']; // ${DS_ASSIGNED_DOMAIN}
	$name = $_GET['name']; // ${DS_HOSTNAME}
	//$fullname = $_GET['fullname']; // ${DS_COMPUTERNAME}
	$group = $_GET['group']; // ${DS_COMPUTER_GROUP}
	
	if (array_key_exists($group, $munki_groups))
	{
		$group_id = $munki_groups[$group]['id'];
		$unit_id = $munki_groups[$group]['unit_id'];
		$environment_id = $munki_groups[$group]['environment_id'];
	}
	else
	{
		mail($email, 'Group does not exist in MunkiServer', print_r($_GET, TRUE));
		die('Group does not exist in MunkiServer');
	}
	
	$sqls[] = 'DELETE FROM computers WHERE mac_address = "' . mysql_real_escape_string($mac) . '" OR hostname = "' . mysql_real_escape_string($name . '.' . $domain) . '"';
	
	$sqls[] = 'INSERT INTO computers (mac_address, name, computer_model_id, computer_group_id, unit_id, environment_id, created_at, updated_at, hostname, shortname) VALUES ("' . mysql_real_escape_string($mac) . '", "' . mysql_real_escape_string($name) . '", 16, "' . $group_id . '", "' . $unit_id . '", "' . $environment_id . '", NOW(), NOW(), "' . mysql_real_escape_string($name . '.' . $domain) . '", LOWER("' . mysql_real_escape_string($name) . '"))';
	
	//foreach ($sqls as $sql)
	//	mysql_query($sql, $db_conn);
	
	mail($email, 'Register in MunkiServer', print_r($sqls, TRUE));
?>
