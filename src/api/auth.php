<?php
// Ne moze se testirati jer fali env fajl da bi se mogao povezati sa bazom
// Mzd napraviti env fajl i ubaciti u gitignore

include "../../config/dbconnection.php"; 
session_start();

// try{


    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'];
    if ($action === "register") {
        $email = trim($data['email']);
        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);

        // Creating unique username
        $current_timestamp = date("YmdHis");
        $first_name = trim($data['first_name']);
        $last_name = trim($data['last_name']);

        // Username is generated in first university app (name.lastname). 
        // Later we could add option, so user or admin can change it
        $username = strtolower($first_name . "." . $last_name . $current_timestamp);

        $emailCheckStmt = $pdo->prepare("SELECT id AS professor_id, full_name, email FROM professor WHERE email = :email");
        $emailCheckStmt->execute([':email' => $email]);
        $existingUser = $emailCheckStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existingUser) {
            echo json_encode(["status" => "error", "message" => "Korisnik nije pronadjen"]);
            exit;
        }

        $professor_id = (int)$existingUser['professor_id'];
        

        $userAlreadyExistStmt = $pdo->prepare("SELECT id FROM user_account WHERE professor_id=:professor_id");
        $userAlreadyExistStmt ->execute([':professor_id'=>$existingUser['professor_id']]);
        $userAlreadyExist = $userAlreadyExistStmt->fetch(PDO::FETCH_ASSOC);
        
        if($userAlreadyExist){
            echo json_encode(["status" => "userExist", "message" => "Nalog sam ovim mejlom je vec kreiran"]);
            exit;
        }

        // ADMIN SHOULDN'T BE ADDED THROUGH REGISTRATION FORM
        $registerStmt = $pdo->prepare("
            INSERT INTO user_account (username, role_enum, password_hash, is_active, professor_id)
            VALUES (:username, 'PROFESSOR', :password, true, :professor_id)
        ");

        $success = $registerStmt->execute([
            ':password' => $passwordHash,
            ':username' => $username,
            ':professor_id' => $professor_id,
        ]);

        if ($success) {
            echo json_encode(["status" => "success", "message" => "Uspješna registracija"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Greška prilikom registracije"]);
        }

    } elseif ($action === "login") {
        $email = trim($data['email']);
        $password = trim($data['password']);
        $userStmt = $pdo->prepare("SELECT ua.id, ua.username, ua.password_hash, ua.role_enum, ua.professor_id
                                FROM user_account ua
                                JOIN professor p ON ua.professor_id = p.id
                                WHERE p.email = :email 
                                LIMIT 1;");
        $userStmt->execute([':email' => $email]);
        $userData = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$userData) {
            echo json_encode(["status" => "error", "message" => "Neispravan mejl ili lozinka"]);
            exit;
        }

        if (password_verify($password, $userData['password_hash'])) {
            $_SESSION['user_type'] = $userData['role_enum'];
            $_SESSION['user_id'] = $userData['id'];
            $_SESSION['username'] = $userData['username'];
            $_SESSION['professor_id'] = $userData['professor_id'];

            echo json_encode(["status" => "success", "message" => "Uspješna prijava"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Neispravan mejl ili lozinka"]);
        }
    }
// }catch(Exception $e){
//     echo json_encode([
//         "status" => "error","message" => "Došlo je do greške na serveru"]);

// }
?>