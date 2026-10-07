<?php

class OccupancyService {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->ensureTableExists();
    }

    private function ensureTableExists() {
        $sql = "
            CREATE TABLE IF NOT EXISTS room_occupancy (
                id BIGSERIAL PRIMARY KEY,
                room_id BIGINT NOT NULL,
                weekday SMALLINT NOT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                faculty_code VARCHAR(20) NOT NULL,
                source_type VARCHAR(20) DEFAULT 'MANUAL',
                academic_year_id BIGINT NOT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_occupancy_room FOREIGN KEY (room_id) REFERENCES room(id),
                CONSTRAINT fk_occupancy_year FOREIGN KEY (academic_year_id) REFERENCES academic_year(id)
            );
        ";
        $this->pdo->exec($sql);
    }

    public function getActiveYear() {
        $stmt = $this->pdo->query("SELECT id, year_label FROM academic_year WHERE is_active = TRUE LIMIT 1");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getRooms() {
        $rooms = [];
        $stmt = $this->pdo->query("SELECT id, code FROM room WHERE is_active = TRUE ORDER BY code");
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rooms[] = $r;
        }
        return $rooms;
    }

    public function getOccupancy($yearId) {
        $occupancy = [];
        $stmt = $this->pdo->prepare("
            SELECT room_id, weekday, start_time, end_time, faculty_code, source_type 
            FROM room_occupancy 
            WHERE academic_year_id = ? AND is_active = TRUE
        ");
        $stmt->execute([$yearId]);
        
        while ($o = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Format key: room_id-weekday-start_time (HH:MM)
            $key = $o['room_id'] . '-' . $o['weekday'] . '-' . substr($o['start_time'], 0, 5);
            $occupancy[$key] = $o;
        }
        return $occupancy;
    }

    public function saveOccupancy($yearId, $selections, $facultyCode) {
        try {
            $this->pdo->beginTransaction();
            
            foreach ($selections as $sel) {
                $roomId   = (int)$sel['room_id'];
                $weekday  = (int)$sel['weekday'];
                $start    = $sel['start']; // Check format HH:MM
                $end      = $sel['end'];

                // 1. Delete existing active slot
                // We match start_time to identify the exact slot. 
                // OR we should perhaps delete valid overlaps? 
                // For simplicity and matching current logic, we delete exact matches on start_time.
                $stmtDel = $this->pdo->prepare("
                    DELETE FROM room_occupancy 
                    WHERE room_id = ? 
                      AND weekday = ? 
                      AND start_time = ? 
                      AND academic_year_id = ?
                ");
                $stmtDel->execute([$roomId, $weekday, $start, $yearId]);

                // 2. Insert new if faculty_code is set
                if (!empty($facultyCode)) {
                    $stmtIns = $this->pdo->prepare("
                        INSERT INTO room_occupancy 
                        (room_id, weekday, start_time, end_time, faculty_code, source_type, academic_year_id, is_active)
                        VALUES (?, ?, ?, ?, ?, 'MANUAL', ?, TRUE)
                    ");
                    $stmtIns->execute([$roomId, $weekday, $start, $end, $facultyCode, $yearId]);
                }
            }

            $this->pdo->commit();
            return ['success' => true];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
