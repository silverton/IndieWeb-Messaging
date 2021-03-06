<?php
include('config.php');

if(array_key_exists('from', $_POST) && array_key_exists('text', $_POST)) {
	
	// Validate the "from" address looks like a domain name with no path

	// Add a leading http:// if it doesn't exist
	if(!preg_match('|^https?://|', $_POST['from'])) {
		$from = 'http://' . $_POST['from'];
	} else {
		$from = $_POST['from'];
	}

	// Make sure there are no slashes, otherwise it's not a valid "from" address
	if(strpos($from, '/', 8) !== FALSE) {
		msg('Error: Your "from" address must be only a domain name with no path.');
	}

	// By this point, we can be sure the "from" address is only the hostname part
	$domain = preg_replace('|^https?://|', '', $from);
	
	$verified = FALSE;

	// If a 'message_id' parameter is present, query the sender to ask if this message came from them
	if(array_key_exists('message_id', $_POST)) {
		$challengeInt = rand(100,999);
	
		debug('Sending challenge to ' . $domain . ': ' . $challengeInt);
	
		$ch = curl_init('http://' . $domain . '/');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_POST, TRUE);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
			'message_id' => $_POST['message_id'],
			'challenge' => 'FactorInteger[' . $challengeInt . ']'
		)));
		$output = trim(curl_exec($ch));
		
		// If the remote server did not confirm the message, don't send it.
		if($output == 'denied') {
			msg('Error: "From" domain (' . $domain . ') did not confirm the message');
		}
		
		$factorization = new PrimeFactor($challengeInt);
		$challengeAnswer = '' . $factorization;
		
		debug('Got a reply: ' . $output . ', expected answer is ' . $challengeAnswer);
		
		if($output != $challengeAnswer) {
			echo '-' . $output . $from . "-\n";
			msg('Error: "From" domain (' . $domain . ') did not answer the challenge correctly');
		}
		
		$verified = TRUE;
	}

	if($verified) {
		$msg = $domain . ': ' . $_POST['text'];
		sendAndCloseConnection("Success: Your message was verified and sent!\n");
	} else {
		$msg = '(' . $domain . ') ' . $_POST['text'];
		sendAndCloseConnection("Success: Your message was sent without verification.\n");
	}
	
	if(isset($N))
		$N->Send($msg);
	
	// Save to a file
	$filename = date('YmdHis') . '-' . $domain . '.txt';
	$contents = $from . "\n" 
		. ($verified ? $_POST['message_id'] . "\n" : '') 
		. "\n" . $_POST['text'];
	file_put_contents('received/' . $filename, $contents);
	
	if(defined('TROPO_TOKEN') && TROPO_TOKEN) {
		// Send the SMS via Tropo (requires additional setup at Tropo.com)
		$params = array(
			'action' => 'create',
			'token' => TROPO_TOKEN,
			'message' => $msg,
			'number' => SMS_RECIPIENT
		);
		file_get_contents('https://api.tropo.com/1.0/sessions?' . http_build_query($params));
	}
	
	if(defined('EMAIL_RECIPIENT') && EMAIL_RECIPIENT) {
		$headers = array();
		$headers[] = 'From: ' . $domain . ' <indieweb@' . $_SERVER['SERVER_NAME'] . '>';
		mail(EMAIL_RECIPIENT, 'Message from ' . $domain, $_POST['text'], implode("\r\n", $headers));
	}
}
else if(array_key_exists('message_id', $_POST)) {
	// A remote server is asking us to verify that we sent the specified message
	
	// Simple version is implemented by looking for a matching file in the filesystem
	
	// Sanitize the message ID. We generated it so it's safe to do.
	$message_id = preg_replace('/[^a-z0-9-]/', '', $_POST['message_id']);
	
	if(file_exists('sent/' . $message_id . '.txt')) {
		// Now solve the challenge they sent
		if(array_key_exists('challenge', $_POST) && preg_match('/FactorInteger\[(\d+)\]/', $_POST['challenge'], $match)) {
			if($match[1] < 4294967295) {
				$factorization = new PrimeFactor($match[1]);
				msg(''.$factorization);
			} else {
				msg('challenge denied');
			}
		} else {
			msg('confirmed');
		}
	} else {
		msg('denied');
	}
}
else {
	msg('Error: Send a POST request with fields "from" and "text"');
}


function msg($msg) {
	global $N;
	if(isset($N))
		$N->Send('Received from: ' . $_SERVER['SERVER_NAME'] . ' ' . $msg);
	echo $msg . "\n";
	die();
}

function debug($msg) {
	global $N;
	if(isset($N))
		$N->Send('Debug: ' . $_SERVER['SERVER_NAME'] . ' ' . $msg);
}

function sendAndCloseConnection($msg) {
	// Close the user's browser connection but keep the PHP script running
	// See http://www.php.net/manual/en/features.connection-handling.php#71172
	@ob_end_clean();
	header("Connection: close");
	ignore_user_abort(); // optional
	ob_start();

	echo $msg;

	$size = ob_get_length();
	header("Content-Length: $size");
	ob_end_flush();
	flush();
}

class PrimeFactor {
	public $factors = array();
	private $num;
	
	public function __construct($num) {
		$this->num = $num;
		$run = true;
		while($run && @$this->factors[0] != $num) {
			$run = $this->run();
		}
	}
	
	public function __toString() {
		$parts = array();
		foreach(array_count_values($this->factors) as $n=>$c) {
			$parts[] = '{' . $n . ',' . $c . '}';
		}
		return '{' . implode(',', $parts) . '}';
	}
	
	private function run() {
		if($this->num == 1) {
			return ;
		}
		$this->root = ceil(sqrt($this->num)) + 1;
		$i = 2;
		while($i <= $this->root) {
			$this->count++;
			if($this->num % $i == 0) {
				$this->factors[] = $i;
				$this->num = $this->num / $i;
				return true;
			}
			$i++;
		}
		$this->factors[] = $this->num;
		return false;
	}
}