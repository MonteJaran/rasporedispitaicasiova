<?php
// test/test_auth.php

echo "---- TEST 2: Auth API Response ----\n";

$apiScript = realpath(__DIR__ . '/../src/api/auth.php');

if (!$apiScript) {
    die("❌ GRESKA: auth.php nije pronadjen.\n");
}

// Simuliramo login akciju sa lažnim podacima
$payload = json_encode([
    'action' => 'login',
    'email' => 'test_automatski@test.com',
    'password' => 'lazna_sifra_123'
]);

// Otvaramo proces da bi poslali podatke na STDIN (kao POST body)
$descriptors = [
    0 => ["pipe", "r"], // STDIN
    1 => ["pipe", "w"], // STDOUT
    2 => ["pipe", "w"]  // STDERR
];

$process = proc_open("php \"$apiScript\"", $descriptors, $pipes);

if (is_resource($process)) {
    // Pisemo JSON u input skripte
    fwrite($pipes[0], $payload);
    fclose($pipes[0]);

    // Citamo odgovor
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    // Provjera da li je odgovor validan JSON
    $json = json_decode($output, true);
    
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "✅ USPJEH: API je vratio validan JSON.\n";
        echo "   Status odgovora: " . ($json['status'] ?? 'Nema statusa') . "\n";
        echo "   Poruka: " . ($json['message'] ?? 'Nema poruke') . "\n";
    } else {
        echo "❌ GRESKA: API nije vratio validan JSON.\n";
        echo "   Sirovi odgovor: " . substr(trim($output), 0, 100) . "...\n";
        if ($errors) echo "   Greske (STDERR): $errors\n";
    }
} else {
    echo "❌ GRESKA: Nije moguce pokrenuti PHP proces.\n";
}
echo "\n";
?>
