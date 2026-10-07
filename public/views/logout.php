<?php
session_start();

// Obriši sve session varijable
$_SESSION = [];

// Uništi session cookie (ako postoji)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Uništi sesiju
session_destroy();

// Preusmjeri na login ili početnu stranicu
header("Location: authorization.php");
exit;
