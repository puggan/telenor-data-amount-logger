<?php

	$debug = TRUE;

	define('TELENOR_LOGIN_URL', "https://minasidor.telenor.se/minasidor/login");
	define('TELENOR_ACCOUNT_URL', "https://minasidor.telenor.se/minasidor/impersonateUser?currentPage=%2Fminasidor%2Fportal%2FStart.do&j_username=");
	define('TELENOR_DATA_URL', "https://minasidor.telenor.se/minasidor/shareable/ShareableLagePuffData.do");
	define('TELENOR_MBB_URL', "https://minasidor.telenor.se/app/minasidor/mbb/MBBPuff.do");

	require_once(__DIR__ . "/config.php");
	require_once(__DIR__ . "/remote.php");

	if(!isset($accounts)) die("Configfile have no accounts. (variable missing)\n");
	if(!is_array($accounts)) die("Configfile have no accounts. (variable bad type)\n");
	if(!count($accounts)) die("Configfile have no accounts. (variable empty)\n");

	if(!defined('DATABASE_FILE')) die("Failed to open database (constan DATABASE_FILE missing)\n");

	if(!class_exists("SQLite3")) die("Failed to open database (php don't have support for SQLite3)\n");

	$db = new SQLite3(DATABASE_FILE);

	if(!$db) die("Failed to open database (opening file failed)\n");

	@$db->query('CREATE TABLE IF NOT EXISTS telenor_log (account_lable TEXT, username TEXT, telenor_id TEXT, max_value REAL, value REAL, value2 REAL, time INT)');

	foreach($accounts as $account_lable => $account)
	{
		if(!isset($account['username']) OR !$account['username']) {echo "Username missing in account {$account_lable}\n"; continue;}
		if(!isset($account['password']) OR !$account['password']) {echo "Username missing in account {$account_lable}\n"; continue;}

		$post_data = array();
		$post_data['j_username'] = $account['username'];
		$post_data['j_password'] = $account['password'];

		// create a new instance for each account => new cookies, no reference
		$web = new remote_site();

		// config for telenors https
		$web->cipher_list = 'RC4-MD5';
		$web->force_sslversion(3);
		$web->add_cert_file(__DIR__ . "/telenor_cert.asc");

		/* TODO:
		 * keep seperate accoutn-cookie
		 * try fetching data, login on failer.
		 */

		$page = $web->post_page(TELENOR_LOGIN_URL, $post_data);

		if($debug)
		{
			file_put_contents("/tmp/telenor_1.html", $page);
			file_put_contents("/tmp/telenor_1.info", serialize($web->info));
			file_put_contents("/tmp/telenor_1.head", serialize($web->recived_headers[0]));
			file_put_contents("/tmp/telenor_1.headers", serialize($web->recived_headers));
			file_put_contents("/tmp/telenor_1.curl", serialize($web));
		}

		if(!strpos($page, "/minasidor/portal/logout.do"))
		{
			trigger_error("Login failed for account {$account_lable}");
			//continue;
		}

		if(!isset($account['accounts']) OR !$account['accounts'])
		{
			$account['accounts'] = array(0 => NULL);
		}
		else if(!is_array($account['accounts']))
		{
			$account['accounts'] = explode(",", (string) $account['accounts']);
		}

		foreach($account['accounts'] as $sub_account)
		{
			if($sub_account)
			{
				$page = $web->get_page(TELENOR_ACCOUNT_URL . trim($sub_account));

				if($debug)
				{
					file_put_contents("/tmp/telenor_3.html", $page);
					file_put_contents("/tmp/telenor_3.info", serialize($web->info));
					file_put_contents("/tmp/telenor_3.head", serialize($web->recived_headers[0]));
					file_put_contents("/tmp/telenor_3.headers", serialize($web->recived_headers));
					file_put_contents("/tmp/telenor_3.curl", serialize($web));
				}
			}

			$page = $web->get_page(TELENOR_MBB_URL);

			if($debug)
			{
				file_put_contents("/tmp/telenor_4.html", $page);
				file_put_contents("/tmp/telenor_4.info", serialize($web->info));
				file_put_contents("/tmp/telenor_4.head", serialize($web->recived_headers[0]));
				file_put_contents("/tmp/telenor_4.headers", serialize($web->recived_headers));
				file_put_contents("/tmp/telenor_4.curl", serialize($web));
			}

			if(preg_match("#barValue = (?<portion>[0-9]+\.[0-9]+)#", $page, $match_barvalue))
			{
				$portion = ((float) $match_barvalue['portion'])/100;

				if(preg_match("#<span class=\"amount\">(?<left>[0-9]+,[0-9]+) GB</span>#", $page, $match_amount))
				{
					$amount = (float) str_replace(',', '.', $match_amount['left']);
					if($portion)
					{
						$max = round($amount/$portion);
						$real_amount = (1 - $portion) * $max;

						$sql_values = array();
						$sql_values['account_lable'] = "'" . $db->escapeString($account_lable) . "'";
						$sql_values['username'] = "'" . $db->escapeString($account['username']) . "'";
						$sql_values['telenor_id'] = "'" . $db->escapeString($sub_account) . "'";
						$sql_values['max_value'] = str_replace(",", ".", (string) (float) $max);
						$sql_values['value'] = str_replace(",", ".", (string) (float) $real_amount);
						$sql_values['value2'] = str_replace(",", ".", (string) (float) $amount);
						$sql_values['time'] = time();

						$query = "INSERT INTO telenor_log(" . implode(", ", array_keys($sql_values)) . ") VALUES (" . implode(", ", $sql_values) . ")";

						echo $query . "\n";

						$result = $db->query($query);

						if(!$result)
						{
							trigger_error("Failed to write to database for account {$account_lable}, query: {$query}");
							continue;
						}
					}
				}
			}
			else
			{
				$page = $web->get_page(TELENOR_DATA_URL);

				if($debug)
				{
					file_put_contents("/tmp/telenor_2.html", $page);
					file_put_contents("/tmp/telenor_2.info", serialize($web->info));
					file_put_contents("/tmp/telenor_2.head", serialize($web->recived_headers[0]));
					file_put_contents("/tmp/telenor_2.headers", serialize($web->recived_headers));
					file_put_contents("/tmp/telenor_2.curl", serialize($web));
				}

				$page_parts = explode('<div class="usage-meter"', $page);
				unset($page_parts[0]);

				if($page_parts)
				{
					foreach($page_parts as $page_part)
					{
						$page_sub_parts = explode(">", $page_part, 2);
						$page_part = $page_sub_parts[0];
						unset($page_sub_parts);

						if(preg_match_all("#\b(?<key>[-_a-zA-Z]+)=\"(?<value>[^\"]+)\"#", $page_part, $attributes_matches))
						{
							$attributes = array_combine($attributes_matches['key'], $attributes_matches['value']);
							print_r($attributes);

							$user_values = explode(",", $attributes['data-user_value']);

							$sql_values = array();
							$sql_values['account_lable'] = "'" . $db->escapeString($account_lable) . "'";
							$sql_values['username'] = "'" . $db->escapeString($account['username']) . "'";
							$sql_values['telenor_id'] = "'" . $db->escapeString($attributes['data-msisdn']) . "'";
							$sql_values['max_value'] = str_replace(",", ".", (string) (float) $attributes['data-max_value']);
							$sql_values['value'] = str_replace(",", ".", (string) (float) $user_values[0]);
							$sql_values['value2'] = str_replace(",", ".", (string) (float) $user_values[1]);
							$sql_values['time'] = time();

							$query = "INSERT INTO telenor_log(" . implode(", ", array_keys($sql_values)) . ") VALUES (" . implode(", ", $sql_values) . ")";

							echo $query . "\n";

							$result = $db->query($query);

							if(!$result)
							{
								trigger_error("Failed to write to database for account {$account_lable}, query: {$query}");
								continue;
							}
						}
					}
				}
				else
				{
					trigger_error("Failed to access data-ammount, failed on 2 of 2 senarios, for account {$account_lable}");
					continue;
				}
			}
		}
	}