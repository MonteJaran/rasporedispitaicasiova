<?php
session_start();
require_once __DIR__ . '/../../config/dbconnection.php';
require_once __DIR__ . '/../../src/services/OccupancyService.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

$occupancyService = new OccupancyService($pdo);

// short English comment: e-mails the freshly created account's login credentials to the user
function sendAccountCredentialsEmail($emailAddress, $username, $plainPassword) {
    $mail = new PHPMailer(true);
    try {
        $mail->SMTPDebug = SMTP::DEBUG_OFF;
        $mail->isSMTP();
        $mail->Host = 'smtp-relay.brevo.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'a22987001@smtp-brevo.com';
        $mail->Password = 'G6xQXvBk3F5RcPKp';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('luka.dragicevic2004@gmail.com', 'FIT Sistem');
        $mail->addAddress($emailAddress);

        $mail->isHTML(false);
        $mail->Subject = 'Vaš nalog za Raspored je kreiran';
        $mail->Body =
            "Za Vas je kreiran nalog u sistemu za raspored.\n\n" .
            "Korisničko ime: $username\n" .
            "Lozinka: $plainPassword\n\n" .
            "Preporučujemo da lozinku promijenite nakon prve prijave.";

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('sendAccountCredentialsEmail failed: ' . $e->getMessage());
        return false;
    }
}
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS academic_year (
            id bigserial PRIMARY KEY,
            year_label varchar(9) NOT NULL,
            winter_semester_start date NOT NULL,
            summer_semester_start date NOT NULL,
            is_active boolean DEFAULT true
        )
    ");
    // Kojem fakultetu sala prioritetno pripada (npr. "FIT"); prazno = dijeljena/na zahtjev
    $pdo->exec("ALTER TABLE room ADD COLUMN IF NOT EXISTS faculty_code VARCHAR(20)");
    // Dodatni uslovi predmeta za generisanje rasporeda (prije generisanja):
    // da li zahtijeva računarsku salu (nezavisno od labs_per_week), očekivani broj
    // studenata (poredi se sa kapacitetom sale), i broj paralelnih grupa u isto vrijeme.
    $pdo->exec("ALTER TABLE course ADD COLUMN IF NOT EXISTS requires_computer_lab BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE course ADD COLUMN IF NOT EXISTS expected_students INTEGER");
    $pdo->exec("ALTER TABLE course ADD COLUMN IF NOT EXISTS parallel_groups INTEGER DEFAULT 1");
    // Dodatni zahtjevi profesora uz svaki termin raspoloživosti: da li im je za taj
    // termin neophodna računarska sala, izbor konkretne sale, i vezivanje termina za
    // konkretan predmet (npr. "ovaj predmet želim baš utorkom").
    $pdo->exec("ALTER TABLE professor_availability ADD COLUMN IF NOT EXISTS requires_computer_lab BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE professor_availability ADD COLUMN IF NOT EXISTS preferred_room_id BIGINT REFERENCES room(id)");
    $pdo->exec("ALTER TABLE professor_availability ADD COLUMN IF NOT EXISTS course_id BIGINT REFERENCES course(id)");
// Ensure room_occupancy table exists
    try {
        // Moved to OccupancyService logic
    } catch (PDOException $e) {
        // Ignore if exists
    }
} catch (PDOException $e) {
    // Ignore error if tables already exist or handle appropriately
}
//DEADLINE
$current_deadline = $pdo->query("SELECT value FROM config WHERE \"key\" = 'schedule_deadline'")->fetchColumn() ?: '';

if (isset($_GET['action']) && $_GET['action'] === 'getschedule') {
    header('Content-Type: application/json; charset=utf-8');

    // Kao i ostale ?action= rute ovog fajla - raspored (svi generisani prijedlozi sa
    // imenima profesora) nije javan; koristi ga samo admin panel.
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
        http_response_code(403);
        echo json_encode(['error' => 'Nemate dozvolu za ovu akciju.']);
        exit;
    }

    try {
        // Get all distinct schedule_ids (latest 6)
        $scheduleStmt = $pdo->prepare("
            SELECT DISTINCT schedule_id 
            FROM academic_event 
            WHERE schedule_id IS NOT NULL 
            ORDER BY schedule_id DESC 
            LIMIT 6
        ");
        $scheduleStmt->execute();
        $scheduleIds = $scheduleStmt->fetchAll(PDO::FETCH_COLUMN);

        // Reverse to show oldest first (1, 2, 3, 4, 5, 6)
        $scheduleIds = array_reverse($scheduleIds);

        // academic_event.day je varchar - Java upisuje ime dana ("ponedeljak"...), ali
        // stariji redovi (generisani prije popravke weekday bug-a) mogu imati cifru kao
        // string ("1".."7"). (int)"ponedeljak" bi uvijek dalo 0, pa raspored ostane prazan
        // za svježe generisane rasporede - ovo ispravno mapira oba oblika u 1..7 (koristi
        // ga i exam/kolokvijum upit ispod, ne samo glavni raspored).
        if (!function_exists('dayNameToWeekdayNum')) {
            function dayNameToWeekdayNum($day) {
                static $map = [
                    'ponedeljak' => 1, 'monday' => 1,
                    'utorak' => 2, 'tuesday' => 2,
                    'srijeda' => 3, 'sreda' => 3, 'wednesday' => 3,
                    'cetvrtak' => 4, 'četvrtak' => 4, 'thursday' => 4,
                    'petak' => 5, 'friday' => 5,
                    'subota' => 6, 'saturday' => 6,
                    'nedjelja' => 7, 'nedelja' => 7, 'sunday' => 7,
                ];
                $key = mb_strtolower(trim((string)$day));
                if (isset($map[$key])) return $map[$key];
                return is_numeric($day) ? (int)$day : 0;
            }
        }

        $stmt = $pdo->prepare("
            SELECT 
                MIN(ae.id) AS event_id,
                ae.schedule_id,
                ae.day,
                ae.starts_at,
                ae.ends_at,
                c.name AS coursename,
                r.code AS roomcode,
                c.semester,
                ae.type_enum,
                c.is_online,
                COALESCE(string_agg(DISTINCT p.full_name, ', ') FILTER (WHERE cp.is_assistant = FALSE), '') AS professors,
                COALESCE(string_agg(DISTINCT p.full_name, ', ') FILTER (WHERE cp.is_assistant = TRUE), '') AS assistants
            FROM academic_event ae
            JOIN course c ON ae.course_id = c.id
            LEFT JOIN room r ON ae.room_id = r.id
            LEFT JOIN course_professor cp ON cp.course_id = c.id
            LEFT JOIN professor p ON p.id = cp.professor_id
            WHERE ae.type_enum IN ('LECTURE', 'EXERCISE', 'LAB')
              AND ae.schedule_id IN (" . implode(',', array_fill(0, count($scheduleIds), '?')) . ")
            GROUP BY ae.schedule_id, ae.day, ae.starts_at, ae.ends_at, c.name, r.code, c.semester, ae.type_enum, c.is_online
            ORDER BY ae.schedule_id, c.semester, ae.day, ae.starts_at
        ");
        $stmt->execute($scheduleIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by schedule_id -> semester -> events
        $data = [
            'schedules' => [],
            'schedule_ids' => $scheduleIds
        ];

        foreach ($scheduleIds as $sid) {
            $data['schedules'][$sid] = [];
        }

        foreach ($rows as $row) {
            $schedId = (int)$row['schedule_id'];
            $sem = (int)$row['semester'];

            if (!isset($data['schedules'][$schedId][$sem])) {
                $data['schedules'][$schedId][$sem] = [];
            }

            $data['schedules'][$schedId][$sem][] = [
                'event_id' => (int)$row['event_id'],
                'day' => dayNameToWeekdayNum($row['day']),
                'start' => substr($row['starts_at'], 11, 5),
                'end' => substr($row['ends_at'], 11, 5),
                'course' => $row['coursename'],

                'room' => $row['roomcode'],
                'type' => $row['type_enum'],
                'is_online' => (bool)$row['is_online'],
                'professors' => $row['professors'],
                'assistants' => $row['assistants'],
            ];
        }

        // Fetch EXAM events (raspored kolokvijuma)
        $examScheduleId = $scheduleIds[0] ?? 0;
        $examStmt = $pdo->prepare("
            SELECT 
                ae.day,
                ae.starts_at,
                ae.ends_at,
                c.name AS coursename,
                r.code AS roomcode,
                ae.type_enum,
                ae.is_online,
                c.semester
            FROM academic_event ae
            JOIN course c ON ae.course_id = c.id
            LEFT JOIN room r ON ae.room_id = r.id
            WHERE (ae.type_enum IN ('EXAM', 'COLLOQUIUM') AND ae.schedule_id = ?)
               OR (ae.type_enum IN ('COLLOQUIUM_1', 'COLLOQUIUM_2', 'EXAM') AND ae.notes = 'generated')
            ORDER BY ae.starts_at ASC
        ");
        $examStmt->execute([$examScheduleId]);
        $examRows = $examStmt->fetchAll(PDO::FETCH_ASSOC);

        $data['exams'] = [];
        foreach ($examRows as $row) {
            $data['exams'][] = [
                'day' => dayNameToWeekdayNum($row['day']),
                'start' => substr($row['starts_at'], 11, 5),
                'end' => substr($row['ends_at'], 11, 5),
                'course' => $row['coursename'],
                'room' => $row['roomcode'],
                'is_online' => (bool)$row['is_online'],
                'date' => substr($row['starts_at'], 0, 10),
                'type' => $row['type_enum'],
                'semester' => (int)$row['semester']
            ];
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Greška pri čitanju rasporeda: ' . $e->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------------------------
// Ručna izmjena generisanog rasporeda: admin pomjera BLOK časova (uzastopni termini
// istog predmeta, dana, sale i nosioca - npr. predavanja 10-13h, ili vježbe+lab) na
// drugi dan/vrijeme/salu. Provjeravaju se ista pravila kao u generatoru: sukob sale,
// profesora i semestra (u istom periodu zimski/ljetnji), dnevni fond 6h, nastava
// 8-17h, zauzetost sale od drugih fakulteta, kapacitet/rač. sala. Termin van
// raspoloživosti profesora je upozorenje koje admin može svjesno potvrditi.
// ---------------------------------------------------------------------------
const SCHEDULE_DAY_NAMES = [1 => 'ponedeljak', 2 => 'utorak', 3 => 'srijeda', 4 => 'cetvrtak', 5 => 'petak'];

function scheduleTimeToMinutes($t) {
    return (int)substr($t, 0, 2) * 60 + (int)substr($t, 3, 2);
}

function scheduleMinutesToTime($m) {
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}

// Vraća blok kome pripada termin $eventId: osnovni podaci + lista termina (id, od, do).
function loadScheduleBlock(PDO $pdo, int $eventId) {
    $st = $pdo->prepare("
        SELECT ae.id, ae.schedule_id, ae.course_id, ae.type_enum, ae.day, ae.room_id, ae.created_by_professor,
               ae.starts_at, ae.locked_by_admin, c.name AS course_name, c.semester, c.is_online,
               COALESCE(c.requires_computer_lab, FALSE) AS requires_computer_lab, c.expected_students
        FROM academic_event ae JOIN course c ON c.id = ae.course_id
        WHERE ae.id = ? AND ae.type_enum IN ('LECTURE', 'EXERCISE', 'LAB') AND ae.schedule_id IS NOT NULL
    ");
    $st->execute([$eventId]);
    $ev = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ev) {
        return null;
    }
    $st = $pdo->prepare("
        SELECT id, type_enum, starts_at, ends_at, locked_by_admin
        FROM academic_event
        WHERE schedule_id = ? AND course_id = ? AND day = ? AND room_id IS NOT DISTINCT FROM ?
          AND created_by_professor = ? AND type_enum IN ('LECTURE', 'EXERCISE', 'LAB')
        ORDER BY starts_at
    ");
    $st->execute([$ev['schedule_id'], $ev['course_id'], $ev['day'], $ev['room_id'], $ev['created_by_professor']]);
    $all = $st->fetchAll(PDO::FETCH_ASSOC);

    // Uzastopni niz termina koji sadrži kliknuti termin
    $runs = [];
    foreach ($all as $row) {
        $n = count($runs);
        if ($n && substr($runs[$n - 1][count($runs[$n - 1]) - 1]['ends_at'], 11, 5) === substr($row['starts_at'], 11, 5)) {
            $runs[$n - 1][] = $row;
        } else {
            $runs[] = [$row];
        }
    }
    $events = [];
    foreach ($runs as $run) {
        if (in_array((int)$eventId, array_map('intval', array_column($run, 'id')), true)) {
            $events = $run;
            break;
        }
    }
    $ev['events'] = $events;
    $ev['start'] = substr($events[0]['starts_at'], 11, 5);
    $ev['end'] = substr($events[count($events) - 1]['ends_at'], 11, 5);
    $ev['hours'] = count($events);
    $ev['weekday'] = array_search($ev['day'], SCHEDULE_DAY_NAMES, true) ?: 0;
    $ev['locked'] = (bool)array_filter(array_column($events, 'locked_by_admin'));
    $ev['types'] = array_values(array_unique(array_column($events, 'type_enum')));
    $ids = array_map('intval', array_column($events, 'id'));
    $st = $pdo->prepare("SELECT DISTINCT professor_id FROM event_professor WHERE event_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $st->execute($ids);
    $ev['professor_ids'] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (!$ev['professor_ids']) {
        $ev['professor_ids'] = [(int)$ev['created_by_professor']];
    }
    return $ev;
}

// Provjera pomjeranja bloka na ($newDay, $newStart) u salu $newRoomId.
// Vraća ['errors' => [...], 'warnings' => [...]].
function validateBlockMove(PDO $pdo, array $block, int $newDay, int $newStart, $newRoomId) {
    $errors = [];
    $warnings = [];
    $newEnd = $newStart + 60 * $block['hours'];
    $dayName = SCHEDULE_DAY_NAMES[$newDay];
    $blockIds = array_map('intval', array_column($block['events'], 'id'));

    if ($newStart < 8 * 60 || $newEnd > 17 * 60) {
        $errors[] = 'Nastava mora biti između 08:00 i 17:00.';
    }

    $room = null;
    if ($block['is_online']) {
        if ($newRoomId !== null) {
            $errors[] = 'Onlajn predmet ne dobija salu.';
        }
    } else {
        if ($newRoomId === null) {
            $errors[] = 'Izaberite salu.';
        } else {
            $st = $pdo->prepare("SELECT id, code, capacity, is_computer_lab, is_active FROM room WHERE id = ?");
            $st->execute([$newRoomId]);
            $room = $st->fetch(PDO::FETCH_ASSOC);
            if (!$room || !$room['is_active']) {
                $errors[] = 'Sala ne postoji ili nije aktivna.';
            } else {
                if ($block['requires_computer_lab'] && !$room['is_computer_lab']) {
                    $errors[] = 'Predmet zahtijeva računarsku salu.';
                }
                if (in_array('LAB', $block['types'], true) && !$room['is_computer_lab']) {
                    $errors[] = 'Laboratorijske vježbe moraju biti u računarskoj sali.';
                }
                if ($block['expected_students'] && (int)$room['capacity'] < (int)$block['expected_students']) {
                    $errors[] = "Sala {$room['code']} ima {$room['capacity']} mjesta, a očekuje se {$block['expected_students']} studenata.";
                }
            }
        }
    }

    // Ostali termini istog rasporeda tog dana (bez termina iz bloka koji se pomjera)
    $st = $pdo->prepare("
        SELECT ae.id, ae.course_id, ae.room_id, ae.starts_at, ae.ends_at, c.name, c.semester, r.code AS room_code,
               COALESCE((SELECT string_agg(ep.professor_id::text, ',') FROM event_professor ep WHERE ep.event_id = ae.id),
                        ae.created_by_professor::text) AS profs
        FROM academic_event ae
        JOIN course c ON c.id = ae.course_id
        LEFT JOIN room r ON r.id = ae.room_id
        WHERE ae.schedule_id = ? AND ae.day = ? AND ae.type_enum IN ('LECTURE', 'EXERCISE', 'LAB')
          AND ae.id NOT IN (" . implode(',', $blockIds) . ")
    ");
    $st->execute([$block['schedule_id'], $dayName]);
    $semIntervals = [[$newStart, $newEnd]];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $os = scheduleTimeToMinutes(substr($o['starts_at'], 11, 5));
        $oe = scheduleTimeToMinutes(substr($o['ends_at'], 11, 5));
        if ((int)$o['semester'] === (int)$block['semester']) {
            $semIntervals[] = [$os, $oe];
        }
        if (!($os < $newEnd && $newStart < $oe)) {
            continue;
        }
        $label = $o['name'] . ' ' . scheduleMinutesToTime($os) . '-' . scheduleMinutesToTime($oe);
        $sameTerm = ((int)$o['semester'] % 2) === ((int)$block['semester'] % 2);
        if ($sameTerm && $newRoomId !== null && (int)$o['room_id'] === $newRoomId) {
            $errors[] = "Sala {$o['room_code']} je zauzeta: $label.";
        }
        $common = array_intersect($block['professor_ids'], array_map('intval', explode(',', $o['profs'])));
        if ($sameTerm && $common) {
            $errors[] = "Profesor već ima čas u tom terminu: $label.";
        }
        if ((int)$o['semester'] === (int)$block['semester'] && (int)$o['course_id'] !== (int)$block['course_id']) {
            $errors[] = "Studenti {$block['semester']}. semestra već imaju čas: $label.";
        }
    }

    // Dnevni fond semestra (spojeni intervali) ne smije preći 6h
    usort($semIntervals, fn($a, $b) => $a[0] <=> $b[0]);
    $total = 0;
    [$cs, $ce] = $semIntervals[0];
    foreach (array_slice($semIntervals, 1) as [$a, $b]) {
        if ($a <= $ce) { $ce = max($ce, $b); } else { $total += $ce - $cs; [$cs, $ce] = [$a, $b]; }
    }
    $total += $ce - $cs;
    if ($total > 6 * 60) {
        $errors[] = "Dnevni fond {$block['semester']}. semestra bi tog dana bio " . round($total / 60, 1) . "h (najviše 6h).";
    }

    // Zauzetost sale od drugih fakulteta (aktivna akademska godina)
    if ($newRoomId !== null) {
        $st = $pdo->prepare("
            SELECT ro.faculty_code, ro.start_time, ro.end_time FROM room_occupancy ro
            JOIN academic_year ay ON ay.id = ro.academic_year_id AND ay.is_active = TRUE
            WHERE ro.room_id = ? AND ro.weekday = ? AND ro.is_active = TRUE AND UPPER(ro.faculty_code) <> 'FIT'
              AND ro.start_time < ? AND ro.end_time > ?
        ");
        $st->execute([$newRoomId, $newDay, scheduleMinutesToTime($newEnd), scheduleMinutesToTime($newStart)]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $errors[] = "Salu u tom terminu koristi {$o['faculty_code']} (" . substr($o['start_time'], 0, 5) . '-' . substr($o['end_time'], 0, 5) . ').';
        }
    }

    // Raspoloživost profesora (upozorenje - admin može potvrditi)
    $st = $pdo->prepare("SELECT professor_id, weekday, start_time, end_time, course_id FROM professor_availability
                         WHERE professor_id IN (" . implode(',', array_fill(0, count($block['professor_ids']), '?')) . ")");
    $st->execute($block['professor_ids']);
    $byProf = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $byProf[(int)$a['professor_id']][] = $a;
    }
    $names = $pdo->prepare("SELECT full_name FROM professor WHERE id = ?");
    foreach ($byProf as $pid => $slots) {
        // isti izbor termina kao generator: vezani za predmet, inače opšti, inače svi
        $bound = array_filter($slots, fn($a) => (int)$a['course_id'] === (int)$block['course_id']);
        $general = array_filter($slots, fn($a) => $a['course_id'] === null);
        $use = $bound ?: ($general ?: $slots);
        $iv = [];
        foreach ($use as $a) {
            if ((int)$a['weekday'] === $newDay) {
                $iv[] = [scheduleTimeToMinutes($a['start_time']), scheduleTimeToMinutes($a['end_time'])];
            }
        }
        usort($iv, fn($a, $b) => $a[0] <=> $b[0]);
        $covered = $newStart;
        foreach ($iv as [$a, $b]) {
            if ($a <= $covered && $b > $covered) $covered = $b;
        }
        if ($covered < $newEnd) {
            $names->execute([$pid]);
            $warnings[] = $names->fetchColumn() . ' nije naveo/la raspoloživost za ' . $dayName . ' '
                . scheduleMinutesToTime($newStart) . '-' . scheduleMinutesToTime($newEnd) . '.';
        }
    }

    return ['errors' => array_values(array_unique($errors)), 'warnings' => $warnings];
}

if (isset($_GET['action']) && in_array($_GET['action'], ['block_info', 'move_block'], true)) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Nemate dozvolu za ovu akciju.']);
        exit;
    }
    $input = $_GET['action'] === 'move_block' ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
    $block = loadScheduleBlock($pdo, (int)($input['event_id'] ?? 0));
    if (!$block) {
        echo json_encode(['status' => 'error', 'message' => 'Termin nije pronađen.']);
        exit;
    }

    if ($_GET['action'] === 'block_info') {
        $rooms = $pdo->query("SELECT id, code, capacity, is_computer_lab FROM room WHERE is_active = TRUE ORDER BY code")
            ->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'status' => 'ok',
            'block' => [
                'schedule_id' => (int)$block['schedule_id'], 'course' => $block['course_name'],
                'semester' => (int)$block['semester'], 'types' => $block['types'],
                'weekday' => (int)$block['weekday'], 'start' => $block['start'], 'end' => $block['end'],
                'hours' => $block['hours'], 'room_id' => $block['room_id'] !== null ? (int)$block['room_id'] : null,
                'is_online' => (bool)$block['is_online'], 'locked' => $block['locked'],
            ],
            'rooms' => $rooms,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($block['locked']) {
        echo json_encode(['status' => 'error', 'message' => 'Raspored je zaključan - otključajte ga prije izmjene.']);
        exit;
    }
    $newDay = (int)($input['day'] ?? 0);
    $newStartStr = (string)($input['start'] ?? '');
    $newRoomId = (isset($input['room_id']) && $input['room_id'] !== '' && $input['room_id'] !== null) ? (int)$input['room_id'] : null;
    if (!isset(SCHEDULE_DAY_NAMES[$newDay]) || !preg_match('/^\d{2}:\d{2}$/', $newStartStr)) {
        echo json_encode(['status' => 'error', 'message' => 'Neispravan dan ili vrijeme.']);
        exit;
    }
    $newStart = scheduleTimeToMinutes($newStartStr);
    $check = validateBlockMove($pdo, $block, $newDay, $newStart, $newRoomId);
    if ($check['errors']) {
        echo json_encode(['status' => 'error', 'message' => implode(' ', $check['errors']), 'errors' => $check['errors']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($check['warnings'] && empty($input['force'])) {
        echo json_encode(['status' => 'confirm', 'warnings' => $check['warnings']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $upd = $pdo->prepare("UPDATE academic_event SET starts_at = ?, ends_at = ?, day = ?, room_id = ? WHERE id = ?");
        $dayShift = $newDay - (int)$block['weekday'];
        $baseDate = date('Y-m-d', strtotime(substr($block['events'][0]['starts_at'], 0, 10) . " $dayShift days"));
        foreach ($block['events'] as $i => $e) {
            $s = $newStart + 60 * $i;
            $upd->execute([
                "$baseDate " . scheduleMinutesToTime($s) . ':00',
                "$baseDate " . scheduleMinutesToTime($s + 60) . ':00',
                SCHEDULE_DAY_NAMES[$newDay],
                $newRoomId,
                (int)$e['id'],
            ]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Greška pri čuvanju: ' . $e->getMessage()]);
        exit;
    }
    echo json_encode(['status' => 'ok', 'message' => 'Termin je pomjeren.', 'warnings' => $check['warnings']], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'generatecolloquiums') {
    // Očisti sve output buffere da osiguramo čist JSON
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');

    // Provera da li je korisnik ADMIN
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
        echo json_encode(['status' => 'error', 'message' => 'Nemate dozvolu za ovu akciju.']);
        exit;
    }

    try {
        // Postavi working directory na root projekta
        $projectRoot = dirname(__DIR__, 2);
        if (!is_dir($projectRoot)) {
            echo json_encode(['status' => 'error', 'message' => 'Root direktorijum projekta nije pronađen.']);
            exit;
        }
        chdir($projectRoot);

        // Putanja do Java fajlova
        $javaDir = $projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'java';
        $jarFile = $javaDir . DIRECTORY_SEPARATOR . 'postgresql-42.7.8.jar';

        // Provera da li Java fajlovi postoje
        if (!file_exists($javaDir . DIRECTORY_SEPARATOR . 'ValidacijaTermina.class')) {
            echo json_encode(['status' => 'error', 'message' => 'Java klasa ValidacijaTermina nije pronađena u: ' . $javaDir]);
            exit;
        }

        if (!file_exists($jarFile)) {
            echo json_encode(['status' => 'error', 'message' => 'PostgreSQL JDBC driver nije pronađen: ' . $jarFile]);
            exit;
        }

        $storageDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }
        $progressFile = $storageDir . DIRECTORY_SEPARATOR . 'colloquium_progress.json';
        $logFile = $storageDir . DIRECTORY_SEPARATOR . 'colloquium_generation.log';

        // Concurrency guard - isti obrazac kao generateschedule (vidi tamo za objašnjenje
        // 10-minutne staleness granice).
        if (file_exists($progressFile)) {
            $existingRaw = @file_get_contents($progressFile);
            $existing = ($existingRaw !== false) ? json_decode($existingRaw, true) : null;
            if (is_array($existing) && ($existing['done'] ?? true) === false) {
                $updatedAt = (int) ($existing['updated_at'] ?? 0);
                if ($updatedAt > 0 && (time() - $updatedAt) < 600) {
                    echo json_encode(['status' => 'error', 'message' => 'Generisanje kolokvijuma je već u toku, sačekajte da se završi.']);
                    exit;
                }
            }
        }

        // Resetuj progress fajl PRIJE pokretanja procesa - isti razlog kao kod
        // generateschedule (spriječi da polling vidi zaostali done:true).
        file_put_contents($progressFile, json_encode([
            'running' => true,
            'done' => false,
            'success' => null,
            'message' => 'Pokretanje generisanja...',
            'error' => null,
            'updated_at' => time(),
        ]));

        // Formiranje Java komande
        $separator = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? ';' : ':';
        $classpath = $javaDir . $separator . $jarFile;
        $command = ['java', '-cp', $classpath, 'ValidacijaTermina', 'generisiKolokvijume'];

        // Pokreni Java ASINHRONO - isti razlog kao kod generateschedule: shell_exec
        // je blokirao HTTP zahtjev dok Java ne završi, pa je Render (proxy timeout)
        // prekidao konekciju sa HTTP 520 prije nego što bi Java stigla da odgovori.
        // Napredak se prati preko $progressFile (vidi ScheduleProgress.writeSimple
        // u ValidacijaTermina.java), stdout/stderr idu u $logFile radi debagovanja.
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];
        $process = proc_open($command, $descriptorspec, $pipes, $projectRoot, null, ['bypass_shell' => true]);

        if (!is_resource($process)) {
            file_put_contents($progressFile, json_encode([
                'running' => false, 'done' => true, 'success' => false,
                'message' => 'Java proces nije mogao biti pokrenut.',
                'error' => 'proc_open failed', 'updated_at' => time(),
            ]));
            echo json_encode(['status' => 'error', 'message' => 'Java proces nije mogao biti pokrenut. Proverite da li je Java instaliran i u PATH-u.']);
            exit;
        }

        fclose($pipes[0]);
        // Namjerno NEMA proc_close(): PHP treba odmah da vrati odgovor dok Java
        // nastavlja u pozadini (vidi generateschedule).

        echo json_encode(['status' => 'started', 'message' => 'Generisanje kolokvijuma je pokrenuto.']);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Greška: ' . $e->getMessage()]);
    } catch (Error $e) {
        echo json_encode(['status' => 'error', 'message' => 'Fatalna greška: ' . $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'generatecolloquiums_status') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
        echo json_encode(['status' => 'error', 'message' => 'Nemate dozvolu za ovu akciju.']);
        exit;
    }

    $projectRoot = dirname(__DIR__, 2);
    $progressFile = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'colloquium_progress.json';

    $default = ['running' => false, 'done' => true, 'success' => null, 'message' => '', 'error' => null];

    if (!file_exists($progressFile)) {
        echo json_encode($default, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // @ + retry - isti razlog kao generateschedule_status (Java atomično piše
    // preko tmp+rename, pa PHP povremeno naiđe na prolazni Windows read error).
    $rawContents = @file_get_contents($progressFile);
    if ($rawContents === false) {
        $rawContents = @file_get_contents($progressFile);
    }
    $data = ($rawContents !== false) ? json_decode($rawContents, true) : null;
    if (!is_array($data)) {
        $stillRunning = ['running' => true, 'done' => false, 'success' => null, 'message' => 'Generisanje u toku...', 'error' => null];
        echo json_encode($rawContents === false ? $stillRunning : $default, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'generateschedule') {
    // Očisti sve output buffere da osiguramo čist JSON
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');

    // Provera da li je korisnik ADMIN
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
        echo json_encode(['status' => 'error', 'message' => 'Nemate dozvolu za ovu akciju.']);
        exit;
    }

    try {
        // Postavi working directory na root projekta (gde se nalazi .env fajl)
        $projectRoot = dirname(__DIR__, 2); // dva nivoa iznad public/views
        if (!is_dir($projectRoot)) {
            echo json_encode(['status' => 'error', 'message' => 'Root direktorijum projekta nije pronađen.']);
            exit;
        }
        chdir($projectRoot);

        // Putanja do Java fajlova
        $javaDir = $projectRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'java';
        $jarFile = $javaDir . DIRECTORY_SEPARATOR . 'postgresql-42.7.8.jar';

        // Provera da li Java fajlovi postoje
        if (!file_exists($javaDir . DIRECTORY_SEPARATOR . 'ValidacijaTermina.class')) {
            echo json_encode(['status' => 'error', 'message' => 'Java klasa ValidacijaTermina nije pronađena u: ' . $javaDir]);
            exit;
        }

        if (!file_exists($jarFile)) {
            echo json_encode(['status' => 'error', 'message' => 'PostgreSQL JDBC driver nije pronađen: ' . $jarFile]);
            exit;
        }

        $storageDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }
        $progressFile = $storageDir . DIRECTORY_SEPARATOR . 'schedule_progress.json';
        $logFile = $storageDir . DIRECTORY_SEPARATOR . 'schedule_generation.log';

        // Concurrency guard: ne dozvoli novo pokretanje dok prethodno još traje
        // (staleness od 10 min pokriva slučaj da je prethodni proces crash-ovao
        // bez da je stigao da upiše done:true).
        if (file_exists($progressFile)) {
            // @ - Java (na drugom procesu) povremeno piše u ovaj isti fajl preko
            // atomic rename-a tačno dok PHP pokušava da ga pročita; na Windows-u
            // to katkad izazove prolazni "failed to open stream" warning koji bi,
            // nesuzbijen, iskvario JSON odgovor. Samouzdravljivo je: sledeće
            // čitanje (za par sekundi) će uspjeti.
            $existingRaw = @file_get_contents($progressFile);
            $existing = ($existingRaw !== false) ? json_decode($existingRaw, true) : null;
            if (is_array($existing) && ($existing['done'] ?? true) === false) {
                $updatedAt = (int) ($existing['updated_at'] ?? 0);
                if ($updatedAt > 0 && (time() - $updatedAt) < 600) {
                    echo json_encode(['status' => 'error', 'message' => 'Generisanje rasporeda je već u toku, sačekajte da se završi.']);
                    exit;
                }
            }
        }

        // Resetuj progress fajl PRIJE pokretanja procesa, da polling nikad ne
        // vidi zaostali "done:true" iz prethodnog pokretanja.
        file_put_contents($progressFile, json_encode([
            'running' => true,
            'done' => false,
            'success' => null,
            'current_schedule' => 0,
            'total_schedules' => 6,
            'current_course' => 0,
            'total_courses' => 0,
            'message' => 'Pokretanje generisanja...',
            'error' => null,
            'updated_at' => time(),
        ]));

        // Formiranje Java komande (isti obrazac kao ranije - Windows/Linux separator)
        $separator = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? ';' : ':';
        $classpath = $javaDir . $separator . $jarFile;
        // Niz argumenata (ne jedan string) + bypass_shell: izbjegava cmd.exe /c
        // omotač na Windows-u, koji je pravio problem da se pozadinski Java
        // proces gasi zajedno sa PHP zahtjevom koji ga je pokrenuo. PHP sam
        // ispravno kvotuje argumente (npr. putanju projekta sa razmakom).
        $command = ['java', '-cp', $classpath, 'ValidacijaTermina', 'generisiKompletan'];

        // Pokreni Java ASINHRONO - proc_open ne blokira kao shell_exec, proces
        // nastavlja da radi u pozadini pošto ovaj PHP zahtjev završi. Napredak
        // se prati preko $progressFile (vidi ScheduleProgress.java), ne preko
        // stdout-a - stdout/stderr idu u $logFile samo radi debagovanja.
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];
        $process = proc_open($command, $descriptorspec, $pipes, $projectRoot, null, ['bypass_shell' => true]);

        if (!is_resource($process)) {
            file_put_contents($progressFile, json_encode([
                'running' => false, 'done' => true, 'success' => false,
                'current_schedule' => 0, 'total_schedules' => 6,
                'current_course' => 0, 'total_courses' => 0,
                'message' => 'Java proces nije mogao biti pokrenut.',
                'error' => 'proc_open failed', 'updated_at' => time(),
            ]));
            echo json_encode(['status' => 'error', 'message' => 'Java proces nije mogao biti pokrenut. Proverite da li je Java instaliran i u PATH-u.']);
            exit;
        }

        // Ne šaljemo ništa na stdin - zatvori ga odmah.
        fclose($pipes[0]);
        // Namjerno NEMA proc_close() poziva: to bi čekalo da se proces završi
        // (isto kao blokirajući shell_exec od ranije). Ovako PHP odmah vraća
        // odgovor, a Java nastavlja u pozadini.

        echo json_encode(['status' => 'started', 'message' => 'Generisanje rasporeda je pokrenuto.']);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Greška: ' . $e->getMessage()]);
    } catch (Error $e) {
        echo json_encode(['status' => 'error', 'message' => 'Fatalna greška: ' . $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'generateschedule_status') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
        echo json_encode(['status' => 'error', 'message' => 'Nemate dozvolu za ovu akciju.']);
        exit;
    }

    $projectRoot = dirname(__DIR__, 2);
    $progressFile = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'schedule_progress.json';

    $default = ['running' => false, 'done' => true, 'success' => null, 'message' => '', 'error' => null];

    if (!file_exists($progressFile)) {
        echo json_encode($default, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // @ + retry - Java prepisuje ovaj fajl atomično (tmp + rename) često (svaki
    // predmet), pa PHP povremeno naiđe na prolazni Windows "sharing violation"
    // baš u trenutku rename-a. Trenutni retry gotovo uvijek uspije (prozor je
    // ispod milisekunde); ako ipak ne uspije, javi "još radi" umjesto lažnog
    // "završeno" da frontend ne prekine polling s pogrešnim podacima.
    $rawContents = @file_get_contents($progressFile);
    if ($rawContents === false) {
        $rawContents = @file_get_contents($progressFile);
    }
    $data = ($rawContents !== false) ? json_decode($rawContents, true) : null;
    if (!is_array($data)) {
        $stillRunning = ['running' => true, 'done' => false, 'success' => null, 'message' => 'Generisanje u toku...', 'error' => null];
        echo json_encode($rawContents === false ? $stillRunning : $default, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


// Only ADMIN can view this page.
// Redirect others.
if (!isset($_SESSION['role']) || !isset($_SESSION['user_id'])) {
    // Not loggedIn -> go to auth
    header('Location: ./authorization.php');
    exit;
}

if ($_SESSION['role'] !== 'ADMIN') {
    // LoggedIn but not admin -> go to professor profile
    header('Location: ./professor_panel.php');
    exit;
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_profesor':
                $full_name = $_POST['full_name'];
                $email = $_POST['email'];

                try {
                    $stmt = $pdo->prepare("INSERT INTO professor (full_name, email, is_active) VALUES (?, ?, TRUE)");
                    $stmt->execute([$full_name, $email]);
                    header("Location: ?page=profesori&success=1&message=" . urlencode("Profesor je uspješno dodat."));
                    exit;
                } catch (PDOException $e) {
                    $error = "Greška pri dodavanju profesora: " . $e->getMessage();
                }
                break;

            case 'add_predmet':
                $name = $_POST['name'];
                $semester = $_POST['semester'];
                $code = $_POST['code'];
                $is_optional = isset($_POST['is_optional']) ? 1 : 0;
                $lectures_per_week = isset($_POST['lectures_per_week']) ? (int)$_POST['lectures_per_week'] : 0;
                $exercises_per_week = isset($_POST['exercises_per_week']) ? (int)$_POST['exercises_per_week'] : 0;
                $labs_per_week = isset($_POST['labs_per_week']) ? (int)$_POST['labs_per_week'] : 0;
                $is_online = isset($_POST['is_online']) ? 1 : 0;
                $requires_computer_lab = isset($_POST['requires_computer_lab']) ? 1 : 0;
                $expected_students = (isset($_POST['expected_students']) && $_POST['expected_students'] !== '')
                    ? (int)$_POST['expected_students'] : null;
                $parallel_groups = isset($_POST['parallel_groups']) && (int)$_POST['parallel_groups'] > 0
                    ? (int)$_POST['parallel_groups'] : 1;

                try {
                    $stmt = $pdo->prepare("INSERT INTO course (name, semester, code, is_optional, lectures_per_week, exercises_per_week, labs_per_week, is_online, requires_computer_lab, expected_students, parallel_groups, is_active)
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)");
                    $stmt->execute([$name, $semester, $code, $is_optional, $lectures_per_week, $exercises_per_week, $labs_per_week, $is_online, $requires_computer_lab, $expected_students, $parallel_groups]);
                    header("Location: ?page=predmeti&success=1&message=" . urlencode("Predmet je uspješno dodat."));
                    exit;
                } catch (PDOException $e) {
                    $error = "Greška pri dodavanju predmeta: " . $e->getMessage();
                }
                break;

            case 'add_sala':
                $code = $_POST['code'];
                $capacity = $_POST['capacity'];
                $is_computer_lab = isset($_POST['is_computer_lab']) ? 1 : 0;
                $faculty_code = trim($_POST['faculty_code'] ?? '') !== '' ? trim($_POST['faculty_code']) : null;

                try {
                    $stmt = $pdo->prepare("INSERT INTO room (code, capacity, is_computer_lab, faculty_code, is_active)
                                          VALUES (?, ?, ?, ?, TRUE)");
                    $stmt->execute([$code, $capacity, $is_computer_lab, $faculty_code]);
                    header("Location: ?page=sale&success=1&message=" . urlencode("Sala je uspješno dodata."));
                    exit;
                } catch (PDOException $e) {
                    $error = "Greška pri dodavanju sale: " . $e->getMessage();
                }
                break;

            case 'add_dogadjaj':
                $course_id = $_POST['course_id'];
                $professor_id = $_POST['professor_id'];
                $type = $_POST['type'];
                $starts_at = $_POST['starts_at'];
                $ends_at = $_POST['ends_at'];
                $is_online = isset($_POST['is_online']) ? 1 : 0;
                $room_id = $is_online ? null : $_POST['room_id'];
                $notes = $_POST['notes'];
                $is_published = isset($_POST['is_published']) ? 1 : 0;

                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("INSERT INTO academic_event 
                                          (course_id, created_by_professor, type_enum, starts_at, ends_at, 
                                           is_online, room_id, notes, is_published, locked_by_admin) 
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE)");
                    $stmt->execute([$course_id, $professor_id, $type, $starts_at, $ends_at,
                        $is_online, $room_id, $notes, $is_published]);

                    $event_id = $pdo->lastInsertId();

                    // Dodavanje veze događaj-profesor
                    $stmt = $pdo->prepare("INSERT INTO event_professor (event_id, professor_id) 
                                          VALUES (?, ?)");
                    $stmt->execute([$event_id, $professor_id]);

                    $pdo->commit();
                    header("Location: ?page=dogadjaji&success=1&message=" . urlencode("Događaj je uspješno dodat."));
                    exit;
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $error = "Greška pri dodavanju događaja: " . $e->getMessage();
                }
                break;

            case 'assign_professor':
                // Pridruživanje profesora predmetu
                $course_id = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;
                $professor_id = isset($_POST['professor_id']) ? (int)$_POST['professor_id'] : 0;
                $is_assistant = isset($_POST['is_assistant']) ? 1 : 0;

                if ($course_id <= 0 || $professor_id <= 0) {
                    $error = "Morate izabrati i predmet i profesora.";
                    break;
                }

                try {
                    // Provjeri postojeće veze za predmet
                    $stmt = $pdo->prepare("SELECT professor_id, is_assistant FROM course_professor WHERE course_id = ?");
                    $stmt->execute([$course_id]);
                    $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Ako je profesor već pridružen - zabranjeno
                    foreach ($existing as $ex) {
                        if ((int)$ex['professor_id'] === $professor_id) {
                            $error = "Odabrani profesor je već pridružen ovom predmetu.";
                            break 2;
                        }
                    }

                    // Ubaci vezu (role_enum ima podrazumijevanu vrijednost u bazi)
                    $stmt = $pdo->prepare("INSERT INTO course_professor (course_id, professor_id, is_assistant) VALUES (?, ?, ?)");
                    $stmt->execute([$course_id, $professor_id, $is_assistant]);

                    header("Location: ?page=predmeti&success=1&message=" . urlencode("Profesor je uspješno pridružen predmetu."));
                    exit;
                } catch (PDOException $e) {
                    $error = "Greška pri povezivanju profesora i predmeta: " . $e->getMessage();
                }
                break;


            // Brisanje i deaktiviranje
            case 'delete_professor':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];

                    try {
                        $stmt = $pdo->prepare("UPDATE professor SET is_active = FALSE WHERE id = ?");
                        $stmt->execute([$id]);

                        header("Location: ?page=profesori&success=1&message=" . urlencode("Profesor je uspješno deaktiviran."));
                        exit;
                    } catch (PDOException $e) {
                        $error = "Greška pri deaktiviranju profesora: " . $e->getMessage();
                    }
                }
                break;

            case 'hard_delete_professor':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];

                    try {
                        $stmt = $pdo->prepare("DELETE FROM professor WHERE id = ?");
                        $stmt->execute([$id]);

                        header("Location: ?page=profesori&success=1&message=" . urlencode("Profesor je trajno obrisan."));
                        exit;
                    } catch (PDOException $e) {
                        $error = "Ne možete trajno obrisati ovog profesora jer postoje podaci vezani za njega (predmeti, raspored, nalog...). Probajte ga deaktivirati.";
                    }
                }
                break;

            case 'activate_professor':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];

                    try {
                        $stmt = $pdo->prepare("UPDATE professor SET is_active = TRUE WHERE id = ?");
                        $stmt->execute([$id]);

                        header("Location: ?page=profesori&success=1&message=" . urlencode("Profesor je uspješno aktiviran."));
                        exit;
                    } catch (PDOException $e) {
                        $error = "Greška pri aktiviranju profesora: " . $e->getMessage();
                    }
                }
                break;

            case 'activate_predmet':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];

                    try {
                        $stmt = $pdo->prepare("UPDATE course SET is_active = TRUE WHERE id = ?");
                        $stmt->execute([$id]);

                        header("Location: ?page=predmeti&success=1&message=" . urlencode("Predmet je uspješno aktiviran."));
                        exit;
                    } catch (PDOException $e) {
                        $error = "Greška pri aktiviranju predmeta: " . $e->getMessage();
                    }
                }
                break;

            case 'delete_predmet':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];

                    try {
                        $stmt = $pdo->prepare("UPDATE course SET is_active = FALSE WHERE id = ?");
                        $stmt->execute([$id]);

                        header("Location: ?page=predmeti&success=1&message=" . urlencode("Predmet je uspješno deaktiviran."));
                        exit;
                    } catch (PDOException $e) {
                        $error = "Greška pri deaktiviranju predmeta: " . $e->getMessage();
                    }
                }
                break;

            case 'hard_delete_predmet':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];

                    try {
                        $pdo->beginTransaction();
                        // course_professor veze ne blokiraju brisanje predmeta - obriši ih prvo
                        $pdo->prepare("DELETE FROM course_professor WHERE course_id = ?")->execute([$id]);
                        $stmt = $pdo->prepare("DELETE FROM course WHERE id = ?");
                        $stmt->execute([$id]);
                        $pdo->commit();

                        header("Location: ?page=predmeti&success=1&message=" . urlencode("Predmet je trajno obrisan."));
                        exit;
                    } catch (PDOException $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $error = "Ne možete trajno obrisati ovaj predmet jer postoje podaci vezani za njega (raspored, kolokvijumi...). Probajte ga deaktivirati.";
                    }
                }
                break;

            case 'activate_sala':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];

                    try {
                        $stmt = $pdo->prepare("UPDATE room SET is_active = TRUE WHERE id = ?");
                        $stmt->execute([$id]);

                        header("Location: ?page=sale&success=1&message=" . urlencode("Sala je uspješno aktivirana."));
                        exit;
                    } catch (PDOException $e) {
                        $error = "Greška pri aktiviranju sale: " . $e->getMessage();
                    }
                }
                break;

            case 'delete_sala':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];

                    try {
                        $stmt = $pdo->prepare("UPDATE room SET is_active = FALSE WHERE id = ?");
                        $stmt->execute([$id]);

                        header("Location: ?page=sale&success=1&message=" . urlencode("Sala je uspješno deaktivirana."));
                        exit;
                    } catch (PDOException $e) {
                        $error = "Greška pri deaktiviranju sale: " . $e->getMessage();
                    }
                }
                break;

            case 'hard_delete_sala':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];

                    try {
                        $stmt = $pdo->prepare("DELETE FROM room WHERE id = ?");
                        $stmt->execute([$id]);

                        header("Location: ?page=sale&success=1&message=" . urlencode("Sala je trajno obrisana."));
                        exit;
                    } catch (PDOException $e) {
                        $error = "Ne možete trajno obrisati ovu salu jer postoje podaci vezani za nju (raspored, zauzetost...). Probajte je deaktivirati.";
                    }
                }
                break;

            case 'delete_dogadjaj':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];

                    try {
                        $pdo->beginTransaction();

                        $stmt = $pdo->prepare("DELETE FROM event_professor WHERE event_id = ?");
                        $stmt->execute([$id]);

                        $stmt = $pdo->prepare("DELETE FROM academic_event WHERE id = ?");
                        $stmt->execute([$id]);

                        $pdo->commit();
                        header("Location: ?page=dogadjaji&success=1&message=" . urlencode("Događaj je uspješno obrisan."));
                        exit;
                    } catch (PDOException $e) {
                        $pdo->rollBack();
                        $error = "Greška pri brisanju događaja: " . $e->getMessage();
                    }
                }
                break;

            case 'add_academic_year':
                $year_label = $_POST['year_label'];
                $winter_start = $_POST['winter_semester_start'];
                $summer_start = $_POST['summer_semester_start'];

                try {
                    // Nova godina postaje aktivna - ostale se deaktiviraju, jer i Java
                    // generator i PHP stranice čitaju "WHERE is_active = TRUE LIMIT 1", pa bi
                    // dvije aktivne godine dale nasumično jednu ili drugu (datumi semestara,
                    // zauzetost sala).
                    $pdo->beginTransaction();
                    $pdo->exec("UPDATE academic_year SET is_active = FALSE WHERE is_active = TRUE");
                    $stmt = $pdo->prepare("INSERT INTO academic_year (year_label, winter_semester_start, summer_semester_start, is_active) VALUES (?, ?, ?, TRUE)");
                    $stmt->execute([$year_label, $winter_start, $summer_start]);
                    $pdo->commit();
                    header("Location: ?page=dogadjaji&success=1&message=" . urlencode("Akademska godina je uspješno dodata."));
                    exit;
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = "Greška pri dodavanju akademske godine: " . $e->getMessage();
                }
                break;

            case 'save_occupancy':
                $selections = json_decode($_POST['selections'] ?? '[]', true);
                $faculty_code = $_POST['faculty_code'] ?? '';
                $acad_year_id = (int)($_POST['academic_year_id'] ?? 0);

                if (empty($selections) || $acad_year_id === 0) {
                    $error = "Nevažeći podaci za snimanje zauzetosti.";
                    break;
                }

                $ret = $occupancyService->saveOccupancy($acad_year_id, $selections, $faculty_code);
                if ($ret['success']) {
                    header("Location: ?page=zauzetost&success=1&message=" . urlencode("Zauzetost sala je uspješno ažurirana."));
                    exit;
                } else {
                    $error = "Greška pri snimanju zauzetosti sala: " . $ret['error'];
                }
                break;

            // ****  update  ****

            case 'delete_academic_year':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];

                    try {
                        $stmt = $pdo->prepare("DELETE FROM academic_year WHERE id = ?");
                        $stmt->execute([$id]);
                        header("Location: ?page=dogadjaji&success=1&message=" . urlencode("Akademska godina je uspješno obrisana."));
                        exit;
                    } catch (PDOException $e) {
                        // Check for foreign key violation
                        if ($e->getCode() == '23503') {
                            $error = "Greška: Ne možete obrisati ovu akademsku godinu jer postoje podaci vezani za nju. Probajte je deaktivirati.";
                        } else {
                            $error = "Greška pri brisanju akademske godine: " . $e->getMessage();
                        }
                    }
                }
                break;

            case 'update_academic_year':
                $id = (int)$_POST['year_id'];
                $year_label = $_POST['year_label'];
                $winter_start = $_POST['winter_semester_start'];
                $summer_start = $_POST['summer_semester_start'];
                $is_active = isset($_POST['is_active']) ? true : false;

                try {
                    $pdo->beginTransaction();
                    if ($is_active) {
                        // Samo jedna aktivna akademska godina (vidi add_academic_year)
                        $pdo->prepare("UPDATE academic_year SET is_active = FALSE WHERE id <> ? AND is_active = TRUE")->execute([$id]);
                    }
                    $stmt = $pdo->prepare("UPDATE academic_year SET year_label = ?, winter_semester_start = ?, summer_semester_start = ?, is_active = ? WHERE id = ?");
                    $stmt->execute([$year_label, $winter_start, $summer_start, $is_active ? 'TRUE' : 'FALSE', $id]);
                    $pdo->commit();
                    header("Location: ?page=dogadjaji&success=1&message=" . urlencode("Akademska godina je uspješno ažurirana."));
                    exit;
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = "Greška pri ažuriranju akademske godine: " . $e->getMessage();
                }
                break;

            case 'update_profesor':

                $fields = [];
                $params = [];

                if (isset($_POST['full_name'])) {
                    $fields[] = "full_name = ?";
                    $params[] = $_POST['full_name'];
                }
                if (isset($_POST['email'])) {
                    $fields[] = "email = ?";
                    $params[] = $_POST['email'];
                }
                if (!isset($_POST['profesor_id'])) {
                    throw new Exception("Profesorov ID nije validan.");
                }

                $params[] = (int)$_POST['profesor_id'];

                if (empty($fields)) {
                    throw new Exception("Nema podataka za ažuriranje.");
                }


                try {
                    $stmt = $pdo->prepare("UPDATE professor SET " . implode(', ', $fields) . " WHERE id=?");
                    $stmt->execute($params);
                    header("Location: ?page=profesori&success=1&message=" . urlencode("Profesor je uspješno ažuriran."));
                    exit;
                } catch (PDOException $e) {
                    $error = "Greška pri ažuriranju profesora: " . $e->getMessage();
                }
                break;

            case 'update_predmet':
                $fields = [];
                $params = [];

                if (isset($_POST['name'])) {
                    $fields[] = "name = ?";
                    $params[] = $_POST['name'];
                }
                if (isset($_POST['semester'])) {
                    $fields[] = "semester = ?";
                    $params[] = $_POST['semester'];
                }
                if (isset($_POST['code'])) {
                    $fields[] = "code = ?";
                    $params[] = $_POST['code'];
                }
                if (isset($_POST['lectures_per_week'])) {
                    $fields[] = "lectures_per_week = ?";
                    $params[] = (int)$_POST['lectures_per_week'];
                }
                if (isset($_POST['exercises_per_week'])) {
                    $fields[] = "exercises_per_week = ?";
                    $params[] = (int)$_POST['exercises_per_week'];
                }
                if (isset($_POST['labs_per_week'])) {
                    $fields[] = "labs_per_week = ?";
                    $params[] = (int)$_POST['labs_per_week'];
                }
                if (isset($_POST['expected_students'])) {
                    $fields[] = "expected_students = ?";
                    $params[] = $_POST['expected_students'] !== '' ? (int)$_POST['expected_students'] : null;
                }
                if (isset($_POST['parallel_groups'])) {
                    $fields[] = "parallel_groups = ?";
                    $params[] = (int)$_POST['parallel_groups'] > 0 ? (int)$_POST['parallel_groups'] : 1;
                }

                // Uvijek ažuriramo checkbox-ove jer HTML forme ne šalju unchecked vrijednosti
                $fields[] = "is_optional = ?";
                $params[] = isset($_POST['is_optional']) ? 1 : 0;
                $fields[] = "is_online = ?";
                $params[] = isset($_POST['is_online']) ? 1 : 0;
                $fields[] = "requires_computer_lab = ?";
                $params[] = isset($_POST['requires_computer_lab']) ? 1 : 0;

                if (!isset($_POST['course_id'])) {
                    throw new Exception("Course ID nije validan.");
                }

                $params[] = (int)$_POST['course_id'];

                if (empty($fields)) {
                    throw new Exception("Nema podataka za ažuriranje.");
                }


                try {
                    $stmt = $pdo->prepare("UPDATE course SET " . implode(', ', $fields) . " WHERE id=?");
                    $stmt->execute($params);
                    // Ako su poslata prof_assignments polja, obraditi ih (zamijeni postojeće veze)
                    if (isset($_POST['prof_assignments'])) {
                        $raw = $_POST['prof_assignments'];
                        $assignments = json_decode($raw, true);
                        if (!is_array($assignments)) {
                            throw new Exception('Neispravan format podataka o profesorima.');
                        }

                        // Basic validation
                        $count = count($assignments);

                        $ids = [];
                        foreach ($assignments as $a) {
                            // accept either professor_id or id (frontend may send {id:...})
                            if (!isset($a['professor_id']) && !isset($a['id'])) throw new Exception('Nedostaje professor_id.');
                            $pid = (int)($a['professor_id'] ?? $a['id']);
                            if ($pid <= 0) throw new Exception('Neispravan professor_id.');
                            if (in_array($pid, $ids)) throw new Exception('Isti profesor ne može biti u više uloga.');
                            $ids[] = $pid;
                        }

                        // Zamijeni veze u transakciji
                        $pdo->beginTransaction();
                        $stmt = $pdo->prepare("DELETE FROM course_professor WHERE course_id = ?");
                        $stmt->execute([(int)$_POST['course_id']]);

                        if ($count > 0) {
                            $ins = $pdo->prepare("INSERT INTO course_professor (course_id, professor_id, is_assistant) VALUES (?, ?, ?)");
                            foreach ($assignments as $a) {
                                $pid = (int)($a['professor_id'] ?? $a['id']);
                                $ins->execute([(int)$_POST['course_id'], $pid, isset($a['is_assistant']) && $a['is_assistant'] ? 1 : 0]);
                            }
                        }
                        $pdo->commit();
                    }
                    header("Location: ?page=predmeti&success=1&message=" . urlencode("Predmet je uspješno ažuriran."));
                    exit;
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = "Greška pri ažuriranju predmeta: " . $e->getMessage();
                }
                break;

            case 'update_sala':
                $fields = [];
                $params = [];

                if (isset($_POST['code'])) {
                    $fields[] = "code = ?";
                    $params[] = $_POST['code'];
                }
                if (isset($_POST['capacity'])) {
                    $fields[] = "capacity = ?";
                    $params[] = $_POST['capacity'];
                }

                // Uvijek ažuriramo checkbox jer HTML forme ne šalju unchecked vrijednosti
                $fields[] = "is_computer_lab = ?";
                $params[] = isset($_POST['is_computer_lab']) ? 1 : 0;

                if (isset($_POST['faculty_code'])) {
                    $fields[] = "faculty_code = ?";
                    $params[] = trim($_POST['faculty_code']) !== '' ? trim($_POST['faculty_code']) : null;
                }

                if (!isset($_POST['sala_id'])) {
                    throw new Exception("Sala ID nije validan.");
                }

                $params[] = (int)$_POST['sala_id'];

                if (empty($fields)) {
                    throw new Exception("Nema podataka za ažuriranje.");
                }

                try {
                    $stmt = $pdo->prepare("UPDATE room SET " . implode(', ', $fields) . " WHERE id=?");
                    $stmt->execute($params);
                    header("Location: ?page=sale&success=1&message=" . urlencode("Sala je uspješno ažurirana."));
                    exit;
                } catch (PDOException $e) {
                    $error = "Greška pri ažuriranju sale: " . $e->getMessage();
                }
                break;

            case 'update_dogadjaj':
                $fields = [];
                $params = [];

                $fields2 = [];
                $params2 = [];

                if (isset($_POST['course_id'])) {
                    $fields[] = "course_id = ?";
                    $params[] = $_POST['course_id'];
                }
                if (isset($_POST['professor_id'])) {
                    $fields2[] = "professor_id = ?";
                    $params2[] = $_POST['professor_id'];
                }
                if (isset($_POST['type'])) {
                    // DB column is 'type_enum'
                    $fields[] = "type_enum = ?";
                    $params[] = $_POST['type'];
                }
                if (isset($_POST['starts_at'])) {
                    $fields[] = "starts_at = ?";
                    $params[] = $_POST['starts_at'];
                }
                if (isset($_POST['ends_at'])) {
                    $fields[] = "ends_at = ?";
                    $params[] = $_POST['ends_at'];
                }
                if (isset($_POST['is_online'])) {
                    $fields[] = "is_online = ?";
                    $params[] = isset($_POST['is_online']) ? 1 : 0;
                }
                if (isset($_POST['room_id'])) {
                    $fields[] = "room_id = ?";
                    $params[] = $_POST['room_id'];
                }
                if (isset($_POST['notes'])) {
                    $fields[] = "notes = ?";
                    $params[] = $_POST['notes'];
                }
                if (isset($_POST['is_published'])) {
                    $fields[] = "is_published = ?";
                    $params[] = isset($_POST['is_published']) ? 1 : 0;
                }

                if (!isset($_POST['dogadjaj_id'])) {
                    throw new Exception("Dogadjaj ID nije validan.");
                }
                // dogadjaj id
                $params[] = (int)$_POST['dogadjaj_id'];
                // If frontend didn't provide event_professor_id, try to lookup; if none, we'll insert later.
                $need_insert_event_prof = false;
                if (!isset($_POST['event_professor_id'])) {
                    try {
                        $stmtEp = $pdo->prepare("SELECT id FROM event_professor WHERE event_id = ? LIMIT 1");
                        $stmtEp->execute([(int)$_POST['dogadjaj_id']]);
                        $epRow = $stmtEp->fetch(PDO::FETCH_ASSOC);
                        if ($epRow && isset($epRow['id'])) {
                            $_POST['event_professor_id'] = (int)$epRow['id'];
                        } else {
                            // mark that we need to insert a new event_professor row after updating the event
                            $need_insert_event_prof = true;
                        }
                    } catch (PDOException $e) {
                        // DB error while looking up — mark for insert (so we attempt to create row if possible)
                        $need_insert_event_prof = true;
                    }
                }

                if (empty($fields)) {
                    throw new Exception("Nema podataka za ažuriranje.");
                }


                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("UPDATE academic_event SET " . implode(', ', $fields) . " WHERE id=?");
                    $stmt->execute($params);

                    $event_id = (int)$_POST['dogadjaj_id'];;

                    // Handle event_professor relation: update existing or insert new
                    if (isset($_POST['event_professor_id']) && (int)$_POST['event_professor_id'] > 0) {
                        // update the existing relation's professor if professor_id provided
                        if (isset($_POST['professor_id'])) {
                            $upd = $pdo->prepare("UPDATE event_professor SET professor_id = ? WHERE id = ?");
                            $upd->execute([(int)$_POST['professor_id'], (int)$_POST['event_professor_id']]);
                        }
                    } else {
                        // no existing relation id — replace any existing relation for this event with new one (if professor_id present)
                        if (isset($_POST['professor_id'])) {
                            // remove any existing relations for safety
                            $del = $pdo->prepare("DELETE FROM event_professor WHERE event_id = ?");
                            $del->execute([$event_id]);
                            // insert new relation
                            $ins = $pdo->prepare("INSERT INTO event_professor (event_id, professor_id) VALUES (?, ?)");
                            $ins->execute([$event_id, (int)$_POST['professor_id']]);
                        }
                    }

                    $pdo->commit();
                    header("Location: ?page=dogadjaji&success=1&message=" . urlencode("Događaj je uspješno ažuriran."));
                    exit;
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $error = "Greška pri ažuriranju događaja: " . $e->getMessage();
                }
                break;

            // --------- user_account management ---------
            case 'add_account':
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                $role = $_POST['role'] ?? 'PROFESSOR';
                if (!in_array($role, ['ADMIN', 'PROFESSOR'], true)) {
                    $role = 'PROFESSOR';
                }
                $professor_id = isset($_POST['professor_id']) && is_numeric($_POST['professor_id']) ? (int)$_POST['professor_id'] : null;

                if ($username === '' || $password === '') {
                    $error = 'Username i lozinka su obavezni.';
                    break;
                }

                try {
                    // check unique username
                    $stmt = $pdo->prepare("SELECT id FROM user_account WHERE username = ? LIMIT 1");
                    $stmt->execute([$username]);
                    if ($stmt->fetch()) {
                        $error = 'Korisničko ime već postoji.';
                        break;
                    }

                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO user_account (username, password_hash, role_enum, is_active, professor_id) VALUES (?, ?, ?, TRUE, ?)");
                    $stmt->execute([$username, $password_hash, $role, $professor_id]);

                    // Pošalji inicijalnu lozinku korisniku na email povezanog profesora (ako postoji)
                    $mailSent = null;
                    if ($professor_id) {
                        $profStmt = $pdo->prepare("SELECT email FROM professor WHERE id = ?");
                        $profStmt->execute([$professor_id]);
                        $profEmail = $profStmt->fetchColumn();
                        if ($profEmail) {
                            $mailSent = sendAccountCredentialsEmail($profEmail, $username, $password);
                        }
                    }

                    $successMsg = "Korisnik je uspješno dodat.";
                    if ($mailSent === true) {
                        $successMsg .= " Podaci za prijavu su poslani na email.";
                    } elseif ($mailSent === false) {
                        $successMsg .= " Napomena: slanje emaila sa lozinkom nije uspjelo — prenesite lozinku korisniku ručno.";
                    }

                    header("Location: ?page=account&success=1&message=" . urlencode($successMsg));
                    exit;
                } catch (PDOException $e) {
                    $error = 'Greška pri dodavanju korisnika: ' . $e->getMessage();
                }
                break;

            case 'update_account':
                if (!isset($_POST['account_id']) || !is_numeric($_POST['account_id'])) {
                    $error = 'Neispravan ID korisnika.';
                    break;
                }
                $accId = (int)$_POST['account_id'];
                $fields = [];
                $params = [];

                if (isset($_POST['username']) && trim($_POST['username']) !== '') {
                    $fields[] = 'username = ?';
                    $params[] = trim($_POST['username']);
                }
                if (isset($_POST['password']) && $_POST['password'] !== '') {
                    $fields[] = 'password_hash = ?';
                    $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
                }
                if (isset($_POST['role'])) {
                    $fields[] = 'role_enum = ?';
                    $params[] = $_POST['role'];
                }
                if (isset($_POST['is_active'])) {
                    $fields[] = 'is_active = ?';
                    $params[] = isset($_POST['is_active']) && $_POST['is_active'] ? 1 : 0;
                }
                if (array_key_exists('professor_id', $_POST)) {
                    $prof = is_numeric($_POST['professor_id']) ? (int)$_POST['professor_id'] : null;
                    $fields[] = 'professor_id = ?';
                    $params[] = $prof;
                }

                if (empty($fields)) {
                    $error = 'Nema podataka za ažuriranje.';
                    break;
                }

                $params[] = $accId;
                try {
                    $stmt = $pdo->prepare('UPDATE user_account SET ' . implode(', ', $fields) . ' WHERE id = ?');
                    $stmt->execute($params);
                    header("Location: ?page=account&success=1&message=" . urlencode("Korisnik je uspješno ažuriran."));
                    exit;
                } catch (PDOException $e) {
                    $error = 'Greška pri ažuriranju korisnika: ' . $e->getMessage();
                }
                break;

            case 'delete_account':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];
                    try {
                        $stmt = $pdo->prepare("UPDATE user_account SET is_active = FALSE WHERE id = ?");
                        $stmt->execute([$id]);
                        header("Location: ?page=account&success=1&message=" . urlencode("Korisnik je uspješno deaktiviran."));
                        exit;
                    } catch (PDOException $e) {
                        $error = 'Greška pri deaktiviranju korisnika: ' . $e->getMessage();
                    }
                }
                break;

            case 'activate_account':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];
                    try {
                        $stmt = $pdo->prepare("UPDATE user_account SET is_active = TRUE WHERE id = ?");
                        $stmt->execute([$id]);
                        header("Location: ?page=account&success=1&message=" . urlencode("Korisnik je uspješno aktiviran."));
                        exit;
                    } catch (PDOException $e) {
                        $error = 'Greška pri aktiviranju korisnika: ' . $e->getMessage();
                    }
                }
                break;

            case 'hard_delete_account':
                if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                    $id = (int)$_POST['id'];
                    try {
                        $stmt = $pdo->prepare("DELETE FROM user_account WHERE id = ?");
                        $stmt->execute([$id]);
                        header("Location: ?page=account&success=1&message=" . urlencode("Nalog je trajno obrisan."));
                        exit;
                    } catch (PDOException $e) {
                        $error = 'Ne možete trajno obrisati ovaj nalog jer postoje podaci vezani za njega. Probajte ga deaktivirati.';
                    }
                }
                break;

            case 'set_deadline':
                $deadline_date = $_POST['deadline_date'];

                try {

                    $stmt = $pdo->prepare("
                        INSERT INTO config (\"key\", value) 
                        VALUES ('schedule_deadline', ?) 
                        ON CONFLICT (\"key\") DO UPDATE SET value = ?
                    ");
                    $stmt->execute([$deadline_date, $deadline_date]);

                    header("Location: ?page=profesori&success=1&message=" . urlencode("Uspješno postavljen rok za izbor sedmice kolokvijuma."));
                    exit;
                } catch (PDOException $e) {
                    $error = "Greška pri postavljanju deadlina: " . $e->getMessage();
                }
                break;

            // --------- end user_account management ---------
        }
    }
}
?>

<!DOCTYPE html>
<html lang="sr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Raspored Ispita</title>
    <link rel="stylesheet" href="../assets/css/admin.css"/>
    <link rel="stylesheet" href="../assets/css/base.css"/>
    <link rel="stylesheet" href="../assets/css/fields.css"/>
    <link rel="stylesheet" href="../assets/css/colors.css"/>
    <link rel="stylesheet" href="../assets/css/stacks.css"/>
    <link rel="stylesheet" href="../assets/css/tabs.css"/>
    <link rel="stylesheet" href="../assets/css/table.css"/>
    <link rel="stylesheet" href="../assets/css/occupancy.css"/>

    <?php // expose active professors to JS before admin.js loads ?>
    <script>
        window.adminData = window.adminData || {};
        window.adminData.professors = <?php
        try {
            $stmtForJS = $pdo->query("SELECT id, full_name, email FROM professor WHERE is_active = TRUE ORDER BY full_name");
            $rows = $stmtForJS->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            echo '[]';
        }
        ?> ||
        [];
        <?php
        // expose schedule_locked config and locked schedule ids
        try {
            $schedLockedVal = $pdo->query("SELECT value FROM config WHERE \"key\" = 'schedule_locked'")->fetchColumn();
            $schedLocked = false;
            if ($schedLockedVal !== false && $schedLockedVal !== null) {
                $schedLocked = in_array(strval($schedLockedVal), ['1', 'true', 'TRUE', 't'], true);
            }
            $lockedIds = [];
            if ($schedLocked) {
                $lockedStmt = $pdo->query("SELECT DISTINCT schedule_id FROM academic_event WHERE locked_by_admin = TRUE AND schedule_id IS NOT NULL ORDER BY schedule_id DESC");
                $lockedIds = $lockedStmt->fetchAll(PDO::FETCH_COLUMN);
            }
            echo "window.adminData.schedule_locked = " . ($schedLocked ? 'true' : 'false') . ";\n";
            echo "window.adminData.locked_schedule_ids = " . json_encode(array_values($lockedIds), JSON_UNESCAPED_UNICODE) . ";\n";
        } catch (PDOException $e) {
            echo "window.adminData.schedule_locked = false;\nwindow.adminData.locked_schedule_ids = [];\n";
        }
        ?>
        window.adminData.faculties = ["FIT", "FEB", "MTS", "PF", "FSJ", "FVU"];
    </script>
    <script src="../assets/js/jspdf.umd.min.js"></script>
    <script src="../assets/js/jspdf.plugin.autotable.min.js"></script>
    <script src="../assets/js/DejaVuSans-normal.js"></script>
    <script src="../assets/js/admin.js" defer></script>

    <header>
        <h1>Admin Panel</h1>
        <nav>
            <ul>
                <li><a href="index.php">Pocetna</a></li>
                <li><a href="?page=profesori">Profesori</a></li>
                <li><a href="?page=predmeti">Predmeti</a></li>
                <li><a href="?page=sale">Sale</a></li>
                <li><a href="?page=account">Nalog</a></li>
                <li><a href="?page=zauzetost">Zauzetost sala</a></li>
                <li><a href="?page=dogadjaji">Informacije</a></li>
                <li><a href="?page=logout">Rasporedi</a></li>
                <li><a href="logout.php">Odjavi se</a></li>
            </ul>
        </nav>
    </header>

    <main>
        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="success">
                <?php
                if (isset($_GET['message'])) {
                    echo htmlspecialchars($_GET['message']);
                } else {
                    echo "Operacija je uspješno izvršena!";
                }
                ?>
            </div>
        <?php endif; ?>

        <?php
        $page = isset($_GET['page']) ? $_GET['page'] : 'pocetna';

        switch ($page) {
        case 'profesori':
        ?>
        <h2>Upravljanje profesorima</h2>
        <button class="action-button add-button" onclick="toggleForm('profesorForm')">+ Dodaj Profesora</button>
        <button class="action-button add-button deadline-button" onclick="toggleForm('deadlineForm')">+ Rok za unos
            kolokvijuma
        </button>

        <div id="profesorForm" class="form-container" style="display: none">
            <h3>Novi profesor</h3>
            <form method="post">
                <input type="hidden" name="action" value="add_profesor">

                <label for="full_name">Ime i prezime:</label>
                <input type="text" id="full_name" name="full_name" required>

                <label for="email">Email:</label>
                <input type="email" id="email" name="email" required>

                <button type="submit">Sačuvaj</button>
            </form>
        </div>

        <div id="deadlineForm" class="form-container" style="display:none">
            <h3>Postavite rok za izbor sedmice kolokvijuma</h3>
            <form method="post">
                <input type="hidden" name="action" value="set_deadline">
                <input type="date" id="deadline_date" name="deadline_date" value="<?php echo $current_deadline; ?>"
                       required>
                <div style="display: flex; gap: 10px; margin-top: 15px;">
                    <button type="submit">Sačuvaj</button>
                </div>
            </form>
        </div>

        <form method="get" style="margin: 15px 0;">
            <input type="hidden" name="page" value="profesori">
            <input type="text" name="q" placeholder="Pretraga po imenu ili emailu..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" style="padding:6px; min-width:250px;">
            <button type="submit" class="action-button">Pretraži</button>
            <?php if (!empty($_GET['q'])): ?>
                <a href="?page=profesori" class="action-button" style="text-decoration:none; display:inline-block;">Poništi</a>
            <?php endif; ?>
        </form>

        <table border="1" cellpadding="5">
            <tr>
                <th>Ime i prezime</th>
                <th>Email</th>
                <th>Uloga</th>
                <th>Status</th>
                <th>Akcije</th>
            </tr>
            <?php

            try {
                // Uloga se izvodi iz dodjela na predmetima (predavač/saradnik) i naloga (admin)
                $profSelect = "SELECT p.*,
                        EXISTS (SELECT 1 FROM course_professor cp WHERE cp.professor_id = p.id AND cp.is_assistant = FALSE) AS is_lecturer,
                        EXISTS (SELECT 1 FROM course_professor cp WHERE cp.professor_id = p.id AND cp.is_assistant = TRUE) AS is_assistant_any,
                        EXISTS (SELECT 1 FROM user_account ua WHERE ua.professor_id = p.id AND ua.role_enum = 'ADMIN') AS is_admin
                    FROM professor p";
                $profSearch = trim($_GET['q'] ?? '');
                if ($profSearch !== '') {
                    $stmt = $pdo->prepare("$profSelect WHERE p.full_name ILIKE ? OR p.email ILIKE ? ORDER BY p.full_name");
                    $like = '%' . $profSearch . '%';
                    $stmt->execute([$like, $like]);
                } else {
                    $stmt = $pdo->query("$profSelect ORDER BY p.full_name");
                }
                while ($row = $stmt->fetch()) {
                    $roles = [];
                    if ($row['is_admin']) $roles[] = 'Admin';
                    if ($row['is_lecturer']) $roles[] = 'Predavač';
                    if ($row['is_assistant_any']) $roles[] = 'Saradnik';
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($row['full_name']) . "</td>";
                    echo "<td>" . htmlspecialchars($row['email']) . "</td>";
                    echo "<td>" . ($roles ? implode(', ', $roles) : '<span style="color:#888">bez predmeta</span>') . "</td>";
                    echo "<td>" . ($row['is_active'] ? 'Aktivan' : 'Neaktivan') . "</td>";
                    echo "<td>";
                    echo "<button class='action-button edit-button' data-entity='profesor' data-id='" . $row['id'] . "' data-full_name='" . htmlspecialchars($row['full_name'], ENT_QUOTES) . "' data-email='" . htmlspecialchars($row['email'], ENT_QUOTES) . "'>Uredi</button>";

                    if ($row['is_active']) {
                        echo "<form id='delete-profesor-{$row['id']}' style='display:inline' method='post' action='{$_SERVER['PHP_SELF']}'>
                            <input type='hidden' name='action' value='delete_professor'>
                            <input type='hidden' name='id' value='{$row['id']}'>
                            <button type='button' class='action-button delete-button' onclick=\"submitDeleteForm({$row['id']}, 'delete_professor', 'profesor')\">Deaktiviraj</button>
                        </form>";
                    } else {
                        echo "<form id='activate-profesor-{$row['id']}' style='display:inline' method='post' action='{$_SERVER['PHP_SELF']}'>
                            <input type='hidden' name='action' value='activate_professor'>
                            <input type='hidden' name='id' value='{$row['id']}'>
                            <button type='button' class='action-button activation-button' onclick=\"submitDeleteForm({$row['id']}, 'activate_professor', 'profesor')\">Aktiviraj</button>
                        </form>";
                    }

                    echo "<form id='harddelete-profesor-{$row['id']}' style='display:inline' method='post' action='{$_SERVER['PHP_SELF']}'>
                        <input type='hidden' name='action' value='hard_delete_professor'>
                        <input type='hidden' name='id' value='{$row['id']}'>
                        <button type='button' class='action-button delete-button' onclick=\"if(confirm('Trajno obrisati ovog profesora? Ova akcija se ne može poništiti.')) submitDeleteForm({$row['id']}, 'hard_delete_professor', 'profesor')\">Obriši trajno</button>
                    </form>";

                    echo "</td>";
                    echo "</tr>";
                }

            } catch (PDOException $e) {
                echo "<tr><td colspan='5'>Greška pri dohvaćanju profesora: " . htmlspecialchars($e->getMessage()) . "</td></tr>";
            }
            echo "</table>";
            break;
            case 'predmeti':
                ?>

                <h2>Upravljanje predmetima</h2>

                <button class="action-button add-button" onclick="toggleForm('predmetForm')">
                    + Dodaj Predmet
                </button>

                <button class="action-button add-button" onclick="toggleForm('assignForm')">
                    + Pridruži Profesora
                </button>

                <form method="get" style="margin: 15px 0;">
                    <input type="hidden" name="page" value="predmeti">
                    <input type="text" name="q" placeholder="Pretraga po nazivu ili šifri predmeta..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" style="padding:6px; min-width:250px;">
                    <button type="submit" class="action-button">Pretraži</button>
                    <?php if (!empty($_GET['q'])): ?>
                        <a href="?page=predmeti" class="action-button" style="text-decoration:none; display:inline-block;">Poništi</a>
                    <?php endif; ?>
                </form>

                <!-- ===== Pridruži profesora ===== -->
                <div id="assignForm" class="form-container" style="display:none">
                    <h3>Pridruži profesora predmetu</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="assign_professor">

                        <label>Predmet:</label>
                        <select name="course_id" required>
                            <option value="">-- Odaberite --</option>
                            <?php
                            $stmt = $pdo->query("SELECT id, name, code FROM course WHERE is_active = TRUE ORDER BY name");
                            while ($c = $stmt->fetch()):
                                ?>
                                <option value="<?= $c['id'] ?>">
                                    <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['code']) ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>

                        <label>Profesor:</label>
                        <select name="professor_id" required>
                            <option value="">-- Odaberite --</option>
                            <?php
                            $stmt = $pdo->query("SELECT id, full_name FROM professor WHERE is_active = TRUE ORDER BY full_name");
                            while ($p = $stmt->fetch()):
                                ?>
                                <option value="<?= $p['id'] ?>">
                                    <?= htmlspecialchars($p['full_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>

                        <label>
                            <input type="checkbox" name="is_assistant"> Asistent
                        </label>

                        <button type="submit">Sačuvaj</button>
                    </form>
                </div>

                <!-- ===== Novi predmet ===== -->
                <div id="predmetForm" class="form-container" style="display:none">
                    <h3>Novi predmet</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="add_predmet">

                        <label>Naziv:</label>
                        <input type="text" name="name" required>

                        <label>Šifra:</label>
                        <input type="text" name="code" required>

                        <label>Semestar:</label>
                        <input type="number" name="semester" min="1" max="6" required>

                        <label>Fond časova - predavanja (sedmično):</label>
                        <input type="number" name="lectures_per_week" min="0" step="1" value="0" required>

                        <label>Fond časova - vježbe (sedmično):</label>
                        <input type="number" name="exercises_per_week" min="0" step="1" value="0" required>

                        <label>Fond časova - lab (sedmično):</label>
                        <input type="number" name="labs_per_week" min="0" step="1" value="0" required>

                        <label>Očekivan broj studenata (za poređenje sa kapacitetom sale, opciono):</label>
                        <input type="number" name="expected_students" min="0" step="1" placeholder="npr. 35">

                        <label>Broj paralelnih grupa istovremeno (1 = jedna grupa):</label>
                        <input type="number" name="parallel_groups" min="1" step="1" value="1">

                        <label>
                            <input type="checkbox" name="is_optional"> Izborni
                        </label>

                        <label>
                            <input type="checkbox" name="is_online"> Ne zahtijeva salu (onlajn nastava)
                        </label>

                        <label>
                            <input type="checkbox" name="requires_computer_lab"> Zahtijeva računarsku salu
                        </label>

                        <button type="submit">Sačuvaj</button>
                    </form>
                </div>

                <?php
                $predmetSearch = trim($_GET['q'] ?? '');
                $predmetSql = "
    SELECT
        c.id AS course_id,
        c.name,
        c.code,
        c.semester,
        c.is_optional,
        c.lectures_per_week,
        c.exercises_per_week,
        c.labs_per_week,
        c.is_online,
        c.requires_computer_lab,
        c.expected_students,
        c.parallel_groups,
        c.is_active,
        cp.professor_id,
        cp.is_assistant,
        p.full_name
    FROM course c
    LEFT JOIN course_professor cp ON cp.course_id = c.id
    LEFT JOIN professor p ON p.id = cp.professor_id
    " . ($predmetSearch !== '' ? "WHERE c.name ILIKE ? OR c.code ILIKE ?" : "") . "
    ORDER BY c.name ASC, c.id ASC
";
                $stmt = $pdo->prepare($predmetSql);
                if ($predmetSearch !== '') {
                    $like = '%' . $predmetSearch . '%';
                    $stmt->execute([$like, $like]);
                } else {
                    $stmt->execute();
                }

                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $courses = [];

                foreach ($rows as $r) {
                    $cid = (int)$r['course_id'];
                    if (!isset($courses[$cid])) {
                        $courses[$cid] = [
                            'id' => $cid,
                            'name' => $r['name'],
                            'code' => $r['code'],
                            'semester' => $r['semester'],
                            'is_optional' => (int)$r['is_optional'],
                            'lectures_per_week' => (int)$r['lectures_per_week'],
                            'exercises_per_week' => (int)$r['exercises_per_week'],
                            'labs_per_week' => (int)$r['labs_per_week'],
                            'is_online' => (int)$r['is_online'],
                            'requires_computer_lab' => (int)$r['requires_computer_lab'],
                            'expected_students' => $r['expected_students'],
                            'parallel_groups' => (int)($r['parallel_groups'] ?: 1),
                            'is_active' => (int)$r['is_active'],
                            'professors' => []
                        ];
                    }

                    if ($r['professor_id']) {
                        $courses[$cid]['professors'][] = [
                            'id' => (int)$r['professor_id'],
                            'name' => $r['full_name'],
                            'is_assistant' => (int)$r['is_assistant']
                        ];
                    }
                }
                ?>

                <table border="1" cellpadding="5">
                    <tr>

                        <th>Naziv</th>
                        <th>Šifra</th>
                        <th>Semestar</th>
                        <th>Obavezni</th>
                        <th>Predavanja</th>
                        <th>Vježbe</th>
                        <th>Lab</th>
                        <th>Uslovi</th>
                        <th>Profesori</th>
                        <th>Status</th>
                        <th>Akcije</th>
                    </tr>

                    <?php foreach ($courses as $c): ?>
                        <tr>

                            <td><?= htmlspecialchars($c['name']) ?></td>
                            <td><?= htmlspecialchars($c['code']) ?></td>
                            <td><?= $c['semester'] ?></td>
                            <td><?= $c['is_optional'] ? 'Ne' : 'Da' ?></td>
                            <td><?= $c['lectures_per_week'] ?></td>
                            <td><?= $c['exercises_per_week'] ?></td>
                            <td><?= $c['labs_per_week'] ?></td>

                            <td>
                                <?php
                                    $uslovi = [];
                                    if ($c['is_online']) $uslovi[] = 'onlajn';
                                    if ($c['requires_computer_lab']) $uslovi[] = 'rač. sala';
                                    if ($c['expected_students']) $uslovi[] = (int)$c['expected_students'] . ' stud.';
                                    if ($c['parallel_groups'] > 1) $uslovi[] = $c['parallel_groups'] . ' paral. grupe';
                                    echo $uslovi ? htmlspecialchars(implode(', ', $uslovi)) : '<em>—</em>';
                                ?>
                            </td>

                            <td>
                                <?= $c['professors']
                                    ? implode(', ', array_map(
                                        fn($p) => htmlspecialchars($p['name']) . ($p['is_assistant'] ? ' (A)' : ''),
                                        $c['professors']
                                    ))
                                    : '<em>Nema</em>' ?>
                            </td>

                            <td><?= $c['is_active'] ? 'Aktivan' : 'Neaktivan' ?></td>

                            <td>
                                <button class="action-button edit-button"
                                        data-entity="predmet"
                                        data-id="<?= $c['id'] ?>"
                                        data-name="<?= htmlspecialchars($c['name']) ?>"
                                        data-code="<?= htmlspecialchars($c['code']) ?>"
                                        data-semester="<?= $c['semester'] ?>"
                                        data-is_optional="<?= $c['is_optional'] ?>"
                                        data-lectures_per_week="<?= $c['lectures_per_week'] ?>"
                                        data-exercises_per_week="<?= $c['exercises_per_week'] ?>"
                                        data-labs_per_week="<?= $c['labs_per_week'] ?>"
                                        data-is_online="<?= $c['is_online'] ?>"
                                        data-requires_computer_lab="<?= $c['requires_computer_lab'] ?>"
                                        data-expected_students="<?= htmlspecialchars($c['expected_students'] ?? '') ?>"
                                        data-parallel_groups="<?= $c['parallel_groups'] ?>"
                                        data-professors="<?= htmlspecialchars(json_encode($c['professors'])) ?>">
                                    Uredi
                                </button>
                                <?php if ($c['is_active']): ?>
                                    <form method="post" style="display:inline">
                                        <input type="hidden" name="action" value="delete_predmet">
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <button class="action-button delete-button">Deaktiviraj</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" style="display:inline">
                                        <input type="hidden" name="action" value="activate_predmet">
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <button class="action-button activation-button">Aktiviraj</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" style="display:inline" onsubmit="return confirm('Trajno obrisati ovaj predmet? Ova akcija se ne može poništiti.');">
                                    <input type="hidden" name="action" value="hard_delete_predmet">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <button class="action-button delete-button">Obriši trajno</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <?php
                break;








            case 'sale':
            ?>

            <h2>Upravljanje salama</h2>
            <button class="action-button add-button" onclick="toggleForm('salaForm')">+ Dodaj Salu</button>

            <div id="salaForm" class="form-container" style='display: none'>
                <h3>Nova sala</h3>
                <form method="post">
                    <input type="hidden" name="action" value="add_sala">

                    <label for="code">Oznaka sale:</label>
                    <input type="text" id="code" name="code" required>

                    <label for="capacity">Kapacitet:</label>
                    <input type="number" id="capacity" name="capacity" min="1" required>

                    <label for="is_computer_lab">Računarska sala:</label>
                    <input type="checkbox" id="is_computer_lab" name="is_computer_lab">

                    <label for="faculty_code">Kome sala prioritetno pripada (npr. FIT), prazno = dijeljena/na zahtjev:</label>
                    <input type="text" id="faculty_code" name="faculty_code" maxlength="20" placeholder="npr. FIT">

                    <button type="submit">Sačuvaj</button>
                </form>
            </div>

            <form method="get" style="margin: 15px 0;">
                <input type="hidden" name="page" value="sale">
                <input type="text" name="q" placeholder="Pretraga po oznaci sale..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" style="padding:6px; min-width:250px;">
                <button type="submit" class="action-button">Pretraži</button>
                <?php if (!empty($_GET['q'])): ?>
                    <a href="?page=sale" class="action-button" style="text-decoration:none; display:inline-block;">Poništi</a>
                <?php endif; ?>
            </form>

            <table border="1" cellpadding="5">
                <tr>
                    <th>Oznaka</th>
                    <th>Kapacitet</th>
                    <th>Tip</th>
                    <th>Pripada</th>
                    <th>Status</th>
                    <th>Akcije</th>
                </tr>
                <?php

                try {
                    $salaSearch = trim($_GET['q'] ?? '');
                    if ($salaSearch !== '') {
                        $stmt = $pdo->prepare("SELECT * FROM room WHERE code ILIKE ? ORDER BY code");
                        $stmt->execute(['%' . $salaSearch . '%']);
                    } else {
                        $stmt = $pdo->query("SELECT * FROM room ORDER BY code");
                    }
                    while ($row = $stmt->fetch()) {
                        echo "<tr>";

                        echo "<td>" . htmlspecialchars($row['code']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['capacity']) . "</td>";
                        echo "<td>" . ($row['is_computer_lab'] ? 'Računarska' : 'Standardna') . "</td>";
                        echo "<td>" . ($row['faculty_code'] ? htmlspecialchars($row['faculty_code']) : '<em>Dijeljena / na zahtjev</em>') . "</td>";
                        echo "<td>" . ($row['is_active'] ? 'Aktivna' : 'Neaktivna') . "</td>";
                        echo "<td>";
                        echo "<button class='action-button edit-button' data-entity='sala' data-id='" . $row['id'] . "' data-code='" . htmlspecialchars($row['code'], ENT_QUOTES) . "' data-capacity='" . htmlspecialchars($row['capacity'], ENT_QUOTES) . "' data-is_computer_lab='" . ($row['is_computer_lab'] ? '1' : '0') . "' data-faculty_code='" . htmlspecialchars($row['faculty_code'] ?? '', ENT_QUOTES) . "'>Uredi</button>";

                        // Ako je sala neaktivna ne moze imati deaktiviraj dugme
                        if ($row['is_active']) {
                            echo "<form id='delete-sala-{$row['id']}' style='display:inline' method='post' action='{$_SERVER['PHP_SELF']}'>
                            <input type='hidden' name='action' value='delete_sala'>
                            <input type='hidden' name='id' value='{$row['id']}'>
                            <button type='button' class='action-button delete-button' onclick=\"submitDeleteForm({$row['id']}, 'delete_sala', 'salu')\">Deaktiviraj</button>
                        </form>";
                        } else {
                            echo "<form id='activate-sala-{$row['id']}' style='display:inline' method='post' action='{$_SERVER['PHP_SELF']}'>
                            <input type='hidden' name='action' value='activate_sala'>
                            <input type='hidden' name='id' value='{$row['id']}'>
                            <button type='button' class='action-button activation-button' onclick=\"submitDeleteForm({$row['id']}, 'activate_sala', 'salu')\">Aktiviraj</button>
                        </form>";
                        }

                        echo "<form id='harddelete-sala-{$row['id']}' style='display:inline' method='post' action='{$_SERVER['PHP_SELF']}'>
                            <input type='hidden' name='action' value='hard_delete_sala'>
                            <input type='hidden' name='id' value='{$row['id']}'>
                            <button type='button' class='action-button delete-button' onclick=\"if(confirm('Trajno obrisati ovu salu? Ova akcija se ne može poništiti.')) submitDeleteForm({$row['id']}, 'hard_delete_sala', 'salu')\">Obriši trajno</button>
                        </form>";

                        echo "</td>";
                        echo "</tr>";
                    }
                } catch (PDOException $e) {
                    echo "<tr><td colspan='6'>Greška pri dohvaćanju sala: " . $e->getMessage() . "</td></tr>";
                }
                echo "</table>";
                break;
                case 'account':
                    ?>

                    <h2>Upravljanje nalozima profesora</h2>
                    <p style="max-width:900px; color:#aaa; line-height:1.5;">
                        <strong>Profesor</strong> (stranica "Profesori") je osoba koja drži nastavu - ime i email se
                        koriste u rasporedu i pri dodjeli predmeta. <strong>Nalog</strong> (ova stranica) su podaci za
                        prijavu u sistem (korisničko ime, lozinka, uloga ADMIN/PROFESSOR).
                        <strong>Povezan profesor</strong> određuje čiji raspored, predmete i raspoloživost nalog vidi
                        nakon prijave; nalog bez povezanog profesora ima smisla samo za administratora. Kad je
                        profesor povezan i ima email, inicijalna lozinka mu se šalje na taj email.
                    </p>
                    <button class="action-button add-button" onclick="toggleForm('accountForm')">+ Dodaj Nalog</button>

                    <div id="accountForm" class="form-container" style="display: none">
                        <h3>Novi nalog</h3>
                        <form method="post">
                            <input type="hidden" name="action" value="add_account">

                            <label for="username">Korisničko ime:</label>
                            <input type="text" id="username" name="username" required>

                            <label for="password">Lozinka:</label>
                            <input type="password" id="password" name="password" required>

                            <label for="role">Uloga:</label>
                            <select id="role" name="role">
                                <option value="ADMIN">ADMIN</option>
                                <option value="PROFESSOR">PROFESSOR</option>
                            </select>

                            <label for="professor_id">Povezan profesor (opcionalno):</label>
                            <select id="professor_id" name="professor_id">
                                <option value="">-- Nema --</option>
                                <?php
                                try {
                                    $stmt = $pdo->query("SELECT id, full_name, email FROM professor WHERE is_active = TRUE ORDER BY full_name ASC");
                                    while ($p = $stmt->fetch()) {
                                        echo "<option value='" . $p['id'] . "'>" . htmlspecialchars($p['full_name']) . " (" . htmlspecialchars($p['email']) . ")</option>";
                                    }
                                } catch (PDOException $e) {
                                    echo "<option value=''>Greška pri dohvaćanju profesora</option>";
                                }
                                ?>
                            </select>

                            <button type="submit">Sačuvaj</button>
                        </form>
                    </div>

                    <form method="get" style="margin: 15px 0;">
                        <input type="hidden" name="page" value="account">
                        <input type="text" name="q" placeholder="Pretraga po imenu ili korisničkom imenu..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" style="padding:6px; min-width:250px;">
                        <button type="submit" class="action-button">Pretraži</button>
                        <?php if (!empty($_GET['q'])): ?>
                            <a href="?page=account" class="action-button" style="text-decoration:none; display:inline-block;">Poništi</a>
                        <?php endif; ?>
                    </form>

                    <table border="1" cellpadding="5">
                        <tr>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Profesor</th>
                            <th>Status</th>
                            <th>Akcije</th>
                        </tr>
                        <?php
                        try {
                            // Sada takođe biramo email povezane profesorke
                            // Sortirano po imenu (profesor.full_name, uz username kao fallback za admin naloge bez profesora)
                            $accSearch = trim($_GET['q'] ?? '');
                            if ($accSearch !== '') {
                                $stmt = $pdo->prepare("SELECT ua.*, p.full_name as professor_name, p.email as professor_email FROM user_account ua LEFT JOIN professor p ON ua.professor_id = p.id WHERE ua.username ILIKE ? OR p.full_name ILIKE ? ORDER BY COALESCE(p.full_name, ua.username)");
                                $like = '%' . $accSearch . '%';
                                $stmt->execute([$like, $like]);
                            } else {
                                $stmt = $pdo->query("SELECT ua.*, p.full_name as professor_name, p.email as professor_email FROM user_account ua LEFT JOIN professor p ON ua.professor_id = p.id ORDER BY COALESCE(p.full_name, ua.username)");
                            }

                            
                            while ($row = $stmt->fetch()) {
                                echo "<tr>";
                                echo "<td>" . htmlspecialchars($row['username']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['role_enum']) . "</td>";
                                // Pokazujemo ime profesora i, ako postoji, njegov email
                                $profDisplay = htmlspecialchars($row['professor_name'] ?? '');
                                if (!empty($row['professor_email'])) {
                                    $profDisplay .= ' (' . htmlspecialchars($row['professor_email']) . ')';
                                }
                                echo "<td>" . $profDisplay . "</td>";
                                echo "<td>" . ($row['is_active'] ? 'Aktivan' : 'Neaktivan') . "</td>";
                                echo "<td>";
                                // Edit button: rely on admin.js generic edit handler
                                $dataAttr = htmlspecialchars(json_encode(['id' => (int)$row['id'], 'username' => $row['username'], 'role' => $row['role_enum'], 'professor_id' => $row['professor_id'], 'is_active' => $row['is_active']]), ENT_QUOTES);
                                echo "<button class='action-button edit-button' data-entity='account' data-payload='" . $dataAttr . "'>Uredi</button> ";

                                if ($row['is_active']) {
                                    echo "<form method='post' action='{$_SERVER['PHP_SELF']}' style='display:inline-block; margin-left:2px;'>";
                                    echo "<input type='hidden' name='action' value='delete_account'>";
                                    echo "<input type='hidden' name='id' value='" . $row['id'] . "'>";
                                    echo "<button type='button' class='action-button delete-button' onclick=\"submitDeleteForm({$row['id']}, 'delete_account', 'nalog')\">Deaktiviraj</button>";
                                    echo "</form>";
                                } else {
                                    // account is inactive -> show activate button
                                    echo "<form method='post' style='display:inline-block; margin-left:2px;' action='{$_SERVER['PHP_SELF']}'>";
                                    echo "<input type='hidden' name='action' value='activate_account'>";
                                    echo "<input type='hidden' name='id' value='" . $row['id'] . "'>";
                                    echo " <button type='button' class='action-button activation-button' onclick=\"submitDeleteForm({$row['id']}, 'activate_account', 'nalog')\">Aktiviraj</button>";
                                    echo "</form>";
                                }

                                echo "<form method='post' style='display:inline-block; margin-left:2px;' action='{$_SERVER['PHP_SELF']}'>";
                                echo "<input type='hidden' name='action' value='hard_delete_account'>";
                                echo "<input type='hidden' name='id' value='" . $row['id'] . "'>";
                                echo "<button type='button' class='action-button delete-button' onclick=\"if(confirm('Trajno obrisati ovaj nalog? Ova akcija se ne može poništiti.')) submitDeleteForm({$row['id']}, 'hard_delete_account', 'nalog')\">Obriši trajno</button>";
                                echo "</form>";

                                echo "</td>";
                                echo "</tr>";
                            }
                        } catch (PDOException $e) {
                            echo "<tr><td colspan='6'>Greška pri dohvaćanju naloga: " . $e->getMessage() . "</td></tr>";
                        }
                        ?>

                    </table>

                    <?php
                    break;

                case 'dogadjaji':
                    ?>
                    <h2>Informacije i događaji</h2>

                    <!-- Sekcija za Akademsku godinu -->
                    <div style="margin-bottom: 40px; border-bottom: 1px solid #ccc; padding-bottom: 20px;">
                        <h3>Akademske Godine</h3>
                        <p>Definišite početke zimskog i ljetnjeg semestra za svaku akademsku godinu.</p>

                        <button class="action-button add-button" onclick="toggleForm('academicYearForm')">+ Nova
                            akademska godina
                        </button>

                        <div id="academicYearForm" class="form-container" style="display: none; margin-top: 15px;">
                            <form method="post" style="max-width: 500px;">
                                <input type="hidden" name="action" value="add_academic_year">

                                <label for="year_label" style="display:block; margin-bottom:5px;">Naziv godine (npr.
                                    2025/2026):</label>
                                <input type="text" id="year_label" name="year_label" required placeholder="YYYY/YYYY"
                                       style="width:100%; padding:8px; margin-bottom:10px;">

                                <label for="winter_semester_start" style="display:block; margin-bottom:5px;">Početak
                                    zimskog semestra:</label>
                                <input type="date" id="winter_semester_start" name="winter_semester_start" required
                                       style="width:100%; padding:8px; margin-bottom:10px;">

                                <label for="summer_semester_start" style="display:block; margin-bottom:5px;">Početak
                                    ljetnjeg semestra:</label>
                                <input type="date" id="summer_semester_start" name="summer_semester_start" required
                                       style="width:100%; padding:8px; margin-bottom:10px;">

                                <button type="submit" class="btn btn-primary"
                                        style="background: var(--accent); color: white; border: none; padding: 10px 20px; cursor: pointer;">
                                    Sačuvaj
                                </button>
                            </form>
                        </div>

                        <table border="1" cellpadding="5"
                               style="margin-top: 20px; width: 100%; border-collapse: collapse;">
                            <tr style="background: #f4f4f4; color: #333;">

                                <th>Naziv</th>
                                <th>Zimski semestar (Start)</th>
                                <th>Ljetnji semestar (Start)</th>
                                <th>Aktivan</th>
                                <th>Akcije</th>
                            </tr>
                            <?php
                            try {
                                $stmt = $pdo->query("SELECT * FROM academic_year ORDER BY id DESC");
                                while ($row = $stmt->fetch()) {
                                    echo "<tr>";
                                    echo "<td>" . htmlspecialchars($row['year_label']) . "</td>";
                                    echo "<td>" . htmlspecialchars($row['winter_semester_start']) . "</td>";
                                    echo "<td>" . htmlspecialchars($row['summer_semester_start']) . "</td>";
                                    echo "<td>" . ($row['is_active'] ? 'DA' : 'NE') . "</td>";
                                    echo "<td>";
                                    echo "<button class='action-button edit-button' data-entity='academic_year' data-id='" . $row['id'] . "' data-year_label='" . htmlspecialchars($row['year_label'], ENT_QUOTES) . "' data-winter_semester_start='" . htmlspecialchars($row['winter_semester_start'], ENT_QUOTES) . "' data-summer_semester_start='" . htmlspecialchars($row['summer_semester_start'], ENT_QUOTES) . "' data-is_active='" . ($row['is_active'] ? 'true' : 'false') . "'>Uredi</button> ";

                                    echo "<form method='post' action='{$_SERVER['PHP_SELF']}' style='display:inline-block; margin-left:2px;'>";
                                    echo "<input type='hidden' name='action' value='delete_academic_year'>";
                                    echo "<input type='hidden' name='id' value='" . $row['id'] . "'>";
                                    echo "<button type='button' class='action-button delete-button' onclick=\"submitDeleteForm({$row['id']}, 'delete_academic_year', 'akademsku godinu')\">Obriši</button>";
                                    echo "</form>";

                                    echo "</td>";
                                    echo "</tr>";
                                }
                            } catch (PDOException $e) {
                                echo "<tr><td colspan='6'>Greška: " . $e->getMessage() . "</td></tr>";
                            }
                            ?>
                        </table>
                    </div>

                    <!-- Sekcija: Rezervisani termini profesora -->
                    <div style="margin-bottom: 40px;">
                        <h3>Rezervisani termini profesora</h3>
                        <p>Termini koje su profesori unijeli preko svog panela.</p>

                        <?php
                        try {
                            $stmt = $pdo->query("SELECT pa.professor_id, pa.weekday, pa.start_time, pa.end_time,
                                                    pa.requires_computer_lab, pa.preferred_room_id, pa.course_id,
                                                    p.full_name, r.code AS preferred_room_code, c.name AS course_name
                                                FROM professor_availability pa
                                                JOIN professor p ON p.id = pa.professor_id
                                                LEFT JOIN room r ON r.id = pa.preferred_room_id
                                                LEFT JOIN course c ON c.id = pa.course_id
                                                ORDER BY p.full_name, pa.weekday, pa.start_time");
                            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                            $dayMap = [
                                1 => 'Ponedjeljak',
                                2 => 'Utorak',
                                3 => 'Srijeda',
                                4 => 'Četvrtak',
                                5 => 'Petak'
                            ];

                            if (empty($rows)) {
                                echo "<p>Nema rezervisanih termina.</p>";
                            } else {
                                $grouped = [];
                                foreach ($rows as $row) {
                                    $pid = (int)$row['professor_id'];
                                    if (!isset($grouped[$pid])) {
                                        $grouped[$pid] = [
                                            'name' => $row['full_name'],
                                            'items' => []
                                        ];
                                    }
                                    $grouped[$pid]['items'][] = $row;
                                }

                                foreach ($grouped as $prof) {
                                    echo "<details style='margin-top: 12px; border: 1px solid #3a3f45; border-radius: 6px; padding: 8px 10px; background: #1f232a;'>";
                                    echo "<summary style='cursor: pointer; font-weight: 600;'>" . htmlspecialchars($prof['name']) . "</summary>";
                                    echo "<table border='1' cellpadding='5' style='margin-top: 10px; width: 100%; border-collapse: collapse; background: #242a31;'>";
                                    echo "<tr style='background: #f4f4f4; color: #333;'>";
                                    echo "<th>Dan</th><th>Od</th><th>Do</th><th>Dodatni zahtjevi</th>";
                                    echo "</tr>";

                                    foreach ($prof['items'] as $row) {
                                        $dayLabel = $dayMap[(int)$row['weekday']] ?? (string)$row['weekday'];
                                        $startTime = $row['start_time'] ? substr($row['start_time'], 0, 5) : '';
                                        $endTime = $row['end_time'] ? substr($row['end_time'], 0, 5) : '';

                                        $requests = [];
                                        if ($row['requires_computer_lab']) $requests[] = 'neophodna rač. sala';
                                        if ($row['preferred_room_code']) $requests[] = 'sala: ' . $row['preferred_room_code'];
                                        if ($row['course_name']) $requests[] = 'predmet: ' . $row['course_name'];
                                        $requestsLabel = $requests ? implode(', ', $requests) : '—';

                                        echo '<tr>';
                                        echo '<td>' . htmlspecialchars($dayLabel) . '</td>';
                                        echo '<td>' . htmlspecialchars($startTime) . '</td>';
                                        echo '<td>' . htmlspecialchars($endTime) . '</td>';
                                        echo '<td>' . htmlspecialchars($requestsLabel) . '</td>';
                                        echo '</tr>';
                                    }

                                    echo "</table>";
                                    echo "</details>";
                                }
                            }
                        } catch (PDOException $e) {
                            echo "<p>Greška: " . $e->getMessage() . "</p>";
                        }
                        ?>
                    </div>

                    <!-- Sekcija: Događaji koje su profesori unijeli u svoj kalendar (tab "Događaji") -->
                    <div style="margin-bottom: 40px;">
                        <h3>Događaji profesora</h3>
                        <p>Događaji/bilješke koje su profesori unijeli u svoj kalendar (od prije 30 dana nadalje).</p>
                        <?php
                        try {
                            $notesStmt = $pdo->query("
                                SELECT n.note_date, n.content, p.full_name
                                FROM notes n
                                JOIN professor p ON p.id = n.professor_id
                                WHERE n.note_date >= CURRENT_DATE - INTERVAL '30 days'
                                ORDER BY n.note_date, p.full_name
                            ");
                            $profNotes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);
                            if (!$profNotes) {
                                echo "<p style='color:#888'>Nema unesenih događaja.</p>";
                            } else {
                                echo "<table border='1' cellpadding='5'><tr><th>Datum</th><th>Profesor</th><th>Događaj</th></tr>";
                                foreach ($profNotes as $n) {
                                    echo "<tr><td>" . htmlspecialchars(date('d.m.Y.', strtotime($n['note_date']))) . "</td>"
                                        . "<td>" . htmlspecialchars($n['full_name']) . "</td>"
                                        . "<td>" . nl2br(htmlspecialchars($n['content'] ?? '')) . "</td></tr>";
                                }
                                echo "</table>";
                            }
                        } catch (PDOException $e) {
                            echo "<p>Greška: " . htmlspecialchars($e->getMessage()) . "</p>";
                        }
                        ?>
                    </div>

                    <!-- Sekcija: Predmeti i sedmice kolokvijuma -->
                    <div style="margin-bottom: 40px;">
                        <h3>Predmeti i sedmice kolokvijuma</h3>
                        <p>Pregled svih predmeta sa povezanim profesorima/asistentima i postavljenim sedmicama
                            kolokvijuma.</p>

                        <table border="1" cellpadding="5"
                               style="margin-top: 20px; width: 100%; border-collapse: collapse;">
                            <tr style="background: #f4f4f4; color: #333;">
                                <th>Predmet</th>
                                <th>Profesori</th>
                                <th>Asistenti</th>
                                <th>Kolokvijum 1</th>
                                <th>Kolokvijum 2</th>
                                <th>Završni ispit</th>
                            </tr>
                            <?php
                            try {
                                $stmtYear = $pdo->query("SELECT winter_semester_start, summer_semester_start FROM academic_year WHERE is_active = TRUE ORDER BY id DESC LIMIT 1");
                                $academicYear = $stmtYear->fetch(PDO::FETCH_ASSOC);

                                $winterStart = $academicYear && $academicYear['winter_semester_start'] ? strtotime($academicYear['winter_semester_start']) : null;
                                $summerStart = $academicYear && $academicYear['summer_semester_start'] ? strtotime($academicYear['summer_semester_start']) : null;

                                if (!function_exists('formatColloquiumWeekLabel')) {
                                    function formatColloquiumWeekLabel($week, $semester, $winterStart, $summerStart)
                                    {
                                        if ($week === null) return 'Nije uneseno';
                                        $week = (int)$week;
                                        if ($week <= 0) return 'Nije uneseno';
                                        if ($week === 1) return 'Ne održava se';

                                        $semStart = ($semester % 2 !== 0) ? $winterStart : $summerStart;
                                        $label = $week . '. sedmica';
                                        if ($semStart) {
                                            $wStart = strtotime('+' . ($week - 1) . ' weeks', $semStart);
                                            $wEnd = strtotime('+6 days', $wStart);
                                            $label .= ' (' . date('d.m.Y', $wStart) . '-' . date('d.m.Y', $wEnd) . ')';
                                        }
                                        return $label;
                                    }
                                }

                                $stmt = $pdo->query("SELECT c.id, c.name, c.semester, c.colloquium_1_week, c.colloquium_2_week, c.final_exam_week, p.full_name, cp.is_assistant
                                                    FROM course c
                                                    LEFT JOIN course_professor cp ON cp.course_id = c.id
                                                    LEFT JOIN professor p ON p.id = cp.professor_id
                                                    ORDER BY c.name, cp.is_assistant, p.full_name");
                                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                                $courses = [];
                                foreach ($rows as $row) {
                                    $cid = (int)$row['id'];
                                    if (!isset($courses[$cid])) {
                                        $courses[$cid] = [
                                            'name' => $row['name'],
                                            'semester' => (int)$row['semester'],
                                            'col1' => $row['colloquium_1_week'],
                                            'col2' => $row['colloquium_2_week'],
                                            'examWeek' => $row['final_exam_week'],
                                            'professors' => [],
                                            'assistants' => []
                                        ];
                                    }

                                    if (!empty($row['full_name'])) {
                                        if ((int)$row['is_assistant'] === 1) {
                                            $courses[$cid]['assistants'][] = $row['full_name'];
                                        } else {
                                            $courses[$cid]['professors'][] = $row['full_name'];
                                        }
                                    }
                                }

                                if (empty($courses)) {
                                    echo "<tr><td colspan='6'>Nema predmeta za prikaz.</td></tr>";
                                } else {
                                    foreach ($courses as $course) {
                                        $profList = !empty($course['professors']) ? implode(', ', array_unique($course['professors'])) : '—';
                                        $asstList = !empty($course['assistants']) ? implode(', ', array_unique($course['assistants'])) : '—';

                                        $col1Label = formatColloquiumWeekLabel($course['col1'], $course['semester'], $winterStart, $summerStart);
                                        $col2Label = formatColloquiumWeekLabel($course['col2'], $course['semester'], $winterStart, $summerStart);
                                        $examLabel = formatColloquiumWeekLabel($course['examWeek'], $course['semester'], $winterStart, $summerStart);

                                        echo '<tr>';
                                        echo '<td>' . htmlspecialchars($course['name']) . '</td>';
                                        echo '<td>' . htmlspecialchars($profList) . '</td>';
                                        echo '<td>' . htmlspecialchars($asstList) . '</td>';
                                        echo '<td>' . htmlspecialchars($col1Label) . '</td>';
                                        echo '<td>' . htmlspecialchars($col2Label) . '</td>';
                                        echo '<td>' . htmlspecialchars($examLabel) . '</td>';
                                        echo '</tr>';
                                    }
                                }
                            } catch (PDOException $e) {
                                echo "<tr><td colspan='6'>Greška: " . htmlspecialchars($e->getMessage()) . "</td></tr>";
                            }
                            ?>
                        </table>
                    </div>
                    <?php
                    break;

                case 'pocetna':
                    echo "<h2>Rasporedi</h2>";
                    echo "<p>Odaberite opciju ispod da generišete raspored časova:</p>";

                    echo "<button id='generate-schedule' class='option-button'>Generiši raspored časova</button>";
                    echo "<button id='generate-colloquiums' class='option-button' style='margin-left: 10px; background-color: #9333ea;'>Generiši kolokvijume</button>";
                    echo "<div id='schedule-status' style='margin-top:20px; display:none'></div>";

                    // Colloquium Section (prikazuje se automatski kad učitani raspored ima kolokvijume - vidi renderScheduleData)
                    echo "
                    <div id='colloquium-section' style='display:none; margin-top:30px; border-top: 1px solid #444; padding-top: 20px;'>
                        <h3 style='color: #ecc94b; margin-bottom: 15px;'>Raspored Kolokvijuma</h3>
                        
                        <div style='display:flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap;'>
                            <div>
                                <label style='display:block; margin-bottom:5px; color:#aaa;'>Tip Kolokvijuma:</label>
                                <select id='coll-type-select' class='form-control' style='width: 200px; background: #333; color: white; border: 1px solid #555; padding: 8px; border-radius: 4px;'>
                                    <option value='COLLOQUIUM_1'>Kolokvijum 1</option>
                                    <option value='COLLOQUIUM_2'>Kolokvijum 2</option>
                                    <option value='EXAM'>Završni ispit</option>
                                </select>
                            </div>
                            <div>
                                <label style='display:block; margin-bottom:5px; color:#aaa;'>Semestar:</label>
                                <select id='coll-sem-select' class='form-control' style='width: 200px; background: #333; color: white; border: 1px solid #555; padding: 8px; border-radius: 4px;'>
                                    <option value='1'>Semestar 1</option>
                                    <option value='2'>Semestar 2</option>
                                    <option value='3'>Semestar 3</option>
                                    <option value='4'>Semestar 4</option>
                                    <option value='5'>Semestar 5</option>
                                    <option value='6'>Semestar 6</option>
                                </select>
                            </div>
                        </div>

                        <button id='colloquium-pdf-btn' class='action-button add-button' style='margin-bottom: 20px;'>Sačuvaj sve kolokvijume i završni ispit kao PDF (po semestru)</button>

                        <div id='colloquium-container'></div>
                    </div>
                    ";

                    echo "<div id='schedule-container' style='margin-top:20px; display:none'></div>";


                    ?>

                    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>


                    <script>
                        const days = ['Ponedjeljak', 'Utorak', 'Srijeda', 'Četvrtak', 'Petak'];

                        function formatEventLabel(ev) {
                            const typeMap = {
                                LECTURE: 'Predavanje',
                                EXERCISE: 'Vježbe',
                                LAB: 'Lab'
                            };
                            const typeLabel = typeMap[ev.type] || ev.type || '';
                            const useAssistants = ev.type === 'EXERCISE' || ev.type === 'LAB';
                            const nameList = useAssistants ? (ev.assistants || '') : (ev.professors || '');
                            const fallbackNameList = (!nameList && ev.professors) ? ev.professors : nameList;
                            const locationLabel = ev.is_online ? 'ONLINE' : (ev.room || '');
                            const typeLine = typeLabel ? (typeLabel + (locationLabel ? (' (' + locationLabel + ')') : '')) : '';
                            // Rezultat ide u innerHTML - imena predmeta/profesora dolaze iz baze
                            // (profesor sam mijenja svoje ime), pa se escapuju.
                            const label = [ev.course, fallbackNameList, typeLine].filter(Boolean).map(escapeHtml).join('<br>');
                            return ev.event_id
                                ? '<div class="sched-ev" data-event-id="' + Number(ev.event_id) + '" title="Klik za izmjenu termina" style="cursor:pointer">' + label + '</div>'
                                : label;
                        }

                        function escapeHtml(value) {
                            return String(value).replace(/[&<>"']/g, ch => ({
                                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
                            }[ch]));
                        }

                        // ---- Ručna izmjena generisanog rasporeda (klik na čas u tabeli) ----
                        // Pomjera se cijeli blok (npr. predavanja 10-13h) - server provjerava
                        // sukobe sale/profesora/semestra, dnevni fond, 8-17h i zauzetost sala.
                        async function openBlockEditor(eventId) {
                            let info;
                            try {
                                info = await (await fetch('admin_panel.php?action=block_info&event_id=' + encodeURIComponent(eventId))).json();
                            } catch (e) {
                                alert('Greška pri učitavanju termina.');
                                return;
                            }
                            if (info.status !== 'ok') {
                                alert(info.message || 'Greška');
                                return;
                            }
                            const b = info.block;
                            const dayNames = ['', 'Ponedjeljak', 'Utorak', 'Srijeda', 'Četvrtak', 'Petak'];
                            const typeNames = {LECTURE: 'Predavanje', EXERCISE: 'Vježbe', LAB: 'Lab'};

                            const overlay = document.createElement('div');
                            overlay.id = 'block-editor';
                            overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.55);display:flex;align-items:center;justify-content:center;z-index:10000;';
                            const box = document.createElement('div');
                            box.style.cssText = 'background:#0b1220;color:#fff;padding:20px;border-radius:8px;width:min(480px,92vw);';
                            overlay.appendChild(box);

                            const title = document.createElement('h3');
                            title.textContent = b.course + ' (' + b.types.map(t => typeNames[t] || t).join(' + ') + ')';
                            box.appendChild(title);
                            const cur = document.createElement('p');
                            cur.style.color = '#aaa';
                            const curRoom = b.is_online ? 'ONLINE' : ((info.rooms.find(r => Number(r.id) === b.room_id) || {}).code || '-');
                            cur.textContent = 'Trenutno: ' + dayNames[b.weekday] + ' ' + b.start + '-' + b.end + ', ' + curRoom
                                + ' (' + b.hours + 'h, raspored ID ' + b.schedule_id + ')';
                            box.appendChild(cur);

                            const mkSelect = (labelText, name, options, value) => {
                                const wrap = document.createElement('label');
                                wrap.style.cssText = 'display:block;margin:8px 0;';
                                wrap.textContent = labelText;
                                const sel = document.createElement('select');
                                sel.name = name;
                                sel.style.cssText = 'display:block;width:100%;margin-top:4px;padding:6px;';
                                options.forEach(([v, t]) => sel.add(new Option(t, v)));
                                sel.value = String(value);
                                wrap.appendChild(sel);
                                box.appendChild(wrap);
                                return sel;
                            };
                            const daySel = mkSelect('Dan', 'day', [1, 2, 3, 4, 5].map(d => [d, dayNames[d]]), b.weekday);
                            const starts = [];
                            for (let h = 8; h + b.hours <= 17; h++) {
                                const hh = String(h).padStart(2, '0') + ':00';
                                starts.push([hh, hh + ' - ' + String(h + b.hours).padStart(2, '0') + ':00']);
                            }
                            const startSel = mkSelect('Vrijeme', 'start', starts, b.start);
                            let roomSel = null;
                            if (!b.is_online) {
                                roomSel = mkSelect('Sala', 'room_id', info.rooms.map(r => [r.id,
                                    r.code + ' (' + r.capacity + ' mjesta' + (r.is_computer_lab ? ', rač.' : '') + ')']), b.room_id);
                            }

                            const msg = document.createElement('p');
                            msg.id = 'block-editor-msg';
                            msg.style.cssText = 'color:#f87171;min-height:1em;';
                            box.appendChild(msg);
                            if (b.locked) {
                                msg.textContent = 'Raspored je zaključan - otključajte ga prije izmjene.';
                            }

                            const btns = document.createElement('div');
                            btns.style.cssText = 'display:flex;gap:10px;justify-content:flex-end;';
                            const saveBtn = document.createElement('button');
                            saveBtn.textContent = 'Sačuvaj';
                            saveBtn.className = 'action-button add-button';
                            saveBtn.disabled = b.locked;
                            const cancelBtn = document.createElement('button');
                            cancelBtn.textContent = 'Odustani';
                            cancelBtn.className = 'action-button';
                            btns.appendChild(cancelBtn);
                            btns.appendChild(saveBtn);
                            box.appendChild(btns);
                            cancelBtn.addEventListener('click', () => overlay.remove());
                            overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

                            const submit = async (force) => {
                                saveBtn.disabled = true;
                                msg.style.color = '#f87171';
                                msg.textContent = '';
                                let res;
                                try {
                                    res = await (await fetch('admin_panel.php?action=move_block', {
                                        method: 'POST',
                                        headers: {'Content-Type': 'application/json'},
                                        body: JSON.stringify({
                                            event_id: Number(eventId), day: Number(daySel.value), start: startSel.value,
                                            room_id: roomSel ? Number(roomSel.value) : null, force: force
                                        })
                                    })).json();
                                } catch (e) {
                                    res = {status: 'error', message: 'Greška u komunikaciji sa serverom.'};
                                }
                                saveBtn.disabled = false;
                                if (res.status === 'confirm') {
                                    if (confirm('Upozorenje:\n' + res.warnings.join('\n') + '\n\nSačuvati izmjenu uprkos tome?')) {
                                        return submit(true);
                                    }
                                    return;
                                }
                                if (res.status !== 'ok') {
                                    msg.textContent = res.message || 'Greška';
                                    return;
                                }
                                overlay.remove();
                                await reloadScheduleView();
                                const statusDiv = document.getElementById('schedule-status');
                                if (statusDiv) {
                                    const p = document.createElement('p');
                                    p.style.cssText = 'color:#22c55e;';
                                    p.textContent = '✓ ' + b.course + ': termin je pomjeren.';
                                    statusDiv.appendChild(p);
                                }
                            };
                            saveBtn.addEventListener('click', () => submit(false));
                            document.body.appendChild(overlay);
                        }

                        // Ponovo učitaj raspored zadržavajući prikazane verzije (zimski/ljetnji)
                        async function reloadScheduleView() {
                            const labels = Array.from(document.querySelectorAll('#schedule-container span'))
                                .map(s => s.textContent.match(/^Verzija (\d+)$/)).filter(Boolean);
                            window.scheduleViewState = {
                                winter: labels[0] ? Number(labels[0][1]) - 1 : 0,
                                summer: labels[1] ? Number(labels[1][1]) - 1 : 0
                            };
                            const data = await (await fetch('admin_panel.php?action=getschedule')).json();
                            renderScheduleData(data);
                        }

                        document.addEventListener('click', (e) => {
                            const el = e.target.closest && e.target.closest('#schedule-container .sched-ev');
                            if (el) openBlockEditor(el.dataset.eventId);
                        });

                        // Shared renderer: builds the full interactive schedule UI from `getschedule` response
                        function renderScheduleData(data) {
                            const statusDiv = document.getElementById('schedule-status');
                            const container = document.getElementById('schedule-container');
                            const colSection = document.getElementById('colloquium-section');

                            // Store exams for filtering
                            window.allExams = data.exams || [];

                            // Check for Colloquiums/Exams
                            const hasColloquiums = window.allExams.some(e =>
                                e.type === 'COLLOQUIUM_1' || e.type === 'COLLOQUIUM_2' || e.type === 'EXAM'
                            );

                            if (colSection) {
                                if (hasColloquiums) {
                                    colSection.style.display = 'block';
                                    if (typeof renderColloquiums === 'function') renderColloquiums();
                                } else {
                                    colSection.style.display = 'none';
                                }
                            }

                            // clear previous
                            container.innerHTML = '';

                            const schedules = data.schedules || {};
                            const scheduleIds = data.schedule_ids || [];

                            if (scheduleIds.length === 0) {
                                statusDiv.innerHTML = '<p>Nema rasporeda u bazi.</p>';
                                return;
                            }

                            // Collect all events
                            const allEvents = [];
                            scheduleIds.forEach(sid => {
                                Object.keys(schedules[sid] || {}).forEach(sem => {
                                    (schedules[sid][sem] || []).forEach(ev => allEvents.push(ev));
                                });
                            });

                            const timeSlots = Array.from(new Set(allEvents.map(e => e.start + '-' + e.end))).sort();

                            // State (poslije ručne izmjene termina zadrži prikazane verzije)
                            const viewState = window.scheduleViewState || {};
                            window.scheduleViewState = null;
                            let currentWinterIndex = Math.min(viewState.winter || 0, Math.max(scheduleIds.length - 1, 0));
                            let currentSummerIndex = Math.min(viewState.summer || 0, Math.max(scheduleIds.length - 1, 0));
                            // initialise lock state from server-exposed global if available
                            let isScheduleLocked = (window.adminData && window.adminData.schedule_locked) ? true : false;
                            const allArrowButtons = [];

                            // createControlGroup copied from previous inline code (keeps same behaviour)
                            function createControlGroup(title, isWinter) {
                                const group = document.createElement('div');
                                group.style.textAlign = 'center';
                                group.style.margin = '10px';

                                const label = document.createElement('h3');
                                label.textContent = title;
                                label.style.marginBottom = '10px';
                                label.style.color = '#e5e7eb';

                                const controls = document.createElement('div');
                                controls.style.display = 'flex';
                                controls.style.alignItems = 'center';
                                controls.style.gap = '15px';
                                controls.style.justifyContent = 'center';

                                const leftBtn = document.createElement('button');
                                leftBtn.innerHTML = '◀';
                                leftBtn.className = 'nav-arrow';
                                leftBtn.style.cssText = 'font-size: 20px; padding: 5px 12px; cursor: pointer; border: none; background: #3b82f6; color: white; border-radius: 6px;';
                                allArrowButtons.push(leftBtn);

                                const info = document.createElement('span');
                                info.innerHTML = 'Verzija 1';
                                info.style.fontWeight = 'bold';

                                const rightBtn = document.createElement('button');
                                rightBtn.innerHTML = '▶';
                                rightBtn.className = 'nav-arrow';
                                rightBtn.style.cssText = 'font-size: 20px; padding: 5px 12px; cursor: pointer; border: none; background: #3b82f6; color: white; border-radius: 6px;';
                                allArrowButtons.push(rightBtn);

                                const updateState = () => {
                                    const idx = isWinter ? currentWinterIndex : currentSummerIndex;
                                    info.innerHTML = 'Verzija ' + (idx + 1);

                                    leftBtn.disabled = idx === 0 || isScheduleLocked;
                                    leftBtn.style.opacity = (idx === 0 || isScheduleLocked) ? '0.5' : '1';

                                    rightBtn.disabled = idx === scheduleIds.length - 1 || isScheduleLocked;
                                    rightBtn.style.opacity = (idx === scheduleIds.length - 1 || isScheduleLocked) ? '0.5' : '1';

                                    const sems = isWinter ? [1, 3, 5] : [2, 4, 6];
                                    sems.forEach(s => updateSemesterTable(s));
                                };

                                leftBtn.addEventListener('click', () => {
                                    if (isScheduleLocked) return;
                                    if (isWinter) {
                                        if (currentWinterIndex > 0) currentWinterIndex--;
                                    } else {
                                        if (currentSummerIndex > 0) currentSummerIndex--;
                                    }
                                    updateState();
                                });

                                rightBtn.addEventListener('click', () => {
                                    if (isScheduleLocked) return;
                                    if (isWinter) {
                                        if (currentWinterIndex < scheduleIds.length - 1) currentWinterIndex++;
                                    } else {
                                        if (currentSummerIndex < scheduleIds.length - 1) currentSummerIndex++;
                                    }
                                    updateState();
                                });

                                setTimeout(updateState, 0);

                                controls.appendChild(leftBtn);
                                controls.appendChild(info);
                                controls.appendChild(rightBtn);

                                group.appendChild(label);
                                group.appendChild(controls);
                                return group;
                            }

                            // enableTdSwap and buildTableForSemester functions (copied behaviour)
                            function enableTdSwap(tableEl) {
                                const rows = tableEl.querySelectorAll('tbody tr');
                                rows.forEach((tr) => {
                                    new Sortable(tr, {
                                        group: {name: 'cells', pull: true, put: true},
                                        animation: 150,
                                        draggable: 'td',
                                        filter: '.no-drag',
                                        preventOnFilter: true,
                                        swap: true,
                                        swapClass: 'td-swap-hl',
                                        fallbackOnBody: true,
                                        swapThreshold: 0.65,
                                        invertSwap: true
                                    });
                                });
                            }

                            function formatEventLabel(ev) {
                                const typeMap = {
                                    LECTURE: 'Predavanje',
                                    EXERCISE: 'Vježbe',
                                    LAB: 'Lab'
                                };
                                const typeLabel = typeMap[ev.type] || ev.type || '';
                                const useAssistants = ev.type === 'EXERCISE' || ev.type === 'LAB';
                                const nameList = useAssistants ? (ev.assistants || '') : (ev.professors || '');
                                const fallbackNameList = (!nameList && ev.professors) ? ev.professors : nameList;
                                const locationLabel = ev.is_online ? 'ONLINE' : (ev.room || '');
                                const typeLine = typeLabel ? (typeLabel + (locationLabel ? (' (' + locationLabel + ')') : '')) : '';
                                const label = [ev.course, fallbackNameList, typeLine].filter(Boolean).map(escapeHtml).join('<br>');
                            return ev.event_id
                                ? '<div class="sched-ev" data-event-id="' + Number(ev.event_id) + '" title="Klik za izmjenu termina" style="cursor:pointer">' + label + '</div>'
                                : label;
                            }

                            // Dnevni fond (max 6h/dan po godini/semestru, preko svih predmeta te
                            // godine kombinovano) - vizuelna potvrda da algoritam poštuje limit.
                            const MAX_DAILY_HOURS = 6;
                            function buildDailyHoursFooter(events) {
                                const tfoot = document.createElement('tfoot');
                                const tr = document.createElement('tr');
                                const tdLabel = document.createElement('td');
                                tdLabel.textContent = 'Dnevni fond';
                                tdLabel.style.fontWeight = 'bold';
                                tr.appendChild(tdLabel);
                                for (let d = 1; d <= 5; d++) {
                                    const td = document.createElement('td');
                                    const hoursForDay = timeSlots.filter(slot =>
                                        events.some(ev => ev.day === d && (ev.start + '-' + ev.end) === slot)
                                    ).length;
                                    td.textContent = hoursForDay + 'h';
                                    td.style.fontWeight = 'bold';
                                    td.style.textAlign = 'center';
                                    td.style.color = hoursForDay > MAX_DAILY_HOURS ? '#ef4444' : '#22c55e';
                                    td.title = hoursForDay > MAX_DAILY_HOURS
                                        ? 'Prekoračen dnevni limit od ' + MAX_DAILY_HOURS + 'h!'
                                        : 'U okviru dnevnog limita od ' + MAX_DAILY_HOURS + 'h';
                                    tr.appendChild(td);
                                }
                                tfoot.appendChild(tr);
                                return tfoot;
                            }

                            function buildTableForSemester(sem, events, scheduleIdx, totalSchedules) {
                                const wrapper = document.createElement('div');
                                wrapper.className = 'semester-wrapper';
                                wrapper.id = 'semester-wrapper-' + sem;
                                wrapper.style.marginBottom = '40px';
                                wrapper.style.border = '1px solid #444';
                                wrapper.style.borderRadius = '12px';
                                wrapper.style.padding = '20px';
                                wrapper.style.background = 'transparent';

                                const header = document.createElement('div');
                                header.style.textAlign = 'center';
                                header.style.marginBottom = '15px';

                                const h3 = document.createElement('h3');
                                const semType = (sem % 2 === 1) ? 'Zimski semestar' : 'Ljetnji semestar';
                                h3.style.margin = '0';
                                h3.innerHTML = sem + '. semestar – ' + semType + '<br><small style="color: #666; font-weight: normal;">(Prikazana verzija: ' + (scheduleIdx + 1) + ')</small>';
                                header.appendChild(h3);
                                wrapper.appendChild(header);

                                if (!events || events.length === 0) {
                                    const noData = document.createElement('p');
                                    noData.textContent = 'Nema podataka za ovaj raspored.';
                                    noData.style.textAlign = 'center';
                                    noData.style.color = '#999';
                                    wrapper.appendChild(noData);
                                    return wrapper;
                                }

                                const table = document.createElement('table');
                                table.border = '1';
                                table.cellPadding = '5';
                                table.style.width = '100%';
                                table.style.borderCollapse = 'collapse';
                                table.className = 'schedule-table';
                                table.setAttribute('data-semester', sem);

                                const thead = document.createElement('thead');
                                const trHead = document.createElement('tr');
                                const thTime = document.createElement('th');
                                thTime.textContent = 'Vrijeme';
                                trHead.appendChild(thTime);
                                days.forEach(d => {
                                    const th = document.createElement('th');
                                    th.textContent = d;
                                    trHead.appendChild(th);
                                });
                                thead.appendChild(trHead);
                                table.appendChild(thead);

                                const tbody = document.createElement('tbody');
                                timeSlots.forEach(slot => {
                                    const hasAnyEvent = events.some(ev => (ev.start + '-' + ev.end) === slot);
                                    if (!hasAnyEvent) return;

                                    const tr = document.createElement('tr');
                                    const tdTime = document.createElement('td');
                                    tdTime.textContent = slot;
                                    tdTime.classList.add('no-drag');
                                    tr.appendChild(tdTime);

                                    for (let d = 1; d <= 5; d++) {
                                        const td = document.createElement('td');
                                        const cellEvents = events.filter(ev => ev.day === d && (ev.start + '-' + ev.end) === slot);
                                        if (cellEvents.length > 0) {
                                            td.innerHTML = cellEvents.map(ev => formatEventLabel(ev)).join('<br>');
                                        }
                                        tr.appendChild(td);
                                    }

                                    tbody.appendChild(tr);
                                });

                                table.appendChild(tbody);
                                table.appendChild(buildDailyHoursFooter(events));
                                wrapper.appendChild(table);
                                enableTdSwap(table);

                                const pdfBtn = document.createElement('button');
                                pdfBtn.textContent = 'Sačuvaj kao PDF';
                                pdfBtn.className = 'action-button add-button';
                                pdfBtn.style.marginTop = '10px';
                                pdfBtn.addEventListener('click', () => {
                                    saveTableAsPDF(table, sem);
                                });
                                wrapper.appendChild(pdfBtn);

                                return wrapper;
                            }

                            function updateSemesterTable(sem) {
                                const oldWrapper = document.getElementById('semester-wrapper-' + sem);
                                if (!oldWrapper) return;

                                const isWinter = (sem % 2 !== 0);
                                const schedIdx = isWinter ? currentWinterIndex : currentSummerIndex;
                                const schedId = scheduleIds[schedIdx];
                                const events = (schedules[schedId] && schedules[schedId][sem]) || [];

                                const newWrapper = buildTableForSemester(sem, events, schedIdx, scheduleIds.length);
                                oldWrapper.replaceWith(newWrapper);
                            }

                            // MASTER CONTROLS
                            const controlsDiv = document.createElement('div');
                            controlsDiv.style.display = 'flex';
                            controlsDiv.style.justifyContent = 'space-around';
                            controlsDiv.style.flexWrap = 'wrap';
                            controlsDiv.style.marginBottom = '30px';
                            controlsDiv.style.padding = '20px';
                            controlsDiv.style.background = '#2d2d2d';
                            controlsDiv.style.borderRadius = '10px';
                            controlsDiv.style.border = '1px solid #444';
                            container.appendChild(controlsDiv);

                            controlsDiv.appendChild(createControlGroup('Zimski Semestri (1, 3, 5)', true));

                            // Lock button
                            const lockBtnContainer = document.createElement('div');
                            lockBtnContainer.style.display = 'flex';
                            lockBtnContainer.style.alignItems = 'center';
                            lockBtnContainer.style.justifyContent = 'center';
                            lockBtnContainer.style.margin = '10px';
                            const lockBtn = document.createElement('button');
                            // set initial label/style based on current lock state
                            if (isScheduleLocked) {
                                lockBtn.textContent = '🔒 Otključaj';
                                lockBtn.style.cssText = 'padding: 10px 20px; font-size: 14px; cursor: pointer; border: 2px solid #ef4444; background: #ef4444; color: white; border-radius: 6px; font-weight: bold; transition: all 0.3s ease;';
                            } else {
                                lockBtn.textContent = '🔓 Zaključaj';
                                lockBtn.style.cssText = 'padding: 10px 20px; font-size: 14px; cursor: pointer; border: 2px solid #f59e0b; background: #f59e0b; color: white; border-radius: 6px; font-weight: bold; transition: all 0.3s ease;';
                            }
                            lockBtnContainer.appendChild(lockBtn);
                            // ensure arrow buttons reflect initial lock state
                            setTimeout(() => {
                                allArrowButtons.forEach(btn => {
                                    if (isScheduleLocked) {
                                        btn.disabled = true;
                                        btn.style.opacity = '0.5';
                                        btn.style.cursor = 'not-allowed';
                                    } else {
                                        btn.disabled = false;
                                        btn.style.opacity = '1';
                                        btn.style.cursor = 'pointer';
                                    }
                                });
                            }, 0);

                            // Lock button click handler (calls server API)
                            lockBtn.addEventListener('click', async () => {
                                // Determine winter and summer schedule IDs based on what's available
                                let winterScheduleId, summerScheduleId;
                                if (scheduleIds.length >= 2) {
                                    winterScheduleId = scheduleIds[currentWinterIndex] || scheduleIds[0];
                                    summerScheduleId = scheduleIds[currentSummerIndex] || scheduleIds[1] || scheduleIds[0];
                                } else if (scheduleIds.length === 1) {
                                    winterScheduleId = scheduleIds[0];
                                    summerScheduleId = scheduleIds[0];
                                } else {
                                    alert('Greška: Nema dostupnih rasporeda za zaključavanje.');
                                    return;
                                }

                                isScheduleLocked = !isScheduleLocked;

                                try {
                                    const payload = {
                                        action: 'toggle_lock',
                                        is_locked: isScheduleLocked,
                                        winter_schedule_id: winterScheduleId,
                                        summer_schedule_id: summerScheduleId
                                    };

                                    const response = await fetch('../api/schedule_lock.php', {
                                        method: 'POST',
                                        headers: {'Content-Type': 'application/json'},
                                        body: JSON.stringify(payload)
                                    });

                                    const data = await response.json();
                                    if (data && data.success) {
                                        // update UI based on new lock state
                                        if (isScheduleLocked) {
                                            lockBtn.textContent = '🔒 Otključaj';
                                            lockBtn.style.background = '#ef4444';
                                            lockBtn.style.borderColor = '#ef4444';
                                        } else {
                                            lockBtn.textContent = '🔓 Zaključaj';
                                            lockBtn.style.background = '#f59e0b';
                                            lockBtn.style.borderColor = '#f59e0b';
                                        }

                                        allArrowButtons.forEach(btn => {
                                            if (isScheduleLocked) {
                                                btn.disabled = true;
                                                btn.style.opacity = '0.5';
                                                btn.style.cursor = 'not-allowed';
                                            } else {
                                                btn.disabled = false;
                                                btn.style.opacity = '1';
                                                btn.style.cursor = 'pointer';
                                            }
                                        });

                                        // update global flag so subsequent loads see correct state
                                        if (window.adminData) window.adminData.schedule_locked = isScheduleLocked;
                                    } else {
                                        console.error('Failed to save lock state', data && data.message);
                                        // revert
                                        isScheduleLocked = !isScheduleLocked;
                                        alert('Neuspeh pri čuvanju statusa zaključavanja: ' + (data && data.message ? data.message : 'Nepoznata greška'));
                                    }
                                } catch (err) {
                                    console.error('API error', err);
                                    isScheduleLocked = !isScheduleLocked;
                                    alert('Greška pri povezivanju sa serverom.');
                                }
                            });
                            controlsDiv.appendChild(lockBtnContainer);

                            controlsDiv.appendChild(createControlGroup('Ljetnji Semestri (2, 4, 6)', false));

                            // Initial render for all semesters
                            [1, 3, 5, 2, 4, 6].forEach(sem => {
                                const schedId = scheduleIds[0];
                                const events = (schedules[schedId] && schedules[schedId][sem]) || [];
                                const wrapper = buildTableForSemester(sem, events, 0, scheduleIds.length);
                                container.appendChild(wrapper);
                            });

                            // PDF all
                            const pdfAllBtn = document.createElement('button');
                            pdfAllBtn.textContent = 'Sačuvaj kompletan raspored kao PDF';
                            pdfAllBtn.className = 'action-button add-button';
                            pdfAllBtn.style.marginTop = '20px';
                            pdfAllBtn.addEventListener('click', saveFullScheduleAsPDF);
                            container.appendChild(pdfAllBtn);
                        }

                        (function () {
                            try {
                                const genBtn = document.getElementById('generate-schedule');
                                if (!genBtn) return;
                                const lockedDiv = document.createElement('div');
                                lockedDiv.id = 'locked-schedules-list';
                                lockedDiv.style.marginTop = '12px';
                                lockedDiv.style.display = 'block';

                                if (window.adminData && window.adminData.schedule_locked) {
                                    const ids = window.adminData.locked_schedule_ids || [];
                                    if (ids.length > 0) {
                                        // Auto-load the most recent locked schedule so admin sees it immediately
                                        (async () => {
                                            const firstId = ids[0];
                                            const statusDiv = document.getElementById('schedule-status');
                                            const container = document.getElementById('schedule-container');
                                            statusDiv.style.display = 'block';
                                            statusDiv.innerHTML = '<p style="color:#3b82f6;">Učitavanje zaključanog rasporeda ID ' + firstId + '...</p>';
                                            container.style.display = 'none';
                                            container.innerHTML = '';

                                            try {
                                                const res = await fetch('admin_panel.php?action=getschedule&only_locked_id=' + encodeURIComponent(firstId));
                                                if (!res.ok) throw new Error('HTTP ' + res.status);
                                                const data = await res.json();
                                                if (data.error) {
                                                    statusDiv.innerHTML = '<p style="color:#ef4444;">Greška: ' + (data.error || 'Nepoznata greška') + '</p>';
                                                    return;
                                                }
                                                // Render using shared renderer so locked schedule UI matches generated one
                                                statusDiv.innerHTML = '<p style="color:#22c55e;">Prikaz zaključanog rasporeda ID ' + firstId + '</p>';
                                                container.style.display = 'block';
                                                renderScheduleData(data);
                                            } catch (err) {
                                                statusDiv.innerHTML = '<p style="color:#ef4444;">Greška pri učitavanju: ' + err.message + '</p>';
                                            }
                                        })();

                                        // quick-access buttons removed; auto-load of the most recent locked schedule remains
                                    } else {
                                        lockedDiv.innerHTML = '<em>Nema zaključanih rasporeda.</em>';
                                    }
                                }

                                genBtn.parentNode.insertBefore(lockedDiv, genBtn.nextSibling);
                            } catch (e) {
                                console.warn('locked schedules init error', e);
                            }
                        })();
                        document.getElementById('generate-schedule').addEventListener('click', async () => {
                            const button = document.getElementById('generate-schedule');
                            const statusDiv = document.getElementById('schedule-status');
                            const container = document.getElementById('schedule-container');

                            // Disable dugme i prikaži loading stanje
                            button.disabled = true;
                            button.textContent = 'Generiše se...';
                            button.classList.add('loading');

                            statusDiv.style.display = 'block';
                            statusDiv.innerHTML = '<p style="color: #3b82f6;">Generisanje rasporeda u toku, molimo sačekajte...</p>';
                            container.style.display = 'none';
                            container.innerHTML = '';

                            try {
                                // Pokreni generisanje - ovo se odmah vraća, Java radi u pozadini
                                // (vidi admin_panel.php akciju 'generateschedule' - proc_open, ne blokira)
                                const generateRes = await fetch('admin_panel.php?action=generateschedule');

                                if (!generateRes.ok) {
                                    throw new Error('HTTP greška: ' + generateRes.status + ' ' + generateRes.statusText);
                                }

                                const contentType = generateRes.headers.get('content-type');
                                if (!contentType || !contentType.includes('application/json')) {
                                    const text = await generateRes.text();
                                    throw new Error('Server nije vratio JSON. Odgovor: ' + text.substring(0, 200));
                                }

                                let generateData;
                                try {
                                    const responseText = await generateRes.text();
                                    if (!responseText || responseText.trim() === '') {
                                        throw new Error('Server je vratio prazan odgovor');
                                    }
                                    generateData = JSON.parse(responseText);
                                } catch (jsonError) {
                                    throw new Error('Greška pri parsiranju JSON odgovora: ' + jsonError.message);
                                }

                                if (generateData.status === 'error') {
                                    statusDiv.innerHTML = '<p style="color: #ef4444; padding: 12px; background: rgba(239, 68, 68, 0.1); border-radius: 8px; border: 1px solid #ef4444;">Greška: ' + generateData.message + '</p>';
                                    button.disabled = false;
                                    button.textContent = 'Generiši raspored časova';
                                    button.classList.remove('loading');
                                    return;
                                }

                                // Pollinguj dok Java piše napredak u storage/schedule_progress.json
                                // (vidi ScheduleProgress.java). statusDiv se u potpunosti prekuca na
                                // SVAKOM tick-u (ne oslanjamo se na cuvanje reference na unutrašnje
                                // #schedule-progress-text/#schedule-progress-bar) jer postojeći
                                // auto-load zaključanog rasporeda (vidi IIFE iznad) takođe piše u isti
                                // statusDiv i može ga u međuvremenu prepisati - sledeći tick (≤1.2s)
                                // ionako vraća ispravan sadržaj, pa je ovo samo-isceljujuće.
                                const renderProgress = (pollData) => {
                                    const totalSchedules = pollData.total_schedules || 6;
                                    const totalCourses = pollData.total_courses || 0;
                                    const totalUnits = Math.max(1, totalSchedules * totalCourses);
                                    const doneUnits = Math.max(0, (pollData.current_schedule || 0) - 1) * totalCourses
                                        + (pollData.current_course || 0);
                                    const percent = Math.min(100, Math.max(0, Math.round((doneUnits / totalUnits) * 100)));

                                    statusDiv.innerHTML =
                                        '<div style="padding: 12px; background: rgba(59, 130, 246, 0.1); border-radius: 8px; border: 1px solid #3b82f6;">' +
                                        '<p style="color: #3b82f6; margin: 0 0 8px 0;">' + (pollData.message || 'Generisanje u toku...') + ' (' + percent + '%)</p>' +
                                        '<div style="background: rgba(59, 130, 246, 0.2); border-radius: 6px; height: 10px; overflow: hidden;">' +
                                        '<div style="background: #3b82f6; height: 100%; width: ' + percent + '%; transition: width 0.3s;"></div>' +
                                        '</div></div>';
                                };

                                renderProgress({ message: 'Pokretanje generisanja...', total_courses: 0 });

                                const finalData = await new Promise((resolve, reject) => {
                                    const startedAt = Date.now();
                                    const maxWaitMs = 10 * 60 * 1000; // safety - ne pollinguj vjecno ako proces crash-uje

                                    const poll = async () => {
                                        try {
                                            const pollRes = await fetch('admin_panel.php?action=generateschedule_status');
                                            if (!pollRes.ok) {
                                                throw new Error('HTTP greška pri provjeri napretka: ' + pollRes.status);
                                            }
                                            const pollData = await pollRes.json();

                                            if (pollData.done) {
                                                resolve(pollData);
                                                return;
                                            }

                                            renderProgress(pollData);

                                            if (Date.now() - startedAt > maxWaitMs) {
                                                reject(new Error('Generisanje predugo traje (preko 10 minuta) - proverite server log.'));
                                                return;
                                            }

                                            setTimeout(poll, 2000);
                                        } catch (pollError) {
                                            reject(pollError);
                                        }
                                    };
                                    poll();
                                });

                                if (finalData.error) {
                                    statusDiv.innerHTML = '<p style="color: #ef4444; padding: 12px; background: rgba(239, 68, 68, 0.1); border-radius: 8px; border: 1px solid #ef4444;">Greška: ' + (finalData.message || finalData.error) + '</p>';
                                    button.disabled = false;
                                    button.textContent = 'Generiši raspored časova';
                                    button.classList.remove('loading');
                                    return;
                                }

                                // Ako je uspešno generisano, prikaži poruku o uspehu. Poruka može
                                // sadržati spisak neraspoređenih predmeta (imena iz baze) - zato
                                // textContent, ne innerHTML.
                                statusDiv.innerHTML = '<p style="color: #22c55e; padding: 12px; background: rgba(34, 197, 94, 0.1); border-radius: 8px; border: 1px solid #22c55e;"></p>';
                                statusDiv.firstChild.textContent = '✓ ' + finalData.message;

                                // Sada učitaj i prikaži generisani raspored
                                container.style.display = 'block';

                                // Generisanje je već završeno i podaci su sačuvani u bazi - ako ovaj
                                // fetch omane (npr. prolazni hiccup odmah nakon dugog pozadinskog
                                // pokretanja), probaj ponovo umjesto da tjeramo admina da ponovo
                                // pokreće cijelo generisanje.
                                let data;
                                let lastLoadErr = null;
                                for (const delayMs of [0, 800, 2000, 4000]) {
                                    if (delayMs > 0) {
                                        await new Promise(r => setTimeout(r, delayMs));
                                    }
                                    try {
                                        const res = await fetch('admin_panel.php?action=getschedule');
                                        data = await res.json();
                                        lastLoadErr = null;
                                        break;
                                    } catch (loadErr) {
                                        lastLoadErr = loadErr;
                                    }
                                }
                                if (lastLoadErr) {
                                    // Raspored JE uspješno generisan (finalData to potvrđuje) - ovo je
                                    // omanulo samo pri UČITAVANJU prikaza, pa ne treba plašiti admina
                                    // generičkom tehničkom greškom. Prikaz će se pojaviti na osvježenje.
                                    statusDiv.innerHTML += '<p style="color: #f59e0b; margin-top: 10px; padding: 12px; background: rgba(245, 158, 11, 0.1); border-radius: 8px; border: 1px solid #f59e0b;">Raspored je uspješno sačuvan, ali prikaz nije mogao da se učita. Osvježite stranicu (F5) da vidite rezultat.</p>';
                                    button.disabled = false;
                                    button.textContent = 'Generiši raspored časova';
                                    button.classList.remove('loading');
                                    return;
                                }
                                if (data.error) {
                                    statusDiv.innerHTML += '<p style="color: #ef4444; margin-top: 10px;">Greška pri učitavanju rasporeda: ' + data.error + '</p>';
                                    button.disabled = false;
                                    button.textContent = 'Generiši raspored časova';
                                    button.classList.remove('loading');
                                    return;
                                }
                                // Nova struktura: data.schedules[scheduleId][semester] = events[]
                                // data.schedule_ids = [id1, id2, ...]

                                const schedules = data.schedules || {};
                                const scheduleIds = data.schedule_ids || [];

                                if (scheduleIds.length === 0) {
                                    container.innerHTML = '<p>Nema rasporeda u bazi.</p>';
                                    button.disabled = false;
                                    button.textContent = 'Generiši raspored časova';
                                    button.classList.remove('loading');
                                    return;
                                }

                                // Skupi sve događaje za vremenske slotove
                                const allEvents = [];
                                scheduleIds.forEach(sid => {
                                    Object.keys(schedules[sid] || {}).forEach(sem => {
                                        (schedules[sid][sem] || []).forEach(ev => allEvents.push(ev));
                                    });
                                });

                                const timeSlots = Array.from(
                                    new Set(allEvents.map(e => e.start + '-' + e.end))
                                ).sort();

                                // State: zimski i ljetnji indeksi
                                let currentWinterIndex = 0;
                                let currentSummerIndex = 0;

                                // --- MASTER CONTROLS (ZIMSKI / LJETNJI) ---
                                const controlsDiv = document.createElement('div');
                                controlsDiv.style.display = 'flex';
                                controlsDiv.style.justifyContent = 'space-around';
                                controlsDiv.style.flexWrap = 'wrap';
                                controlsDiv.style.marginBottom = '30px';
                                controlsDiv.style.padding = '20px';
                                controlsDiv.style.background = '#2d2d2d';
                                controlsDiv.style.borderRadius = '10px';
                                controlsDiv.style.border = '1px solid #444';

                                container.appendChild(controlsDiv);

                                // Lock state management
                                let isScheduleLocked = false; //zakljucavanje rasporeda
                                const allArrowButtons = []; //zakljucavanje rasporeda

                                function createControlGroup(title, isWinter) {
                                    const group = document.createElement('div');
                                    group.style.textAlign = 'center';
                                    group.style.margin = '10px';

                                    const label = document.createElement('h3');
                                    label.textContent = title;
                                    label.style.marginBottom = '10px';
                                    label.style.color = '#e5e7eb';

                                    const controls = document.createElement('div');
                                    controls.style.display = 'flex';
                                    controls.style.alignItems = 'center';
                                    controls.style.gap = '15px';
                                    controls.style.justifyContent = 'center';

                                    const leftBtn = document.createElement('button');
                                    leftBtn.innerHTML = '◀';
                                    leftBtn.className = 'nav-arrow';
                                    leftBtn.style.cssText = 'font-size: 20px; padding: 5px 12px; cursor: pointer; border: none; background: #3b82f6; color: white; border-radius: 6px;';
                                    allArrowButtons.push(leftBtn); //zakljucavanje rasporeda

                                    const info = document.createElement('span');
                                    info.innerHTML = 'Verzija 1';
                                    info.style.fontWeight = 'bold';

                                    const rightBtn = document.createElement('button');
                                    rightBtn.innerHTML = '▶';
                                    rightBtn.className = 'nav-arrow';
                                    rightBtn.style.cssText = 'font-size: 20px; padding: 5px 12px; cursor: pointer; border: none; background: #3b82f6; color: white; border-radius: 6px;';
                                    allArrowButtons.push(rightBtn); //zakljucavanje rasporeda


                                    const updateState = () => {
                                        const idx = isWinter ? currentWinterIndex : currentSummerIndex;
                                        info.innerHTML = 'Verzija ' + (idx + 1);

                                        //zakljucavanje rasporeda
                                        leftBtn.disabled = idx === 0 || isScheduleLocked;
                                        leftBtn.style.opacity = (idx === 0 || isScheduleLocked) ? '0.5' : '1';

                                        rightBtn.disabled = idx === scheduleIds.length - 1 || isScheduleLocked;
                                        rightBtn.style.opacity = (idx === scheduleIds.length - 1 || isScheduleLocked) ? '0.5' : '1';


                                        leftBtn.disabled = idx === 0;
                                        leftBtn.style.opacity = idx === 0 ? '0.5' : '1';

                                        rightBtn.disabled = idx === scheduleIds.length - 1;
                                        rightBtn.style.opacity = idx === scheduleIds.length - 1 ? '0.5' : '1';

                                        // Update relevant tables
                                        const sems = isWinter ? [1, 3, 5] : [2, 4, 6];
                                        sems.forEach(s => updateSemesterTable(s));
                                    };

                                    leftBtn.addEventListener('click', () => {
                                        if (isScheduleLocked) return; //zakljucavanje rasporeda
                                        if (isWinter) {
                                            if (currentWinterIndex > 0) currentWinterIndex--;
                                        } else {
                                            if (currentSummerIndex > 0) currentSummerIndex--;
                                        }
                                        updateState();
                                    });

                                    rightBtn.addEventListener('click', () => {
                                        if (isScheduleLocked) return; //zakljucavanje rasporeda
                                        if (isWinter) {
                                            if (currentWinterIndex < scheduleIds.length - 1) currentWinterIndex++;
                                        } else {
                                            if (currentSummerIndex < scheduleIds.length - 1) currentSummerIndex++;
                                        }
                                        updateState();
                                    });

                                    // Initial call
                                    setTimeout(updateState, 0);

                                    controls.appendChild(leftBtn);
                                    controls.appendChild(info);
                                    controls.appendChild(rightBtn);

                                    group.appendChild(label);
                                    group.appendChild(controls);
                                    return group;
                                }

                                controlsDiv.appendChild(createControlGroup('Zimski Semestri (1, 3, 5)', true));

                                //Zakljucavanje rasporeda - START
                                // Lock button
                                const lockBtnContainer = document.createElement('div');
                                lockBtnContainer.style.display = 'flex';
                                lockBtnContainer.style.alignItems = 'center';
                                lockBtnContainer.style.justifyContent = 'center';
                                lockBtnContainer.style.margin = '10px';

                                const lockBtn = document.createElement('button');
                                lockBtn.textContent = '🔓 Zaključaj';
                                lockBtn.style.cssText = 'padding: 10px 20px; font-size: 14px; cursor: pointer; border: 2px solid #f59e0b; background: #f59e0b; color: white; border-radius: 6px; font-weight: bold; transition: all 0.3s ease;';

                                lockBtn.addEventListener('click', async () => {
                                    // Determine winter and summer schedule IDs based on what's available
                                    // If we have multiple schedules, use different ones for winter/summer
                                    // If only 1 schedule, use it for both
                                    let winterScheduleId, summerScheduleId;

                                    if (scheduleIds.length >= 2) {
                                        // Multiple schedules: use first for winter, second for summer
                                        winterScheduleId = scheduleIds[currentWinterIndex] || scheduleIds[0];
                                        summerScheduleId = scheduleIds[currentSummerIndex] || scheduleIds[1] || scheduleIds[0];
                                    } else if (scheduleIds.length === 1) {
                                        // Only 1 schedule: use it for both
                                        winterScheduleId = scheduleIds[0];
                                        summerScheduleId = scheduleIds[0];
                                    } else {
                                        // No schedules at all
                                        console.error('✗ No schedule IDs found');
                                        alert('Greška: Nema dostupnih rasporeda.');
                                        return;
                                    }

                                    // console.log('=== LOCK TOGGLE ===');
                                    // console.log('All Available Schedule IDs:', scheduleIds);
                                    // console.log('Winter Index:', currentWinterIndex);
                                    // console.log('Summer Index:', currentSummerIndex);
                                    // console.log('Winter Schedule ID to lock:', winterScheduleId);
                                    // console.log('Summer Schedule ID to lock:', summerScheduleId);

                                    isScheduleLocked = !isScheduleLocked;

                                    // console.log('Winter scheduleId: ',winterScheduleId);
                                    // console.log('Summer schedule Id: ',summerScheduleId);
                                    // console.log('Is Locked:', isScheduleLocked);
                                    // console.log('==================');


                                    // Make AJAX call to API
                                    try {
                                        const payload = {
                                            action: 'toggle_lock',
                                            is_locked: isScheduleLocked,
                                            winter_schedule_id: winterScheduleId,
                                            summer_schedule_id: summerScheduleId
                                        };

                                        // console.log('Sending payload:', JSON.stringify(payload, null, 2));

                                        const response = await fetch('../api/schedule_lock.php', {
                                            method: 'POST',
                                            headers: {
                                                'Content-Type': 'application/json',
                                            },
                                            body: JSON.stringify(payload)
                                        });

                                        const data = await response.json();
                                        console.log('API Response:', data);

                                        if (data.success) {
                                            // console.log('✓ Lock state saved:', data.message);

                                            // Update button appearance AFTER API succeeds
                                            if (isScheduleLocked) {
                                                lockBtn.textContent = '🔒 Otključaj';
                                                lockBtn.style.background = '#ef4444';
                                                lockBtn.style.borderColor = '#ef4444';
                                            } else {
                                                lockBtn.textContent = '🔓 Zaključaj';
                                                lockBtn.style.background = '#f59e0b';
                                                lockBtn.style.borderColor = '#f59e0b';
                                            }

                                            // Update all arrow buttons AFTER API succeeds
                                            allArrowButtons.forEach(btn => {
                                                if (isScheduleLocked) {
                                                    btn.disabled = true;
                                                    btn.style.opacity = '0.5';
                                                    btn.style.cursor = 'not-allowed';
                                                } else {
                                                    btn.disabled = false;
                                                    btn.style.opacity = '1';
                                                    btn.style.cursor = 'pointer';
                                                }
                                            });
                                        } else {
                                            console.error('✗ Failed to save lock state:', data.message);
                                            // Revert the toggle if API call failed
                                            isScheduleLocked = !isScheduleLocked;
                                        }
                                    } catch (error) {
                                        console.error('✗ API call error:', error);
                                        // Revert the toggle if API call failed
                                        isScheduleLocked = !isScheduleLocked;
                                    }
                                });

                                lockBtnContainer.appendChild(lockBtn);
                                controlsDiv.appendChild(lockBtnContainer);

                                //Zakljucavanje rasporeda - END

                                controlsDiv.appendChild(createControlGroup('Ljetnji Semestri (2, 4, 6)', false));

                                function enableTdSwap(tableEl) {
                                    const rows = tableEl.querySelectorAll('tbody tr');

                                    rows.forEach((tr) => {
                                        new Sortable(tr, {
                                            group: {name: 'cells', pull: true, put: true},
                                            animation: 150,
                                            draggable: 'td',

                                            filter: '.no-drag',
                                            preventOnFilter: true,

                                            swap: true,
                                            swapClass: 'td-swap-hl',

                                            fallbackOnBody: true,
                                            swapThreshold: 0.65,
                                            invertSwap: true
                                        });
                                    });
                                }

                                const MAX_DAILY_HOURS = 6;
                                function buildDailyHoursFooter(events) {
                                    const tfoot = document.createElement('tfoot');
                                    const tr = document.createElement('tr');
                                    const tdLabel = document.createElement('td');
                                    tdLabel.textContent = 'Dnevni fond';
                                    tdLabel.style.fontWeight = 'bold';
                                    tr.appendChild(tdLabel);
                                    for (let d = 1; d <= 5; d++) {
                                        const td = document.createElement('td');
                                        const hoursForDay = timeSlots.filter(slot =>
                                            events.some(ev => ev.day === d && (ev.start + '-' + ev.end) === slot)
                                        ).length;
                                        td.textContent = hoursForDay + 'h';
                                        td.style.fontWeight = 'bold';
                                        td.style.textAlign = 'center';
                                        td.style.color = hoursForDay > MAX_DAILY_HOURS ? '#ef4444' : '#22c55e';
                                        td.title = hoursForDay > MAX_DAILY_HOURS
                                            ? 'Prekoračen dnevni limit od ' + MAX_DAILY_HOURS + 'h!'
                                            : 'U okviru dnevnog limita od ' + MAX_DAILY_HOURS + 'h';
                                        tr.appendChild(td);
                                    }
                                    tfoot.appendChild(tr);
                                    return tfoot;
                                }

                                function buildTableForSemester(sem, events, scheduleIdx, totalSchedules) {
                                    const wrapper = document.createElement('div');
                                    wrapper.className = 'semester-wrapper';
                                    wrapper.id = 'semester-wrapper-' + sem;
                                    wrapper.style.marginBottom = '40px';
                                    wrapper.style.border = '1px solid #444';
                                    wrapper.style.borderRadius = '12px';
                                    wrapper.style.padding = '20px';
                                    wrapper.style.background = 'transparent';

                                    // NOVI HEADER BEZ STRELICA (Kontrola je sada na vrhu)
                                    const header = document.createElement('div');
                                    header.style.textAlign = 'center';
                                    header.style.marginBottom = '15px';

                                    const h3 = document.createElement('h3');
                                    const semType = (sem % 2 === 1) ? 'Zimski semestar' : 'Ljetnji semestar';
                                    h3.style.margin = '0';
                                    h3.innerHTML = sem + '. semestar – ' + semType +
                                        '<br><small style="color: #666; font-weight: normal;">(Prikazana verzija: ' + (scheduleIdx + 1) + ')</small>';

                                    header.appendChild(h3);
                                    wrapper.appendChild(header);

                                    // Tabela
                                    if (!events || events.length === 0) {
                                        const noData = document.createElement('p');
                                        noData.textContent = 'Nema podataka za ovaj raspored.';
                                        noData.style.textAlign = 'center';
                                        noData.style.color = '#999';
                                        wrapper.appendChild(noData);
                                    } else {
                                        const table = document.createElement('table');
                                        table.border = '1';
                                        table.cellPadding = '5';
                                        table.style.width = '100%';
                                        table.style.borderCollapse = 'collapse';
                                        table.className = 'schedule-table';
                                        table.setAttribute('data-semester', sem);

                                        const thead = document.createElement('thead');
                                        const trHead = document.createElement('tr');
                                        const thTime = document.createElement('th');
                                        thTime.textContent = 'Vrijeme';
                                        trHead.appendChild(thTime);
                                        days.forEach(d => {
                                            const th = document.createElement('th');
                                            th.textContent = d;
                                            trHead.appendChild(th);
                                        });
                                        thead.appendChild(trHead);
                                        table.appendChild(thead);

                                        const tbody = document.createElement('tbody');

                                        timeSlots.forEach(slot => {
                                            const hasAnyEvent = events.some(ev =>
                                                (ev.start + '-' + ev.end) === slot
                                            );
                                            if (!hasAnyEvent) return;

                                            const tr = document.createElement('tr');
                                            const tdTime = document.createElement('td');
                                            tdTime.textContent = slot;
                                            tdTime.classList.add('no-drag');
                                            tr.appendChild(tdTime);

                                            for (let d = 1; d <= 5; d++) {
                                                const td = document.createElement('td');
                                                const cellEvents = events.filter(ev =>
                                                    ev.day === d && (ev.start + '-' + ev.end) === slot
                                                );
                                                if (cellEvents.length > 0) {
                                                    td.innerHTML = cellEvents.map(ev => formatEventLabel(ev)).join('<br>');
                                                }
                                                tr.appendChild(td);
                                            }

                                            tbody.appendChild(tr);
                                        });

                                        table.appendChild(tbody);
                                        table.appendChild(buildDailyHoursFooter(events));
                                        wrapper.appendChild(table);
                                        enableTdSwap(table);

                                        // PDF dugme
                                        const pdfBtn = document.createElement('button');
                                        pdfBtn.textContent = 'Sačuvaj kao PDF';
                                        pdfBtn.className = 'action-button add-button';
                                        pdfBtn.style.marginTop = '10px';
                                        pdfBtn.addEventListener('click', () => {
                                            saveTableAsPDF(table, sem);
                                        });
                                        wrapper.appendChild(pdfBtn);
                                    }

                                    return wrapper;
                                }

                                function updateSemesterTable(sem) {
                                    const oldWrapper = document.getElementById('semester-wrapper-' + sem);
                                    if (!oldWrapper) return;

                                    // Determine if winter or summer
                                    const isWinter = (sem % 2 !== 0);
                                    const schedIdx = isWinter ? currentWinterIndex : currentSummerIndex;

                                    const schedId = scheduleIds[schedIdx];
                                    const events = (schedules[schedId] && schedules[schedId][sem]) || [];

                                    const newWrapper = buildTableForSemester(sem, events, schedIdx, scheduleIds.length);
                                    oldWrapper.replaceWith(newWrapper);
                                }

                                // Helper function for Exams (List View)
                                function buildSimpleScheduleTable(titleText, events, idSuffix) {
                                    const wrapper = document.createElement('div');
                                    wrapper.className = 'semester-wrapper';
                                    wrapper.id = 'semester-wrapper-' + idSuffix;
                                    wrapper.style.marginTop = '60px';
                                    wrapper.style.marginBottom = '40px';
                                    wrapper.style.border = '1px solid #444';
                                    wrapper.style.borderRadius = '12px';
                                    wrapper.style.padding = '20px';
                                    wrapper.style.background = 'transparent';

                                    const header = document.createElement('div');
                                    header.style.textAlign = 'center';
                                    header.style.marginBottom = '15px';

                                    const h3 = document.createElement('h3');
                                    h3.style.margin = '0';
                                    h3.innerHTML = titleText;
                                    header.appendChild(h3);
                                    wrapper.appendChild(header);

                                    if (!events || events.length === 0) {
                                        const p = document.createElement('p');
                                        p.textContent = 'Nema zakazanih kolokvijuma.';
                                        p.style.textAlign = 'center';
                                        p.style.color = '#999';
                                        wrapper.appendChild(p);
                                        return wrapper;
                                    }

                                    const table = document.createElement('table');
                                    table.border = '1';
                                    table.cellPadding = '5';
                                    table.style.width = '100%';
                                    table.style.borderCollapse = 'collapse';
                                    table.className = 'schedule-table';

                                    const thead = document.createElement('thead');
                                    const trHead = document.createElement('tr');
                                    ['Datum', 'Dan', 'Vrijeme', 'Predmet', 'Sala'].forEach(text => {
                                        const th = document.createElement('th');
                                        th.textContent = text;
                                        // Reuse existing styles logic via class, but enforce headers
                                        // trHead.appendChild(th);
                                    });

                                    // Or better, stick to valid DOM
                                    const headers = ['Datum', 'Dan', 'Vrijeme', 'Predmet', 'Sala'];
                                    headers.forEach(h => {
                                        const th = document.createElement('th');
                                        th.textContent = h;
                                        trHead.appendChild(th);
                                    });

                                    thead.appendChild(trHead);
                                    table.appendChild(thead);

                                    const tbody = document.createElement('tbody');

                                    // Sort events by date and time
                                    events.sort((a, b) => {
                                        const dateA = a.date || '';
                                        const dateB = b.date || '';
                                        if (dateA !== dateB) return dateA < dateB ? -1 : 1;
                                        return a.start.localeCompare(b.start);
                                    });

                                    events.forEach(ev => {
                                        const tr = document.createElement('tr');

                                        // Date
                                        const tdDate = document.createElement('td');
                                        tdDate.textContent = ev.date || '-';
                                        tr.appendChild(tdDate);

                                        // Day
                                        const tdDay = document.createElement('td');
                                        tdDay.textContent = ev.day;
                                        tr.appendChild(tdDay);

                                        // Time
                                        const tdTime = document.createElement('td');
                                        tdTime.textContent = ev.start + ' - ' + ev.end;
                                        tr.appendChild(tdTime);

                                        // Course
                                        const tdCourse = document.createElement('td');
                                        tdCourse.textContent = ev.course;
                                        tr.appendChild(tdCourse);

                                        // Room
                                        const tdRoom = document.createElement('td');
                                        tdRoom.textContent = ev.room;
                                        tr.appendChild(tdRoom);

                                        tbody.appendChild(tr);
                                    });

                                    table.appendChild(tbody);
                                    wrapper.appendChild(table);

                                    // Print button for exams
                                    const pdfBtn = document.createElement('button');
                                    pdfBtn.textContent = 'Sačuvaj kolokvijume kao PDF';
                                    pdfBtn.className = 'action-button add-button';
                                    pdfBtn.style.marginTop = '10px';
                                    pdfBtn.addEventListener('click', () => {
                                        saveTableAsPDF(table, 'kolokvijumi');
                                    });
                                    wrapper.appendChild(pdfBtn);

                                    return wrapper;
                                }

                                // Inicijalni prikaz - svi semestri sa prvim rasporedom
                                [1, 3, 5, 2, 4, 6].forEach(sem => {
                                    const schedId = scheduleIds[0];
                                    const events = (schedules[schedId] && schedules[schedId][sem]) || [];
                                    const wrapper = buildTableForSemester(sem, events, 0, scheduleIds.length);
                                    container.appendChild(wrapper);
                                });

                                // Dugme za PDF svih
                                const pdfAllBtn = document.createElement('button');
                                pdfAllBtn.textContent = 'Sačuvaj kompletan raspored kao PDF';
                                pdfAllBtn.className = 'action-button add-button';
                                pdfAllBtn.style.marginTop = '20px';
                                pdfAllBtn.addEventListener('click', saveFullScheduleAsPDF);
                                container.appendChild(pdfAllBtn);

                                // --- EXAM TABLE (Raspored Kolokvijuma) ---
                                if (data.exams && data.exams.length > 0) {
                                    const exWrapper = buildSimpleScheduleTable('Raspored kolokvijuma (Ispitni rokovi)', data.exams, 'exams');
                                    container.appendChild(exWrapper);
                                }

                                // Vrati dugme u normalno stanje
                                button.disabled = false;
                                button.textContent = 'Generiši raspored časova';
                                button.classList.remove('loading');

                            } catch (e) {
                                statusDiv.innerHTML = '<p style="color: #ef4444; padding: 12px; background: rgba(239, 68, 68, 0.1); border-radius: 8px; border: 1px solid #ef4444;">Greška pri generisanju rasporeda: ' + e.message + '</p>';
                                button.disabled = false;
                                button.textContent = 'Generiši raspored časova';
                                button.classList.remove('loading');
                            }
                        });
                    </script>
                    <script>
                        function saveFullScheduleAsPDF() {
                            const {jsPDF} = window.jspdf;
                            const doc = new jsPDF('landscape', 'pt', 'a4');
                            doc.setFont("DejaVuSans", "normal");
                            let y = 40;

                            const normalizeCellText = (text) => (text || '')
                                .replace(/\r/g, '')
                                .replace(/[ \t]+/g, ' ')
                                .replace(/\n\s+/g, '\n')
                                .trim();
                            const getCellText = (td) => {
                                if (!td) return '';
                                const html = td.innerHTML || '';
                                if (!html) return normalizeCellText(td.textContent || '');
                                const withBreaks = html.replace(/<br\s*\/?>/gi, '\n');
                                // textContent dekodira entitete (&amp; ...) iz escapovanih imena
                                const tmp = document.createElement('div');
                                tmp.innerHTML = withBreaks;
                                return normalizeCellText(tmp.textContent);
                            };
                            const baseStyles = {
                                font: "DejaVuSans",
                                fontSize: 9,
                                cellPadding: 4,
                                overflow: 'linebreak',
                                cellWidth: 'wrap',
                                valign: 'middle',
                                minCellHeight: 28
                            };

                            doc.setFontSize(18);
                            doc.text('Kompletan raspored časova', 40, y);
                            y += 30;

                            const tables = document.querySelectorAll('.schedule-table');

                            tables.forEach((table, index) => {
                                if (index > 0) {
                                    doc.addPage();
                                    y = 40;
                                }

                                // Naslov semestra (uzimamo h3 iznad tabele)
                                const title = table.previousSibling?.textContent || `Semestar ${index + 1}`;
                                doc.setFontSize(14);
                                doc.text(title, 40, y);
                                y += 20;

                                const headers = [];
                                const rows = [];

                                table.querySelectorAll('thead th').forEach(th => {
                                    headers.push(normalizeCellText(th.textContent));
                                });

                                table.querySelectorAll('tbody tr').forEach(tr => {
                                    const row = [];
                                    tr.querySelectorAll('td').forEach(td => {
                                        row.push(getCellText(td));
                                    });
                                    rows.push(row);
                                });

                                const pageWidth = doc.internal.pageSize.getWidth();
                                const marginX = 40;
                                const usableWidth = pageWidth - (marginX * 2);
                                const colCount = headers.length;
                                const timeColWidth = 70;
                                const otherColWidth = colCount > 1
                                    ? Math.floor((usableWidth - timeColWidth) / (colCount - 1))
                                    : usableWidth;

                                const columnStyles = {0: {cellWidth: timeColWidth}};
                                for (let i = 1; i < colCount; i++) {
                                    columnStyles[i] = {cellWidth: otherColWidth};
                                }

                                doc.autoTable({
                                    head: [headers],
                                    body: rows,
                                    startY: y,
                                    tableWidth: usableWidth,
                                    styles: baseStyles,
                                    headStyles: {
                                        ...baseStyles,
                                        fillColor: [15, 23, 42] // tamna (kao tvoj UI)
                                    },
                                    bodyStyles: baseStyles,
                                    columnStyles
                                });
                            });

                            doc.save('kompletan_raspored_casova.pdf');
                        }
                    </script>
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>


                    <script>
                        function saveTableAsPDF(table, semester) {
                            const {jsPDF} = window.jspdf;
                            const doc = new jsPDF('landscape', 'pt', 'a4');

                            // AKTIVIRAJ FONT
                            doc.setFont("DejaVuSans", "normal");
                            doc.setFontSize(16);
                            doc.text(`Raspored časova – ${semester}. semestar`, 40, 40);
                            let startY = 70;

                            const normalizeCellText = (text) => (text || '')
                                .replace(/\r/g, '')
                                .replace(/[ \t]+/g, ' ')
                                .replace(/\n\s+/g, '\n')
                                .trim();
                            const getCellText = (td) => {
                                if (!td) return '';
                                const html = td.innerHTML || '';
                                if (!html) return normalizeCellText(td.textContent || '');
                                const withBreaks = html.replace(/<br\s*\/?>/gi, '\n');
                                // textContent dekodira entitete (&amp; ...) iz escapovanih imena
                                const tmp = document.createElement('div');
                                tmp.innerHTML = withBreaks;
                                return normalizeCellText(tmp.textContent);
                            };
                            const baseStyles = {
                                font: "DejaVuSans",
                                fontSize: 9,
                                cellPadding: 4,
                                overflow: 'linebreak',
                                cellWidth: 'wrap',
                                valign: 'middle',
                                minCellHeight: 28
                            };

                            const rows = [];
                            const headers = [];

                            // headeri
                            table.querySelectorAll('thead th').forEach(th => {
                                headers.push(normalizeCellText(th.textContent));
                            });

                            // redovi
                            table.querySelectorAll('tbody tr').forEach(tr => {
                                const row = [];
                                tr.querySelectorAll('td').forEach(td => {
                                    row.push(getCellText(td));
                                });
                                rows.push(row);
                            });

                            const pageWidth = doc.internal.pageSize.getWidth();
                            const marginX = 40;
                            const usableWidth = pageWidth - (marginX * 2);
                            const colCount = headers.length;
                            const timeColWidth = 70;
                            const otherColWidth = colCount > 1
                                ? Math.floor((usableWidth - timeColWidth) / (colCount - 1))
                                : usableWidth;

                            const columnStyles = {0: {cellWidth: timeColWidth}};
                            for (let i = 1; i < colCount; i++) {
                                columnStyles[i] = {cellWidth: otherColWidth};
                            }

                            doc.autoTable({
                                head: [headers],
                                body: rows,
                                startY: startY,
                                tableWidth: usableWidth,
                                styles: baseStyles,
                                headStyles: {
                                    ...baseStyles,
                                    fillColor: [22, 101, 52]
                                },
                                bodyStyles: baseStyles,
                                columnStyles
                            });

                            doc.save(`raspored_semestar_${semester}.pdf`);
                        }
                    </script>

                    <script>
                        // Sve kolokvijume (K1 + K2) i završni ispit, grupisano po semestru - jedna
                        // stranica po semestru koji ima podataka, sortirano po datumu unutar semestra.
                        // Koristi window.allExams (isti podaci koje puni renderScheduleData) umjesto
                        // trenutno prikazane sedmične mreže, da PDF ne zavisi od toga koji je Tip/Semestar
                        // trenutno izabran u filterima.
                        function saveColloquiumScheduleAsPDF() {
                            const events = window.allExams || [];
                            if (events.length === 0) {
                                alert('Nema podataka za PDF - prvo generišite kolokvijume.');
                                return;
                            }

                            const {jsPDF} = window.jspdf;
                            const doc = new jsPDF('landscape', 'pt', 'a4');
                            doc.setFont("DejaVuSans", "normal");

                            const typeLabels = {COLLOQUIUM_1: 'Kolokvijum 1', COLLOQUIUM_2: 'Kolokvijum 2', EXAM: 'Završni ispit'};
                            const dayNames = ['', 'Ponedjeljak', 'Utorak', 'Srijeda', 'Četvrtak', 'Petak', 'Subota', 'Nedjelja'];
                            const baseStyles = {
                                font: "DejaVuSans",
                                fontSize: 9,
                                cellPadding: 4,
                                overflow: 'linebreak',
                                cellWidth: 'wrap',
                                valign: 'middle',
                                minCellHeight: 24
                            };

                            let wroteAnyPage = false;

                            for (let sem = 1; sem <= 6; sem++) {
                                const semEvents = events
                                    .filter(e => e.semester === sem)
                                    .slice()
                                    .sort((a, b) => {
                                        if (a.date !== b.date) return a.date < b.date ? -1 : 1;
                                        return a.start.localeCompare(b.start);
                                    });

                                if (semEvents.length === 0) continue;

                                if (wroteAnyPage) doc.addPage();
                                wroteAnyPage = true;

                                doc.setFontSize(16);
                                doc.text(`Kolokvijumi i završni ispit – ${sem}. semestar`, 40, 40);

                                const headers = ['Datum', 'Dan', 'Vrijeme', 'Tip', 'Predmet', 'Sala'];
                                const rows = semEvents.map(e => [
                                    e.date || '-',
                                    dayNames[e.day] || '',
                                    e.start + ' - ' + e.end,
                                    typeLabels[e.type] || e.type,
                                    e.course,
                                    e.is_online ? 'ONLINE' : (e.room || '')
                                ]);

                                const pageWidth = doc.internal.pageSize.getWidth();
                                const marginX = 40;
                                const usableWidth = pageWidth - (marginX * 2);

                                doc.autoTable({
                                    head: [headers],
                                    body: rows,
                                    startY: 70,
                                    tableWidth: usableWidth,
                                    styles: baseStyles,
                                    headStyles: {
                                        ...baseStyles,
                                        fillColor: [147, 51, 234] // ljubicasta (kao dugme 'Generiši kolokvijume')
                                    },
                                    bodyStyles: baseStyles
                                });
                            }

                            if (!wroteAnyPage) {
                                alert('Nema podataka za PDF - prvo generišite kolokvijume.');
                                return;
                            }

                            doc.save('kolokvijumi_i_zavrsni_ispiti.pdf');
                        }

                        document.addEventListener('DOMContentLoaded', () => {
                            const collPdfBtn = document.getElementById('colloquium-pdf-btn');
                            if (collPdfBtn) collPdfBtn.addEventListener('click', saveColloquiumScheduleAsPDF);
                        });
                    </script>

                    <script>
                        document.getElementById('generate-colloquiums').addEventListener('click', async () => {
                            const button = document.getElementById('generate-colloquiums');
                            const statusDiv = document.getElementById('schedule-status');
                            const container = document.getElementById('schedule-container');

                            // if(!confirm("Da li ste sigurni da želite generisati kolokvijume?")) return;

                            button.disabled = true;
                            const originalText = button.textContent;
                            button.textContent = 'Generiše se...';

                            statusDiv.style.display = 'block';
                            statusDiv.innerHTML = '<p style="color: #9333ea;">Generisanje kolokvijuma u toku, molimo sačekajte...</p>';

                            try {
                                // Pokreni generisanje - ovo se odmah vraća, Java radi u pozadini
                                // (vidi admin_panel.php akciju 'generatecolloquiums' - proc_open, ne blokira).
                                const res = await fetch('admin_panel.php?action=generatecolloquiums');
                                if (!res.ok) throw new Error('HTTP error ' + res.status);

                                const text = await res.text();
                                let data;
                                try {
                                    data = JSON.parse(text);
                                } catch (e) {
                                    throw new Error('Invalid JSON: ' + text.substring(0, 100));
                                }

                                if (data.status === 'error') {
                                    statusDiv.innerHTML = '<p style="color: #ef4444; padding: 12px; background: rgba(239, 68, 68, 0.1); border-radius: 8px; border: 1px solid #ef4444;">Greška: ' + data.message + '</p>';
                                    return;
                                }

                                // Pollinguj dok Java piše napredak u storage/colloquium_progress.json.
                                const finalData = await new Promise((resolve, reject) => {
                                    const startedAt = Date.now();
                                    const maxWaitMs = 10 * 60 * 1000; // safety - ne pollinguj vjecno ako proces crash-uje

                                    const poll = async () => {
                                        try {
                                            const pollRes = await fetch('admin_panel.php?action=generatecolloquiums_status');
                                            if (!pollRes.ok) {
                                                throw new Error('HTTP greška pri provjeri napretka: ' + pollRes.status);
                                            }
                                            const pollData = await pollRes.json();

                                            if (pollData.done) {
                                                resolve(pollData);
                                                return;
                                            }

                                            statusDiv.innerHTML = '<p style="color: #9333ea;">' + (pollData.message || 'Generisanje kolokvijuma u toku, molimo sačekajte...') + '</p>';

                                            if (Date.now() - startedAt > maxWaitMs) {
                                                reject(new Error('Generisanje predugo traje (preko 10 minuta) - proverite server log.'));
                                                return;
                                            }

                                            setTimeout(poll, 1500);
                                        } catch (pollError) {
                                            reject(pollError);
                                        }
                                    };
                                    poll();
                                });

                                if (finalData.success === false) {
                                    statusDiv.innerHTML = '<p style="color: #ef4444; padding: 12px; background: rgba(239, 68, 68, 0.1); border-radius: 8px; border: 1px solid #ef4444;">Greška: ' + (finalData.message || finalData.error) + '</p>';
                                    return;
                                }

                                statusDiv.innerHTML = '<p style="color: #22c55e; padding: 12px; background: rgba(34, 197, 94, 0.1); border-radius: 8px; border: 1px solid #22c55e;">✓ ' + finalData.message + '</p>';

                                // Refresh schedule
                                const schedRes = await fetch('admin_panel.php?action=getschedule');
                                const schedData = await schedRes.json();
                                if (schedData.error) {
                                    statusDiv.innerHTML += '<p style="color: #ef4444;">Greška pri učitavanju rasporeda: ' + schedData.error + '</p>';
                                } else {
                                    container.style.display = 'block';
                                    if (typeof renderScheduleData === 'function') {
                                        renderScheduleData(schedData);
                                    } else {
                                        console.error('renderScheduleData function not found');
                                    }
                                }
                            } catch (e) {
                                statusDiv.innerHTML = '<p style="color: #ef4444; padding: 12px; background: rgba(239, 68, 68, 0.1); border-radius: 8px; border: 1px solid #ef4444;">Greška: ' + e.message + '</p>';
                                console.error(e);
                            } finally {
                                button.disabled = false;
                                button.textContent = originalText;
                            }
                        });

                        // COLLOQUIUM RENDERING LOGIC
                        function renderColloquiums() {
                            const container = document.getElementById('colloquium-container');
                            const typeSelect = document.getElementById('coll-type-select');
                            const semSelect = document.getElementById('coll-sem-select');

                            if (!container || !window.allExams || !typeSelect || !semSelect) return;

                            const type = typeSelect.value;
                            const sem = parseInt(semSelect.value);

                            // Filter events
                            const filtered = window.allExams.filter(e => e.type === type && e.semester === sem);

                            container.innerHTML = '';

                            if (filtered.length === 0) {
                                container.innerHTML = '<p style="color:#aaa; text-align:center; padding:20px;">Nema pronađenih kolokvijuma za odabrane kriterijume.</p>';
                                return;
                            }

                            // Group by Week
                            const getMonday = (d) => {
                                d = new Date(d);
                                const day = d.getDay(), diff = d.getDate() - day + (day == 0 ? -6 : 1);
                                const m = new Date(d.setDate(diff));
                                return m.toISOString().slice(0, 10);
                            };

                            const groups = {};
                            filtered.forEach(ev => {
                                const mon = getMonday(ev.date);
                                if (!groups[mon]) groups[mon] = [];
                                groups[mon].push(ev);
                            });

                            const sortedWeeks = Object.keys(groups).sort();

                            sortedWeeks.forEach((weekStart, idx) => {
                                const events = groups[weekStart];
                                const weekDiv = document.createElement('div');
                                weekDiv.className = 'semester-wrapper';
                                weekDiv.style.marginBottom = '30px';
                                // Gold border override
                                weekDiv.style.border = '2px solid #ecc94b';
                                weekDiv.style.padding = '15px';
                                weekDiv.style.borderRadius = '8px';
                                weekDiv.style.background = '#1a1a1a';

                                const h4 = document.createElement('h3');
                                h4.style.textAlign = 'center';
                                h4.style.color = '#ecc94b';
                                h4.style.marginBottom = '15px';
                                h4.innerHTML = `Sedmica ${idx + 1} <small style='color:#ccc; font-weight:normal; font-size:0.7em;'>(Početak sedmice: ${weekStart})</small>`;
                                weekDiv.appendChild(h4);

                                // Build Grid Table
                                const table = document.createElement('table');
                                table.className = 'schedule-table';
                                table.style.width = '100%';
                                table.style.borderCollapse = 'collapse';

                                // Header
                                const thead = document.createElement('thead');
                                const trH = document.createElement('tr');
                                ['Vrijeme', 'Ponedjeljak', 'Utorak', 'Srijeda', 'Četvrtak', 'Petak', 'Subota'].forEach(h => {
                                    const th = document.createElement('th');
                                    th.textContent = h;
                                    th.style.borderBottom = '1px solid #ecc94b';
                                    trH.appendChild(th);
                                });
                                thead.appendChild(trH);
                                table.appendChild(thead);

                                // Body
                                const tbody = document.createElement('tbody');
                                // Get unique time slots for this week
                                const slots = Array.from(new Set(events.map(e => e.start + '-' + e.end))).sort();

                                slots.forEach(slot => {
                                    const tr = document.createElement('tr');
                                    const tdTime = document.createElement('td');
                                    tdTime.textContent = slot;
                                    tdTime.classList.add('no-drag');
                                    tr.appendChild(tdTime);

                                    // e.day je vec broj 1..7 (admin_panel.php normalizuje preko
                                    // dayNameToWeekdayNum prije slanja - baza cuva ime dana kao
                                    // varchar, npr. "ponedeljak", ne "Ponedjeljak").
                                    const getDayIdx = (day) => {
                                        const n = parseInt(day, 10);
                                        return Number.isFinite(n) ? n : 0;
                                    };

                                    for (let d = 1; d <= 6; d++) {
                                        const td = document.createElement('td');
                                        const cellEvs = events.filter(e => getDayIdx(e.day) === d && (e.start + '-' + e.end) === slot);

                                        if (cellEvs.length > 0) {
                                            td.innerHTML = cellEvs.map(e => `<strong style="color:#ecc94b">${escapeHtml(e.course)}</strong><br>(${e.is_online ? 'ONLINE' : escapeHtml(e.room || '')})`).join('<br>'); // Highlight course
                                            // Make cell border subtle but distinct
                                            td.style.border = '1px solid #444';
                                        }
                                        tr.appendChild(td);
                                    }
                                    tbody.appendChild(tr);
                                });

                                table.appendChild(tbody);
                                weekDiv.appendChild(table);
                                container.appendChild(weekDiv);
                            });
                        }

                        document.addEventListener('DOMContentLoaded', () => {
                            const cType = document.getElementById('coll-type-select');
                            const cSem = document.getElementById('coll-sem-select');
                            if (cType) cType.addEventListener('change', renderColloquiums);
                            if (cSem) cSem.addEventListener('change', renderColloquiums);
                        });
                    </script>
                    <?php
                    break;

                case 'zauzetost':
                    // 1. Get Academic Year
                    $year_id = 0;
                    $year_label = "Nije definisana";
                    $stmtYear = $pdo->query("SELECT id, year_label FROM academic_year WHERE is_active = TRUE LIMIT 1");
                    if ($y = $stmtYear->fetch()) {
                        $year_id = $y['id'];
                        $year_label = $y['year_label'];
                    }

                    // 2. Define Time Slots
                    $slots = [
                        ['08:00', '09:00'], ['09:00', '10:00'], ['10:00', '11:00'],
                        ['11:00', '12:00'], ['12:00', '13:00'], ['13:00', '14:00'],
                        ['14:00', '15:00'], ['15:00', '16:00'], ['16:00', '17:00'],
                        ['17:00', '18:00'], ['18:00', '19:00'], ['19:00', '20:00'],
                        ['20:00', '21:00']
                    ];

                    // 3. Get Rooms
                    $rooms = $occupancyService->getRooms();

                    // 4. Get Occupancy Data
                    $occupancy = ($year_id > 0) ? $occupancyService->getOccupancy($year_id) : [];

                    $days = [
                        1 => 'PONEDJELJAK',
                        2 => 'UTORAK',
                        3 => 'SRIJEDA',
                        4 => 'ČETVRTAK',
                        5 => 'PETAK'
                    ];
                    ?>

                    <div class="occupancy-header">
                        <h2>Zauzetost sala - Akademska godina: <?= htmlspecialchars($year_label) ?></h2>
                        <p class="info-text">Kliknite na polje (ili prevucite preko više polja) da biste rezervisali
                            termin za fakultet.</p>
                        <p class="info-text" style="color:#f0b429;">
                            <strong>Napomena:</strong> algoritam za generisanje rasporeda časova sada izbjegava sale
                            koje su ovdje označene kao zauzete od strane <em>drugih</em> fakulteta (FEB, MTS, PF, FSJ,
                            FVU). Termini označeni kao <strong>FIT</strong> namjerno se <em>ne</em> tretiraju kao
                            prepreka - to je sopstvena zauzetost FIT-a (npr. već generisan raspored), pa ne bi imalo
                            smisla da FIT-ov algoritam sam sebe blokira. Ako ovdje FIT pokazuje zauzetost skoro cijelog
                            radnog dana u skoro svakoj sali, to je vjerovatno test/probni podatak, ne stvarna
                            rezervacija - vrijedi pregledati i po potrebi obrisati.
                        </p>
                    </div>

                    <div class="legend-container">
                        <div class="legend-item">
                            <div class="legend-color faculty-fit"></div>
                            <span>FIT</span></div>
                        <div class="legend-item">
                            <div class="legend-color faculty-feb"></div>
                            <span>FEB</span></div>
                        <div class="legend-item">
                            <div class="legend-color faculty-mts"></div>
                            <span>MTS</span></div>
                        <div class="legend-item">
                            <div class="legend-color faculty-pf"></div>
                            <span>PF</span></div>
                        <div class="legend-item">
                            <div class="legend-color faculty-fsj"></div>
                            <span>FSJ</span></div>
                        <div class="legend-item">
                            <div class="legend-color faculty-fvu"></div>
                            <span>FVU</span></div>
                    </div>

                    <div class="occupancy-container" id="occupancy-grid-container">
                        <table class="occupancy-table" id="occupancy-table">
                            <thead>
                            <tr>
                                <th rowspan="2" class="time-col">Vrijeme</th>
                                <?php foreach ($days as $dayNum => $dayName): ?>
                                    <th colspan="<?= count($rooms) ?>"><?= $dayName ?></th>
                                <?php endforeach; ?>
                            </tr>
                            <tr>
                                <?php foreach ($days as $dayNum => $dayName): ?>
                                    <?php foreach ($rooms as $room): ?>
                                        <th><?= htmlspecialchars($room['code']) ?></th>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($slots as $slot): ?>
                                <tr>
                                    <td class="time-col"><?= $slot[0] ?> - <?= $slot[1] ?></td>
                                    <?php foreach ($days as $dayNum => $dayName): ?>
                                        <?php foreach ($rooms as $room): ?>
                                            <?php
                                            $key = $room['id'] . '-' . $dayNum . '-' . $slot[0];
                                            $occ = $occupancy[$key] ?? null;
                                            $class = "";
                                            if ($occ) {
                                                $class = "faculty-" . strtolower($occ['faculty_code']);
                                            }
                                            ?>
                                            <td class="occupancy-cell <?= $class ?>"
                                                data-room-id="<?= $room['id'] ?>"
                                                data-weekday="<?= $dayNum ?>"
                                                data-start="<?= $slot[0] ?>"
                                                data-end="<?= $slot[1] ?>"
                                                data-faculty="<?= $occ ? htmlspecialchars($occ['faculty_code']) : '' ?>"
                                                title="<?= $occ ? "Zauzeto: {$occ['faculty_code']} ({$occ['source_type']})" : "Slobodno" ?>">
                                                <?php if ($occ): ?>
                                                    <div class="cell-info"><?= htmlspecialchars($occ['faculty_code']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Occupancy Modal -->
                    <div id="occupancyModal" class="occupancy-modal">
                        <div class="occupancy-modal-content">
                            <h3>Upravljanje terminom</h3>
                            <p id="modal-selection-info"></p>
                            <form id="occupancyForm" method="post">
                                <input type="hidden" name="action" value="save_occupancy">
                                <input type="hidden" name="academic_year_id" value="<?= $year_id ?>">
                                <input type="hidden" name="selections" id="selections-input">

                                <label for="faculty_code_select">Odaberite fakultet:</label>
                                <select name="faculty_code" id="faculty_code_select" class="form-control"
                                        style="margin-bottom: 15px;" required>
                                    <option value="" selected disabled>-- Odaberite fakultet --</option>
                                    <option value="FIT">FIT (Fakultet za informacione tehnologije)</option>
                                    <option value="FEB">FEB (Fakultet za ekonomiju i biznis)</option>
                                    <option value="MTS">MTS (Fakultet za mediteranske poslovne studije)</option>
                                    <option value="PF">PF (Pravni fakultet)</option>
                                    <option value="FSJ">FSJ (Fakultet za strane jezike)</option>
                                    <option value="FVU">FVU (Fakultet vizuelnih umjetnosti)</option>
                                </select>

                                <div id="fit-warning"
                                     style="display:none; color: #ffa500; font-size: 0.8rem; margin-bottom: 10px;">
                                    Napomena: Promjene za FIT je preporučljivo vršiti kroz automatski generator
                                    rasporeda.
                                </div>

                                <div style="display: flex; gap: 10px;">
                                    <button type="button" class="btn btn-secondary" style="flex: 1;"
                                            onclick="closeOccupancyModal()">Otkaži
                                    </button>
                                    <button type="button" id="btn-delete" class="btn btn-danger"
                                            style="flex: 1; background: #dc3545; border: none; color: white;">Obriši
                                    </button>
                                    <button type="submit" class="btn btn-primary"
                                            style="flex: 1; background: var(--accent); border: none;">Sačuvaj
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            const table = document.getElementById('occupancy-table');
                            const cells = table.querySelectorAll('.occupancy-cell');
                            const modal = document.getElementById('occupancyModal');
                            const facultySelect = document.getElementById('faculty_code_select');
                            const btnDelete = document.getElementById('btn-delete');
                            const form = document.getElementById('occupancyForm');
                            const btnSave = form.querySelector('button[type="submit"]');

                            // Initial state: Disable save button
                            btnSave.disabled = true;

                            // Enable save only when a faculty is selected
                            facultySelect.addEventListener('change', function () {
                                if (this.value) {
                                    btnSave.disabled = false;
                                } else {
                                    btnSave.disabled = true;
                                }
                            });

                            btnDelete.addEventListener('click', function () {
                                // To remove reservation, we submit with EMPTY faculty code.
                                // But since select is required, we must disable validation or remove attribute momentarily
                                facultySelect.removeAttribute('required');
                                facultySelect.value = "";
                                form.submit();
                            });
                            const fitWarning = document.getElementById('fit-warning');
                            const selectionsInput = document.getElementById('selections-input');
                            const infoText = document.getElementById('modal-selection-info');

                            let isMouseDown = false;
                            let startCell = null;
                            let selectedCells = [];

                            cells.forEach(cell => {
                                cell.addEventListener('mousedown', function (e) {
                                    isMouseDown = true;
                                    startCell = this;
                                    clearSelection();
                                    toggleCellSelection(this);
                                });

                                cell.addEventListener('mouseenter', function () {
                                    if (isMouseDown) {
                                        toggleCellSelection(this);
                                    }
                                });
                            });

                            document.addEventListener('mouseup', function () {
                                if (isMouseDown) {
                                    isMouseDown = false;
                                    if (selectedCells.length > 0) {
                                        openOccupancyModal();
                                    }
                                }
                            });

                            function toggleCellSelection(cell) {
                                if (!selectedCells.includes(cell)) {
                                    selectedCells.push(cell);
                                    cell.classList.add('selected');
                                }
                            }

                            function clearSelection() {
                                selectedCells.forEach(c => c.classList.remove('selected'));
                                selectedCells = [];
                            }

                            function openOccupancyModal() {
                                const data = selectedCells.map(c => ({
                                    room_id: c.dataset.roomId,
                                    weekday: c.dataset.weekday,
                                    start: c.dataset.start,
                                    end: c.dataset.end
                                }));

                                selectionsInput.value = JSON.stringify(data);
                                infoText.innerText = `Odabrali ste ${selectedCells.length} termin(a).`;

                                // Preset the faculty if only one cell is selected and it's already occupied
                                if (selectedCells.length === 1) {
                                    facultySelect.value = selectedCells[0].dataset.faculty || "";
                                } else {
                                    facultySelect.value = "";
                                }

                                // Trigger change to update button state and warnings
                                facultySelect.dispatchEvent(new Event('change'));
                                modal.style.display = 'block';
                            }

                            window.closeOccupancyModal = function () {
                                modal.style.display = 'none';
                                clearSelection();
                            }

                            facultySelect.addEventListener('change', updateFitWarning);

                            function updateFitWarning() {
                                if (facultySelect.value === 'FIT') {
                                    fitWarning.style.display = 'block';
                                } else {
                                    fitWarning.style.display = 'none';
                                }
                            }

                            // Close modal on escape
                            document.addEventListener('keydown', (e) => {
                                if (e.key === 'Escape') closeOccupancyModal();
                            });
                        });
                    </script>
                    <?php
                    break;

                default:
                    // Ako stranica nije pronađena, prikaži početnu
                    echo "<script>window.location.href='?page=pocetna';</script>";
                    break;
                }
                ?>
    </main>

    <footer>
        <p>© <?php echo date('Y'); ?> Raspored Ispita | Admin Panel</p>
    </footer>

    </body>
</html>