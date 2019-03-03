#!/opt/local/bin/php73
<?php
    // DEMONSTRATION CALL CODE WITH EXCEPTION CATCHING
    // ...
    try {
        $invoice_number = submit_quote(
                "Molly Terrier",   // full name
                "foo@bar.com",      // Emai address
                "888.555.1212",     // phone numbner
                "123 Main St.",     // address line 1
                "Apt. D-22",        // address line 2 (optional). Pass in empty string if no value.
                "Foo",              // City
                "TX",               // State
                "01234",            // ZIP code
                "2019-03-05",       // Effective Date. Must be YYYY-MM-DD.
                1275                // Premium dollar amount as integer or float.
        );
        print 'Invoice number: ' . strval($invoice_number);
        print "\n";

    } catch (SOAPCallException $se) {
        print "SoapCallException caught ...\n";
        print $se->message;
        print "\n\n";
        print implode("; ", $se->additional_information_to_log_array);

    } catch (QuoteGenerationException $qge) {
        print "QuoteGenerationException caught ...\n";
        print implode("\n", $qge->errors);
    }
?>
<?php

// Definitions of submit_quote() and friends ...

// Take the data, return either an integer quote number or throw
// either a SOAPCallException or a QuoteGenerationException,
// both exception classes defined right after this function.
function submit_quote(
            $name, $email_address, $phone_number,
            $address_one, $address_two,
            $city, $state, $zip,
            $effective_date,
            $insurance_product_price)
{

    // Most of the data get entombed in a XML document we'll pass
    // as one parameter of the SOAP call..
    $xml_innards = __build_xml($name, $email_address, $phone_number,
        $address_one, $address_two, $city, $state, $zip,
        $insurance_product_price, $effective_date);


    try {

         // Learn URL, username, password ...
        $soap_params = __get_soap_url_and_credentials();

        $client = new SoapClient(

                        // WSDL URL ...
                        $soap_params['service_url'],

                        // Soap connection options ...
                        [
                            'connection_timeout' => 10,
                            'compression' => true,
                            'cache_wsdl' => WSDL_CACHE_BOTH,
                            'trace' => true
                        ]
                    );

        // Preamble part of the required request info to pass over:
        // authentication and user type info.
        $auth = array(
            "UserName" => $soap_params['username'],
            "UserPassword" => $soap_params['password'],
            "UserType" => "Entity",
            "PortfolioCode" => "900"
        );

        // Main part of the request, with the real main info describing the
        // customer and the invoice details as an XML document.
        $payload = array(
            "XmlQuoteImport" => $xml_innards,
            "Options" => [
                "ReturnQuoteInfo" => True,
                "ReturnPFA" => False
            ]
        );

        $response = $client->ImportQuote(['authInfo' => $auth, 'qiRequest' => $payload]);

    } catch (Exception $e) {
        throw new SOAPCallException(
            "A communication issue happened while generating the invoice",
            [
                $client->__getLastRequest(),
                $client->__getLastResponseHeaders(),
                $client->__getLastResponse(),
                strval($e)
            ]
        );
    }

    // Successful call if 3 things line up happy:

    // 1) No errors reported!
    //    Must convert from stdCls -> array, thanks for info,
    //      https://stackoverflow.com/questions/6905237/soap-result-to-variable-php
    //
    $error_array = __objToArray($response->ImportQuoteResult->Errors);
    if (!empty($error_array))
    {
        // Ugh. $error_array will right now be a single-value'd
        // array: "string" -> the actual array of messages (or a single string).
        // So re-bind $error_array to the 'string' member. I love SOAP
        // and this particular interface.
        $error_array = $error_array['string'];

        if(gettype($error_array) == "string")
        {
            // hey! Not an array! Just a single String. Thanks grr ;-/.
            // We want a single-item array then.
            $error_array = [$error_array];
        }

        // Now error_array references an actual list of error messages!
        throw new QuoteGenerationException($error_array);
    }

    // 2) Sane quote number?
    $quote_number = $response->ImportQuoteResult->QuoteInformation->QuoteNumber;
    if(!$quote_number || $quote_number < 1)
    {
        throw new SOAPCallException(
            "Odd! The generated quote has no quote number!",
            [
                $client->__getLastRequest(),
                $client->__getLastResponseHeaders(),
                $client->__getLastResponse()
            ]
        );
    }

    // 3) Premium matches what we sent
    $ackd_premium = $response->ImportQuoteResult->QuoteInformation->TotalPremium;
    if($ackd_premium != $insurance_product_price)
    {
        throw new SOAPCallException(
            "Very Odd! The generated quote does not agree with the price we asked!",
            [
                "What we asked for: " . strval($insurance_product_price),
                "What we got back: " . strval($ackd_premium),
                $client->__getLastRequest(),
                $client->__getLastResponseHeaders(),
                $client->__getLastResponse()
            ]
        );
    }

    // Hey! Success!
    return $quote_number;
}


class SOAPCallException extends Exception
{
    public $message, $additional_information_to_log_array;

    function __construct($display_error_message, $additional_information_to_log_array)
    {
        $this->message = $display_error_message; // for customer display purposes

        // Internal logging. Would like to get these into a log file
        // and email to Ken.
        $this->additional_information_to_log_array = $additional_information_to_log_array;
    }
}

class QuoteGenerationException extends Exception
{
    public $errors;

    function __construct($error_array)
    {
        $this->errors = $error_array;
    }
}

//
// All of the rest of this is internal helper routines.
//

function __get_soap_url_and_credentials()
{
    // Currently the DEMO settings w/o real username and password values.
    return [
        'service_url' =>
            "https://demo.pbs.first-quotes.com/ExternalServices/PBSWebService.asmx?WSDL",
        'username' => 'REAL_USERNAME_HERE',
        'password' => 'REAL_PASSWORD_HERE'
    ];
}

function __load_defaults()
{
    return array (
        "Quoting_For" => "G00119",
        "Carrier_Code" => "C00006",
        "GA_code" => "G00119",
        "Agent_Code" => "A00191",
        "Coverage_Code" => "LIAB CYBER",

        "Quote_Profile" => "Commercial",
        "Country" => "USA",
        "Earned_Taxes_Fees" => "0",
        "Financed_Taxes_Fees" =>  "0",
        "Policy_Term" => "12",

        "Agent_Name"  => "Anonymous Agent",     # Need better value
        "Carrier_Name" => "Carrier Name Here",  # Need better value
        "Policy_Number" => "999999",            # Need better value
    );
}



function __build_xml($name, $email_address, $phone_number,
    $address_one, $address_two, $city,
    $state, $zipcode, $premium_amount, $effective_date)
{

    $defaults = __load_defaults();

    $document = new DOMDocument('1.0', 'UTF-8');
    $root = $document->createElementNS('TemporaryQuote', 'tq:QuoteInfo');

    $cust_info = $document->createElement('tq:CustomerInfo');
    $cust_info_attributes = array (
        "Name_1" => $name,
        "Main_Phone" => $phone_number,
        "E-Mail" => $email_address,
        "Address_Line_1" => $address_one,
        "City" => $city,
        "Region" => $state,
        "Postal_Code" => $zipcode,
    );

    foreach($cust_info_attributes as $attrname => $value)
    {
        $cust_info->setAttribute($attrname, $value);
    }

    // Optional param ...
    if ($address_two != "")
    {
        $cust_info_attributes["Address_Line_2"] = $address_two;
    }


    // These attrs come from defaults array. Migrate from defaults to cust_info
    // attributes, deleting from defaults as we go so that we can be sure to have
    // ultimately consumed all of defaults by the end of all of this.
    foreach(["Quoting_For", "Agent_Code", "Quote_Profile",
                    "Country", "Agent_Name"] as $attrname)
    {
        $value = $defaults[$attrname];
        $cust_info->setAttribute($attrname, $value);
        unset($defaults[$attrname]);
    }

    $root->appendChild($cust_info);

    // Done with cust_info, now on to the policy
    $policy_info = $document->createElement('tq:PolicyInfo');
    $policy_count = $document->createElement('tq:Policy_Count', "1");
    $policy_info->appendChild($policy_count);

    $policy = $document->createElement('tq:Policy');
    $policy->setAttribute("Effective_Date", $effective_date);
    $policy->setAttribute("Premium", $premium_amount);


    foreach(["Policy_Number", "Policy_Term", "Coverage_Code",
            "Earned_Taxes_Fees", "Financed_Taxes_Fees",
            "Carrier_Code", "Carrier_Name",
            "GA_code"] as $attrname)
    {
        $value = $defaults[$attrname];
        $policy->setAttribute($attrname, $value);
        unset($defaults[$attrname]);
    }

    $policy_info->appendChild($policy);
    $root->appendChild($policy_info);
    $document->appendChild($root);

    // Should have consumed all of defaults ...
    if (!empty($defaults))
    {
        throw new Exception('Should have emptied $defaults by now!');
    }

    $document->formatOutput = true;

    // Return string containing the document XML.
    return $document->saveXML();
}

function __objToArray($obj=false)  {
    if (is_object($obj))
        $obj= get_object_vars($obj);
    if (is_array($obj)) {
        return array_map(__FUNCTION__, $obj);
    } else {
        return $obj;
    }
}
?>
