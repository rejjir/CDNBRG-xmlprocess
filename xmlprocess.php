<?php
header('Content-Type: text/xml');
header('Content-Disposition: attachment; filename="text.xml"');
$incoming = file_get_contents('php://input');
$incoming = simplexml_load_string($incoming);

// validate incoming xml
if ($incoming)
	{
	$senderID = (string)$incoming->SenderID;
	$requestorID = (string)$incoming->RequestorLocationID;
	$transactionID = (string)$incoming->TransactionID;
	$requestDate = (string)$incoming->RequestDate;
	$idCode = (string)$incoming->IDCode;

	// Create xml file and root
	$xml = new DOMDocument("1.0");
	$root = $xml->createElement("DirectVendorAccess"); // root name
	$xml->appendChild($root);

	// Level 1 element data
	// Placeholder values will update with real values if query is successful
	$level1 = array();
	$level1["MessageType"] = "IR";
	$level1["SenderID"] = $senderID;
	$level1["RequestorLocationID"] = $requestorID;
	$level1["TransactionID"] = $transactionID;
	$level1["RequestDate"] = $requestDate;
	$dbruntime = 0; 
	$level1["ResponseTime"] = $dbruntime;
	$level1["IDCode"] = $idCode;
	$CntOfItmsRtrnd = 0;
	$CntOfItmsRtrndByLoc = 0;

	// check if there are valid requests
	$incdetail = $incoming->xpath('//Detail');
	if ($incdetail)
		{
		$level1["HeaderErrorCode"] = "0";
		$level1["HeaderErrorDesc"] = "Success";
		foreach($level1 as $key => $value)
			{
			$keyconv = (string)$key;
			$element = $xml->createElement($key);
			$node = $xml->createTextNode("$value");
			$element->appendChild($node);
			$root->appendChild($element);
			}

		// Inner elements (details->detail)
		$details = $xml->createElement("Details");
		$root->appendChild($details);

		// loop for multiple products
		foreach($incdetail as $inc)
			{
			$productQual = (string)$inc->ProductQualifier;
			$requestQuan = (string)$inc->QuantityRequested;
			$unitMeasure = (string)$inc->UnitOfMeasure;
			$productnum = (string)$inc->Product;

			// Start timer to calculate db running time
			$time_start = microtime(true);

			// Send request product search from external program through Linux pipe
			// bc Progress Database is not supported by PHP, requires proprietary software
			$sendPipe = "p2db";

			/* 
			// For unknown reasons, fwrite is not writing properly into the pipe
			$pipeFile = fopen($sendPipe, 'a') or die("can't open file");
			fwrite($pipeFile, $productnum);
			//fwrite($pipeFile, "1111"); // Even hardcoded numbers don't work
			fclose($pipeFile);
			*/

			// Workaround using a system call to write to pipe
			// Remove any special characters, including spaces
			// to prevent $productnum from being used for injection
			$productnum = preg_replace('/[^A-Za-z0-9]/', '', $productnum);
			exec("echo '$productnum' > $sendPipe");

			// Retrieve data from external program through Linux pipe
			$replyPipe = "db2p";
			$replyContents = file_get_contents($replyPipe);
			$replyContent = simplexml_load_string($replyContents);

			// Calculate db running time, in microseconds
			$time_end = microtime(true);
			$timediff = 1000000 * ($time_end - $time_start);
			$dbruntime += $timediff;

			// move query results into new xml
			$detail = $xml->createElement("Detail");
			$level2 = array();
			$level2["ProductQualifier"] = $productQual;
			$level2["Product"] = $productnum;
			$level2["QuantityRequested"] = $requestQuan;
			$level2["UnitOfMeasure"] = $unitMeasure;

			// Inner-inner elements (responsedetails->detail)
			$responseDetails = $xml->createElement("ResponseDetails");

			// check if product was found
			$resfound = $replyContent->productinfo->result;
			if ($resfound != "Found")
				{
				$level2["DetailErrorCode"] = "1";
				$level2["DetailErrorDesc"] = "Product not found";
				foreach($level2 as $key => $value)
					{
					$element = $xml->createElement($key);
					$node = $xml->createTextNode("$value");
					$element->appendChild($node);
					$detail->appendChild($element);
					}
				}
			  else
				{
				$level2["DetailErrorCode"] = "0";
				$level2["DetailErrorDesc"] = "Successful";
				$CntOfItmsRtrnd+= 1;
				foreach($level2 as $key => $value)
					{
					$element = $xml->createElement($key);
					$node = $xml->createTextNode("$value");
					$element->appendChild($node);
					$detail->appendChild($element);
					}

				// Create <responsedetail> for each location retrieved
				$whdata = $replyContent->xpath('//warehousdata');
				foreach($whdata as $wh)
					{
					$level3 = array();
					$level3["LocationID"] = $wh->locationid;
					$level3["ProductQualifier"] = $productQual;
					$level3["Product"] = $replyContent->productinfo->item;
					$level3["UnitOfMeasure"] = $unitMeasure;
					$level3["DateAvailable"] = $wh->DateAvailable;
					$level3["AvailableCutOffTime"] = $wh->AvailableCufOffTime;
					$level3["CityName"] = $wh->cityname;
					$level3["StateCode"] = $wh->statecode;
					$level3["CountryCode"] = $wh->countrycode;
					$level3["PostalCode"] = $wh->postalcode;
					$level3["AvailableQty"] = $wh->availableqty;
					$level3["PriceType"] = $replyContent->productinfo->PriceType;
					$level3["Price"] = $replyContent->productinfo->price;
					$level3["Warehouse"] = $wh->warehouse;
					$responseDetail = $xml->createElement("ResponseDetail");
					foreach($level3 as $key => $value)
						{
						$element = $xml->createElement($key);
						$node = $xml->createTextNode("$value");
						$element->appendChild($node);
						$responseDetail->appendChild($element);
						}

					$responseDetails->appendChild($responseDetail);
					$CntOfItmsRtrndByLoc+= 1;
					}
				}

			$detail->appendChild($responseDetails);
			$details->appendChild($detail);
			}
		}
	  else
		{
		$level1["HeaderErrorCode"] = "1";
		$level1["HeaderErrorDesc"] = "Incoming XML does not contain valid request for product";
		foreach($level1 as $key => $value)
			{
			$keyconv = (string)$key;
			$element = $xml->createElement($key);
			$node = $xml->createTextNode("$value");
			$element->appendChild($node);
			$root->appendChild($element);
			}

		// Inner elements (details->detail)

		$details = $xml->createElement("Details");
		$root->appendChild($details);
		}

	// add response time
	$dbruntime = number_format($dbruntime, 0, ".", "");
	$xml->getElementsByTagName("ResponseTime")->item(0)->nodeValue = $dbruntime;

	// add Count of items returned
	$element = $xml->createElement("CntOfItmsRtrnd");
	$node = $xml->createTextNode("$CntOfItmsRtrnd");
	$element->appendChild($node);
	$root->appendChild($element);
	$element = $xml->createElement("CntOfItmsRtrndByLoc");
	$node = $xml->createTextNode("$CntOfItmsRtrndByLoc");
	$element->appendChild($node);
	$root->appendChild($element);

	// Format & send
	$xml->formatOutput = true;
	echo $xml->saveXML();
	}

// Default response if incoming XML is not valid
else
	{
	$xml = new DOMDocument("1.0");
	$root = $xml->createElement("DirectVendorAccess");
	$xml->appendChild($root);
	$level1 = array();
	$level1["MessageType"] = "ERROR";
	$level1["HeaderErrorCode"] = "2";
	$level1["HeaderErrorDesc"] = "Did not receive valid XML";
	foreach($level1 as $key => $value)
		{
		$keyconv = (string)$key;
		$element = $xml->createElement($key);
		$node = $xml->createTextNode("$value");
		$element->appendChild($node);
		$root->appendChild($element);
		}

	$xml->formatOutput = true;
	echo $xml->saveXML();
	}
?>
