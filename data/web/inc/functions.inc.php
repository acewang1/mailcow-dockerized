<?php
function hash_password($password) {
	$salt_str = bin2hex(openssl_random_pseudo_bytes(8));
	return "{SSHA256}".base64_encode(hash('sha256', $password . $salt_str, true) . $salt_str);
}
function hasDomainAccess($username, $role, $domain) {
	global $pdo;
	if (!filter_var($username, FILTER_VALIDATE_EMAIL) && !ctype_alnum(str_replace(array('_', '.', '-'), '', $username))) {
		return false;
	}
	if (empty($domain) || !is_valid_domain_name($domain)) {
		return false;
	}
	if ($role != 'admin' && $role != 'domainadmin' && $role != 'user') {
		return false;
	}
	try {
		$stmt = $pdo->prepare("SELECT `domain` FROM `domain_admins`
		WHERE (
			`active`='1'
			AND `username` = :username
			AND (`domain` = :domain1 OR `domain` = (SELECT `target_domain` FROM `alias_domain` WHERE `alias_domain` = :domain2))
		)
    OR 'admin' = :role");
		$stmt->execute(array(':username' => $username, ':domain1' => $domain, ':domain2' => $domain, ':role' => $role));
		$num_results = count($stmt->fetchAll(PDO::FETCH_ASSOC));
	}
  catch(PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
    return false;
  }
	if (!empty($num_results)) {
		return true;
	}
	return false;
}
function hasMailboxObjectAccess($username, $role, $object) {
	global $pdo;
	if (!filter_var($username, FILTER_VALIDATE_EMAIL) && !ctype_alnum(str_replace(array('_', '.', '-'), '', $username))) {
		return false;
	}
	if ($role != 'admin' && $role != 'domainadmin' && $role != 'user') {
		return false;
	}
	if ($username == $object) {
		return true;
	}
	try {
		$stmt = $pdo->prepare("SELECT `domain` FROM `mailbox` WHERE `username` = :object");
		$stmt->execute(array(':object' => $object));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (isset($row['domain']) && hasDomainAccess($username, $role, $row['domain'])) {
      return true;
    }
	}
  catch(PDOException $e) {
		error_log($e);
		return false;
	}
	return false;
}
function verify_ssha256($hash, $password) {
	// Remove tag if any
	$hash = ltrim($hash, '{SSHA256}');
	// Decode hash
	$dhash = base64_decode($hash);
	// Get first 32 bytes of binary which equals a SHA256 hash
	$ohash = substr($dhash, 0, 32);
	// Remove SHA256 hash from decoded hash to get original salt string
	$osalt = str_replace($ohash, '', $dhash);
	// Check single salted SHA256 hash against extracted hash
	if (hash('sha256', $password . $osalt, true) == $ohash) {
		return true;
	}
	else {
		return false;
	}
}
function doveadm_authenticate($hash, $algorithm, $password) {
	$descr = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
	$pipes = array();
	$process = proc_open("/usr/bin/doveadm pw -s ".$algorithm." -t '".$hash."'", $descr, $pipes);
	if (is_resource($process)) {
		fputs($pipes[0], $password);
		fclose($pipes[0]);
		while ($f = fgets($pipes[1])) {
			if (preg_match('/(verified)/', $f)) {
				proc_close($process);
				return true;
			}
			return false;
		}
		fclose($pipes[1]);
		while ($f = fgets($pipes[2])) {
			proc_close($process);
			return false;
		}
		fclose($pipes[2]);
		proc_close($process);
	}
	return false;
}
function check_login($user, $pass) {
	global $pdo;
	if (!filter_var($user, FILTER_VALIDATE_EMAIL) && !ctype_alnum(str_replace(array('_', '.', '-'), '', $user))) {
		return false;
	}
	$user = strtolower(trim($user));
	$stmt = $pdo->prepare("SELECT `password` FROM `admin`
			WHERE `superadmin` = '1'
			AND `username` = :user");
	$stmt->execute(array(':user' => $user));
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	foreach ($rows as $row) {
		if (verify_ssha256($row['password'], $pass)) {
      if (get_tfa($user)['name'] != "none") {
        $_SESSION['pending_mailcow_cc_username'] = $user;
        $_SESSION['pending_mailcow_cc_role'] = "admin";
        $_SESSION['pending_tfa_method'] = get_tfa($user)['name'];
        unset($_SESSION['ldelay']);
        return "pending";
      }
      else {
        unset($_SESSION['ldelay']);
        return "admin";
      }
		}
	}
	$stmt = $pdo->prepare("SELECT `password` FROM `admin`
			WHERE `superadmin` = '0'
			AND `active`='1'
			AND `username` = :user");
	$stmt->execute(array(':user' => $user));
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	foreach ($rows as $row) {
		if (verify_ssha256($row['password'], $pass) !== false) {
      if (get_tfa($user)['name'] != "none") {
        $_SESSION['pending_mailcow_cc_username'] = $user;
        $_SESSION['pending_mailcow_cc_role'] = "domainadmin";
        $_SESSION['pending_tfa_method'] = get_tfa($user)['name'];
        unset($_SESSION['ldelay']);
        return "pending";
      }
      else {
        unset($_SESSION['ldelay']);
        $stmt = $pdo->prepare("UPDATE `tfa` SET `active`='1' WHERE `username` = :user");
        $stmt->execute(array(':user' => $user));
        return "domainadmin";
      }
		}
	}
	$stmt = $pdo->prepare("SELECT `password` FROM `mailbox`
			WHERE `kind` NOT REGEXP 'location|thing|group'
        AND `active`='1'
        AND `username` = :user");
	$stmt->execute(array(':user' => $user));
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	foreach ($rows as $row) {
		if (verify_ssha256($row['password'], $pass) !== false) {
			unset($_SESSION['ldelay']);
			return "user";
		}
	}
	if (!isset($_SESSION['ldelay'])) {
		$_SESSION['ldelay'] = "0";
	}
	elseif (!isset($_SESSION['mailcow_cc_username'])) {
		$_SESSION['ldelay'] = $_SESSION['ldelay']+0.5;
	}
	sleep($_SESSION['ldelay']);
}
function formatBytes($size, $precision = 2) {
	if(!is_numeric($size)) {
		return "0";
	}
	$base = log($size, 1024);
	$suffixes = array(' Byte', ' KiB', ' MiB', ' GiB', ' TiB');
	if ($size == "0") {
		return "0";
	}
	return round(pow(1024, $base - floor($base)), $precision) . $suffixes[floor($base)];
}
function edit_admin_account($postarray) {
	global $lang;
	global $pdo;
	if ($_SESSION['mailcow_cc_role'] != "admin") {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
	$username       = $postarray['admin_user'];
	$username_now   = $_SESSION['mailcow_cc_username'];
  $password       = $postarray['admin_pass'];
  $password2      = $postarray['admin_pass2'];

	if (!ctype_alnum(str_replace(array('_', '.', '-'), '', $username)) || empty ($username)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['username_invalid'])
		);
		return false;
	}

	if (!empty($password) && !empty($password2)) {
    if (!preg_match('/' . $GLOBALS['PASSWD_REGEP'] . '/', $password)) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['password_complexity'])
      );
      return false;
    }
		if ($password != $password2) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['password_mismatch'])
			);
			return false;
		}
		$password_hashed = hash_password($password);
		try {
			$stmt = $pdo->prepare("UPDATE `admin` SET 
				`password` = :password_hashed,
				`username` = :username1
					WHERE `username` = :username2");
			$stmt->execute(array(
				':password_hashed' => $password_hashed,
				':username1' => $username,
				':username2' => $username_now
			));
		}
		catch (PDOException $e) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => 'MySQL: '.$e
			);
			return false;
		}
	}
	else {
		try {
			$stmt = $pdo->prepare("UPDATE `admin` SET 
				`username` = :username1
					WHERE `username` = :username2");
			$stmt->execute(array(
				':username1' => $username,
				':username2' => $username_now
			));
		}
		catch (PDOException $e) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => 'MySQL: '.$e
			);
			return false;
		}
	}
	try {
		$stmt = $pdo->prepare("UPDATE `domain_admins` SET `domain` = 'ALL', `username` = :username1 WHERE `username` = :username2");
		$stmt->execute(array(':username1' => $username, ':username2' => $username_now));
		$stmt = $pdo->prepare("UPDATE `tfa` SET `username` = :username1 WHERE `username` = :username2");
		$stmt->execute(array(':username1' => $username, ':username2' => $username_now));
	}
	catch (PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
  $_SESSION['mailcow_cc_username'] = $username;
	$_SESSION['return'] = array(
		'type' => 'success',
		'msg' => sprintf($lang['success']['admin_modified'])
	);
}
function edit_user_account($postarray) {
	global $lang;
	global $pdo;
  if (isset($postarray['username']) && filter_var($postarray['username'], FILTER_VALIDATE_EMAIL)) {
    if (!hasMailboxObjectAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $postarray['username'])) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
    }
    else {
      $username = $postarray['username'];
    }
  }
  else {
    $username = $_SESSION['mailcow_cc_username'];
  }
	$password_old		= $postarray['user_old_pass'];

	if (isset($postarray['user_new_pass']) && isset($postarray['user_new_pass2'])) {
		$password_new	= $postarray['user_new_pass'];
		$password_new2	= $postarray['user_new_pass2'];
	}

	$stmt = $pdo->prepare("SELECT `password` FROM `mailbox`
			WHERE `kind` NOT REGEXP 'location|thing|group'
        AND `username` = :user");
	$stmt->execute(array(':user' => $username));
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!verify_ssha256($row['password'], $password_old)) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => sprintf($lang['danger']['access_denied'])
    );
    return false;
  }

	if (isset($password_new) && isset($password_new2)) {
		if (!empty($password_new2) && !empty($password_new)) {
			if ($password_new2 != $password_new) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => sprintf($lang['danger']['password_mismatch'])
				);
				return false;
			}
			if (!preg_match('/' . $GLOBALS['PASSWD_REGEP'] . '/', $password_new)) {
					$_SESSION['return'] = array(
						'type' => 'danger',
						'msg' => sprintf($lang['danger']['password_complexity'])
					);
					return false;
			}
			$password_hashed = hash_password($password_new);
			try {
				$stmt = $pdo->prepare("UPDATE `mailbox` SET `password` = :password_hashed WHERE `username` = :username");
				$stmt->execute(array(
					':password_hashed' => $password_hashed,
					':username' => $username
				));
			}
			catch (PDOException $e) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => 'MySQL: '.$e
				);
				return false;
			}
		}
	}
	$_SESSION['return'] = array(
		'type' => 'success',
		'msg' => sprintf($lang['success']['mailbox_modified'], htmlspecialchars($username))
	);
}
function user_get_alias_details($username) {
	global $lang;
	global $pdo;
  if ($_SESSION['mailcow_cc_role'] == "user") {
    $username	= $_SESSION['mailcow_cc_username'];
  }
  if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
    return false;
  }
  try {
    $data['address'] = $username;
    $stmt = $pdo->prepare("SELECT IFNULL(GROUP_CONCAT(`address` SEPARATOR ', '), '&#10008;') AS `aliases` FROM `alias`
      WHERE `goto` REGEXP :username_goto
      AND `address` NOT LIKE '@%'
      AND `address` != :username_address");
    $stmt->execute(array(':username_goto' => '(^|,)'.$username.'($|,)', ':username_address' => $username));
    $run = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($row = array_shift($run)) {
      $data['aliases'] = $row['aliases'];
    }
    $stmt = $pdo->prepare("SELECT IFNULL(GROUP_CONCAT(local_part, '@', alias_domain SEPARATOR ', '), '&#10008;') AS `ad_alias` FROM `mailbox`
      LEFT OUTER JOIN `alias_domain` on `target_domain` = `domain`
        WHERE `username` = :username ;");
    $stmt->execute(array(':username' => $username));
    $run = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($row = array_shift($run)) {
      $data['ad_alias'] = $row['ad_alias'];
    }
    $stmt = $pdo->prepare("SELECT IFNULL(GROUP_CONCAT(`send_as` SEPARATOR ', '), '&#10008;') AS `send_as` FROM `sender_acl` WHERE `logged_in_as` = :username AND `send_as` NOT LIKE '@%';");
    $stmt->execute(array(':username' => $username));
    $run = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($row = array_shift($run)) {
      $data['aliases_also_send_as'] = $row['send_as'];
    }
    $stmt = $pdo->prepare("SELECT IFNULL(GROUP_CONCAT(`send_as` SEPARATOR ', '), '&#10008;') AS `send_as` FROM `sender_acl` WHERE `logged_in_as` = :username AND `send_as` LIKE '@%';");
    $stmt->execute(array(':username' => $username));
    $run = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($row = array_shift($run)) {
      $data['aliases_send_as_all'] = $row['send_as'];
    }
    $stmt = $pdo->prepare("SELECT IFNULL(GROUP_CONCAT(`address` SEPARATOR ', '), '&#10008;') as `address` FROM `alias` WHERE `goto` REGEXP :username AND `address` LIKE '@%';");
    $stmt->execute(array(':username' => '(^|,)'.$username.'($|,)'));
    $run = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($row = array_shift($run)) {
      $data['is_catch_all'] = $row['address'];
    }
    return $data;
  }
  catch(PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
    return false;
  }
}
function is_valid_domain_name($domain_name) { 
	if (empty($domain_name)) {
		return false;
	}
	$domain_name = idn_to_ascii($domain_name);
	return (preg_match("/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))*$/i", $domain_name)
		   && preg_match("/^.{1,253}$/", $domain_name)
		   && preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", $domain_name));
}
function add_domain_admin($postarray) {
	global $lang;
	global $pdo;
	$username		= strtolower(trim($postarray['username']));
	$password		= $postarray['password'];
	$password2  = $postarray['password2'];
  $active  = intval($postarray['active']);

	if ($_SESSION['mailcow_cc_role'] != "admin") {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
	if (empty($postarray['domain'])) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['domain_invalid'])
		);
		return false;
	}
	if (!ctype_alnum(str_replace(array('_', '.', '-'), '', $username)) || empty ($username)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['username_invalid'])
		);
		return false;
	}
	try {
		$stmt = $pdo->prepare("SELECT `username` FROM `mailbox`
			WHERE `username` = :username");
		$stmt->execute(array(':username' => $username));
		$num_results[] = count($stmt->fetchAll(PDO::FETCH_ASSOC));
		
		$stmt = $pdo->prepare("SELECT `username` FROM `admin`
			WHERE `username` = :username");
		$stmt->execute(array(':username' => $username));
		$num_results[] = count($stmt->fetchAll(PDO::FETCH_ASSOC));
		
		$stmt = $pdo->prepare("SELECT `username` FROM `domain_admins`
			WHERE `username` = :username");
		$stmt->execute(array(':username' => $username));
		$num_results[] = count($stmt->fetchAll(PDO::FETCH_ASSOC));
	}
	catch(PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
	foreach ($num_results as $num_results_each) {
		if ($num_results_each != 0) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['object_exists'], htmlspecialchars($username))
			);
			return false;
		}
	}
	if (!empty($password) && !empty($password2)) {
    if (!preg_match('/' . $GLOBALS['PASSWD_REGEP'] . '/', $password)) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['password_complexity'])
      );
      return false;
    }
		if ($password != $password2) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => sprintf($lang['danger']['password_mismatch'])
			);
			return false;
		}
		$password_hashed = hash_password($password);
		foreach ($postarray['domain'] as $domain) {
			if (!is_valid_domain_name($domain)) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => sprintf($lang['danger']['domain_invalid'])
				);
				return false;
			}
			try {
				$stmt = $pdo->prepare("INSERT INTO `domain_admins` (`username`, `domain`, `created`, `active`)
						VALUES (:username, :domain, :created, :active)");
				$stmt->execute(array(
					':username' => $username,
					':domain' => $domain,
					':created' => date('Y-m-d H:i:s'),
					':active' => $active
				));
			}
			catch (PDOException $e) {
        delete_domain_admin(array('username' => $username));
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => 'MySQL: '.$e
				);
				return false;
			}
		}
		try {
			$stmt = $pdo->prepare("INSERT INTO `admin` (`username`, `password`, `superadmin`, `active`)
				VALUES (:username, :password_hashed, '0', :active)");
			$stmt->execute(array(
				':username' => $username,
				':password_hashed' => $password_hashed,
				':active' => $active
			));
		}
		catch (PDOException $e) {
			$_SESSION['return'] = array(
				'type' => 'danger',
				'msg' => 'MySQL: '.$e
			);
			return false;
		}
	}
	else {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['password_empty'])
		);
		return false;
	}
	$_SESSION['return'] = array(
		'type' => 'success',
		'msg' => sprintf($lang['success']['domain_admin_added'], htmlspecialchars($username))
	);
}
function delete_domain_admin($postarray) {
	global $pdo;
	global $lang;
	if ($_SESSION['mailcow_cc_role'] != "admin") {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
	$username = $postarray['username'];
	if (!ctype_alnum(str_replace(array('_', '.', '-'), '', $username))) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['username_invalid'])
		);
		return false;
	}
	try {
		$stmt = $pdo->prepare("DELETE FROM `domain_admins` WHERE `username` = :username");
		$stmt->execute(array(
			':username' => $username,
		));
		$stmt = $pdo->prepare("DELETE FROM `admin` WHERE `username` = :username");
		$stmt->execute(array(
			':username' => $username,
		));
	}
	catch (PDOException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'MySQL: '.$e
		);
		return false;
	}
	$_SESSION['return'] = array(
		'type' => 'success',
		'msg' => sprintf($lang['success']['domain_admin_removed'], htmlspecialchars($username))
	);
}
function get_domain_admins() {
	global $pdo;
	global $lang;
  $domainadmins = array();
	if ($_SESSION['mailcow_cc_role'] != "admin") {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
  try {
    $stmt = $pdo->query("SELECT DISTINCT
      `username`
        FROM `domain_admins` 
          WHERE `username` IN (
            SELECT `username` FROM `admin`
              WHERE `superadmin`!='1'
          )");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($row = array_shift($rows)) {
      $domainadmins[] = $row['username'];
    }
  }
  catch(PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
  }
  return $domainadmins;
}
function get_domain_admin_details($domain_admin) {
	global $pdo;

	global $lang;
  $domainadmindata = array();
	if (isset($domain_admin) && $_SESSION['mailcow_cc_role'] != "admin") {
		return false;
	}
  if (!isset($domain_admin) && $_SESSION['mailcow_cc_role'] != "domainadmin") {
		return false;
	}
  (!isset($domain_admin)) ? $domain_admin = $_SESSION['mailcow_cc_username'] : null;
  
  if (!ctype_alnum(str_replace(array('_', '.', '-'), '', $domain_admin))) {
		return false;
	}
  try {
    $stmt = $pdo->prepare("SELECT
      `tfa`.`active` AS `tfa_active_int`,
      CASE `tfa`.`active` WHEN 1 THEN '".$lang['mailbox']['yes']."' ELSE '".$lang['mailbox']['no']."' END AS `tfa_active`,
      `domain_admins`.`username`,
      `domain_admins`.`created`,
      `domain_admins`.`active` AS `active_int`,
      CASE `domain_admins`.`active` WHEN 1 THEN '".$lang['mailbox']['yes']."' ELSE '".$lang['mailbox']['no']."' END AS `active`
        FROM `domain_admins`
        LEFT OUTER JOIN `tfa` ON `tfa`.`username`=`domain_admins`.`username`
          WHERE `domain_admins`.`username`= :domain_admin");
    $stmt->execute(array(
      ':domain_admin' => $domain_admin
    ));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (empty($row)) { 
      return false;
    }
    $domainadmindata['username'] = $row['username'];
    $domainadmindata['tfa_active'] = $row['tfa_active'];
    $domainadmindata['active'] = $row['active'];
    $domainadmindata['tfa_active_int'] = $row['tfa_active_int'];
    $domainadmindata['active_int'] = $row['active_int'];
    $domainadmindata['modified'] = $row['created'];
    // GET SELECTED
    $stmt = $pdo->prepare("SELECT `domain` FROM `domain`
      WHERE `domain` IN (
        SELECT `domain` FROM `domain_admins`
          WHERE `username`= :domain_admin)");
    $stmt->execute(array(':domain_admin' => $domain_admin));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while($row = array_shift($rows)) {
      $domainadmindata['selected_domains'][] = $row['domain'];
    }
    // GET UNSELECTED
    $stmt = $pdo->prepare("SELECT `domain` FROM `domain`
      WHERE `domain` NOT IN (
        SELECT `domain` FROM `domain_admins`
          WHERE `username`= :domain_admin)");
    $stmt->execute(array(':domain_admin' => $domain_admin));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while($row = array_shift($rows)) {
      $domainadmindata['unselected_domains'][] = $row['domain'];
    }
    if (!isset($domainadmindata['unselected_domains'])) {
      $domainadmindata['unselected_domains'] = "";
    }
  }
  catch(PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
  }
  return $domainadmindata;
}
function set_tfa($postarray) {
	global $lang;
	global $pdo;
	global $yubi;
	global $u2f;
	global $tfa;

  if ($_SESSION['mailcow_cc_role'] != "domainadmin" &&
    $_SESSION['mailcow_cc_role'] != "admin") {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
  }
  $username = $_SESSION['mailcow_cc_username'];
  
  $stmt = $pdo->prepare("SELECT `password` FROM `admin`
      WHERE `username` = :user");
  $stmt->execute(array(':user' => $username));
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!verify_ssha256($row['password'], $postarray["confirm_password"])) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => sprintf($lang['danger']['access_denied'])
    );
    return false;
  }
  
	switch ($postarray["tfa_method"]) {
		case "yubi_otp":
      $key_id = (!isset($postarray["key_id"])) ? 'unidentified' : $postarray["key_id"];
      $yubico_id = $postarray['yubico_id'];
      $yubico_key = $postarray['yubico_key'];
      $yubi = new Auth_Yubico($yubico_id, $yubico_key);
      if (!$yubi) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['access_denied'])
        );
        return false;
      }
			if (!ctype_alnum($postarray["otp_token"]) || strlen($postarray["otp_token"]) != 44) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => sprintf($lang['danger']['tfa_token_invalid'])
				);
				return false;
			}
      $yauth = $yubi->verify($postarray["otp_token"]);
      if (PEAR::isError($yauth)) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => 'Yubico API: ' . $yauth->getMessage()
				);
				return false;
      }
			try {
        // We could also do a modhex translation here
        $yubico_modhex_id = substr($postarray["otp_token"], 0, 12);
        $stmt = $pdo->prepare("DELETE FROM `tfa` 
          WHERE `username` = :username
            AND (`authmech` != 'yubi_otp')
            OR (`authmech` = 'yubi_otp' AND `secret` LIKE :modhex)");
				$stmt->execute(array(':username' => $username, ':modhex' => '%' . $yubico_modhex_id));
				$stmt = $pdo->prepare("INSERT INTO `tfa` (`key_id`, `username`, `authmech`, `active`, `secret`) VALUES
					(:key_id, :username, 'yubi_otp', '1', :secret)");
				$stmt->execute(array(':key_id' => $key_id, ':username' => $username, ':secret' => $yubico_id . ':' . $yubico_key . ':' . $yubico_modhex_id));
			}
			catch (PDOException $e) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => 'MySQL: '.$e
				);
				return false;
			}
			$_SESSION['return'] = array(
				'type' => 'success',
				'msg' => sprintf($lang['success']['object_modified'], htmlspecialchars($username))
			);
		break;

		case "u2f":
      $key_id = (!isset($postarray["key_id"])) ? 'unidentified' : $postarray["key_id"];
      try {
        $reg = $u2f->doRegister(json_decode($_SESSION['regReq']), json_decode($postarray['token']));
        $stmt = $pdo->prepare("DELETE FROM `tfa` WHERE `username` = :username AND `authmech` != 'u2f'");
				$stmt->execute(array(':username' => $username));
        $stmt = $pdo->prepare("INSERT INTO `tfa` (`username`, `key_id`, `authmech`, `keyHandle`, `publicKey`, `certificate`, `counter`, `active`) VALUES (?, ?, 'u2f', ?, ?, ?, ?, '1')");
        $stmt->execute(array($username, $key_id, $reg->keyHandle, $reg->publicKey, $reg->certificate, $reg->counter));
        $_SESSION['return'] = array(
          'type' => 'success',
          'msg' => sprintf($lang['success']['object_modified'], $username)
        );
        $_SESSION['regReq'] = null;
      }
      catch (Exception $e) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => "U2F: " . $e->getMessage()
        );
        $_SESSION['regReq'] = null;
        return false;
      }
		break;

		case "totp":
      $key_id = (!isset($postarray["key_id"])) ? 'unidentified' : $postarray["key_id"];
      if ($tfa->verifyCode($_POST['totp_secret'], $_POST['totp_confirm_token']) === true) {
        try {
        $stmt = $pdo->prepare("DELETE FROM `tfa` WHERE `username` = :username");
        $stmt->execute(array(':username' => $username));
        $stmt = $pdo->prepare("INSERT INTO `tfa` (`username`, `key_id`, `authmech`, `secret`, `active`) VALUES (?, ?, 'totp', ?, '1')");
        $stmt->execute(array($username, $key_id, $_POST['totp_secret']));
        }
        catch (PDOException $e) {
          $_SESSION['return'] = array(
            'type' => 'danger',
            'msg' => 'MySQL: '.$e
          );
          return false;
        }
        $_SESSION['return'] = array(
          'type' => 'success',
          'msg' => sprintf($lang['success']['object_modified'], $username)
        );
      }
      else {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => 'TOTP verification failed'
        );
      }
		break;

		case "none":
			try {
				$stmt = $pdo->prepare("DELETE FROM `tfa` WHERE `username` = :username");
				$stmt->execute(array(':username' => $username));
			}
			catch (PDOException $e) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => 'MySQL: '.$e
				);
				return false;
			}
			$_SESSION['return'] = array(
				'type' => 'success',
				'msg' => sprintf($lang['success']['object_modified'], htmlspecialchars($username))
			);
		break;
	}
}
function unset_tfa_key($postarray) {
  // Can only unset own keys
  // Needs at least one key left
  global $pdo;
  global $lang;
  $id = intval($postarray['unset_tfa_key']);
  if ($_SESSION['mailcow_cc_role'] != "domainadmin" &&
    $_SESSION['mailcow_cc_role'] != "admin") {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
  }
  $username = $_SESSION['mailcow_cc_username'];
  try {
    if (!is_numeric($id)) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) AS `keys` FROM `tfa`
      WHERE `username` = :username AND `active` = '1'");
    $stmt->execute(array(':username' => $username));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row['keys'] == "1") {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['last_key'])
      );
      return false;
    }
    $stmt = $pdo->prepare("DELETE FROM `tfa` WHERE `username` = :username AND `id` = :id");
    $stmt->execute(array(':username' => $username, ':id' => $id));
    $_SESSION['return'] = array(
      'type' => 'success',
      'msg' => sprintf($lang['success']['object_modified'], $username)
    );
  }
  catch (PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
    return false;
  }
}
function get_tfa($username = null) {
	global $pdo;
  if (isset($_SESSION['mailcow_cc_username'])) {
    $username = $_SESSION['mailcow_cc_username'];
  }
  elseif (empty($username)) {
    return false;
  }

  $stmt = $pdo->prepare("SELECT * FROM `tfa`
      WHERE `username` = :username AND `active` = '1'");
  $stmt->execute(array(':username' => $username));
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  
	switch ($row["authmech"]) {
		case "yubi_otp":
      $data['name'] = "yubi_otp";
      $data['pretty'] = "Yubico OTP";
      $stmt = $pdo->prepare("SELECT `id`, `key_id`, RIGHT(`secret`, 12) AS 'modhex' FROM `tfa` WHERE `authmech` = 'yubi_otp' AND `username` = :username");
      $stmt->execute(array(
        ':username' => $username,
      ));
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
      while($row = array_shift($rows)) {
        $data['additional'][] = $row;
      }
      return $data;
    break;
		case "u2f":
      $data['name'] = "u2f";
      $data['pretty'] = "Fido U2F";
      $stmt = $pdo->prepare("SELECT `id`, `key_id` FROM `tfa` WHERE `authmech` = 'u2f' AND `username` = :username");
      $stmt->execute(array(
        ':username' => $username,
      ));
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
      while($row = array_shift($rows)) {
        $data['additional'][] = $row;
      }
      return $data;
    break;
		case "hotp":
      $data['name'] = "hotp";
      $data['pretty'] = "HMAC-based OTP";
      return $data;
		break;
 		case "totp":
      $data['name'] = "totp";
      $data['pretty'] = "Time-based OTP";
      $stmt = $pdo->prepare("SELECT `id`, `key_id`, `secret` FROM `tfa` WHERE `authmech` = 'totp' AND `username` = :username");
      $stmt->execute(array(
        ':username' => $username,
      ));
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
      while($row = array_shift($rows)) {
        $data['additional'][] = $row;
      }
      return $data;
      break;
    default:
      $data['name'] = 'none';
      $data['pretty'] = "-";
      return $data;
    break;
	}
}
function verify_tfa_login($username, $token) {
	global $pdo;
	global $lang;
	global $yubi;
	global $u2f;
	global $tfa;

  $stmt = $pdo->prepare("SELECT `authmech` FROM `tfa`
      WHERE `username` = :username AND `active` = '1'");
  $stmt->execute(array(':username' => $username));
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  
	switch ($row["authmech"]) {
		case "yubi_otp":
			if (!ctype_alnum($token) || strlen($token) != 44) {
        return false;
      }
      $yubico_modhex_id = substr($token, 0, 12);
      $stmt = $pdo->prepare("SELECT `id`, `secret` FROM `tfa`
          WHERE `username` = :username
          AND `authmech` = 'yubi_otp'
          AND `active`='1'
          AND `secret` LIKE :modhex");
      $stmt->execute(array(':username' => $username, ':modhex' => '%' . $yubico_modhex_id));
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      $yubico_auth = explode(':', $row['secret']);
      $yubi = new Auth_Yubico($yubico_auth[0], $yubico_auth[1]);
      $yauth = $yubi->verify($token);
      if (PEAR::isError($yauth)) {
				$_SESSION['return'] = array(
					'type' => 'danger',
					'msg' => 'Yubico Authentication error: ' . $yauth->getMessage()
				);
				return false;
      }
      else {
        $_SESSION['tfa_id'] = $row['id'];
        return true;
      }
    return false;
  break;
  case "u2f":
    try {
      $reg = $u2f->doAuthenticate(json_decode($_SESSION['authReq']), get_u2f_registrations($username), json_decode($token));
      $stmt = $pdo->prepare("UPDATE `tfa` SET `counter` = ? WHERE `id` = ?");
      $stmt->execute(array($reg->counter, $reg->id));
      $_SESSION['tfa_id'] = $reg->id;
      $_SESSION['authReq'] = null;
      return true;
    }
    catch (Exception $e) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => "U2F: " . $e->getMessage()
      );
      $_SESSION['regReq'] = null;
      return false;
    }
    return false;
  break;
  case "hotp":
      return false;
  break;
  case "totp":
    try {
      $stmt = $pdo->prepare("SELECT `id`, `secret` FROM `tfa`
          WHERE `username` = :username
          AND `authmech` = 'totp'
          AND `active`='1'");
      $stmt->execute(array(':username' => $username));
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($tfa->verifyCode($row['secret'], $_POST['token']) === true) {
        $_SESSION['tfa_id'] = $row['id'];
        return true;
      }
      return false;
    }
    catch (PDOException $e) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => 'MySQL: '.$e
      );
      return false;
    }
  break;
  default:
      return false;
  break;
	}
  return false;
}
function edit_domain_admin($postarray) {
	global $lang;
	global $pdo;

	if ($_SESSION['mailcow_cc_role'] != "admin" && $_SESSION['mailcow_cc_role'] != "domainadmin") {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
	// Administrator
  if ($_SESSION['mailcow_cc_role'] == "admin") {
    $username     = $postarray['username'];
    $username_now = $postarray['username_now'];
    $password     = $postarray['password'];
    $password2    = $postarray['password2'];
    $active       = intval($postarray['active']);

    if(isset($postarray['domain'])) {
      foreach ($postarray['domain'] as $domain) {
        if (!is_valid_domain_name($domain)) {
          $_SESSION['return'] = array(
            'type' => 'danger',
            'msg' => sprintf($lang['danger']['domain_invalid'])
          );
          return false;
        }
      }
    }

    if (empty($postarray['domain'])) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['domain_invalid'])
      );
      return false;
    }

    if (!ctype_alnum(str_replace(array('_', '.', '-'), '', $username))) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['username_invalid'])
      );
      return false;
    }
    if ($username != $username_now) {
      if (empty(get_domain_admin_details($username_now)['username']) || !empty(get_domain_admin_details($username)['username'])) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['username_invalid'])
        );
        return false;
      }
    }
    try {
      $stmt = $pdo->prepare("DELETE FROM `domain_admins` WHERE `username` = :username");
      $stmt->execute(array(
        ':username' => $username_now,
      ));
    }
    catch (PDOException $e) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => 'MySQL: '.$e
      );
      return false;
    }

    if (isset($postarray['domain'])) {
      foreach ($postarray['domain'] as $domain) {
        try {
          $stmt = $pdo->prepare("INSERT INTO `domain_admins` (`username`, `domain`, `created`, `active`)
            VALUES (:username, :domain, :created, :active)");
          $stmt->execute(array(
            ':username' => $username,
            ':domain' => $domain,
            ':created' => date('Y-m-d H:i:s'),
            ':active' => $active
          ));
        }
        catch (PDOException $e) {
          $_SESSION['return'] = array(
            'type' => 'danger',
            'msg' => 'MySQL: '.$e
          );
          return false;
        }
      }
    }

    if (!empty($password) && !empty($password2)) {
      if (!preg_match('/' . $GLOBALS['PASSWD_REGEP'] . '/', $password)) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['password_complexity'])
        );
        return false;
      }
      if ($password != $password2) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['password_mismatch'])
        );
        return false;
      }
      $password_hashed = hash_password($password);
      try {
        $stmt = $pdo->prepare("UPDATE `admin` SET `username` = :username1, `active` = :active, `password` = :password_hashed WHERE `username` = :username2");
        $stmt->execute(array(
          ':password_hashed' => $password_hashed,
          ':username1' => $username,
          ':username2' => $username_now,
          ':active' => $active
        ));
        if (isset($postarray['disable_tfa'])) {
          $stmt = $pdo->prepare("UPDATE `tfa` SET `active` = '0' WHERE `username` = :username");
          $stmt->execute(array(':username' => $username_now));
        }
        else {
          $stmt = $pdo->prepare("UPDATE `tfa` SET `username` = :username WHERE `username` = :username_now");
          $stmt->execute(array(':username' => $username, ':username_now' => $username_now));
        }
      }
      catch (PDOException $e) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => 'MySQL: '.$e
        );
        return false;
      }
    }
    else {
      try {
        $stmt = $pdo->prepare("UPDATE `admin` SET `username` = :username1, `active` = :active WHERE `username` = :username2");
        $stmt->execute(array(
          ':username1' => $username,
          ':username2' => $username_now,
          ':active' => $active
        ));
        if (isset($postarray['disable_tfa'])) {
          $stmt = $pdo->prepare("UPDATE `tfa` SET `active` = '0' WHERE `username` = :username");
          $stmt->execute(array(':username' => $username));
        }
        else {
          $stmt = $pdo->prepare("UPDATE `tfa` SET `username` = :username WHERE `username` = :username_now");
          $stmt->execute(array(':username' => $username, ':username_now' => $username_now));
        }
      }
      catch (PDOException $e) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => 'MySQL: '.$e
        );
        return false;
      }
    }
    $_SESSION['return'] = array(
      'type' => 'success',
      'msg' => sprintf($lang['success']['domain_admin_modified'], htmlspecialchars($username))
    );
  }
  // Domain administrator
  // Can only edit itself
  elseif ($_SESSION['mailcow_cc_role'] == "domainadmin") {
    $username = $_SESSION['mailcow_cc_username'];
    $password_old		= $postarray['user_old_pass'];
    $password_new	= $postarray['user_new_pass'];
    $password_new2	= $postarray['user_new_pass2'];

    $stmt = $pdo->prepare("SELECT `password` FROM `admin`
        WHERE `username` = :user");
    $stmt->execute(array(':user' => $username));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!verify_ssha256($row['password'], $password_old)) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['access_denied'])
      );
      return false;
    }

    if (!empty($password_new2) && !empty($password_new)) {
      if ($password_new2 != $password_new) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['password_mismatch'])
        );
        return false;
      }
      if (!preg_match('/' . $GLOBALS['PASSWD_REGEP'] . '/', $password_new)) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => sprintf($lang['danger']['password_complexity'])
        );
        return false;
      }
      $password_hashed = hash_password($password_new);
      try {
        $stmt = $pdo->prepare("UPDATE `admin` SET `password` = :password_hashed WHERE `username` = :username");
        $stmt->execute(array(
          ':password_hashed' => $password_hashed,
          ':username' => $username
        ));
      }
      catch (PDOException $e) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => 'MySQL: '.$e
        );
        return false;
      }
    }
    
    $_SESSION['return'] = array(
      'type' => 'success',
      'msg' => sprintf($lang['success']['domain_admin_modified'], htmlspecialchars($username))
    );
  }
}
function get_admin_details() {
  // No parameter to be given, only one admin should exist
	global $pdo;
	global $lang;
  $data = array();
  if ($_SESSION['mailcow_cc_role'] != 'admin') {
    return false;
  }
  try {
    $stmt = $pdo->prepare("SELECT `username`, `modified`, `created` FROM `admin` WHERE `superadmin`='1' AND active='1'");
    $stmt->execute();
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
  }
  catch(PDOException $e) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => 'MySQL: '.$e
    );
  }
  return $data;
}
function dkim_add_key($postarray) {
	global $lang;
	global $pdo;
	global $redis;
  if ($_SESSION['mailcow_cc_role'] != "admin") {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => sprintf($lang['danger']['access_denied'])
    );
    return false;
  }
  // if (!hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $domain)) {
    // $_SESSION['return'] = array(
      // 'type' => 'danger',
      // 'msg' => sprintf($lang['danger']['access_denied'])
    // );
    // return false;
  // }
  $key_length	= intval($postarray['key_size']);
  $dkim_selector = (isset($postarray['dkim_selector'])) ? $postarray['dkim_selector'] : 'dkim';
  $domain	= $postarray['domain'];
  if (!is_valid_domain_name($domain) || !is_numeric($key_length)) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => sprintf($lang['danger']['dkim_domain_or_sel_invalid'])
    );
    return false;
  }

  if (!empty(glob($GLOBALS['MC_DKIM_TXTS'] . '/' . $domain . '.dkim')) ||
    $redis->hGet('DKIM_PUB_KEYS', $domain)) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['dkim_domain_or_sel_invalid'])
      );
      return false;
  }

  if (!ctype_alnum($dkim_selector)) {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => sprintf($lang['danger']['dkim_domain_or_sel_invalid'])
    );
    return false;
  }

  $config = array(
    "digest_alg" => "sha256",
    "private_key_bits" => $key_length,
    "private_key_type" => OPENSSL_KEYTYPE_RSA,
  );
  if ($keypair_ressource = openssl_pkey_new($config)) {
    $key_details = openssl_pkey_get_details($keypair_ressource);
    $pubKey = implode(array_slice(
        array_filter(
          explode(PHP_EOL, $key_details['key'])
        ), 1, -1)
      );
    // Save public key and selector to redis
    try {
      $redis->hSet('DKIM_PUB_KEYS', $domain, $pubKey);
      $redis->hSet('DKIM_SELECTORS', $domain, $dkim_selector);
    }
    catch (RedisException $e) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => 'Redis: '.$e
      );
      return false;
    }
    // Export private key and save private key to redis
    openssl_pkey_export($keypair_ressource, $privKey);
    if (isset($privKey) && !empty($privKey)) {
      try {
        $redis->hSet('DKIM_PRIV_KEYS', $dkim_selector . '.' . $domain, trim($privKey));
      }
      catch (RedisException $e) {
        $_SESSION['return'] = array(
          'type' => 'danger',
          'msg' => 'Redis: '.$e
        );
        return false;
      }
    }
    $_SESSION['return'] = array(
      'type' => 'success',
      'msg' => sprintf($lang['success']['dkim_added'])
    );
    return true;
  }
  else {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => sprintf($lang['danger']['dkim_domain_or_sel_invalid'])
    );
    return false;
  }
}
function dkim_get_key_details($domain) {
  global $redis;
  if (!hasDomainAccess($_SESSION['mailcow_cc_username'], $_SESSION['mailcow_cc_role'], $domain)) {
    return false;
  }
  $data = array();
  if ($redis_dkim_key_data = $redis->hGet('DKIM_PUB_KEYS', $domain)) {
    $data['pubkey'] = $redis_dkim_key_data;
    $data['length'] = (strlen($data['pubkey']) < 391) ? 1024 : 2048;
    $data['dkim_txt'] = 'v=DKIM1;k=rsa;t=s;s=email;p=' . $redis_dkim_key_data;
    $data['dkim_selector'] = $redis->hGet('DKIM_SELECTORS', $domain);
  }
  return $data;
}
function dkim_get_blind_keys() {
  global $redis;
	global $lang;
  if ($_SESSION['mailcow_cc_role'] != "admin") {
    return false;
  }
  $domains = array();
  foreach ($redis->hKeys('DKIM_PUB_KEYS') as $redis_dkim_domain) {
    $domains[] = $redis_dkim_domain;
  }
  return array_diff($domains, array_merge(mailbox('get', 'domains'), mailbox('get', 'alias_domains')));
}
function dkim_delete_key($postarray) {
	global $redis;
	global $lang;
  if (!is_array($postarray['domains'])) {
    $domains = array();
    $domains[] = $postarray['domains'];
  }
  else {
    $domains = $postarray['domains'];
  }
  if ($_SESSION['mailcow_cc_role'] != "admin") {
    $_SESSION['return'] = array(
      'type' => 'danger',
      'msg' => sprintf($lang['danger']['access_denied'])
    );
    return false;
  }
  foreach ($domains as $domain) {
    if (!is_valid_domain_name($domain)) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => sprintf($lang['danger']['dkim_domain_or_sel_invalid'])
      );
      return false;
    }
    try {
      $selector = $redis->hGet('DKIM_SELECTORS', $domain);
      $redis->hDel('DKIM_PUB_KEYS', $domain);
      $redis->hDel('DKIM_PRIV_KEYS', $selector . '.' . $domain);
      $redis->hDel('DKIM_SELECTORS', $domain);
    }
    catch (RedisException $e) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => 'Redis: '.$e
      );
      return false;
    }
  }
  $_SESSION['return'] = array(
    'type' => 'success',
    'msg' => sprintf($lang['success']['dkim_removed'], htmlspecialchars(implode(', ', $domains)))
  );
  return true;
}
function get_u2f_registrations($username) {
  global $pdo;
  $sel = $pdo->prepare("SELECT * FROM `tfa` WHERE `authmech` = 'u2f' AND `username` = ? AND `active` = '1'");
  $sel->execute(array($username));
  return $sel->fetchAll(PDO::FETCH_OBJ);
}
function get_forwarding_hosts() {
	global $redis;
  $data = array();
  try {
    $fwd_hosts = $redis->hGetAll('WHITELISTED_FWD_HOST');
    if (!empty($fwd_hosts)) {
      foreach ($fwd_hosts as $fwd_host => $source) {
        $data[] = $fwd_host;
      }
    }
  }
  catch (RedisException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'Redis: '.$e
		);
		return false;
  }
  return $data;
}
function get_forwarding_host_details($host) {
	global $redis;
  $data = array();
  if (!isset($host) || empty($host)) {
    return false;
  }
  try {
    if ($source = $redis->hGet('WHITELISTED_FWD_HOST', $host)) {
      $data['host'] = $host;
      $data['source'] = $source;
      $data['keep_spam'] = ($redis->hGet('KEEP_SPAM', $host)) ? "yes" : "no";
    }
  }
  catch (RedisException $e) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'Redis: '.$e
		);
		return false;
  }
  return $data;
}
function add_forwarding_host($postarray) {
	require_once 'spf.inc.php';
	global $redis;
	global $lang;
	if ($_SESSION['mailcow_cc_role'] != "admin") {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
	$source = $postarray['hostname'];
	$host = trim($postarray['hostname']);
  $filter_spam = $postarray['filter_spam'];
  if (isset($postarray['filter_spam']) && $postarray['filter_spam'] == 1) {
    $filter_spam = 1;
  }
  else {
    $filter_spam = 0;
  }
	if (preg_match('/^[0-9a-fA-F:\/]+$/', $host)) { // IPv6 address
		$hosts = array($host);
	}
	elseif (preg_match('/^[0-9\.\/]+$/', $host)) { // IPv4 address
		$hosts = array($host);
	}
	else {
		$hosts = get_outgoing_hosts_best_guess($host);
	}
	if (empty($hosts)) {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => 'Invalid host specified: '. htmlspecialchars($host)
		);
		return false;
	}
	foreach ($hosts as $host) {
    try {
      $redis->hSet('WHITELISTED_FWD_HOST', $host, $source);
      if ($filter_spam == 0) {
        $redis->hSet('KEEP_SPAM', $host, 1);
      }
      elseif ($redis->hGet('KEEP_SPAM', $host)) {
        $redis->hDel('KEEP_SPAM', $host);
      }
    }
    catch (RedisException $e) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => 'Redis: '.$e
      );
      return false;
    }
	}
	$_SESSION['return'] = array(
		'type' => 'success',
		'msg' => sprintf($lang['success']['forwarding_host_added'], htmlspecialchars(implode(', ', $hosts)))
	);
}
function delete_forwarding_host($postarray) {
	global $redis;
	global $lang;
	if ($_SESSION['mailcow_cc_role'] != "admin") {
		$_SESSION['return'] = array(
			'type' => 'danger',
			'msg' => sprintf($lang['danger']['access_denied'])
		);
		return false;
	}
  if (!is_array($postarray['forwardinghost'])) {
    $hosts = array();
    $hosts[] = $postarray['forwardinghost'];
  }
  else {
    $hosts = $postarray['forwardinghost'];
  }
  foreach ($hosts as $host) {
    try {
      $redis->hDel('WHITELISTED_FWD_HOST', $host);
      $redis->hDel('KEEP_SPAM', $host);
    }
    catch (RedisException $e) {
      $_SESSION['return'] = array(
        'type' => 'danger',
        'msg' => 'Redis: '.$e
      );
      return false;
    }
  }
	$_SESSION['return'] = array(
		'type' => 'success',
		'msg' => sprintf($lang['success']['forwarding_host_removed'], htmlspecialchars(implode(', ', $hosts)))
	);
}
function get_logs($container, $lines = 100) {
	global $lang;
	global $redis;
	if ($_SESSION['mailcow_cc_role'] != "admin") {
		return false;
	}
  $lines = intval($lines);
  if ($container == "dovecot-mailcow") {
    if ($data = $redis->lRange('DOVECOT_MAILLOG', 1, $lines)) {
      foreach ($data as $json_line) {
        $data_array[] = json_decode($json_line, true);
      }
      return $data_array;
    }
  }
  if ($container == "postfix-mailcow") {
    if ($data = $redis->lRange('POSTFIX_MAILLOG', 1, $lines)) {
      foreach ($data as $json_line) {
        $data_array[] = json_decode($json_line, true);
      }
      return $data_array;
    }
  }
  if ($container == "sogo-mailcow") {
    if ($data = $redis->lRange('SOGO_LOG', 1, $lines)) {
      foreach ($data as $json_line) {
        $data_array[] = json_decode($json_line, true);
      }
      return $data_array;
    }
  }
  if ($container == "rspamd-history") {
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL,"http://rspamd-mailcow:11334/history");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $history = curl_exec($curl);
    if (!curl_errno($ch)) {
      $data_array = json_decode($history, true);
      curl_close($curl);
      return $data_array['rows'];
    }
    curl_close($curl);
    return false;
  }
  return false;
}
?>
