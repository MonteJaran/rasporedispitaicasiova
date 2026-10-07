<?php

// =======================
// SESSION + DB CONNECTION
// =======================

session_start();
require_once __DIR__ . '/../../../config/dbconnection.php';

header('Content-Type: application/json; charset=utf-8');

// Osiguraj da dodatna polja za zahtjeve raspoloživosti postoje (idempotentno; ista
// šema kao u admin_panel.php, ali profesor može stići ovdje prvi u svježoj instanci).
try {
    $pdo->exec("ALTER TABLE professor_availability ADD COLUMN IF NOT EXISTS requires_computer_lab BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE professor_availability ADD COLUMN IF NOT EXISTS preferred_room_id BIGINT REFERENCES room(id)");
    $pdo->exec("ALTER TABLE professor_availability ADD COLUMN IF NOT EXISTS course_id BIGINT REFERENCES course(id)");
} catch (PDOException $e) {
    // Ignoriši - kolone već postoje ili nema dozvole da se doda (npr. druga sesija upravo radi isto)
}

// academic_event.day je varchar - Java upisuje ime dana ("ponedeljak"...), ali stariji
// redovi (generisani prije popravke weekday bug-a) mogu imati cifru kao string ("1".."5").
// (int)"ponedeljak" bi uvijek dalo 0, pa bi raspored ostao prazan za svježe generisane
// rasporede - ovo ispravno mapira oba oblika u 1..5.
function dayNameToWeekdayNum($day) {
    static $map = [
        'ponedeljak' => 1, 'monday' => 1,
        'utorak' => 2, 'tuesday' => 2,
        'srijeda' => 3, 'sreda' => 3, 'wednesday' => 3,
        'cetvrtak' => 4, 'četvrtak' => 4, 'thursday' => 4,
        'petak' => 5, 'friday' => 5,
    ];
    $key = mb_strtolower(trim((string)$day));
    if (isset($map[$key])) return $map[$key];
    return is_numeric($day) ? (int)$day : 0;
}

if (!isset($_SESSION['professor_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$professorId = (int)$_SESSION['professor_id'];


// =======================
// READ JSON INPUT
// =======================

$inputJSON = file_get_contents('php://input');
$inputData = json_decode($inputJSON, true);


// =======================
// ACTION ROUTER
// =======================

$action = $_GET['action'] ?? $inputData['action'] ?? null;

if (!$action) {
    echo json_encode(['error' => 'Missing action']);
    exit;
}


// =======================
// MAIN SWITCH
// =======================

switch ($action) {


// ==================================
// GET PROFESSOR SCHEDULE (calendar)
// ==================================

    case 'get_professor_schedule':

        try {
            // Admin zaključava zimski (1/3/5) i ljetnji (2/4/6) raspored nezavisno - mogu
            // biti iz dva različita generisana rasporeda (schedule_lock.php). Zato se za
            // svaki period posebno uzima najnoviji zaključani raspored; ako za period ništa
            // nije zaključano, prikazuje se najnoviji generisani (pregled prije zaključavanja).
            $termStmt = $pdo->query("
            SELECT
                (SELECT MAX(ae.schedule_id) FROM academic_event ae JOIN course c ON c.id = ae.course_id
                  WHERE ae.locked_by_admin = TRUE AND c.semester % 2 = 1
                    AND ae.type_enum IN ('LECTURE','EXERCISE','LAB')) AS winter_locked,
                (SELECT MAX(ae.schedule_id) FROM academic_event ae JOIN course c ON c.id = ae.course_id
                  WHERE ae.locked_by_admin = TRUE AND c.semester % 2 = 0
                    AND ae.type_enum IN ('LECTURE','EXERCISE','LAB')) AS summer_locked,
                (SELECT MAX(schedule_id) FROM academic_event
                  WHERE schedule_id IS NOT NULL AND type_enum IN ('LECTURE','EXERCISE','LAB')) AS latest
        ");
            $terms = $termStmt->fetch(PDO::FETCH_ASSOC);
            $winterId = (int)($terms['winter_locked'] ?: $terms['latest']);
            $summerId = (int)($terms['summer_locked'] ?: $terms['latest']);

            if (!$winterId && !$summerId) {
                echo json_encode([
                    'schedules' => [],
                    'schedule_ids' => []
                ]);
                break;
            }

            $scheduleIds = array_values(array_unique([$winterId, $summerId]));
            $stmt = $pdo->prepare("
            SELECT
                ae.schedule_id,
                ae.day,
                ae.starts_at,
                ae.ends_at,
                c.name AS coursename,
                r.code AS roomcode,
                c.semester,
                ae.type_enum,
                COALESCE(string_agg(DISTINCT p.full_name, ', ') FILTER (WHERE cp.is_assistant = FALSE), '') AS professors,
                COALESCE(string_agg(DISTINCT p.full_name, ', ') FILTER (WHERE cp.is_assistant = TRUE), '') AS assistants
            FROM academic_event ae
            JOIN course c ON ae.course_id = c.id
            LEFT JOIN room r ON ae.room_id = r.id
            LEFT JOIN course_professor cp ON cp.course_id = c.id
            LEFT JOIN professor p ON p.id = cp.professor_id
            WHERE (ae.created_by_professor = ?
               OR EXISTS (
                   SELECT 1 FROM event_professor ep
                   WHERE ep.event_id = ae.id
                   AND ep.professor_id = ?
               ))
              AND ae.type_enum IN ('LECTURE','EXERCISE','LAB')
              AND ((ae.schedule_id = ? AND c.semester % 2 = 1) OR (ae.schedule_id = ? AND c.semester % 2 = 0))
            GROUP BY ae.id, ae.schedule_id, ae.day, ae.starts_at, ae.ends_at, c.name, r.code, c.semester, ae.type_enum
            ORDER BY ae.schedule_id, c.semester, ae.day, ae.starts_at
        ");

            $params = [$professorId, $professorId, $winterId, $summerId];
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $data = [
                'schedules' => [],
                'schedule_ids' => $scheduleIds
            ];

            foreach ($scheduleIds as $sid) {
                $data['schedules'][$sid] = [];
            }

            $typeLabels = ['LECTURE' => 'Predavanje', 'EXERCISE' => 'Vježbe', 'LAB' => 'Lab'];

            foreach ($rows as $row) {
                $sid = (int)$row['schedule_id'];
                $sem = (int)$row['semester'];

                if (!isset($data['schedules'][$sid][$sem])) {
                    $data['schedules'][$sid][$sem] = [];
                }

                $lecturerName = $row['type_enum'] === 'EXERCISE' || $row['type_enum'] === 'LAB'
                    ? ($row['assistants'] !== '' ? $row['assistants'] : $row['professors'])
                    : ($row['professors'] !== '' ? $row['professors'] : $row['assistants']);

                $data['schedules'][$sid][$sem][] = [
                    'day' => dayNameToWeekdayNum($row['day']),
                    'start' => substr($row['starts_at'], 11, 5),
                    'end' => substr($row['ends_at'], 11, 5),
                    'course' => $row['coursename'],
                    'room' => $row['roomcode'],
                    'type' => $row['type_enum'],
                    'type_label' => $typeLabels[$row['type_enum']] ?? $row['type_enum'],
                    'professor' => $lecturerName
                ];
            }

            echo json_encode($data, JSON_UNESCAPED_UNICODE);

        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }

        break;



// =======================
// GET HOLIDAYS
// =======================

    case 'get_holidays':

        try {
            $stmt = $pdo->query("SELECT date, name, is_working_day FROM holiday");
            $holidays = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($holidays as &$h) {
                $h['is_working_day'] = (int)$h['is_working_day'] === 1;
            }
            unset($h);
            echo json_encode($holidays);
        } catch (PDOException $e) {
            echo json_encode([]);
        }

        break;



// =======================
// SAVE NOTE
// =======================

    case 'save_note':

        $date = $inputData['date'] ?? null;
        $content = trim($inputData['content'] ?? '');

        if (!$date || $content === '') {
            echo json_encode(['success' => false]);
            break;
        }

        $stmt = $pdo->prepare("
        INSERT INTO notes (professor_id, note_date, content)
        VALUES (?, ?, ?)
    ");
        $stmt->execute([$professorId, $date, $content]);

        echo json_encode([
            'success' => true,
            'id' => $pdo->lastInsertId()
        ]);

        break;



// =======================
// UPDATE NOTE
// =======================

    case 'update_note':

        $id = $inputData['id'] ?? null;
        $content = trim($inputData['content'] ?? '');

        if (!$id || $content === '') {
            echo json_encode(['success' => false]);
            break;
        }

        $stmt = $pdo->prepare("
        UPDATE notes
        SET content = ?
        WHERE id = ? AND professor_id = ?
    ");
        $stmt->execute([$content, $id, $professorId]);

        echo json_encode(['success' => true]);

        break;



// =======================
// GET NOTES
// =======================

    case 'get_notes':

        $stmt = $pdo->prepare("
        SELECT id, note_date, content
        FROM notes
        WHERE professor_id = ?
    ");
        $stmt->execute([$professorId]);

        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

        break;



// =======================
// DELETE NOTE
// =======================

    case 'delete_note':

        $id = $inputData['id'] ?? null;

        if (!$id) {
            echo json_encode(['success' => false]);
            break;
        }

        $stmt = $pdo->prepare("
        DELETE FROM notes
        WHERE id = ? AND professor_id = ?
    ");
        $stmt->execute([$id, $professorId]);

        echo json_encode(['success' => true]);

        break;



// =======================
// EVENTS SUMMARY
// =======================

    case 'get_professor_events_summary':

        try {
            $stmt = $pdo->prepare("
            SELECT 
                ae.date::date AS event_date,
                SUM(CASE WHEN ae.type_enum='EXAM' THEN 1 ELSE 0 END) AS exams,
                SUM(CASE WHEN ae.type_enum='COLLOQUIUM' THEN 1 ELSE 0 END) AS colloquiums,
                COUNT(*) AS total
            FROM academic_event ae
            WHERE (ae.created_by_professor = ?
               OR EXISTS (
                   SELECT 1 FROM event_professor ep
                   WHERE ep.event_id = ae.id
                   AND ep.professor_id = ?
               ))
              AND ae.type_enum IN ('EXAM','COLLOQUIUM')
            GROUP BY ae.date::date
        ");

            $stmt->execute([$professorId, $professorId]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

        } catch (PDOException $e) {
            echo json_encode([]);
        }

        break;



// =======================
// SAVE AVAILABILITY
// =======================

    case 'save_availability':

        $availability = $inputData['data'] ?? [];

        if (!is_array($availability) || count($availability) === 0) {
            echo json_encode(['success' => false]);
            break;
        }

        try {
            $pdo->beginTransaction();

            $pdo->prepare("
            DELETE FROM professor_availability
            WHERE professor_id = ?
        ")->execute([$professorId]);

            $stmt = $pdo->prepare("
            INSERT INTO professor_availability
            (professor_id, weekday, start_time, end_time, requires_computer_lab, preferred_room_id, course_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

            $count = 0;

            // Predmeti koje ovaj profesor stvarno drži - termin se može vezati samo za njih.
            $ownCoursesStmt = $pdo->prepare("SELECT course_id FROM course_professor WHERE professor_id = ?");
            $ownCoursesStmt->execute([$professorId]);
            $ownCourses = array_map('intval', $ownCoursesStmt->fetchAll(PDO::FETCH_COLUMN));

            foreach ($availability as $slot) {
                if (!is_array($slot)) continue;
                $day = (int)($slot['day'] ?? 0);
                if ($day < 1 || $day > 5) continue;

                // Ista validacija kao u professor_panel.php (HH:MM i od < do)
                $from = trim((string)($slot['from'] ?? ''));
                $to = trim((string)($slot['to'] ?? ''));
                if (!preg_match('/^\d{2}:\d{2}$/', $from) || !preg_match('/^\d{2}:\d{2}$/', $to) || $from >= $to) {
                    continue;
                }

                // Dodatni (opcioni) zahtjevi uz termin: neophodna rač. sala, izbor
                // konkretne sale, i vezivanje termina za konkretan predmet.
                // 1/0 a ne true/false: PDO pgsql šalje PHP false kao '' što PostgreSQL
                // odbija za boolean kolonu - zbog toga je ranije svako čuvanje padalo.
                $requiresComputerLab = !empty($slot['requires_computer_lab']) ? 1 : 0;
                $preferredRoomId = (isset($slot['preferred_room_id']) && (int)$slot['preferred_room_id'] > 0)
                    ? (int)$slot['preferred_room_id'] : null;
                $courseId = (isset($slot['course_id']) && in_array((int)$slot['course_id'], $ownCourses, true))
                    ? (int)$slot['course_id'] : null;

                $stmt->execute([
                    $professorId,
                    $day,
                    $from,
                    $to,
                    $requiresComputerLab,
                    $preferredRoomId,
                    $courseId
                ]);
                $count++;
            }

            if ($count === 0) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Termini nisu validni.']);
                break;
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'count' => $count]);

        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false]);
        }

        break;



// =======================
// SAVE COLLOQUIUM WEEKS
// =======================

    case 'save_colloquium_weeks':

        $rows = $inputData['data'] ?? [];

        if (!is_array($rows)) {
            echo json_encode(['success' => false]);
            break;
        }

        try {
            $pdo->beginTransaction();

            // Profesor smije mijenjati sedmice kolokvijuma samo za predmete koje drži
            // (ranije je mogao poslati bilo koji course_id i izmijeniti tuđi predmet).
            $stmt = $pdo->prepare("
            UPDATE course
            SET colloquium_1_week = ?, colloquium_2_week = ?, final_exam_week = ?
            WHERE id = ?
              AND EXISTS (SELECT 1 FROM course_professor cp WHERE cp.course_id = course.id AND cp.professor_id = ?)
        ");

            $count = 0;

            foreach ($rows as $r) {
                $id = (int)$r['course_id'];
                if (!$id) continue;

                $c1 = $r['colloquium_1_week'] ?: null;
                $c2 = $r['colloquium_2_week'] ?: null;
                $examWeek = $r['final_exam_week'] ?: null;

                $stmt->execute([$c1, $c2, $examWeek, $id, $professorId]);
                $count += $stmt->rowCount();
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'count' => $count]);

        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false]);
        }

        break;



// =======================
// UNKNOWN ACTION
// =======================

    default:
        echo json_encode(['error' => 'Unknown action']);

}
