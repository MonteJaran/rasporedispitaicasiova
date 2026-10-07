<?php
// calendarific_to_db.php
// Minimalni loader: Calendarific -> Postgres
// Usage: php calendarific_to_db.php
// Expectations: .env file u parent direktorijumu s DB_* varijablama i opcionalno API_KEY

// ---- helper: read .env iz parent dir (bez eksternih biblioteka) ----
function read_env_from_parent(): array {
    $envPath = __DIR__ . '/../../.env';
    if (!file_exists($envPath)) {
        throw new RuntimeException(".env not found at $envPath");
    }
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        $v = trim($v, "\"'");
        $env[$k] = $v;
    }
    return $env;
}

// ---- minimal HTTP GET using cURL ----
function http_get(string $url): string {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    // basic UA
    curl_setopt($ch, CURLOPT_USERAGENT, 'calendarific-php-loader/1.0');
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("cURL error: $err");
    }
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("HTTP $code when fetching $url");
    }
    return $resp;
}

// ---- connect PDO Postgres koristeći .env postavke (mimic tvog PHP primjera) ----
function pdo_connect(array $env): PDO {
    if (!isset($env['DB_HOST'], $env['DB_PORT'], $env['DB_NAME'], $env['DB_USER'], $env['DB_PASS'])) {
        throw new RuntimeException("Nedostaju DB_* varijable u .env");
    }

    // build DSN similar to tvoj PHP
    $sslmode = $env['DB_SSLMODE'] ?? '';
    $dsnParts = [
        'host=' . $env['DB_HOST'],
        'port=' . $env['DB_PORT'],
        'dbname=' . $env['DB_NAME'],
    ];

    if ($sslmode === 'require') {
        $dsnParts[] = 'sslmode=require';
    } else {
        if (!empty($env['DB_SSLROOTCERT'])) {
            // ako je setovano, dodaj verify-ca i sslrootcert
            $dsnParts[] = 'sslmode=verify-ca';
            $dsnParts[] = 'sslrootcert=' . $env['DB_SSLROOTCERT'];
        }
    }

    $dsn = 'pgsql:' . implode(';', $dsnParts);

    try {
        $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        echo "Greska pri konekciji: " . $e->getMessage() . PHP_EOL;
        exit(1);
    }
    return $pdo;
}

// ---- simple heuristic za is_working_day iz Calendarific 'type' polja ----
function is_working_from_types(array $types): int {
    // ako bilo koji tip sadrzi 'national' | 'public' | 'bank' => neradni (0)
    foreach ($types as $t) {
        $lt = strtolower($t);
        if (strpos($lt, 'national') !== false || strpos($lt, 'public') !== false || strpos($lt, 'bank') !== false) {
            return 0;
        }
    }
    // otherwise assume working day
    return 1;
}

// ---- main ----
try {
    $env = read_env_from_parent();
} catch (Exception $e) {
    echo "Ne mogu citati .env: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

$apiKey = $env['API_KEY'];

$base = 'https://calendarific.com/api/v2/holidays';
$country = 'ME';
$years = [2026, 2027, 2028, 2029, 2030, 2031, 2032, 2033, 2034, 2035, 2036, 2037, 2038, 2039, 2040, 2041, 2042, 2043, 2044, 2045, 2046, 2047, 2048, 2049];

$pdo = pdo_connect($env);

// Kreiraj tabelu ako ne postoji (minimalno) i jedinstveni index na (date,name) da ON CONFLICT radi.
// Ako ti tabela vec postoji, ovo je benigno.
$pdo->exec("
CREATE TABLE IF NOT EXISTS holiday (
    id SERIAL PRIMARY KEY,
    date DATE NOT NULL,
    name TEXT NOT NULL,
    is_working_day INT NOT NULL DEFAULT 1
);
");
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS holiday_date_name_idx ON holiday(date, name);");

// pripremi INSERT ... ON CONFLICT statement
$insertSql = "
INSERT INTO holiday (date, name, is_working_day)
VALUES (:date, :name, :is_working_day)
ON CONFLICT (date) DO UPDATE
  SET name = EXCLUDED.name,
      is_working_day = EXCLUDED.is_working_day
;
";
$insertStmt = $pdo->prepare($insertSql);

$totalInserted = 0;

foreach ($years as $year) {
    $url = $base . '?api_key=' . urlencode($apiKey) . '&country=' . urlencode($country) . '&year=' . intval($year);
    echo "Fetching $url" . PHP_EOL;
    try {
        $json = http_get($url);
    } catch (Exception $e) {
        echo "Greška pri dohvatu API: " . $e->getMessage() . PHP_EOL;
        continue;
    }
    $data = json_decode($json, true);
    if (!isset($data['response']['holidays']) || !is_array($data['response']['holidays'])) {
        echo "Pogrešan odgovor za $year, preskačem." . PHP_EOL;
        continue;
    }
    $holidays = $data['response']['holidays'];
    foreach ($holidays as $h) {
        // očekivano: name, date.iso, type (array)
        $name = $h['name'] ?? null;
        $dateIso = $h['date']['iso'] ?? null;
        $types = $h['type'] ?? [];
        if (!$name || !$dateIso) continue; // skip minimalisticki

        $isWorking = is_working_from_types(is_array($types) ? $types : [$types]);

        // izvrsi upsert
        try {
            $insertStmt->execute([
                ':date' => $dateIso,
                ':name' => $name,
                ':is_working_day' => $isWorking
            ]);
            $totalInserted++;
        } catch (PDOException $e) {
            // minimalno logovanje i nastavi dalje
            echo "DB greska za $dateIso / $name: " . $e->getMessage() . PHP_EOL;
            continue;
        }
    }
}

echo "Gotovo. Ubacio/azurirao $totalInserted redova." . PHP_EOL;
