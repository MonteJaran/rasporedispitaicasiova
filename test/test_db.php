<?php
// test/test_db.php

echo "---- TEST 1: Database Connection ----\n";

$configFile = __DIR__ . '/../config/dbconnection.php';

if (!file_exists($configFile)) {
    die("❌ GRESKA: Config fajl nije pronadjen na $configFile\n");
}

try {
    // Inkludujemo fajl koji kreira $pdo varijablu
    require $configFile;
    
    if (isset($pdo) && $pdo instanceof PDO) {
        echo "✅ USPJEH: konekcija na bazu uspostavljena.\n";
        echo "   Server Info: " . $pdo->getAttribute(PDO::ATTR_SERVER_INFO) . "\n";
    } else {
        echo "❌ GRESKA: \$pdo objekat nije kreiran.\n";
    }
} catch (Exception $e) {
    echo "❌ GRESKA: Izuzetak prilikom konekcije: " . $e->getMessage() . "\n";
}
echo "\n";
?>
