<?php
header("Access-Control-Allow-Origin: *");
header('Content-Type: application/json; charset=utf-8');

require_once './getLogo.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); // Method Not Allowed
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

class SolrQueryBuilder {
    public static function replaceSpaces($string) {
        return str_replace([' ', '&', '$'], ['%20', '%26', '%24'], $string);
    }

    public static function buildParamQuery($param, $queryName) {
        $arrayParams = explode(',', $param);
        $queries = array_map(function ($item) use ($queryName) {
            return $queryName . '%3A%22' . self::replaceSpaces($item) . '%22';
        }, $arrayParams);

        return '&fq=' . implode('%20OR%20', $queries);
    }

    public static function normalizeString($str) {
        $charMap = [
            'ă' => 'a', 'î' => 'i', 'â' => 'a', 'ș' => 's', 'ț' => 't',
            'Ă' => 'A', 'Î' => 'I', 'Â' => 'A', 'Ș' => 'S', 'Ț' => 'T'
        ];

        return strtr($str, $charMap);
    }
}

// Normalizează parametrii din $_GET
foreach ($_GET as $key => $value) {
    $_GET[$key] = SolrQueryBuilder::normalizeString($value);
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
    
    $core = 'jobs';
    $baseUrl = 'http://' . $server . '/solr/' . $core . '/select';

    // Construim query string-ul
    $query = '?indent=true&q.op=OR&';
    $query .= isset($_GET['q']) ? 'q=' . SolrQueryBuilder::replaceSpaces($_GET['q']) : 'q=*:*';
    $query .= isset($_GET['company']) ? SolrQueryBuilder::buildParamQuery($_GET['company'], 'company') : '';
    $query .= isset($_GET['city']) ? SolrQueryBuilder::buildParamQuery($_GET['city'], 'city') : '';
    $query .= isset($_GET['remote']) ? SolrQueryBuilder::buildParamQuery($_GET['remote'], 'remote') : '&q=remote%3A%22remote%22';

    $context = stream_context_create([
        'http' => [
            'header' => "Authorization: Basic " . base64_encode("$username:$password")
        ]
    ]);

    if (isset($_GET['page'])) {
        $start = ($_GET['page'] - 1) * 12;
        $query .= "&start=$start&rows=12";
    }

    $query .= '&useParams=';
    $url = $baseUrl . $query;

    // Verificăm disponibilitatea endpoint-ului
    $headers = @get_headers($url);
    if ($headers === false || strpos($headers[0], '200') === false) {
        throw new Exception('Endpoint-ul nu este disponibil');
    }

    // Obținem datele din Solr
    $json = file_get_contents($url, false, $context);
    $jobs = json_decode($json, true);

    // Adăugăm logo pentru fiecare job
    foreach ($jobs['response']['docs'] as &$job) {
        $company = $job['company'];
        $job['logoUrl'] = getLogo($company[0]);
    }

    echo json_encode($jobs);

} catch (Exception $e) {
    // Fallback la endpoint-ul de rezervă
    $backupUrl = $backup . '/mobile/';
    $fallbackQuery = isset($_GET['q']) ? '?search=' . SolrQueryBuilder::replaceSpaces($_GET['q']) : '?search=';
   
    $fallbackQuery .= isset($_GET['page']) ? '&page=' . $_GET['page'] : '';
    $citiesString = str_replace('~', '', $_GET['city'] ?? '');
    $fallbackQuery .= isset($_GET['city']) ? '&cities=' . $citiesString : '';
    $fallbackQuery .= isset($_GET['company']) ? '&companies=' . SolrQueryBuilder::replaceSpaces($_GET['company']) : '';
    $fallbackQuery .= isset($_GET['remote']) ? '&remote=' . SolrQueryBuilder::replaceSpaces($_GET['remote']) : '';

    $json = file_get_contents($backupUrl . $fallbackQuery);
    $jobs = json_decode($json, true);

    $newJobs = array_map(function ($job) {
        return [
            'job_title' => $job['job_title'],
            'company' => $job['company_name'],
            'city' => [$job['city']],
            'county' => [$job['county']],
            'remote' => $job['remote'],
            'job_link' => $job['job_link'],
            'id' => $job['id']
        ];
    }, $jobs['results'] ?? []);

    $response = (object)[
        'response' => (object)[
            'docs' => $newJobs,
            'numFound' => $jobs['count'] ?? 0
        ]
    ];

    echo json_encode($response);
}