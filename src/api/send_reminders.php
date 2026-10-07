<?php
session_start();

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/dbconnection.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// if($_GET['key'] !== 'token sa cron joba.org ili neceg drugog') exit('Pristup nije dozvoljen');
// Tek moguce kad se aplikacija digne na server

    $stmt = $pdo->query("SELECT value FROM config WHERE \"key\" = 'schedule_deadline'");
    $deadline_date = $stmt->fetchColumn() ?: '';

    if (empty($deadline_date)) {
        exit('Deadline nije postavljen');
    }
    $today_date_plus_2 = date('Y-m-d', strtotime('+2 days'));
    $deadline = date('Y-m-d', strtotime($deadline_date));
    
    if($deadline > $today_date_plus_2) {
        exit('Deadline jos nije dosao');
    }
    
    

// $stmt_2 = $pdo->query("
    // SELECT DISTINCT 
    //     p.id AS professor_id,
    //     p.full_name,
    //     p.email
    // FROM professor p
    // INNER JOIN course_professor cp ON p.id = cp.professor_id AND cp.is_assistant = false
    // INNER JOIN course c ON cp.course_id = c.id AND c.is_active = true
    // WHERE p.is_active = true
    //   AND (c.colloquium_1_week = 0 OR c.colloquium_1_week IS NULL)
// ");
// $emails = $stmt_2->fetchAll(PDO::FETCH_ASSOC);

$emails_test = [
    [
        'professor_id' => 24,
        'full_name' => 'Aleksa 2', 
        'email' => 'aleksabojovic1b@gmail.com'
    ],
    [
        'professor_id' => 26,
        'full_name' => 'Aleksa Bojovic',
        'email' => 'aleksa.bojovicb@gmail.com'
    ]
];
$emails = $emails_test;

function sendReminderEmail($emails){
    foreach($emails as $prof){
        $mail = new PHPMailer(true);
        try{
             $mail->SMTPDebug = SMTP::DEBUG_OFF;
            $mail->isSMTP();
            $mail->Host = 'smtp-relay.brevo.com'; 
            $mail->SMTPAuth = true;
            $mail->Username = '9d2675001@smtp-brevo.com';
            $mail->Password = 'Sa83rFy5AfqcbTHN'; 
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('aleksabojovic1b@gmail.com','Univerzitet Mediteran FIT');
            $mail->addAddress($prof['email']);
            $mail->Subject = 'Podsjetnik: Popunite Raspored';
            $mail->Body = "Poštovani {$prof['full_name']},\n\nMolimo izaberite sedmice u kojima će se održati kolokvijum u što kraćem roku.\n\nUniverzitet Mediteran";  
            $mail->send();
            echo "Poslat mejl profesoru {$prof['email']} \n";
            
        }catch(Exception $e){
            error_log("Greška za {$prof['email']}: " . $mail->ErrorInfo);
        }
        $mail->clearAddresses();
    }
    return true;
}

if (!empty($emails)) {
    sendReminderEmail($emails);
    echo "Poslato " . count($emails) . " podsjetnika\n";
} else {
    echo "Nema profesora za podsjetnik\n";
}
?>
