<?php
require_once __DIR__.'/SilverpopBaseConnector.php';
require_once __DIR__.'/SilverpopConnectorException.php';

/**
 * This is a basic class for connecting to the Silverpop XML API. If you
 * need to connect only to the XML API, you can use this class directly.
 * However, if you would like to utilize resources spread between the XML
 * and REST APIs, you shoudl instead use the generalized SilverpopConnector
 * class.
 * 
 * @author Mark French, Argyle Social
 */
class SilverpopXmlConnector extends SilverpopBaseConnector {
	protected static $instance = null;

	protected $baseUrl   = null;
	protected $username  = null;
	protected $password  = null;
	protected $sessionId = null;

	///////////////////////////////////////////////////////////////////////////
	// PUBLIC ////////////////////////////////////////////////////////////////
	/////////////////////////////////////////////////////////////////////////

	/**
	 * Performs Silverpop authentication using the supplied credentials,
	 * or with the cached credentials if none are supplied. Any new credentials
	 * will be cached for the next request.
	 * 
	 * @param string $clientId
	 * @param string $clientSecret
	 * @param string $refreshToken
	 *
	 * @throws SilverpopConnectorException
	 */
	public function authenticate($username=null, $password=null) {
		$this->username = empty($username) ? $this->username : $username;
		$this->password = empty($password) ? $this->password : $password;

		$params = "<Envelope>
	<Body>
		<Login>
			<USERNAME>{$username}</USERNAME>
			<PASSWORD>{$password}</PASSWORD>
		</Login>
	</Body>
</Envelope>";

		$ch = curl_init();
		$curlParams = array(
			CURLOPT_URL            => $this->baseUrl.'/XMLAPI',
			CURLOPT_FOLLOWLOCATION => 1,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_MAXREDIRS      => 3,
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_POST           => 1,
			CURLOPT_POSTFIELDS     => http_build_query(array('xml'=>$params)),
			);
		$set = curl_setopt_array($ch, $curlParams);

		$resultStr = curl_exec($ch);
		curl_close($ch);
		$result = $this->checkResponse($resultStr);

		$this->sessionId = $result->Body->RESULT->SESSIONID;
	}

	/**
	 * Initiate a job to export a list from Silverpop. Will return a job ID
	 * that can be queried to determine when the list is complete, and a file
	 * path to retrieve it.
	 * 
	 * @param int    $listId
	 * @param int    $startDate A timestamp for date boundaries
	 * @param int    $endDate   A timestamp for date boundaries
	 * @param string $type      {ALL, OPT_IN, OPT_OUT, UNDELIVERABLE}
	 * @param string $format    {CSV, TAB, PIPE}
	 * @return array An array of ('jobId'=>[int],'filePath'=>[string])
	 */
	public function exportList($listId, $startDate=null, $endDate=null, $type='ALL', $format='CSV') {
		$listId = (int)$listId;
		$type   = urlencode(strtoupper($type));
		$format = urlencode(strtoupper($format));

		$params = "<ExportList>
	<LIST_ID>{$listId}</LIST_ID>
	<EXPORT_TYPE>{$type}</EXPORT_TYPE>
	<EXPORT_FORMAT>{$format}</EXPORT_FORMAT>\n";
		if (!empty($startDate)) {
			$params .= '	<DATE_START>'.date('m/d/Y H:i:s', $startDate)."</DATE_START>\n";
		}
		if (!empty($endDate)) {
			$params .= '	<DATE_END>'.date('m/d/Y H:i:s', $endDate)."</DATE_END>\n";
		}
		$params .= '</ExportList>';
		$params = new SimpleXmlElement($params);
		$result = $this->post($params);
		return array(
			'jobId'    => $result->Body->RESULT->JOB_ID,
			'filePath' => $result->Body->RESULT->FILE_PATH,
			);
	}

	/**
	 * Check the status of a data job.
	 * 
	 * @param int $jobId
	 * @return string {WAITING, RUNNING, CANCELLED, ERROR, COMPLETE}
	 */
	public function getJobStatus($jobId) {
		$jobId = (int)$jobId;

		$params = "<GetJobStatus>\n\t<JOB_ID>{$jobId}</JOB_ID>\n</GetJobStatus>";
		$params = new SimpleXmlElement($params);
		$result = $this->post($params);
		return (string)$result->Body->RESULT->JOB_STATUS;
	}

	/**
	 * Get a set of lists (DBs, queries, and contact lists) defined for this
	 * account.
	 * 
	 * @return array Returns an array of SimpleXmlElement objects, one for each list
	 * @throws SilverpopConnectorException
	 */
	public function getLists() {
		// Get private lists
		$params = '<GetLists>
	<VISIBILITY>0</VISIBILITY>
	<LIST_TYPE>2</LIST_TYPE>
</GetLists>';
		$params = new SimpleXmlElement($params);
		$result = $this->post($params);
		$lists = array();
		foreach ($result->Body->RESULT->LIST as $list) {
			$lists[] = $list;
		}

		// Get shared lists
		$params = '<GetLists>
	<VISIBILITY>1</VISIBILITY>
	<LIST_TYPE>2</LIST_TYPE>
</GetLists>';
		$params = new SimpleXmlElement($params);
		$result = $this->post($params);
		foreach ($result->Body->RESULT->LIST as $list) {
			$lists[] = $list;
		}
		return $lists;
	}

	/**
	 * Get a list of recipients modified within the specified time range.
	 * 
	 * @param int $lastModifiedStart An integer timestamp
	 * @param int $lastModifiedEnd   An integer timestamp
	 * @param int $listId
	 * 
	 * @return array Returns an array of SimpleXmlElement objects, one for each recipient
	 * @throws SilverpopConnectorException
	 */
	public function getModifiedRecipients($lastModifiedStart, $lastModifiedEnd, $listId) {
		$listId = (int)$listId;

		$params = "<GetModifiedRecipients>
	<INSERTS_ONLY>false</INSERTS_ONLY>
	<CONTACT_TYPE>Contact</CONTACT_TYPE>
	<LIST_ID>{$listId}</LIST_ID>
	<INSERTS_ONLY>false</INSERTS_ONLY>
	<LAST_MODIFIED_TIME_START>".date('m/d/Y H:i:s', $lastModifiedStart).'</LAST_MODIFIED_TIME_START>
	<LAST_MODIFIED_TIME_END>'.date('m/d/Y H:i:s', $lastModifiedEnd).'</LAST_MODIFIED_TIME_END>
	<COLUMNS>
		<COLUMN name="FirstName" />
		<COLUMN name="LastName" />
		<COLUMN name="Email" />
	</COLUMNS>
</GetModifiedRecipients>';
		$params = new SimpleXmlElement($params);

		$result =$this->post($params);
		$modifiedRecipients = array();
		foreach ($result->Body->RESULT->RECIPIENTS as $recipient) {
			$modifiedRecipients[] = $recipient;
		}
		return $modifiedRecipients;
	}

	/**
	 * Stream an exported file back from Silverpop over HTTP. Will either
	 * stream file content to the specified local filesystem path, or return
	 * the contents as a string. Note that for very large export files, resource
	 * limits for the PHP process might cause returning as a string to generate
	 * an out-of-memory error.
	 * 
	 * @param string $filePath
	 * @param string $output   A file path to write the result (or null to return string)
	 * @return mixed Returns TRUE/FALSE to indicate success writing file, or
	 * 	string content of no output path provided
	 */
	public function streamExportFile($filePath, $output=null) {
		$urlParams = '?filePath='.urlencode($filePath);
		return $this->get('StreamExportFile', $urlParams, $output);
	}

	/**
	 * Update a recipient in Silverpop.
	 * 
	 * @param int   $recipientId The ID of the recipient to update
	 * @param int   $listId      The ID of the recipient's list
	 * @param array $fields      An associative array of keys and values to update
	 * @return SimpleXmlElement
	 * @throws SilverpopConnectorException
	 */
	public function updateRecipient($recipientId, $listId, $fields) {
		$recipientId = (int)$recipientId;
		$listId = (int)$listId;

		$params = "<UpdateRecipient>\n\t<RECIPIENT_ID>{$recipientId}</RECIPIENT_ID>\n\t<LIST_ID>{$listId}</LIST_ID>\n";
		foreach ($fields as $key => $value) {
			$params .= "\t<COLUMN>\n\t\t<NAME>{$key}</NAME>\n\t\t<VALUE>{$value}</VALUE>\n\t</COLUMN>\n";
		}
		$params .= '</UpdateRecipient>';
		$params = new SimpleXmlElement($params);

		$result = $this->post($params);
		return $result->Body->RESULT->RecipientId;
	}


	//////////////////////////////////////////////////////////////////////////
	// PROTECTED ////////////////////////////////////////////////////////////
	////////////////////////////////////////////////////////////////////////

	/**
	 * Check the XML response to ensure it contains the required elements
	 * and reports success.
	 * 
	 * @param string $xml
	 * @return SimpleXmlElement
	 * @throws SilverpopConnectorException
	 */
	protected function checkResponse($xml) {
		$response = new SimpleXmlElement($xml);
		$nodes = array('Body', 'RESULT', 'SUCCESS');
		if (!isset($response->Body)) {
			throw new SilverpopConnectorException("No <Body> element on response: {$xml}");
		} elseif (!isset($response->Body->RESULT)) {
			throw new SilverpopConnectorException("No <RESULT> element on response body: {$xml}");
		} elseif (!isset($response->Body->RESULT->SUCCESS)) {
			throw new SilverpopConnectorException("No <SUCCESS> element on result: {$xml}");
		} elseif (strtolower($response->Body->RESULT->SUCCESS) != 'true') {
			throw new SilverpopConnectorException('Request failed: '.$response->Body->Fault->FaultString);
		}
		return $response;
	}

	/**
	 * Perform an HTTP GET request.
	 * 
	 * @param string $url
	 * @param string $urlParams
	 * @param string $outputFile Filepath to write output. Output returned if null.
	 * @param string $fileMode   File output mode (any mode argument accepted by fopen)
	 * @return mixed Returns content if no output file is specified, boolean otherwise
	 */
	protected function get($url, $urlParams, $outputFile=null, $fileMode='w') {
		$url = $this->baseUrl.$url.";jsessionid={$this->sessionId}";
		if (!empty($urlParams)) {
			$url .= "?{$urlParams}";
		}

		$ch = curl_init();
		$curlParams = array(
			CURLOPT_URL            => $url,
			CURLOPT_FOLLOWLOCATION => 1,//true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_MAXREDIRS      => 3,
			);
		if (!empty($outputFile)) {
			$fh = fopen($outputFile, $fileMode);
			$curlParams[CURLOPT_FILE] = $fh;
		} else {
			$curlParams[CURLOPT_RETURNTRANSFER] = 1;
		}
		$response = curl_exec($ch);
		curl_close($ch);
		return $response;
	}

	/**
	 * Send a POST request to the API
	 * 
	 * @param SimpleXmlElement $params        Parameters to pass to the requested resource
	 * @param string           $pathExtension Defaults to XML API endpoint
	 *
	 * @return SimpleXmlElement Returns an XML response object
	 * @throws SilverpopConnectorException
	 */
	protected function post($params, $pathExtension='/XMLAPI', $urlParams='') {
		// Wrap the request XML in an "envelope" element
		$envelopeXml = "<Envelope>\n\t<Body>\n";
		$params = $params->asXml();
		$paramLines = explode("\n", $params);
		$paramXml = '';
		for ($i=1; $i<count($paramLines); $i++) {
			$paramXml .= "\t\t{$paramLines[$i]}\n";
		}
		$envelopeXml .= $paramXml;
		$envelopeXml .= "\n\t</Body>\n</Envelope>";
		$xmlParams = http_build_query(array('xml'=>$envelopeXml));

		$url = $this->baseUrl."/XMLAPI;jsessionid={$this->sessionId}";
		$curlHeaders = array(
				'Content-Type: application/x-www-form-urlencoded',
				'Content-Length: '.strlen($xmlParams),
				);

		$ch = curl_init();
		$curlParams = array(
			CURLOPT_URL            => $url,
			CURLOPT_FOLLOWLOCATION => 1,//true,
			CURLOPT_POST           => 1,//true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_MAXREDIRS      => 3,
			CURLOPT_POSTFIELDS     => $xmlParams,
			CURLOPT_RETURNTRANSFER => 1,//true,
			CURLOPT_HTTPHEADER     => $curlHeaders,
			);
		curl_setopt_array($ch, $curlParams);

		$result = curl_exec($ch);
		curl_close($ch);
		return $this->checkResponse($result);
	}
}