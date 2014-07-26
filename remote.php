<?php

	class remote_site
	{
		/* Store the UA and Headers in the class for future requests */
		public $user_agent;
		public $headers;
		public $recived_headers;
		public $info;

		/* Store the URL for each call to use as the referer for the next. */
		public $url;
		public $referer;

		/* Store the absolute path to the file where we store cookies. */
		public $cookie_jar;

		/* Store a private handle to the curl resource */
		private $curl_handle;

		public $delay;

		private $sslversion;

		private $ssl_cert_files;
		private $ssl_allow_insecure;
		public $cipher_list;

		/**
		 * Initialize the class with basic browser configuration at start
		 **/
		function __construct()
		{
			/* Set the default useragent and headers to mimic an ordinary browser. */
			$this->user_agent = "Mozilla/5.0 (compatible; Konqueror/3.5; Linux) KHTML/3.5.9 (like Gecko)";
			$this->headers = array(
				'Accept'          => "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/" . "*;q=0.8",
				'Accept-Language' => "Accept-Language: sv-se,sv;q=0.8,en-us;q=0.5,en;q=0.3",
				'Accept-Charset'  => "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7",
				'Keep-Alive-time' => "Keep-Alive: 300",
				'Keep-Alive'      => "Connection: keep-alive",
			);

			$this->cookie_jar = tempnam(sys_get_temp_dir(), 'curl_cookie_');

			$this->ssl_cert_files = array();
			$this->ssl_allow_insecure = FALSE;

			$this->delay = 0;
		}

		/**
		 * Sets an alternate (preferably unique) file to store cookies to ensure that no collisions occur.
		 **/
		function set_cookie_jar($path)
		{
			/* Set the place to store cookies. */
			$this->cookie_jar = $path;
		}

		function set_delay($secounds)
		{
			$this->delay = $secounds;
			return TRUE;
		}

		function force_sslversion($version)
		{
			$this->sslversion = $version;
		}

		function allow_insecure_ssl($value)
		{
			$this->ssl_allow_insecure = (boolean) $value;
		}

		function add_cert_file($filename)
		{
			$this->ssl_cert_files[$filename] = $filename;
		}

		function init_curl_call($url, $referer = "", $extra_headers = NULL)
		{
			if($this->delay)
			{
				usleep(floor($this->delay * 1000000));
			}

			/* Initialize cURL */
			$this->curl_handle = curl_init();

			if($url)
			{
				$this->url = $url;
			}

			/* Set the URL for this call. */
			curl_setopt($this->curl_handle, CURLOPT_URL, $this->url);

			/* make it possible to read the sent headers */
			curl_setopt($this->curl_handle, CURLINFO_HEADER_OUT, TRUE);

			if($this->sslversion)
			{
				curl_setopt($this->curl_handle, CURLOPT_SSLVERSION, $this->sslversion);
			}

			if($this->cipher_list)
			{
				if(is_array($this->cipher_list))
				{
					curl_setopt($this->curl_handle, CURLOPT_SSL_CIPHER_LIST, implode(":", $this->cipher_list));
				}
				else
				{
					curl_setopt($this->curl_handle, CURLOPT_SSL_CIPHER_LIST, $this->cipher_list);
				}
			}

			if($this->ssl_allow_insecure)
			{
				curl_setopt($this->curl_handle , CURLOPT_SSL_VERIFYPEER, 0);
				curl_setopt($this->curl_handle , CURLOPT_SSL_VERIFYHOST, 0);
			}

			if($this->ssl_cert_files)
			{
				curl_setopt($this->curl_handle , CURLOPT_CAINFO, implode(', ', $this->ssl_cert_files));
			}

			/* Set the user agent and headers for this specific call. */
			curl_setopt($this->curl_handle, CURLOPT_USERAGENT, $this->user_agent);

			if(is_array($extra_headers))
			{
				curl_setopt($this->curl_handle, CURLOPT_HTTPHEADER, array_merge($this->headers, $extra_headers));
			}
			else
			{
				curl_setopt($this->curl_handle, CURLOPT_HTTPHEADER, $this->headers);
			}

			/* Set cURL to behave like a normal browser on redirects. */
			curl_setopt($this->curl_handle, CURLOPT_FOLLOWLOCATION, true);

			/* Tell cURL where to find and store cookies. */
			curl_setopt($this->curl_handle, CURLOPT_COOKIEJAR, $this->cookie_jar);
			curl_setopt($this->curl_handle, CURLOPT_COOKIEFILE, $this->cookie_jar);

			/* Instruct cURL to return the entire page as a string, and not print it out. */
			curl_setopt($this->curl_handle, CURLOPT_RETURNTRANSFER, true);

			/* return headers to */
			curl_setopt($this->curl_handle, CURLOPT_HEADER, 1);

			/* If we have specified a referer.. */
			if($referer)
			{
				/* use the given referer. */
				curl_setopt($this->curl_handle, CURLOPT_REFERER, $referer);
			}
			else
			{
				/* use the last called url for the referer. */
				curl_setopt($this->curl_handle, CURLOPT_REFERER, $this->referer);
			}

			return TRUE;
		}

		function curl_exec()
		{
			/* Post the form and store the returned page. */
			$output = curl_exec($this->curl_handle);

			if($output === FALSE)
			{
				$this->error = curl_error($this->curl_handle);
				$page = $this->error;
			}
			else
			{
				$page = $this->remove_headers_from_content($output);
				$this->error = NULL;
			}

			/* store info about last page */
			$this->info = curl_getinfo($this->curl_handle);

			/* Close cURL and return all resources. */
			curl_close($this->curl_handle);

			if($this->info['url'])
			{
				$this->url = $this->info['url'];
			}

			/* Store the called url as the referer for the next call. */
			$this->referer = $this->url;

			/* Return the page. */
			return $page;
		}

		/**
		 * Posts a form field on a page specified by $post_data and returns the resulting page.
		 **/
		function post_page($url, $post_data, $referer = "", $extra_headers = NULL)
		{
			$result = $this->init_curl_call($url, $referer, $extra_headers);

			if(!$result) return $result;

			/* Instruct cURL to post a form and supply the form fields. */
			curl_setopt($this->curl_handle, CURLOPT_POST, true);

			if(is_array($post_data))
			{
				curl_setopt($this->curl_handle, CURLOPT_POSTFIELDS, $this->array2list($post_data));
			}
			else
			{
				curl_setopt($this->curl_handle, CURLOPT_POSTFIELDS, $post_data);
			}

			return $this->curl_exec();
		}

		/**
		 * Fetches a the page specificed by $url and returns it as a string.
		 **/
		function get_page($url, $referer = "", $extra_headers = NULL)
		{
			$result = $this->init_curl_call($url, $referer, $extra_headers);

			if(!$result) return $result;

			return $this->curl_exec();
		}

		function download_page($url, $filename, $referer = "")
		{
			$result = $this->init_curl_call($url, $referer, $extra_headers);

			if(!$result) return $result;

			$file_pointer = fopen($filename, 'w');
			curl_setopt($this->curl_handle, CURLOPT_RETURNTRANSFER, FALSE);
			curl_setopt($this->curl_handle, CURLOPT_FILE, $file_pointer);

			/* Post the form and store the returned page. */
			curl_exec($this->curl_handle);
			fclose($file_pointer);

			if(!filesize($filename))
			{
				$this->error = curl_error($this->curl_handle);
// 				$page = $this->error;
// 				file_put_contents($filename,  $this->error);
			}
			else
			{
				$this->error = NULL;
			}

			/* store info about last page */
			$this->info = curl_getinfo($this->curl_handle);

			/* Close cURL and return all resources. */
			curl_close($this->curl_handle);

			/* Store the called url as the referer for the next call. */
			$this->referer = $url;

			/* Return the page. */
			return filesize($filename);
		}

		/**
		 * Converts an array of post or get variables into a list in the form:
		 * key1=value1&key2=value2&...&keyN=valueN
		 **/
		function array2list($array)
		{
			/* If the supplied array is not an array.. */
			if(!is_array($array))
			{
				/* Return it as it is. */
				return $array;
			}
			else
			{
				$list = array();

				/* For each element of the array.. */
				foreach($array as $array_key => $array_value)
				{
					/* print the element out as key=value on a long string. */
					$list[$array_key] = $this->subarray2list($array_value, $array_key);
				}

				/* Returned the string with all elements on one line. */
				return implode("&", $list);
			}
		}

		function subarray2list($array, $prepend_name)
		{
			/* If the supplied array is not an array.. */
			if(!is_array($array))
			{
				/* Return it as it is. */
				return urlencode($prepend_name) . "=" . urlencode($array);
			}
			else
			{
				$list = array();

				/* For each element of the array.. */
				foreach($array as $array_key => $array_value)
				{
					/* print the element out as key=value on a long string. */
					$list[$array_key] = $this->subarray2list($array_value, "{$prepend_name}[{$array_key}]");
				}

				/* Returned the string with all elements on one line. */
				return implode("&", $list);
			}
		}

		function remove_headers_from_content($content, $reset = TRUE)
		{
			if($reset)
			{
				$this->recived_headers = array();
			}

			while(substr($content, 0, 5) == 'HTTP/')
			{
				$str_pos_rnrn = strpos($content, "\r\n\r\n");
				$str_pos_rnrn = $str_pos_rnrn ? $str_pos_rnrn : strlen($content);
				$str_pos_nn = strpos($content, "\n\n");
				$str_pos_nn = $str_pos_nn ? $str_pos_nn : strlen($content);
				$split_pos = min($str_pos_rnrn, $str_pos_nn);
				if($split_pos)
				{
					$this->recived_headers[] = rtrim(substr($content, 0, $split_pos));
					$content = ltrim(substr($content, $split_pos));
				}
				else
				{
					break;
				}
			}

			return $content;
		}

		function getinfo()
		{
			return $this->info;
		}
	}

	/* Include an instance of the remote site. */
	$remote_site = new remote_site();
?>
