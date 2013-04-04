<?php
	header('Content-type: text/plain');
	require_once 'config.inc.php';
	
	ob_start();
	function handle_error($errno, $errstr, $errfile, $errline, $errcontext)
	{
		global $email;
		$string = ob_get_contents();
		$string .= "\n\n";
		$string .= $errstr;
		$string .= "\n\n";
		$string .= print_r($_GET, TRUE);
		mail($email, $errstr, $string);
		
		die($errstr);
	}
	set_error_handler("handle_error");
	
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
	$fullname = $_GET['computername']; // ${DS_COMPUTERNAME}
	$group = $_GET['group']; // ${DS_COMPUTER_GROUP}
	
	if (array_key_exists($group, $munki_groups))
	{
		$group_id = $munki_groups[$group]['id'];
		$unit_id = $munki_groups[$group]['unit_id'];
		$environment_id = $munki_groups[$group]['environment_id'];
		
		$sql = 'SELECT * FROM environments WHERE id=' . $environment_id;
		$result = mysql_query($sql,$db_conn) or die(mysql_error());
		$newArray = mysql_fetch_array($result, MYSQL_ASSOC);
		$environment = $newArray['name'];
		
		$sql = 'SELECT * FROM units WHERE id=' . $unit_id;
		$result = mysql_query($sql,$db_conn) or die(mysql_error());
		$newArray = mysql_fetch_array($result, MYSQL_ASSOC);
		$unit = $newArray['name'];
		
		$sql = 'SELECT * FROM computer_groups WHERE id=' . $group_id;
		$result = mysql_query($sql,$db_conn) or die(mysql_error());
		$newArray = mysql_fetch_array($result, MYSQL_ASSOC);
		$group = $newArray['name'];
	}
	else
	{
		trigger_error('Group does not exist in MunkiServer', E_USER_ERROR);
	}
	
	// switch to MunkiServer directory
	chdir($ms_path);
	
	// delete old computer
	system($rake . sprintf(' computers:delete[%s,%s] 2>&1', escapeshellarg($name . '.' . $domain), escapeshellarg($mac)));
	
	// add new computer
	system($rake . sprintf(' computers:add[%s,%s,%s,%s,%s,%s] 2>&1', escapeshellarg($fullname), escapeshellarg($name . '.' .  $domain), escapeshellarg($mac), escapeshellarg($unit), escapeshellarg($environment), escapeshellarg($group)));
	
	$string = ob_get_contents();
	$string .= "\n\n";
	$string .= print_r($_GET, TRUE);
	mail($email, "Added $name to MunkiServer", $string);
?>
