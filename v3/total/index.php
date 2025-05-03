<?php
header("Access-Control-Allow-Origin: *");
header('Content-Type: application/json; charset=utf-8');

class EndpointNotAvailableException extends Exception {}

/**
 * Builds a query string from an associative array of parameters.
 *
 * This function takes an array of key-value pairs and converts them into a URL-encoded
 * query string. The resulting string starts with a question mark (?) followed by the
 * encoded parameters.
 *
 * @param array $params An associative array of parameters to be included in the query string.
 *                      The keys represent the parameter names, and the values represent the
 *                      parameter values.
 * 
 * @return string The URL-encoded query string starting with a question mark (?).
 */
function buildQueryString(array $params): string
{
    return '?' . http_build_query($params);
}

/**
 * Fetches JSON data from a given URL.
 *
 * @param string $url The URL to fetch JSON data from.
 * 
 * @return array The decoded JSON data as an associative array.
 * 
 * @throws EndpointNotAvailableException If the endpoint is not available, 
 *                                       if the content could not be retrieved, 
 *                                       or if the JSON response is invalid.
 */
function fetchJsonData(string $url): array
{
    $headers = @get_headers($url);

    if ($headers === false || strpos($headers[0], '200') === false) {
        throw new EndpointNotAvailableException('Endpoint-ul nu este disponibil: ' . $url);
    }

    $response = file_get_contents($url);
    if ($response === false) {
        throw new EndpointNotAvailableException('Nu s-a putut obține conținutul de la: ' . $url);
    }

    $json = json_decode($response, true);
    if ($json === null) {
        throw new EndpointNotAvailableException('Răspuns JSON invalid de la: ' . $url);
    }

    return $json;
}

// Ensure the request is a GET request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["error" => "Only GET method is allowed"]);
    exit;
}

// Load variables from the api.env file
function loadEnv($file)
{
    $file = realpath($file);

    // Check if the api.env file exists
    if (!$file || !file_exists($file)) {
        die(json_encode(["error" => "The api.env file does not exist!", "path" => $file]));
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments (lines starting with '#')
        if (strpos(trim($line), '#') === 0) continue;

        // Split the line into key and value, and add to environment
        list($key, $value) = explode('=', $line, 2) + [NULL, NULL];
        if ($key && $value) {
            $key = trim($key);
            $value = trim($value);
            $_SERVER[$key] = $value;
            putenv("$key=$value");
        }
    }
}

try {
    // Load api.env file
    loadEnv('../../api.env');

    // Retrieve SOLR variables from environment
    $server = getenv('PROD_SERVER') ?: ($_SERVER['PROD_SERVER'] ?? null);
    $username = getenv('SOLR_USER') ?: ($_SERVER['SOLR_USER'] ?? null);
    $password = getenv('SOLR_PASS') ?: ($_SERVER['SOLR_PASS'] ?? null);

    // Debugging: Check if the server is set
    if (!$server) {
        die(json_encode(["error" => "PROD_SERVER is not set in api.env"]));
    }

    $core = "jobs";  // Solr core name
    $qs = http_build_query([  // Query parameters for Solr
        "facet.field" => "company_str",
        "facet.limit" => "2000000",
        "facet" => "true",
        "fl" => "company",
        "indent" => "true",
        "q.op" => "OR",
        "q" => "*:*",
        "rows" => "0",
        "start" => "0",
        "useParams" => ""
    ]);

    // Build the Solr URL
    $url = "http://$server/solr/$core/select?$qs";

    // Set up the HTTP context for the request
    $context = stream_context_create([
        'http' => [
            'header' => "Authorization: Basic " . base64_encode("$username:$password")
        ]
    ]);

    // Fetch data from Solr
    $string = @file_get_contents($url, false, $context);

    if ($string === false) {
        $error = error_get_last();  // Get the last error
        http_response_code(503);
        echo json_encode([
            "error" => "SOLR server in DEV is down",
            "code" => 503,
            "details" => $error
        ]);
        exit;
    }

    // Decode the JSON response from Solr
    $json = json_decode($string, true);

    if ($json === null || !isset($json['facet_counts']['facet_fields']['company_str'])) {
        http_response_code(500);
        echo json_encode([
            "error" => "Invalid response from Solr",
            "code" => 500,
            "raw_response" => $string
        ]);
        exit;
    }

    // Extract company data from the Solr response
    $companies = $json['facet_counts']['facet_fields']['company_str'] ?? [];
    $companyCount = 0;
    for ($i = 1; $i < count($companies); $i += 2) {
        if ($companies[$i] > 0) {
            $companyCount++;
        }
    }

    // Prepare the final response
    echo json_encode([
        "total" => [
            "jobs" => (int) ($json['response']['numFound'] ?? 0),
            "companies" => (int) $companyCount
        ]
    ]);
} catch (EndpointNotAvailableException $e) {
    $backupServer = rtrim(getenv('BACK_SERVER'), '/');
    $backupUrl = $backupServer . '/mobile/total/';

    try {
        $json = fetchJsonData($backupUrl);

        $obj = (object) [
            'total' => (object) [
                'jobs' => (string) ($json['total'] ?? 0)
            ]
        ];

        echo json_encode($obj);
    } catch (Exception $backupException) {
        echo json_encode([
            'error' => 'Ambele endpoint-uri sunt indisponibile.',
            'details' => $backupException->getMessage()
        ]);
    }
}
