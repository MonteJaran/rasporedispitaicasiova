<?php
// test/test_java.php

echo "---- TEST 3: Java Bridge Integration ----\n";

$javaDir = realpath(__DIR__ . '/../public/java');
// Detektujemo OS za separator classpath-a (; za Windows, : za Linux/Mac)
$sep = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? ';' : ':';

// Classpath mora uključivati trenutni folder (.) i postgres jar fajl
// Specific jar verified: postgresql-42.7.8.jar
$jarName = 'postgresql-42.7.8.jar';
$classpath = ".;" . $javaDir . DIRECTORY_SEPARATOR . $jarName; 

$command = "cd /d \"$javaDir\" && java -cp \"$classpath\" TestConnection";

echo "   Izvrsavam komandu: $command\n";

$output = [];
$returnCode = 0;
exec($command . " 2>&1", $output, $returnCode);

if ($returnCode === 0) {
    // Provjeravamo izlaz za ključne riječi uspjeha
    $fullOutput = implode("\n", $output);
    if (strpos($fullOutput, 'uspješna') !== false || strpos($fullOutput, 'successful') !== false) {
        echo "✅ USPJEH: Java konekcija radi ispravno.\n";
        echo "   Izlaz: " . trim($fullOutput) . "\n";
    } else {
        echo "⚠️  UPOZORENJE: Java je radila, ali nije detektovana poruka o uspjehu. Izlaz:\n";
        echo $fullOutput . "\n";
    }
} else {
    echo "❌ GRESKA: Java proces je vratio kod $returnCode.\n";
    echo "   Izlaz:\n" . implode("\n", $output) . "\n";
}
echo "\n";
?>
