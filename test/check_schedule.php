<?php
// Provjera invarijanti generisanog rasporeda časova (CLI).
//
// Upotreba:
//   php test/check_schedule.php                 -> posljednjih 6 rasporeda
//   php test/check_schedule.php 7 8 9           -> samo navedeni schedule_id-jevi
//   php test/check_schedule.php --json 7        -> mašinski čitljiv izlaz
//
// Izlazni kod je 1 ako bilo koji raspored krši TVRDO pravilo (sukob sale,
// profesora ili semestra, dnevni limit, fond časova, raspoloživost...).

$pdo = require __DIR__ . '/../config/dbconnection.php';

$args = array_slice($argv, 1);
$asJson = false;
$ids = [];
foreach ($args as $a) {
    if ($a === '--json') {
        $asJson = true;
    } elseif (ctype_digit($a)) {
        $ids[] = (int)$a;
    }
}
if (!$ids) {
    $ids = $pdo->query("SELECT DISTINCT schedule_id FROM academic_event
                        WHERE schedule_id IS NOT NULL AND type_enum IN ('LECTURE','EXERCISE','LAB')
                        ORDER BY schedule_id DESC LIMIT 6")->fetchAll(PDO::FETCH_COLUMN);
    $ids = array_reverse(array_map('intval', $ids));
}

const DAY_NUM = ['ponedeljak' => 1, 'utorak' => 2, 'srijeda' => 3, 'cetvrtak' => 4, 'petak' => 5];
const MAX_DAILY_HOURS = 6;

function t2m($t) { [$h, $m] = explode(':', substr($t, 0, 5)); return (int)$h * 60 + (int)$m; }
function overlap($a, $b) { return $a['s'] < $b['e'] && $b['s'] < $a['e']; }

// ---- statički podaci ----
$courses = [];
foreach ($pdo->query("SELECT id, name, semester, lectures_per_week p, exercises_per_week v, labs_per_week l,
                             is_online, is_active, COALESCE(requires_computer_lab,false) rcl,
                             expected_students, COALESCE(parallel_groups,1) pg
                      FROM course") as $c) {
    $courses[(int)$c['id']] = $c;
}
$rooms = [];
foreach ($pdo->query("SELECT id, code, capacity, is_computer_lab, is_active FROM room") as $r) {
    $rooms[(int)$r['id']] = $r;
}
$profActive = [];
foreach ($pdo->query("SELECT id, is_active FROM professor") as $p) {
    $profActive[(int)$p['id']] = (bool)$p['is_active'];
}
$courseProfs = []; // course -> ['p' => [...], 'a' => [...]]
foreach ($pdo->query("SELECT course_id, professor_id, is_assistant FROM course_professor ORDER BY id") as $cp) {
    $courseProfs[(int)$cp['course_id']][$cp['is_assistant'] ? 'a' : 'p'][] = (int)$cp['professor_id'];
}
$avail = []; // prof -> list of [day, s, e, course_id|null]
foreach ($pdo->query("SELECT professor_id, weekday, start_time, end_time, course_id,
                             COALESCE(requires_computer_lab, false) lab, preferred_room_id
                      FROM professor_availability") as $a) {
    $avail[(int)$a['professor_id']][] = [
        'day' => (int)$a['weekday'], 's' => t2m($a['start_time']), 'e' => t2m($a['end_time']),
        'course' => $a['course_id'] !== null ? (int)$a['course_id'] : null,
        'lab' => (bool)$a['lab'], 'room' => $a['preferred_room_id'] !== null ? (int)$a['preferred_room_id'] : null,
    ];
}
$occupancy = [];
$yearId = $pdo->query("SELECT id FROM academic_year WHERE is_active = TRUE LIMIT 1")->fetchColumn();
if ($yearId) {
    $st = $pdo->prepare("SELECT room_id, weekday, start_time, end_time FROM room_occupancy
                         WHERE academic_year_id = ? AND is_active = TRUE AND UPPER(faculty_code) <> 'FIT'");
    $st->execute([$yearId]);
    foreach ($st as $o) {
        $occupancy[] = ['room' => (int)$o['room_id'], 'day' => (int)$o['weekday'],
                        's' => t2m($o['start_time']), 'e' => t2m($o['end_time'])];
    }
}

// Termini koji važe za predmet: vezani za taj predmet, inače opšti (bez predmeta), inače svi.
function slotsForCourse($slots, $courseId) {
    $bound = array_values(array_filter($slots, fn($x) => $x['course'] === $courseId));
    if ($bound) return $bound;
    $general = array_values(array_filter($slots, fn($x) => $x['course'] === null));
    return $general ?: $slots;
}
// Da li je termin [s,e) dana $day pokriven raspoloživošću profesora (spojeni susjedni termini).
function coveredByAvailability($slots, $day, $s, $e, $courseId) {
    $use = slotsForCourse($slots, $courseId);
    $cur = $s;
    $iv = array_filter($use, fn($x) => $x['day'] === $day);
    usort($iv, fn($a, $b) => $a['s'] <=> $b['s']);
    foreach ($iv as $x) {
        if ($x['s'] <= $cur && $x['e'] > $cur) $cur = $x['e'];
        if ($cur >= $e) return true;
    }
    return $cur >= $e;
}
function dayInAvailability($slots, $day, $courseId) {
    $use = slotsForCourse($slots, $courseId);
    foreach ($use as $x) if ($x['day'] === $day) return true;
    return false;
}

$report = [];
$exit = 0;

foreach ($ids as $sid) {
    $st = $pdo->prepare("SELECT ae.id, ae.course_id, ae.created_by_professor prof, ae.type_enum type, ae.day,
                                ae.starts_at, ae.ends_at, ae.date, ae.room_id,
                                COALESCE((SELECT array_to_string(array_agg(ep.professor_id ORDER BY ep.professor_id), ',')
                                          FROM event_professor ep WHERE ep.event_id = ae.id), '') links
                         FROM academic_event ae
                         WHERE ae.schedule_id = ? AND ae.type_enum IN ('LECTURE','EXERCISE','LAB')
                         ORDER BY ae.day, ae.starts_at");
    $st->execute([$sid]);
    $events = [];
    $hard = [];
    $soft = [];
    foreach ($st as $r) {
        $dayNum = DAY_NUM[$r['day']] ?? 0;
        $e = [
            'id' => (int)$r['id'], 'course' => (int)$r['course_id'], 'prof' => (int)$r['prof'],
            'type' => $r['type'], 'day' => $dayNum, 'dayName' => $r['day'],
            's' => t2m(substr($r['starts_at'], 11, 5)), 'e' => t2m(substr($r['ends_at'], 11, 5)),
            'room' => $r['room_id'] !== null ? (int)$r['room_id'] : null,
            'links' => $r['links'] === '' ? [] : array_map('intval', explode(',', $r['links'])),
        ];
        $c = $courses[$e['course']] ?? null;
        $label = "#{$e['id']} " . ($c['name'] ?? '?') . " {$e['type']} {$r['day']} " . substr($r['starts_at'], 11, 5);
        $e['label'] = $label;
        if ($dayNum === 0) $hard[] = "neispravan dan '{$r['day']}': $label";
        if ((int)date('N', strtotime($r['starts_at'])) !== $dayNum) $hard[] = "starts_at datum nije isti dan u sedmici kao 'day': $label";
        if ($r['date'] !== substr($r['starts_at'], 0, 10)) $hard[] = "date != starts_at::date: $label";
        if ($e['s'] < 8 * 60 || $e['e'] > 17 * 60) $hard[] = "van okvira 08-17h: $label";
        if ($e['e'] - $e['s'] !== 60) $hard[] = "termin nije tačno 1h: $label";
        $events[] = $e;
    }

    // --- sukobi ---
    $n = count($events);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $a = $events[$i]; $b = $events[$j];
            if ($a['day'] !== $b['day'] || !overlap($a, $b)) continue;
            // zimski (neparni) i ljetnji (parni) semestar se ne održavaju istovremeno
            if ($courses[$a['course']]['semester'] % 2 !== $courses[$b['course']]['semester'] % 2) continue;
            if ($a['room'] !== null && $a['room'] === $b['room'])
                $hard[] = "SUKOB SALE {$rooms[$a['room']]['code']}: {$a['label']} <-> {$b['label']}";
            if ($a['prof'] === $b['prof'])
                $hard[] = "SUKOB PROFESORA (nosilac termina) {$a['prof']}: {$a['label']} <-> {$b['label']}";
            $common = array_intersect($a['links'], $b['links']);
            if ($common && $a['prof'] !== $b['prof'])
                $soft[] = "sukob su-predavača (event_professor " . implode(',', $common) . "): {$a['label']} <-> {$b['label']}";
            $ca = $courses[$a['course']]; $cb = $courses[$b['course']];
            if ($a['course'] !== $b['course'] && $ca['semester'] === $cb['semester'])
                $hard[] = "SUKOB SEMESTRA {$ca['semester']}: {$a['label']} <-> {$b['label']}";
        }
    }

    // --- dnevni limit po semestru ---
    $bySemDay = [];
    foreach ($events as $e) $bySemDay[$courses[$e['course']]['semester']][$e['day']][] = [$e['s'], $e['e']];
    foreach ($bySemDay as $sem => $days) {
        foreach ($days as $d => $iv) {
            usort($iv, fn($x, $y) => $x[0] <=> $y[0]);
            $tot = 0; [$cs, $ce] = $iv[0];
            foreach (array_slice($iv, 1) as [$s, $e]) {
                if ($s <= $ce) { $ce = max($ce, $e); } else { $tot += $ce - $cs; $cs = $s; $ce = $e; }
            }
            $tot += $ce - $cs;
            if ($tot > MAX_DAILY_HOURS * 60) $hard[] = "DNEVNI FOND semestar $sem dan $d = " . ($tot / 60) . "h > " . MAX_DAILY_HOURS;
        }
    }

    // --- po predmetu ---
    $byCourse = [];
    foreach ($events as $e) $byCourse[$e['course']][] = $e;
    $scheduled = 0;
    $missing = [];
    foreach ($courses as $cid => $c) {
        if (!$c['is_active']) {
            if (isset($byCourse[$cid])) $hard[] = "NEAKTIVAN predmet raspoređen: {$c['name']}";
            continue;
        }
        if (!isset($byCourse[$cid])) { $missing[] = $c['name']; continue; }
        $scheduled++;
        $evs = $byCourse[$cid];
        $cnt = ['LECTURE' => 0, 'EXERCISE' => 0, 'LAB' => 0];
        foreach ($evs as $e) $cnt[$e['type']]++;
        $pg = max(1, (int)$c['pg']);
        foreach (['LECTURE' => 'p', 'EXERCISE' => 'v', 'LAB' => 'l'] as $t => $k) {
            $want = (int)$c[$k];
            $got = $cnt[$t];
            // paralelne grupe su best-effort: dozvoljeno 1x..pg x fond
            if ($got < $want || $got > $want * $pg || ($want > 0 && $got % $want !== 0))
                $hard[] = "FOND $t za '{$c['name']}': očekivano $want (x$pg grupa), dobijeno $got";
        }
        // predavanja i vježbe na različitim danima (osim 4h predmeta u jednom bloku)
        $lecDays = array_unique(array_map(fn($e) => $e['day'], array_filter($evs, fn($e) => $e['type'] === 'LECTURE')));
        $exDays = array_unique(array_map(fn($e) => $e['day'], array_filter($evs, fn($e) => $e['type'] !== 'LECTURE')));
        $total = (int)$c['p'] + (int)$c['v'] + (int)$c['l'];
        if (count($lecDays) > 1) $hard[] = "predavanja '{$c['name']}' razbijena na više dana";
        if (count($exDays) > 1) $hard[] = "vježbe/lab '{$c['name']}' razbijene na više dana";
        if ($lecDays && $exDays && array_intersect($lecDays, $exDays) && $total !== 4)
            $hard[] = "'{$c['name']}' ($total h) ima predavanja i vježbe isti dan";

        foreach ($evs as $e) {
            // sala
            if ($c['is_online']) {
                if ($e['room'] !== null) $hard[] = "onlajn predmet dobio fizičku salu: {$e['label']}";
            } else {
                if ($e['room'] === null) { $hard[] = "predmet bez sale: {$e['label']}"; continue; }
                $room = $rooms[$e['room']];
                if (!$room['is_active']) $hard[] = "NEAKTIVNA sala {$room['code']}: {$e['label']}";
                if ($c['rcl'] && !$room['is_computer_lab']) $hard[] = "predmet traži rač. salu, dobio {$room['code']}: {$e['label']}";
                if ($e['type'] === 'LAB' && !$room['is_computer_lab']) $soft[] = "lab u ne-računarskoj sali {$room['code']}: {$e['label']}";
                $minCap = $c['expected_students'] ? (int)$c['expected_students'] : 0;
                if ($minCap > 0 && $e['type'] === 'LECTURE' && (int)$room['capacity'] < $minCap)
                    $hard[] = "kapacitet {$room['code']}={$room['capacity']} < očekivanih $minCap: {$e['label']}";
                foreach ($occupancy as $o) {
                    if ($o['room'] === $e['room'] && $o['day'] === $e['day'] && overlap($o, $e))
                        $hard[] = "sala zauzeta od drugog fakulteta: {$e['label']}";
                }
            }
            // profesor
            if (isset($profActive[$e['prof']]) && !$profActive[$e['prof']]) $soft[] = "neaktivan profesor {$e['prof']}: {$e['label']}";
            $roleKey = $e['type'] === 'LECTURE' ? 'p' : 'a';
            $allowed = array_merge($courseProfs[$cid]['p'] ?? [], $courseProfs[$cid]['a'] ?? []);
            if (!in_array($e['prof'], $allowed, true)) $hard[] = "nosilac termina {$e['prof']} nije dodijeljen predmetu: {$e['label']}";
            if (!$e['links']) $hard[] = "termin bez event_professor veze: {$e['label']}";
            foreach ($e['links'] as $l) if (!in_array($l, $allowed, true)) $hard[] = "event_professor $l nije na predmetu: {$e['label']}";

            // raspoloživost ostalih profesora vezanih za termin (su-predavači)
            foreach ($e['links'] as $l) {
                if ($l === $e['prof'] || empty($avail[$l])) continue;
                if (!coveredByAvailability($avail[$l], $e['day'], $e['s'], $e['e'], $cid))
                    $soft[] = "raspoloživost su-predavača $l: {$e['label']}";
            }

            // dodatni zahtjevi iz termina raspoloživosti nosioca: rač. sala (obavezno), željena sala (info)
            if ($e['room'] !== null) {
                foreach (slotsForCourse($avail[$e['prof']] ?? [], $cid) as $sl) {
                    if ($sl['day'] !== $e['day'] || !overlap($sl, $e)) continue;
                    if ($sl['lab'] && !$rooms[$e['room']]['is_computer_lab'])
                        $hard[] = "termin raspoloživosti traži rač. salu, dobijena {$rooms[$e['room']]['code']}: {$e['label']}";
                    if ($sl['room'] !== null && $sl['room'] !== $e['room'])
                        $soft[] = "željena sala {$rooms[$sl['room']]['code']} nije dobijena ({$rooms[$e['room']]['code']}): {$e['label']}";
                }
            }

            // raspoloživost nosioca termina (dan + vrijeme)
            $slots = $avail[$e['prof']] ?? [];
            if ($slots) {
                if (!dayInAvailability($slots, $e['day'], $cid))
                    $hard[] = "RASPOLOŽIVOST (dan) prof {$e['prof']}: {$e['label']}";
                elseif (!coveredByAvailability($slots, $e['day'], $e['s'], $e['e'], $cid))
                    $hard[] = "RASPOLOŽIVOST (vrijeme) prof {$e['prof']}: {$e['label']}";
            }
        }
    }

    $hard = array_values(array_unique($hard));
    $soft = array_values(array_unique($soft));
    if ($hard) $exit = 1;
    $active = count(array_filter($courses, fn($c) => $c['is_active']));
    $report[$sid] = [
        'events' => count($events), 'scheduled' => $scheduled, 'active_courses' => $active,
        'missing' => $missing, 'hard' => $hard, 'soft' => $soft,
    ];
}

if ($asJson) {
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    exit($exit);
}
foreach ($report as $sid => $r) {
    echo "=== schedule $sid: {$r['events']} termina, raspoređeno {$r['scheduled']}/{$r['active_courses']} predmeta, "
        . count($r['hard']) . " tvrdih / " . count($r['soft']) . " mekih prekršaja\n";
    if ($r['missing']) echo "  neraspoređeni: " . implode('; ', $r['missing']) . "\n";
    foreach (array_slice($r['hard'], 0, 25) as $h) echo "  [X] $h\n";
    if (count($r['hard']) > 25) echo "  ... još " . (count($r['hard']) - 25) . "\n";
    foreach (array_slice($r['soft'], 0, 10) as $s) echo "  [~] $s\n";
    if (count($r['soft']) > 10) echo "  ... još " . (count($r['soft']) - 10) . " mekih\n";
}
exit($exit);
