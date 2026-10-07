<?php

function aktivirajJavaCache() {
    $javaPath = "java"; // ili "C:\\Program Files\\Java\\jdk-11\\bin\\java"
    $classpath = "./java"; // gde su .class fajlovi
    
    // Komanda
    $command = "$javaPath -cp $classpath BazaInicijalizacija 2>&1";
    
    $output = [];
    $returnCode = 0;
    
    // Pokreni Java
    exec($command, $output, $returnCode);
    
    if ($returnCode === 0) {
        error_log("[" . date('Y-m-d H:i:s') . "] Java cache - OK");
    } else {
        error_log("[" . date('Y-m-d H:i:s') . "] Java cache - GREŠKA: " . implode("\n", $output));
    }
}

// Sa inteligentnim cachiranjem
function aktivirajJavaCheSaProverom($minutaIntervala = 10) {
    $lastFile = "./cache/last_java_cache.txt";
    
    $trebajli = false;
    if (!file_exists($lastFile)) {
        $trebajli = true;
    } else {
        $lastTime = (int)file_get_contents($lastFile);
        if ((time() - $lastTime) / 60 >= $minutaIntervala) {
            $trebajli = true;
        }
    }
    
    if ($trebajli) {
        @mkdir("./cache", 0755, true); // Kreiraj cache dir ako ne postoji
        
        aktivirajJavaCache();
        file_put_contents($lastFile, time());
    }
}

?>
