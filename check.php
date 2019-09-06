<?php

require_once(dirname(__FILE__) . "/config.php");
require_once(GO_CHECK_GO_HOME . "/config.php");
require_once(dirname(__FILE__) . "/vendor/autoload.php");

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

$connection = new PDO(
  "mysql:dbname=" . GO_DATABASE_NAME . ";host=" . GO_DATABASE_HOST . ";",
  GO_DATABASE_USER, GO_DATABASE_PASS, array (PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));

$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

$select = $connection->prepare("
  SELECT
    code.name AS name,
    user.name AS user,
    code.url AS url,
    code.institution AS institution,
    user.name AS webid,
    user.notify AS send
  FROM code
  LEFT JOIN user_to_code
    ON (code.name = user_to_code.code)
  LEFT JOIN user
    ON (user_to_code.user = user.name)
  WHERE code.institution='middlebury.edu'
  ORDER BY code.name
");
$select->execute();

$codes = array();
$users = array();
$results = array();
$emails = array();

while($row = $select->fetch(PDO::FETCH_LAZY, PDO::FETCH_ORI_NEXT)) {
  $codes[$row->institution][$row->name] = $row->url;

  if ($row->send == "1") {
    // Fetch users' email addresses and add them to the users array.
    if (empty($emails[$row->webid])) {
      // For simplicity right now, fetch this from Middlebury's Directory web
      // interface and fail fast without errors. This should be improved to
      // fetch the user email from CAS or LDAP at some point.
      $user = @simplexml_load_file('https://directory.middlebury.edu/WebDirectory.asmx/uidSearch?uid=' . $row->webid);
      if (!empty($user)) {
        $email = @$user->xpath("//record/property[@name='mail']")[0]['value']->__toString();
        if (!empty($email)) {
          $emails[$row->webid] = $email;
        }
      }
    }

    if (!empty($emails[$row->webid])) {
      $users[$emails[$row->webid]][$row->url] = $row->institution . '/'. $row->name;
    }
  }

  $users[GO_CHECK_ADMIN_MAIL][$row->url] = $row->institution . '/'. $row->name;
}

foreach($codes as $institution => $codes) {
  foreach ($codes as $name => $url) {
    print "\nChecking $url\n";
    try {
      $client = new Client();
      $response = $client->request(
        "HEAD", $url, [
          "connect_timeout" => GO_CHECK_CONNECT_TIMEOUT,
          "timeout" => GO_CHECK_TIMEOUT,
          "allow_redirects" => [
            "max" => GO_CHECK_MAX_REDIRECTS,
            "track_redirects" => GO_CHECK_TRACK_REDIRECTS,
          ],
          "headers" => [
            "user_agent" => GO_CHECK_USER_AGENT,
          ],
        ]
      );
    }
    catch (BadResponseException $e) {
      $status = $e->getResponse()->getStatusCode();
      $results[$institution][$name] = $status;
    }
    catch (ConnectException $e) {
      $results[$institution][$name] = "Could not resolve host.";
    }
    catch (RequestException $e) {
      $results[$institution][$name] = $e->getMessage();
    }
    catch (Exception $e) {
      error_log("Caught " . $e);
    }
    if (!empty($results[$institution][$name])) {
      print "$url returned " . $results[$institution][$name] . "\n";
    }
  }
}

foreach($users as $email => $names) {
	asort($names);

	$message = ""; $count = 0;

  foreach($names as $url => $slug) {
    list($institution, $name) = explode('/', $slug, 2);
    if (isset($results[$institution][$name])) {
      $go = "http://go." . $slug;
      $edit = "http://go.middlebury.edu/update.php?code=" . urlencode($name) . "&institution=" . $institution;

      $message .= "GO:\t<a href=\"" . $go . "\">" . $go . "</a><br>";
      $message .= "URL:\t<a href=\"" . $url . "\">" . $url . "</a><br>";
      $message .= "STATUS:\t" . $results[$institution][$name] . "<br>";
      $message .= "EDIT:\t<a href=\"" . $edit . "\">" . $edit . "</a><br>";
      $message .= "<br>~*~*~*~*~*~*~*~*~*~*~*~*~*~*~*~*~*~*~*~<br>";

			$count++;
		}
	}

	if ($count > 0) {
		$message = "There were " . $count . " errors today.<br><br>" .
			"Below are the errors for today: <br><br>" . $message;

		$to = $email;
    $subject = GO_CHECK_EMAIL_SUBJECT;

    $headers = [];
    $headers[] = "From: " . GO_ALERTS_EMAIL_ADDRESS;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/html; charset=iso-8859-1';

    mail($to, $subject, $message, implode("\r\n", $headers));
	}
}
