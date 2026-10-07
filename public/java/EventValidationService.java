import java.sql.*;
import java.time.*;
import java.time.format.DateTimeParseException;
import java.time.temporal.TemporalAdjusters;
import java.util.*;

public class EventValidationService {
    private Connection conn;
    private Map<Integer, Course> courses;
    private Map<Integer, Room> rooms;
    private Map<Integer, Professor> professors;
    private Map<Integer, AcademicEvent> academicEvents;
    private List<Holiday> holidays;
    private List<RoomOccupancySlot> roomOccupancy;
    private LocalDate winterStart;
    private LocalDate summerStart;
    private int activeAcademicYearId;

    // Keš popunjen jednim upitom po tabeli u preloadStaticLookups() umjesto
    // ponovljenih pojedinačnih SELECT-ova po predmetu/času - vidi taj metod.
    private Map<String, List<Integer>> courseProfessorsByCourseAndRole;
    // profesor -> [courseId ili -1, weekday, od (min), do (min), potrebna rač. sala (0/1), željena sala (0 = bilo koja)]
    private Map<Integer, List<int[]>> availabilityByProfessor;
    private boolean staticLookupsLoaded = false;

    // Prati se samo radi ScheduleProgress izvještaja (koji je od 6 rasporeda i
    // koji predmet po redu se trenutno obrađuje) - ne utiče na logiku raspoređivanja.
    private int currentScheduleIndex = 0;
    private int currentCourseCounter = 0;

    // academicEvents (učitan u konstruktoru) sadrži SVE istorijske termine iz
    // baze, bez WHERE-a - to su hiljade redova koji rastu sa svakim generisanjem.
    // hasConflictInSchedule/getSemesterHoursOnDay/isAlreadyScheduledInSchedule
    // filtriraju po scheduleId, pa im istorijski termini iz DRUGIH rasporeda
    // nikad nisu relevantni - skeniranje cijele mape na svaki poziv (a poziva se
    // hiljadama puta po generisanju) je bio glavni uzrok usporavanja koje raste
    // sa svakim novim generisanim rasporedom. Ovaj indeks drži SAMO termine
    // dodane TOKOM tekućeg pokretanja (populiše ga addEventToCache), grupisane
    // po scheduleId, tako da ta tri metoda skeniraju desetine/stotine umjesto
    // desetina hiljada termina.
    private Map<Integer, List<AcademicEvent>> eventsByScheduleIdThisRun = new HashMap<>();

    public EventValidationService(Connection connection) {
        this.conn = connection;
        this.courses = new HashMap<>();
        this.rooms = new HashMap<>();
        this.professors = new HashMap<>();
        this.academicEvents = new HashMap<>();
        this.holidays = new ArrayList<>();
        this.roomOccupancy = new ArrayList<>();
        this.courseProfessorsByCourseAndRole = new HashMap<>();
        this.availabilityByProfessor = new HashMap<>();
        loadDataFromDatabase();
    }

    /**
     * Učitava course_professor i professor_availability JEDNIM upitom po tabeli
     * (umjesto po-predmet/po-profesor upita koji se ponavljaju identično za
     * svih 6 generisanih rasporeda). Poziva se jednom na početku
     * generateSixSchedulesWithDifferentPriorities(); bezopasno je pozvati je
     * više puta (drugi poziv je no-op).
     */
    private void preloadStaticLookups() throws SQLException {
        if (staticLookupsLoaded) {
            return;
        }

        // Samo AKTIVNI profesori - deaktiviran profesor (npr. otišao sa fakulteta) ne smije
        // dobiti termine u novom rasporedu niti biti vezan za njih (event_professor).
        String cpQuery = "SELECT cp.course_id, cp.professor_id, cp.is_assistant FROM course_professor cp " +
                "JOIN professor p ON p.id = cp.professor_id WHERE p.is_active = TRUE ORDER BY cp.id";
        try (Statement stmt = conn.createStatement(); ResultSet rs = stmt.executeQuery(cpQuery)) {
            while (rs.next()) {
                String key = rs.getInt("course_id") + "_" + rs.getBoolean("is_assistant");
                courseProfessorsByCourseAndRole
                        .computeIfAbsent(key, k -> new ArrayList<>())
                        .add(rs.getInt("professor_id"));
            }
        }

        // Učitava se i VRIJEME (start_time/end_time), ne samo dan - generisani termin
        // mora cijelim trajanjem upasti u neki od profesorovih slobodnih termina
        // (vidi isProfessorAvailable), inače bi npr. "utorak 13-17h" dao čas utorkom u 8h.
        String availQuery = "SELECT professor_id, course_id, weekday, start_time, end_time, " +
                "COALESCE(requires_computer_lab, FALSE) AS requires_computer_lab, preferred_room_id " +
                "FROM professor_availability";
        try (Statement stmt = conn.createStatement(); ResultSet rs = stmt.executeQuery(availQuery)) {
            while (rs.next()) {
                int profId = rs.getInt("professor_id");
                int courseId = rs.getInt("course_id");
                if (rs.wasNull()) {
                    courseId = -1;
                }
                int weekday = rs.getInt("weekday");
                Time from = rs.getTime("start_time");
                Time to = rs.getTime("end_time");
                if (from == null || to == null) {
                    continue;
                }
                int fromMin = from.toLocalTime().toSecondOfDay() / 60;
                int toMin = to.toLocalTime().toSecondOfDay() / 60;
                if (toMin <= fromMin) {
                    continue; // neispravan termin (kraj prije početka) - ignoriši
                }
                int needsLab = rs.getBoolean("requires_computer_lab") ? 1 : 0;
                int preferredRoom = rs.getInt("preferred_room_id"); // NULL -> 0
                availabilityByProfessor
                        .computeIfAbsent(profId, k -> new ArrayList<>())
                        .add(new int[] { courseId, weekday, fromMin, toMin, needsLab, preferredRoom });
            }
        }

        staticLookupsLoaded = true;
        System.out.println("Preloaded static lookups: " + courseProfessorsByCourseAndRole.size()
                + " course/role grupa, " + availabilityByProfessor.size() + " profesora sa dostupnošću");
    }

    //region DataLoading

    private void loadDataFromDatabase() {
        try {
            loadAcademicYear();
            loadCourses();
            loadRooms();
            loadProfessors();
            loadAcademicEvents();
            loadHolidays();
        } catch (SQLException e) {
            System.err.println("Error loading data: " + e.getMessage());
        }
        // Odvojeno od gornjeg try/catch - zauzetost sala je opcioni/noviji podatak i ne
        // treba da prekine učitavanje ostatka ako tabela iz nekog razloga ne postoji.
        loadRoomOccupancy();
    }

    private void loadAcademicYear() throws SQLException {
        String query = "SELECT id, winter_semester_start, summer_semester_start FROM academic_year WHERE is_active = TRUE LIMIT 1";
        try (Statement stmt = conn.createStatement(); ResultSet rs = stmt.executeQuery(query)) {
            if (rs.next()) {
                this.activeAcademicYearId = rs.getInt("id");
                java.sql.Date wStart = rs.getDate("winter_semester_start");
                java.sql.Date sStart = rs.getDate("summer_semester_start");
                if (wStart != null) this.winterStart = wStart.toLocalDate();
                if (sStart != null) this.summerStart = sStart.toLocalDate();
                System.out.println("Loaded academic year: Winter=" + this.winterStart + ", Summer=" + this.summerStart);
            } else {
                System.out.println("WARNING: No active academic year found. Colloquium dates cannot be calculated.");
            }
        }
    }

    // Fakultet za koji ova instanca generiše raspored - njegova sopstvena zauzetost
    // ne treba da blokira njegov sopstveni algoritam (vidi napomenu u loadRoomOccupancy).
    private static final String OWN_FACULTY_CODE = "FIT";

    /**
     * Učitava ručno unesenu zauzetost sala (Zauzetost sala - druge fakultete i sl.)
     * za AKTIVNU akademsku godinu, da bi algoritam za generisanje rasporeda
     * izbjegavao sale koje su već zauzete od strane DRUGIH fakulteta. Redovi gdje
     * je faculty_code = OWN_FACULTY_CODE se namjerno preskaču - to je FIT-ova
     * sopstvena (najčešće automatski generisana) zauzetost, pa ne treba da blokira
     * FIT-ov sopstveni algoritam da koristi svoje sale (inače bi npr. cio radni dan
     * u skoro svim salama ispao "zauzet" i ne bi ostalo ništa za raspoređivanje).
     * Namjerno odvojen try/catch: ako tabela ne postoji (starija instanca prije nego
     * je ova funkcionalnost dodata) ili nema aktivne akademske godine, samo se
     * nastavlja bez te provjere.
     */
    private void loadRoomOccupancy() {
        if (activeAcademicYearId <= 0) {
            return;
        }
        String query = "SELECT room_id, weekday, start_time, end_time FROM room_occupancy " +
                "WHERE academic_year_id = ? AND is_active = TRUE " +
                "AND (faculty_code IS NULL OR UPPER(faculty_code) != UPPER(?))";
        try (PreparedStatement ps = conn.prepareStatement(query)) {
            ps.setInt(1, activeAcademicYearId);
            ps.setString(2, OWN_FACULTY_CODE);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    String dayName = weekdayIntToName(rs.getInt("weekday"));
                    if (dayName == null) continue;
                    RoomOccupancySlot slot = new RoomOccupancySlot();
                    slot.roomId = rs.getInt("room_id");
                    slot.day = dayName;
                    slot.start = rs.getTime("start_time").toLocalTime();
                    slot.end = rs.getTime("end_time").toLocalTime();
                    roomOccupancy.add(slot);
                }
            }
            System.out.println("Loaded room occupancy slots: " + roomOccupancy.size());
        } catch (SQLException e) {
            System.out.println("Napomena: zauzetost sala nije učitana (" + e.getMessage() + ") - nastavlja se bez te provjere.");
        }
    }

    /**
     * Da li je sala u datom terminu zauzeta preko ručno unešene "Zauzetosti sala"
     * (npr. drugi fakultet je već rezervisao tu salu za taj termin).
     */
    private boolean isRoomOccupiedExternally(int roomId, String day, LocalTime start, LocalTime end) {
        for (RoomOccupancySlot slot : roomOccupancy) {
            if (slot.roomId != roomId) continue;
            if (day == null || !day.equals(slot.day)) continue;
            if (start.isBefore(slot.end) && end.isAfter(slot.start)) {
                return true;
            }
        }
        return false;
    }

    private void loadCourses() throws SQLException {
        String query = "SELECT id, name, semester, code, " +
                "COALESCE(lectures_per_week, 2) as lectures_per_week, " +
                "COALESCE(exercises_per_week, 2) as exercises_per_week, " +
                "COALESCE(labs_per_week, 0) as labs_per_week, " +
                "COALESCE(is_online, FALSE) as is_online, " +
                "COALESCE(requires_computer_lab, FALSE) as requires_computer_lab, " +
                "expected_students, " +
                "COALESCE(parallel_groups, 1) as parallel_groups, " +
                "colloquium_1_week, colloquium_2_week, " +
                "COALESCE(is_active, TRUE) as is_active " +
                "FROM course";
        try (Statement stmt = conn.createStatement(); ResultSet rs = stmt.executeQuery(query)) {
            int count = 0;
            while (rs.next()) {
                Course course = new Course();
                course.idCourse = rs.getInt("id");
                course.name = rs.getString("name");
                course.semester = rs.getInt("semester");
                course.code = rs.getString("code");
                course.lecturesPerWeek = rs.getInt("lectures_per_week");
                course.exercisesPerWeek = rs.getInt("exercises_per_week");
                course.labsPerWeek = rs.getInt("labs_per_week");
                course.isOnline = rs.getBoolean("is_online");
                course.requiresComputerLab = rs.getBoolean("requires_computer_lab");
                int expStudents = rs.getInt("expected_students");
                course.expectedStudents = rs.wasNull() ? null : expStudents;
                int parGroups = rs.getInt("parallel_groups");
                course.parallelGroups = parGroups > 0 ? parGroups : 1;
                course.isActive = rs.getBoolean("is_active");

                // Load colloquium weeks
                int col1 = rs.getInt("colloquium_1_week");
                if (!rs.wasNull() && col1 > 0) {
                    course.colloquium1Week = col1;
                } else {
                    course.colloquium1Week = 4;  // Auto-set to week 5
                    System.out.println("ℹ️  " + course.name + " - auto-configured to week 5");
                }

                
                int col2 = rs.getInt("colloquium_2_week");
                if (!rs.wasNull()) course.colloquium2Week = col2;
                else System.out.println("This course does not have set colloquium week 2!");

                if (!rs.wasNull() && col2 > 0) {
                    course.colloquium2Week = col2;
                } else {
                    course.colloquium2Week = 7;  // Auto-set to week 5
                    System.out.println("ℹ️  " + course.name + " - auto-configured to week 5");
                }

                courses.put(course.idCourse, course);
                count++;
            }
            System.out.println("Loaded courses: " + count + " (with P+V+L workload and Colloquium weeks)");
        }
    }

    private void loadRooms() throws SQLException {
        String query = "SELECT id, code, capacity, is_computer_lab, is_active FROM room";
        try (Statement stmt = conn.createStatement(); ResultSet rs = stmt.executeQuery(query)) {
            int count = 0;
            while (rs.next()) {
                Room room = new Room();
                room.idRoom = rs.getInt("id");
                room.code = rs.getString("code");
                room.capacity = rs.getInt("capacity");
                room.isComputerLab = rs.getBoolean("is_computer_lab");
                room.isActive = rs.getBoolean("is_active");
                rooms.put(room.idRoom, room);
                count++;
            }
            System.out.println("Loaded rooms: " + count);
        }
    }

    private void loadProfessors() throws SQLException {
        String query = "SELECT id, full_name, email, is_active FROM professor";
        try (Statement stmt = conn.createStatement(); ResultSet rs = stmt.executeQuery(query)) {
            int count = 0;
            while (rs.next()) {
                Professor professor = new Professor();
                professor.idProfessor = rs.getInt("id");
                professor.fullName = rs.getString("full_name");
                professor.email = rs.getString("email");
                professor.isActive = rs.getBoolean("is_active");
                professors.put(professor.idProfessor, professor);
                count++;
            }
            System.out.println("Loaded professors: " + count);
        }
    }
    
    private void loadAcademicEvents() throws SQLException {
        String query = "SELECT id, course_id, created_by_professor, type_enum, starts_at, ends_at, " +
                "room_id, notes, is_published, locked_by_admin, schedule_id, day FROM academic_event";
        try (Statement stmt = conn.createStatement(); ResultSet rs = stmt.executeQuery(query)) {
            int count = 0;
            while (rs.next()) {
                AcademicEvent event = new AcademicEvent();
                event.idAcademicEvent = rs.getInt("id");
                event.idCourse = rs.getInt("course_id");
                event.idProfessor = (int) rs.getLong("created_by_professor");
                event.typeEnum = rs.getString("type_enum");
                event.day = rs.getString("day");
                event.notes = rs.getString("notes");
                event.isPublished = rs.getBoolean("is_published");
                event.lockedByAdmin = rs.getBoolean("locked_by_admin");
                event.scheduleId = rs.getInt("schedule_id");
                event.idRoom = rs.getInt("room_id");

                Timestamp startTs = rs.getTimestamp("starts_at");
                Timestamp endTs = rs.getTimestamp("ends_at");

                if (startTs != null) {
                    LocalDateTime startLdt = startTs.toLocalDateTime();
                    event.startsAt = startLdt;
                    event.date = startLdt.toLocalDate();
                    event.startTime = startLdt.toLocalTime();
                }
                if (endTs != null) {
                    LocalDateTime endLdt = endTs.toLocalDateTime();
                    event.endsAt = endLdt;
                    event.endTime = endLdt.toLocalTime();
                }

                academicEvents.put(event.idAcademicEvent, event);
                count++;
            }
            System.out.println("Loaded academic events: " + count);
        }
    }

    private void loadHolidays() throws SQLException {
        String query = "SELECT id, name, date FROM holiday";
        try (Statement stmt = conn.createStatement(); ResultSet rs = stmt.executeQuery(query)) {
            int count = 0;
            while (rs.next()) {
                Holiday holiday = new Holiday();
                holiday.idHoliday = rs.getInt("id");
                holiday.name = rs.getString("name");
                holiday.date = rs.getDate("date").toLocalDate();
                holidays.add(holiday);
                count++;
            }
            System.out.println("Loaded holidays: " + count);
        }
    }

    //endregion
    
    public static void main(String[] args) {
        Connection conn = null;
        try {
            EnvLoader env = EnvLoader.load();
            String host = env.get("DB_HOST");
            String port = env.get("DB_PORT");
            String name = env.get("DB_NAME");
            String user = env.get("DB_USER");
            String pass = env.get("DB_PASS");

            Class.forName("org.postgresql.Driver");
            conn = DriverManager.getConnection("jdbc:postgresql://" + host + ":" + port + "/" + name + "", user, pass);
            
            EventValidationService service = new EventValidationService(conn);
            
            if (args.length == 0) {
                System.out.println("Usage:");
                System.out.println("  java EventValidationService generateSchedule");
                System.out.println("  java EventValidationService generateExams winter");
                System.out.println("  java EventValidationService generateExams summer");
                return;
            }
            
            String command = args[0];
            
            if (command.equalsIgnoreCase("generateSchedule")) {
                String result = service.generateCompleteSchedule();
                System.out.println(result);
            } 
            else if (command.equalsIgnoreCase("generateExams")) {
                if (args.length < 2) {
                    System.out.println("Usage: java EventValidationService generateExams [winter|summer]");
                    return;
                }
                int period = args[1].equalsIgnoreCase("winter") ? 1 : 2;
                ScheduleResult result = service.generateExamScheduleForPeriod(2026, period);
                System.out.println(result.message);
                System.out.println("Successful: " + result.successfulCourses);
                System.out.println("Failed: " + result.failedCourses);
            }
            else {
                System.out.println("Unknown command: " + command);
            }
            
        } catch (Exception e) {
            System.err.println("Error: " + e.getMessage());
            e.printStackTrace();
        } finally {
            if (conn != null) {
                try { conn.close(); } catch (SQLException e) { e.printStackTrace(); }
            }
        }
    }


    //region Helper Functions



    private void saveToAcademicEvent(int scheduleId, int courseId, int professorId, String day,
            LocalDateTime startsAt, LocalDateTime endsAt, int roomId, String typeEnum) throws SQLException {
        String insert = "INSERT INTO academic_event " +
                "(course_id, created_by_professor, type_enum, starts_at, ends_at, " +
                "room_id, notes, is_published, locked_by_admin, schedule_id, day) " +
                "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id";
        int eventId = 0;
        try (PreparedStatement pstmt = conn.prepareStatement(insert)) {
            pstmt.setInt(1, courseId);
            pstmt.setLong(2, professorId);
            pstmt.setString(3, typeEnum);
            pstmt.setTimestamp(4, Timestamp.valueOf(startsAt));
            pstmt.setTimestamp(5, Timestamp.valueOf(endsAt));
            // roomId <= 0 je "sentinel" za "nije potrebna sala" (npr. onlajn predmet)
            if (roomId > 0) {
                pstmt.setInt(6, roomId);
            } else {
                pstmt.setNull(6, java.sql.Types.BIGINT);
            }
            pstmt.setNull(7, java.sql.Types.VARCHAR);
            pstmt.setBoolean(8, true);
            pstmt.setBoolean(9, false);
            pstmt.setInt(10, scheduleId);
            pstmt.setString(11, day);
            try (ResultSet rs = pstmt.executeQuery()) {
                if (rs.next()) {
                    eventId = rs.getInt(1);
                }
            }
        }

        // Predmet može imati više od jednog predavača/asistenta (npr. 2-3 profesora
        // na istom predmetu) - poveži SVE njih sa generisanim terminom preko
        // event_professor, ne samo onog čija je dostupnost korišćena za pronalazak
        // termina, inače ostali "nestanu" iz svog "Moj raspored" prikaza.
        if (eventId > 0) {
            boolean isAssistantRole = "EXERCISE".equals(typeEnum) || "LAB".equals(typeEnum);
            linkAllCourseProfessorsToEvent(eventId, courseId, professorId, isAssistantRole);
        }
    }

    /**
     * Poveže sve profesore/asistente dodijeljene predmetu (course_professor) sa
     * generisanim terminom preko event_professor, tako da svi vide termin u
     * svom rasporedu (ne samo profesor čija je dostupnost korišćena za
     * pronalazak termina).
     */
    private void linkAllCourseProfessorsToEvent(int eventId, int courseId, int primaryProfessorId,
            boolean isAssistantRole) throws SQLException {
        List<Integer> professorIds = getEventParticipants(courseId, primaryProfessorId, isAssistantRole);

        String insertLink = "INSERT INTO event_professor (event_id, professor_id) VALUES (?, ?)";
        try (PreparedStatement ps = conn.prepareStatement(insertLink)) {
            for (int pid : professorIds) {
                ps.setInt(1, eventId);
                ps.setInt(2, pid);
                ps.addBatch();
            }
            ps.executeBatch();
        }
    }

    /**
     * Svi profesori koji učestvuju u terminu (i vezuju se za njega preko
     * event_professor): nosilac termina + ostali aktivni profesori iste uloge na
     * predmetu (npr. dva predavača koji zajedno drže predavanja). Svi oni moraju biti
     * raspoloživi i bez sukoba u tom terminu - ranije se provjeravao samo nosilac,
     * pa je drugi predavač mogao dobiti čas van svojih slobodnih termina ili dva
     * časa u isto vrijeme. Kod paralelnih grupa svaka grupa ima SVOG predavača/
     * asistenta (vidi scheduleParallelGroups), pa učestvuje samo nosilac.
     */
    private List<Integer> getEventParticipants(int courseId, int primaryProfessorId, boolean isAssistantRole) {
        List<Integer> professorIds = new ArrayList<>();
        Course course = courses.get(courseId);
        if (course == null || course.parallelGroups <= 1) {
            List<Integer> cached = courseProfessorsByCourseAndRole.get(courseId + "_" + isAssistantRole);
            if (cached != null) {
                professorIds.addAll(cached);
            }
        }
        if (!professorIds.contains(primaryProfessorId)) {
            professorIds.add(primaryProfessorId);
        }
        return professorIds;
    }

    private List<Integer> participantsOf(AcademicEvent event) {
        return event.participants != null ? event.participants : List.of(event.idProfessor);
    }

    private boolean areAllAvailable(List<Integer> professorIds, int courseId, String day,
            LocalTime start, LocalTime end) {
        for (int pid : professorIds) {
            if (!isProfessorAvailable(pid, courseId, day, start, end)) {
                return false;
            }
        }
        return true;
    }

    private LocalDateTime convertDayToDate(String day, LocalTime time) {
        LocalDate today = LocalDate.now();
        DayOfWeek targetDay = parseDayOfWeek(day);
        LocalDate targetDate = today.with(TemporalAdjusters.nextOrSame(targetDay));
        return LocalDateTime.of(targetDate, time);
    }

    private DayOfWeek parseDayOfWeek(String day) {
        switch (day.toLowerCase()) {
            case "ponedeljak":
            case "monday":
                return DayOfWeek.MONDAY;
            case "utorak":
            case "tuesday":
                return DayOfWeek.TUESDAY;
            case "srijeda":
            case "sreda":
            case "wednesday":
                return DayOfWeek.WEDNESDAY;
            case "cetvrtak":
            case "thursday":
                return DayOfWeek.THURSDAY;
            case "petak":
            case "friday":
                return DayOfWeek.FRIDAY;
            case "subota":
            case "saturday":
                return DayOfWeek.SATURDAY;
            case "nedelja":
            case "nedjelja":
            case "sunday":
                return DayOfWeek.SUNDAY;
            default:
                return DayOfWeek.MONDAY;
        }
    }

    private boolean hasConflict(String day, LocalDate date, int roomId, int professorId,
                            LocalTime startTime, LocalTime endTime) {
        return hasConflictInSchedule(day, date, roomId, professorId, startTime, endTime, -1);
    }

    private boolean hasConflictInSchedule(String day, LocalDate date, int roomId, int professorId,
                                        LocalTime startTime, LocalTime endTime, int scheduleId) {
        for (AcademicEvent event : academicEvents.values()) {
            // CRITICAL FIX: Skip events that are NOT in the current schedule being generated
            // If scheduleId is -1 (new schedule), skip ALL existing scheduled events
            // If scheduleId > 0, only check conflicts within the SAME schedule
            if (scheduleId == -1 && event.scheduleId > 0) {
                continue; // Skip all events from existing schedules when generating new schedule
            } else if (scheduleId > 0 && event.scheduleId != scheduleId) {
                continue; // Only check conflicts within same schedule
            }

            boolean dayMatch = (day != null && event.day != null && event.day.equals(day));
            boolean dateMatch = (date != null && event.date != null && event.date.equals(date));

            if (dayMatch || dateMatch) {
                boolean roomMatch = (event.idRoom == roomId);
                boolean professorMatch = (event.idProfessor == professorId);

                if (roomMatch || professorMatch) {
                    if (startTime != null && endTime != null && event.startTime != null && event.endTime != null) {
                        if (startTime.isBefore(event.endTime) && endTime.isAfter(event.startTime) &&
                            !startTime.equals(endTime)) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }




    private String addTeachingTerm(int courseId, int roomId, int professorId, String day,
            String startTimeStr, String endTimeStr, String typeEnum, String requiredRoomType) {
        try {
            Course course = courses.get(courseId);
            if (course == null) {
                return "ERROR: Course does not exist";
            }

            Room room = rooms.get(roomId);
            if (room == null) {
                return "ERROR: Room does not exist";
            }

            Professor professor = professors.get(professorId);
            if (professor == null) {
                return "ERROR: Professor does not exist";
            }

            if (!professor.isActive) {
                return "ERROR: Professor is not active";
            }

            LocalTime startTime = parseTime(startTimeStr);
            LocalTime endTime = parseTime(endTimeStr);

            if (room.capacity < 0) {
                return "ERROR: Room capacity is invalid";
            }

            // Proveravamo samo da li je sala aktivna
            if (!room.isActive) {
                return "ERROR: Room is not active";
            }

            if (hasConflict(day, null, roomId, professorId, startTime, endTime)) {
                for (AcademicEvent event : academicEvents.values()) {
                    if (event.day != null && event.day.equals(day)) {
                        if (startTime.isBefore(event.endTime) && endTime.isAfter(event.startTime)) {
                            if (event.idRoom == roomId) {
                                return "ERROR: Room is occupied at that time";
                            }
                            if (event.idProfessor == professorId) {
                                return "ERROR: Professor is occupied at that time";
                            }
                        }
                    }
                }
            }

            String insert = "INSERT INTO academic_event (course_id, created_by_professor, type_enum, " +
                    "starts_at, ends_at, room_id, is_published, locked_by_admin, day) " +
                    "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            try (PreparedStatement pstmt = conn.prepareStatement(insert)) {
                LocalDateTime startsAt = convertDayToDate(day, startTime);
                LocalDateTime endsAt = convertDayToDate(day, endTime);

                pstmt.setInt(1, courseId);
                pstmt.setLong(2, professorId);
                pstmt.setString(3, typeEnum);
                pstmt.setTimestamp(4, Timestamp.valueOf(startsAt));
                pstmt.setTimestamp(5, Timestamp.valueOf(endsAt));
                pstmt.setInt(6, roomId);
                pstmt.setBoolean(7, true);
                pstmt.setBoolean(8, false);
                pstmt.setString(9, day);
                pstmt.executeUpdate();

                AcademicEvent newEvent = new AcademicEvent();
                newEvent.idCourse = courseId;
                newEvent.idRoom = roomId;
                newEvent.idProfessor = professorId;
                newEvent.day = day;
                newEvent.startTime = startTime;
                newEvent.endTime = endTime;
                newEvent.typeEnum = typeEnum;
                newEvent.date = startsAt.toLocalDate();
                newEvent.startsAt = startsAt;
                newEvent.endsAt = endsAt;
                academicEvents.put(newEvent.idAcademicEvent, newEvent);
            }

            return "OK: " + typeEnum + " successfully added";
        } catch (Exception e) {
            return "ERROR: " + e.getMessage();
        }
    }

    public String addLecture(int courseId, int roomId, int professorId, String day,
            String startTime, String endTime) {
        return addTeachingTerm(courseId, roomId, professorId, day, startTime, endTime, "LECTURE", "predavaliste");
    }

    public String addExercise(int courseId, int roomId, int professorId, String day,
            String startTime, String endTime) {
        return addTeachingTerm(courseId, roomId, professorId, day, startTime, endTime, "EXERCISE", "vjezbe");
    }

    public String addColloquium(int courseId, int roomId, int professorId, int supervisorProfessorId,
            String date, String startTimeStr, String endTimeStr) {
        try {
            Course course = courses.get(courseId);
            if (course == null) {
                return "ERROR: Course does not exist";
            }

            Room room = rooms.get(roomId);
            if (room == null) {
                return "ERROR: Room does not exist";
            }

            Professor professor = professors.get(professorId);
            if (professor == null) {
                return "ERROR: Professor does not exist";
            }

            Professor supervisor = professors.get(supervisorProfessorId);
            if (supervisor == null) {
                return "ERROR: Supervisor professor does not exist";
            }

            if (!professor.isActive || !supervisor.isActive) {
                return "ERROR: One or more professors are not active";
            }

            LocalDate colloquiumDate = LocalDate.parse(date);
            LocalTime startTime = parseTime(startTimeStr);
            LocalTime endTime = parseTime(endTimeStr);

            if (colloquiumDate.getDayOfWeek() == DayOfWeek.SUNDAY) {
                return "ERROR: Colloquium cannot be scheduled on Sunday";
            }

            for (Holiday holiday : holidays) {
                if (holiday.date.equals(colloquiumDate)) {
                    return "ERROR: Colloquium cannot be on holiday: " + holiday.name;
                }
            }

            LocalDate weekStart = colloquiumDate.with(TemporalAdjusters.previousOrSame(DayOfWeek.MONDAY));
            LocalDate weekEnd = colloquiumDate.with(TemporalAdjusters.nextOrSame(DayOfWeek.FRIDAY));
            int colloquia = 0;
            for (AcademicEvent event : academicEvents.values()) {
                if ("COLLOQUIUM".equals(event.typeEnum) && event.date != null &&
                        !event.date.isBefore(weekStart) && !event.date.isAfter(weekEnd)) {
                    colloquia++;
                }
            }

            if (colloquia >= 2) {
                return "ERROR: Maximum 2 colloquia per week allowed";
            }

            if (room.capacity <= 0) {
                return "ERROR: Room capacity is invalid";
            }

            if (hasConflict(null, colloquiumDate, roomId, professorId, startTime, endTime)) {
                for (AcademicEvent event : academicEvents.values()) {
                    if (event.date != null && event.date.equals(colloquiumDate)) {
                        if (startTime.isBefore(event.endTime) && endTime.isAfter(event.startTime)) {
                            if (event.idProfessor == professorId) {
                                return "ERROR: Professor is occupied at that time";
                            }
                            if (event.idRoom == roomId) {
                                return "ERROR: Room is occupied at that time";
                            }
                        }
                    }
                }
            }

            int supervisorCount = 0;
            for (AcademicEvent event : academicEvents.values()) {
                if ("COLLOQUIUM".equals(event.typeEnum) && event.idProfessor == supervisorProfessorId) {
                    supervisorCount++;
                }
            }

            if (supervisorCount >= 5) {
                return "WARNING: Supervisor already has " + supervisorCount + " colloquium supervisions";
            }

            String insert = "INSERT INTO academic_event (course_id, created_by_professor, type_enum, " +
                    "starts_at, ends_at, room_id, is_published, locked_by_admin) " +
                    "VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            try (PreparedStatement pstmt = conn.prepareStatement(insert)) {
                LocalDateTime startsAt = LocalDateTime.of(colloquiumDate, startTime);
                LocalDateTime endsAt = LocalDateTime.of(colloquiumDate, endTime);

                pstmt.setInt(1, courseId);
                pstmt.setLong(2, professorId);
                pstmt.setString(3, "COLLOQUIUM");
                pstmt.setTimestamp(4, Timestamp.valueOf(startsAt));
                pstmt.setTimestamp(5, Timestamp.valueOf(endsAt));
                pstmt.setInt(6, roomId);
                pstmt.setBoolean(7, true);
                pstmt.setBoolean(8, false);
                pstmt.executeUpdate();

                AcademicEvent newEvent = new AcademicEvent();
                newEvent.idCourse = courseId;
                newEvent.idRoom = roomId;
                newEvent.idProfessor = professorId;
                newEvent.typeEnum = "COLLOQUIUM";
                newEvent.date = colloquiumDate;
                newEvent.startTime = startTime;
                newEvent.endTime = endTime;
                newEvent.startsAt = LocalDateTime.of(colloquiumDate, startTime);
                newEvent.endsAt = LocalDateTime.of(colloquiumDate, endTime);
                academicEvents.put(newEvent.idAcademicEvent, newEvent);
            }

            return "OK: Colloquium successfully added";
        } catch (DateTimeParseException e) {
            return "ERROR: Invalid date format (YYYY-MM-DD) or time format (HH:mm:ss)";
        } catch (Exception e) {
            return "ERROR: " + e.getMessage();
        }
    }

    public String addExam(int courseId, int roomId, int professorId, String date,
            String startTimeStr, String endTimeStr, String examType) {
        try {
            Course course = courses.get(courseId);
            if (course == null) {
                return "ERROR: Course does not exist";
            }

            Room room = rooms.get(roomId);
            if (room == null) {
                return "ERROR: Room does not exist";
            }

            Professor professor = professors.get(professorId);
            if (professor == null) {
                return "ERROR: Professor does not exist";
            }

            if (!professor.isActive) {
                return "ERROR: Professor is not active";
            }

            LocalDate examDate = LocalDate.parse(date);
            LocalTime startTime = parseTime(startTimeStr);
            LocalTime endTime = parseTime(endTimeStr);

            if (examDate.getDayOfWeek() == DayOfWeek.SUNDAY) {
                return "ERROR: Exam cannot be scheduled on Sunday";
            }

            for (Holiday holiday : holidays) {
                if (holiday.date.equals(examDate)) {
                    return "ERROR: Exam cannot be scheduled on holiday: " + holiday.name;
                }
            }

            if (room.capacity <= 0) {
                return "ERROR: Room capacity is invalid";
            }

            for (AcademicEvent event : academicEvents.values()) {
                if (event.idProfessor == professorId && event.date != null && event.date.equals(examDate)) {
                    if (event.typeEnum.contains("EXAM") || event.typeEnum.contains("COLLOQUIUM")) {
                        if (startTime.isBefore(event.endTime) && endTime.isAfter(event.startTime)) {
                            return "ERROR: Professor is already assigned to another exam at that time";
                        }
                    }
                }

                if (event.idProfessor == professorId &&
                        (event.typeEnum.equals("LECTURE") || event.typeEnum.equals("EXERCISE"))) {
                    if (event.day != null && event.date == null) {
                        LocalDate nextOccurrence = examDate.with(
                                TemporalAdjusters.previousOrSame(DayOfWeek.valueOf(event.day.toUpperCase())));
                        if (nextOccurrence.equals(examDate) &&
                                startTime.isBefore(event.endTime) && endTime.isAfter(event.startTime)) {
                            return "ERROR: Professor has " + event.typeEnum + " on " + event.day;
                        }
                    } else if (event.date != null && event.date.equals(examDate)) {
                        if (startTime.isBefore(event.endTime) && endTime.isAfter(event.startTime)) {
                            return "ERROR: Professor has " + event.typeEnum + " at that time";
                        }
                    }
                }
            }

            if (hasConflict(null, examDate, roomId, professorId, startTime, endTime)) {
                for (AcademicEvent event : academicEvents.values()) {
                    if (event.date != null && event.date.equals(examDate)) {
                        if (startTime.isBefore(event.endTime) && endTime.isAfter(event.startTime)) {
                            if (event.idRoom == roomId) {
                                return "ERROR: Room is occupied at that time";
                            }
                            if (event.idProfessor == professorId) {
                                return "ERROR: Professor is occupied at that time";
                            }
                        }
                    }
                }
            }

            String insert = "INSERT INTO academic_event (course_id, created_by_professor, type_enum, " +
                    "starts_at, ends_at, room_id, is_published, locked_by_admin) " +
                    "VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            try (PreparedStatement pstmt = conn.prepareStatement(insert)) {
                LocalDateTime startsAt = LocalDateTime.of(examDate, startTime);
                LocalDateTime endsAt = LocalDateTime.of(examDate, endTime);

                pstmt.setInt(1, courseId);
                pstmt.setLong(2, professorId);
                pstmt.setString(3, examType);
                pstmt.setTimestamp(4, Timestamp.valueOf(startsAt));
                pstmt.setTimestamp(5, Timestamp.valueOf(endsAt));
                pstmt.setInt(6, roomId);
                pstmt.setBoolean(7, true);
                pstmt.setBoolean(8, false);
                pstmt.executeUpdate();

                AcademicEvent newEvent = new AcademicEvent();
                newEvent.idCourse = courseId;
                newEvent.idRoom = roomId;
                newEvent.idProfessor = professorId;
                newEvent.typeEnum = examType;
                newEvent.date = examDate;
                newEvent.startTime = startTime;
                newEvent.endTime = endTime;
                newEvent.startsAt = LocalDateTime.of(examDate, startTime);
                newEvent.endsAt = LocalDateTime.of(examDate, endTime);
                academicEvents.put(newEvent.idAcademicEvent, newEvent);
            }

            return "OK: Exam successfully added";
        } catch (DateTimeParseException e) {
            return "ERROR: Invalid date format (YYYY-MM-DD) or time format (HH:mm:ss)";
        } catch (Exception e) {
            return "ERROR: " + e.getMessage();
        }
    }

    public String checkCourseHours(int courseId) {
        Course course = courses.get(courseId);
        if (course == null) {
            return "ERROR: Course does not exist";
        }

        int lectureCount = 0;
        int exerciseCount = 0;

        for (AcademicEvent event : academicEvents.values()) {
            if (event.idCourse == courseId) {
                if ("LECTURE".equals(event.typeEnum)) {
                    lectureCount++;
                } else if ("EXERCISE".equals(event.typeEnum)) {
                    exerciseCount++;
                }
            }
        }

        StringBuilder info = new StringBuilder();
        info.append("COURSE: ").append(course.name).append("\n");
        info.append("CODE: ").append(course.code).append("\n");
        info.append("SEMESTER: ").append(course.semester).append("\n");
        info.append("--- EVENT COUNT ---\n");
        info.append("LECTURES: ").append(lectureCount).append("\n");
        info.append("EXERCISES: ").append(exerciseCount).append("\n");

        return info.toString();
    }

    //endregion
    

    //region Automatic Schedule Generation

    public String generateCompleteSchedule() {
        try {
            System.out.println("=== STARTING AUTOMATIC COMPLETE SCHEDULE GENERATION ===\n");
            System.out.println("Using workload-based scheduling (P+V+L hours)\n");

            int scheduleId = generateNewScheduleId();
            System.out.println("Generated schedule_id: " + scheduleId + "\n");

            // Clear the cache before generation (fresh schedule generation)
            academicEvents = new HashMap<>(); // Start with empty cache for new schedule

            Map<Integer, Double> professorFlexibility = analyzeProfessorFlexibility();
            List<CoursePriority> priorities = determinePriorities(professorFlexibility);

            System.out.println("--- COURSE PRIORITIES (with workload) ---");
            for (CoursePriority cp : priorities) {
                Course c = cp.course;
                System.out.printf("Course: %-25s | P=%d V=%d L=%d (total=%d hrs) | Priority: %.2f\n",
                    c.name, c.lecturesPerWeek, c.exercisesPerWeek, c.labsPerWeek,
                    c.getTotalHoursPerWeek(), cp.priority);
            }

            int successfulCourses = 0;
            int partialCourses = 0;
            int failedCourses = 0;
            StringBuilder details = new StringBuilder();
            details.append("\n\n=== GENERATION DETAILS ===\n");

            for (CoursePriority cp : priorities) {
                Course course = cp.course;
                details.append("\n--- ").append(course.name)
                    .append(" (ID: ").append(course.idCourse)
                    .append(") P=").append(course.lecturesPerWeek)
                    .append(" V=").append(course.exercisesPerWeek)
                    .append(" L=").append(course.labsPerWeek)
                    .append(" ---\n");

                String result = generateCourseSchedule(course.idCourse, scheduleId);
                details.append(" [SCHEDULE] ").append(result).append("\n");

                if (result.startsWith("OK")) {
                    successfulCourses++;
                } else if (result.startsWith("WARNING")) {
                    partialCourses++;
                } else {
                    failedCourses++;
                }
            }

            System.out.println(details.toString());

            String summary = String.format(
                "\n=== SCHEDULE GENERATION COMPLETED ===\n" +
                "SCHEDULE_ID: %d\n" +
                "\nCOURSES:\n" +
                " ✓ Successful: %d\n" +
                " ⚠ Partial: %d\n" +
                " ✗ Failed: %d\n" +
                "\nTOTAL COURSES PROCESSED: %d\n" +
                "================================================",
                scheduleId, successfulCourses, partialCourses, failedCourses, priorities.size());

            System.out.println(summary);

            // Reload all events after generation
            loadAcademicEvents();

            if (successfulCourses > 0) {
                // Unlock all old schedules
                String unlockAll = "UPDATE academic_event SET locked_by_admin = FALSE WHERE locked_by_admin = TRUE";
                try (PreparedStatement pstmt = conn.prepareStatement(unlockAll)) {
                    int unlocked = pstmt.executeUpdate();
                    System.out.println("✓ Unlocked " + unlocked + " old events from previous schedules");
                } catch (SQLException e) {
                    System.err.println("Warning: Could not unlock old schedules: " + e.getMessage());
                }

                // Lock only the new schedule
                String lockUpdate = "UPDATE academic_event SET locked_by_admin = TRUE WHERE schedule_id = ?";
                try (PreparedStatement pstmt = conn.prepareStatement(lockUpdate)) {
                    pstmt.setInt(1, scheduleId);
                    int lockedEvents = pstmt.executeUpdate();
                    System.out.println("✓ Schedule " + scheduleId + " automatically LOCKED! (" + lockedEvents + " events locked)");
                } catch (SQLException e) {
                    System.err.println("Warning: Could not lock schedule: " + e.getMessage());
                }
            }

            if (failedCourses > 0) {
                return "WARNING: Schedule partially generated (schedule_id: " + scheduleId +
                    "). Check details above.";
            }

            return "OK: Complete schedule successfully generated for all courses (schedule_id: " + scheduleId + ")!";

        } catch (Exception e) {
            e.printStackTrace();
            return "ERROR: " + e.getMessage();
        }
    }

    
    
    
    private int generateNewScheduleId() throws SQLException {
        // Prvo generišemo novi ID
        String queryMaxId = "SELECT COALESCE(MAX(id), 0) + 1 as novi_id FROM schedule";
        int newId = 1;

        try (Statement stmt = conn.createStatement(); ResultSet rs = stmt.executeQuery(queryMaxId)) {
            if (rs.next()) {
                newId = rs.getInt("novi_id");
            }
        }

        // Kreiramo zapis u schedule tabeli
        String insertSchedule = "INSERT INTO schedule (id, name) VALUES (?, ?)";
        try (PreparedStatement pstmt = conn.prepareStatement(insertSchedule)) {
            pstmt.setInt(1, newId);
            pstmt.setString(2, "Auto-generated schedule " + newId + " - " +
                    java.time.LocalDateTime.now()
                            .format(java.time.format.DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss")));
            pstmt.executeUpdate();
        }

        return newId;
    }

    /**
     * Generiše raspored za jedan predmet poštujući pravila:
     * - Ukupno P+V+L mora biti 4-6 sati
     * - 4 sata: preferira dijeljenje na 2 dana (P dan1, V+L dan2), ali može i u 1
     * bloku
     * - 5-6 sati: MORA podijeljeno na 2 dana (P dan1, V+L dan2)
     * - Maximum 4 sata u jednom bloku
     * - Predmet se pojavljuje max 2 puta sedmično
     */
    private String generateCourseSchedule(int courseId, int scheduleId) {
        try {
            Course course = courses.get(courseId);
            if (course == null)
                return "ERROR: Course does not exist";

            int totalHours = course.getTotalHoursPerWeek();
            if (totalHours < 4 || totalHours > 6) {
                return "WARNING: Course total hours should be 4-6, got " + totalHours + ". Skipping.";
            }

            // Pronađi profesora za predavanja
            int lectureProfessorId = findLectureProfessor(courseId);
            if (lectureProfessorId == 0) {
                return "ERROR: No professor assigned for lectures";
            }

            // Pronađi asistenta za vježbe/lab (ili koristi profesora)
            int exerciseProfessorId = findExerciseProfessor(courseId);
            if (exerciseProfessorId == 0) {
                exerciseProfessorId = lectureProfessorId;
            }

            // Pronađi dostupne dane za profesora
            List<String> availableDays = getPreferredDays(lectureProfessorId);
            if (availableDays.size() < 2) {
                // Dodaj defaultne dane ako profesor nema dovoljno preferenci
                List<String> defaultDays = Arrays.asList("ponedeljak", "utorak", "srijeda", "cetvrtak", "petak");
                for (String d : defaultDays) {
                    if (!availableDays.contains(d))
                        availableDays.add(d);
                }
            }

            // Pronađi odgovarajuće sale
            List<Room> lectureRooms = getSuitableRooms(false, 30);
            List<Room> labRooms = getSuitableRooms(true, 20);

            if (lectureRooms.isEmpty()) {
                return "ERROR: No suitable rooms for lectures (need capacity >= 30)";
            }

            // Ako ima lab sate ali nema kompjuterskih sala, koristi obične
            if (course.labsPerWeek > 0 && labRooms.isEmpty()) {
                labRooms = lectureRooms;
            }

            int addedTerms = 0;

            // Preferira dijeljenje na 2 dana (uvijek za 5-6 sati, preferirano za 4)
            addedTerms = scheduleAsTwoDays(scheduleId, course,
                    lectureProfessorId, exerciseProfessorId,
                    availableDays, lectureRooms, labRooms);

            // Ako nije uspjelo i ima tačno 4 sata, pokušaj kao jedan blok
            if (addedTerms == 0 && totalHours == 4) {
                addedTerms = scheduleAsOneBlock(scheduleId, course, lectureProfessorId,
                        availableDays, lectureRooms);
            }

            if (addedTerms > 0) {
                return "OK: Course '" + course.name + "' scheduled (" + totalHours +
                        " hours in " + (addedTerms == 1 ? "1 block" : "2 days") + ")";
            }

            return "ERROR: Could not find suitable time slots for " + course.name;

        } catch (Exception e) {
            return "ERROR: " + e.getMessage();
        }
    }


    
    /**
     * Raspoređuje predmet na 2 dana: Predavanja dan1, Vježbe+Lab dan2
     */
    private int scheduleAsTwoDays(int scheduleId, Course course,
            int lectureProfId, int exerciseProfId,
            List<String> days, List<Room> lectureRooms, List<Room> labRooms) throws SQLException {

        String lectureDay = null;
        String exerciseDay = null;
        Room lectureRoom = null;
        Room exerciseRoom = null;
        LocalTime lectureStart = null;
        LocalTime exerciseStart = null;

        // Dan 1: Pronađi slobodan blok za predavanja
        for (String day : days) {
            for (Room room : lectureRooms) {
                for (int hour = 8; hour <= 17 - course.lecturesPerWeek; hour++) {
                    LocalTime start = LocalTime.of(hour, 0);
                    LocalTime end = start.plusHours(course.lecturesPerWeek);

                    if (!hasConflictForBlock(day, room.idRoom, lectureProfId, start, end)) {
                        lectureDay = day;
                        lectureRoom = room;
                        lectureStart = start;
                        break;
                    }
                }
                if (lectureDay != null)
                    break;
            }
            if (lectureDay != null)
                break;
        }

        if (lectureDay == null)
            return 0;

        // Dan 2: Pronađi slobodan blok za Vježbe + Lab (mora biti drugi dan)
        int exerciseHours = course.exercisesPerWeek + course.labsPerWeek;
        List<Room> exerciseRoomList = course.labsPerWeek > 0 ? labRooms : lectureRooms;

        for (String day : days) {
            if (day.equals(lectureDay))
                continue; // Mora biti drugi dan

            for (Room room : exerciseRoomList) {
                for (int hour = 8; hour <= 17 - exerciseHours; hour++) {
                    LocalTime start = LocalTime.of(hour, 0);
                    LocalTime end = start.plusHours(exerciseHours);

                    if (!hasConflictForBlock(day, room.idRoom, exerciseProfId, start, end)) {
                        exerciseDay = day;
                        exerciseRoom = room;
                        exerciseStart = start;
                        break;
                    }
                }
                if (exerciseDay != null)
                    break;
            }
            if (exerciseDay != null)
                break;
        }

        if (exerciseDay == null)
            return 0;

        // Sačuvaj termine za predavanja (svaki sat posebno)
        LocalTime currentTime = lectureStart;
        for (int i = 0; i < course.lecturesPerWeek; i++) {
            LocalDateTime startsAt = convertDayToDate(lectureDay, currentTime);
            LocalDateTime endsAt = startsAt.plusHours(1);
            saveToAcademicEvent(scheduleId, course.idCourse, lectureProfId,
                    lectureDay, startsAt, endsAt, lectureRoom.idRoom, "LECTURE");
            addEventToCache(course.idCourse, lectureRoom.idRoom, lectureProfId,
                    lectureDay, currentTime, currentTime.plusHours(1), "LECTURE", scheduleId);
            currentTime = currentTime.plusHours(1);
        }

        // Sačuvaj termine za vježbe
        currentTime = exerciseStart;
        for (int i = 0; i < course.exercisesPerWeek; i++) {
            LocalDateTime startsAt = convertDayToDate(exerciseDay, currentTime);
            LocalDateTime endsAt = startsAt.plusHours(1);
            saveToAcademicEvent(scheduleId, course.idCourse, exerciseProfId,
                    exerciseDay, startsAt, endsAt, exerciseRoom.idRoom, "EXERCISE");
            addEventToCache(course.idCourse, exerciseRoom.idRoom, exerciseProfId,
                    exerciseDay, currentTime, currentTime.plusHours(1), "EXERCISE", scheduleId);
            currentTime = currentTime.plusHours(1);
        }

        // Sačuvaj termine za lab
        for (int i = 0; i < course.labsPerWeek; i++) {
            LocalDateTime startsAt = convertDayToDate(exerciseDay, currentTime);
            LocalDateTime endsAt = startsAt.plusHours(1);
            saveToAcademicEvent(scheduleId, course.idCourse, exerciseProfId,
                    exerciseDay, startsAt, endsAt, exerciseRoom.idRoom, "LAB");
            addEventToCache(course.idCourse, exerciseRoom.idRoom, exerciseProfId,
                    exerciseDay, currentTime, currentTime.plusHours(1), "LAB", scheduleId);
            currentTime = currentTime.plusHours(1);
        }

        System.out.println("  -> Scheduled: " + course.name + " - " +
                course.lecturesPerWeek + "P on " + lectureDay + " @ " + lectureStart +
                ", " + (course.exercisesPerWeek + course.labsPerWeek) + "(V+L) on " +
                exerciseDay + " @ " + exerciseStart);

        return 2; // Uspješno na 2 dana
    }

    /**
     * Raspoređuje predmet kao jedan blok (samo za 4 sata ako 2-dnevni raspored nije
     * uspio)
     */
    private int scheduleAsOneBlock(int scheduleId, Course course, int professorId,
            List<String> days, List<Room> rooms) throws SQLException {

        int totalHours = course.getTotalHoursPerWeek();

        for (String day : days) {
            for (Room room : rooms) {
                for (int hour = 8; hour <= 17 - totalHours; hour++) {
                    LocalTime startTime = LocalTime.of(hour, 0);
                    LocalTime endTime = startTime.plusHours(totalHours);

                    if (!hasConflictForBlock(day, room.idRoom, professorId, startTime, endTime)) {
                        // Kreiraj termine za svaki sat pojedinačno
                        LocalTime currentTime = startTime;

                        // Predavanja
                        for (int i = 0; i < course.lecturesPerWeek; i++) {
                            LocalDateTime startsAt = convertDayToDate(day, currentTime);
                            LocalDateTime endsAt = startsAt.plusHours(1);
                            saveToAcademicEvent(scheduleId, course.idCourse, professorId,
                                    day, startsAt, endsAt, room.idRoom, "LECTURE");
                            addEventToCache(course.idCourse, room.idRoom, professorId,
                                    day, currentTime, currentTime.plusHours(1), "LECTURE", scheduleId);
                            currentTime = currentTime.plusHours(1);
                        }

                        // Vježbe
                        for (int i = 0; i < course.exercisesPerWeek; i++) {
                            LocalDateTime startsAt = convertDayToDate(day, currentTime);
                            LocalDateTime endsAt = startsAt.plusHours(1);
                            saveToAcademicEvent(scheduleId, course.idCourse, professorId,
                                    day, startsAt, endsAt, room.idRoom, "EXERCISE");
                            addEventToCache(course.idCourse, room.idRoom, professorId,
                                    day, currentTime, currentTime.plusHours(1), "EXERCISE", scheduleId);
                            currentTime = currentTime.plusHours(1);
                        }

                        // Lab
                        for (int i = 0; i < course.labsPerWeek; i++) {
                            LocalDateTime startsAt = convertDayToDate(day, currentTime);
                            LocalDateTime endsAt = startsAt.plusHours(1);
                            saveToAcademicEvent(scheduleId, course.idCourse, professorId,
                                    day, startsAt, endsAt, room.idRoom, "LAB");
                            addEventToCache(course.idCourse, room.idRoom, professorId,
                                    day, currentTime, currentTime.plusHours(1), "LAB", scheduleId);
                            currentTime = currentTime.plusHours(1);
                        }

                        System.out.println("  -> Scheduled: " + course.name + " - " +
                                totalHours + " hours in 1 block on " + day + " @ " + startTime);
                        return 1; // Uspješno kao 1 blok
                    }
                }
            }
        }
        return 0;
    }

    /**
     * Provjerava konflikt za cijeli blok termina (od start do end)
     */
    private boolean hasConflictForBlock(String day, int roomId, int professorId,
            LocalTime startTime, LocalTime endTime) {
        // Provjeri svaki sat u bloku
        LocalTime current = startTime;
        while (current.isBefore(endTime)) {
            if (hasConflict(day, null, roomId, professorId, current, current.plusHours(1))) {
                return true;
            }
            current = current.plusHours(1);
        }
        return false;
    }

    /**
     * Helper za dodavanje eventa u memorijski keš
     */
    private void addEventToCache(int courseId, int roomId, int profId,
            String day, LocalTime start, LocalTime end, String type, int scheduleId) {
        AcademicEvent newEvent = new AcademicEvent();
        newEvent.idCourse = courseId;
        newEvent.idRoom = roomId;
        newEvent.idProfessor = profId;
        newEvent.day = day;
        newEvent.startTime = start;
        newEvent.endTime = end;
        newEvent.typeEnum = type;
        newEvent.scheduleId = scheduleId;
        newEvent.date = convertDayToDate(day, start).toLocalDate();
        newEvent.participants = getEventParticipants(courseId, profId,
                "EXERCISE".equals(type) || "LAB".equals(type));
        academicEvents.put(newEvent.hashCode(), newEvent);
        eventsByScheduleIdThisRun.computeIfAbsent(scheduleId, k -> new ArrayList<>()).add(newEvent);
    }

    /**
     * Vraća listu preferiranih dana za profesora
     */
    private List<String> getPreferredDays(int professorId) {
        List<String> days = new ArrayList<>();
        List<int[]> rows = availabilityByProfessor.get(professorId);
        if (rows != null) {
            for (int[] row : rows) {
                // weekday je u bazi int 1..5 (professor_api.php save_availability), ne ime dana
                String dayName = weekdayIntToName(row[1]);
                if (dayName != null && !days.contains(dayName))
                    days.add(dayName);
            }
        }
        return days;
    }

    /**
     * Kao getPreferredDays(), ali ako profesor ima termine raspoloživosti koje je
     * eksplicitno vezao za OVAJ predmet (professor_availability.course_id), vraća
     * SAMO te dane (profesor je tražio da baš taj predmet ide baš tim danima).
     * Ako nema takvih termina, ponaša se identično kao getPreferredDays().
     */
    private List<String> getPreferredDaysForCourse(int professorId, int courseId) {
        List<String> days = new ArrayList<>();
        for (int[] row : getAvailabilitySlotsForCourse(professorId, courseId)) {
            String dayName = weekdayIntToName(row[1]);
            if (dayName != null && !days.contains(dayName))
                days.add(dayName);
        }
        return days;
    }

    /**
     * Termini raspoloživosti profesora koji važe za dati predmet: ako je profesor
     * neke termine eksplicitno vezao za OVAJ predmet (course_id) - samo ti; inače
     * njegovi opšti termini (bez course_id), jer je termin vezan za DRUGI predmet
     * rezervisan za taj predmet. Ako profesor ima samo termine vezane za druge
     * predmete, koriste se svi. Prazna lista = profesor nije unio raspoloživost,
     * pa za njega nema ograničenja (ponašanje kao i ranije).
     */
    private List<int[]> getAvailabilitySlotsForCourse(int professorId, int courseId) {
        List<int[]> rows = availabilityByProfessor.get(professorId);
        if (rows == null || rows.isEmpty()) {
            return Collections.emptyList();
        }
        List<int[]> bound = new ArrayList<>();
        List<int[]> general = new ArrayList<>();
        for (int[] row : rows) {
            if (row[0] == courseId) {
                bound.add(row);
            } else if (row[0] == -1) {
                general.add(row);
            }
        }
        if (!bound.isEmpty()) {
            return bound;
        }
        return general.isEmpty() ? rows : general;
    }

    /**
     * Da li je profesor raspoloživ CIJELIM trajanjem termina [start, end) datog dana,
     * prema svojoj unesenoj raspoloživosti (dan + vrijeme od-do). Susjedni termini
     * (npr. 8-10 i 10-12) se spajaju. Profesor bez unesene raspoloživosti (ili
     * nepoznat profesor, id <= 0) nema ograničenja.
     */
    private boolean isProfessorAvailable(int professorId, int courseId, String day,
            LocalTime start, LocalTime end) {
        if (professorId <= 0) {
            return true;
        }
        List<int[]> slots = getAvailabilitySlotsForCourse(professorId, courseId);
        if (slots.isEmpty()) {
            return true;
        }
        int weekday = weekdayNameToInt(day);
        List<int[]> daySlots = new ArrayList<>();
        for (int[] row : slots) {
            if (row[1] == weekday) {
                daySlots.add(row);
            }
        }
        if (daySlots.isEmpty()) {
            return false;
        }
        daySlots.sort(Comparator.comparingInt(r -> r[2]));
        int from = start.toSecondOfDay() / 60;
        int to = end.toSecondOfDay() / 60;
        int covered = from;
        for (int[] row : daySlots) {
            if (row[2] <= covered && row[3] > covered) {
                covered = row[3];
            }
            if (covered >= to) {
                return true;
            }
        }
        return covered >= to;
    }

    /**
     * Dodatni zahtjevi iz termina raspoloživosti profesora koji se preklapaju sa
     * [start, end) datog dana: {potrebna rač. sala (0/1), željena sala (0 = nema)}.
     * Rač. sala je obavezna, željena sala se samo proba prva (vidi findFreeBlockOnDay).
     */
    private int[] getSlotRequirements(List<Integer> professorIds, int courseId, String day,
            LocalTime start, LocalTime end) {
        int weekday = weekdayNameToInt(day);
        int from = start.toSecondOfDay() / 60;
        int to = end.toSecondOfDay() / 60;
        int needsLab = 0;
        int preferredRoom = 0;
        for (int pid : professorIds) {
            for (int[] row : getAvailabilitySlotsForCourse(pid, courseId)) {
                if (row[1] != weekday || row[2] >= to || row[3] <= from) continue;
                if (row[4] == 1) needsLab = 1;
                if (preferredRoom == 0 && row[5] > 0) preferredRoom = row[5];
            }
        }
        return new int[] { needsLab, preferredRoom };
    }

    private boolean hasRoomPreference(List<Integer> professorIds, int courseId, String day) {
        int weekday = weekdayNameToInt(day);
        for (int pid : professorIds) {
            for (int[] row : getAvailabilitySlotsForCourse(pid, courseId)) {
                if (row[1] == weekday && row[5] > 0) return true;
            }
        }
        return false;
    }

    private int weekdayNameToInt(String day) {
        if (day == null) return 0;
        switch (day) {
            case "ponedeljak": return 1;
            case "utorak": return 2;
            case "srijeda": return 3;
            case "cetvrtak": return 4;
            case "petak": return 5;
            default: return 0;
        }
    }

    /**
     * Mapira weekday int (1=ponedeljak ... 5=petak) iz professor_availability
     * na ime dana koje ostatak koda (parseDayOfWeek i dr.) očekuje.
     */
    private String weekdayIntToName(int weekday) {
        switch (weekday) {
            case 1: return "ponedeljak";
            case 2: return "utorak";
            case 3: return "srijeda";
            case 4: return "cetvrtak";
            case 5: return "petak";
            default: return null;
        }
    }

    /**
     * Vraća listu odgovarajućih sala
     */
    private List<Room> getSuitableRooms(boolean needsComputerLab, int minCapacity) {
        List<Room> result = new ArrayList<>();
        for (Room room : rooms.values()) {
            if (room.isActive && room.capacity >= minCapacity) {
                if (!needsComputerLab || room.isComputerLab) {
                    result.add(room);
                }
            }
        }
        return result;
    }

    /**
     * Pronalazi profesora za predavanja
     */
    private int findLectureProfessor(int courseId) throws SQLException {
        List<Integer> profs = courseProfessorsByCourseAndRole.get(courseId + "_false");
        return (profs != null && !profs.isEmpty()) ? profs.get(0) : 0;
    }

    /**
     * Pronalazi asistenta za vježbe
     */
    private int findExerciseProfessor(int courseId) throws SQLException {
        List<Integer> profs = courseProfessorsByCourseAndRole.get(courseId + "_true");
        return (profs != null && !profs.isEmpty()) ? profs.get(0) : 0;
    }

    private Map<Integer, Double> analyzeProfessorFlexibility() {
        Map<Integer, Double> flexibility = new HashMap<>();

        for (Professor prof : professors.values()) {
            double score = 1.0;

            try {
                // String queryPref = "SELECT COUNT(DISTINCT day) as broj_dana FROM
                // professor_availability " +
                // "WHERE id = ?"; greska: nije day vec weekday
                String queryPref = "SELECT COUNT(DISTINCT weekday) as broj_dana FROM professor_availability " +
                        "WHERE id = ?";
                try (PreparedStatement ps = conn.prepareStatement(queryPref)) {
                    ps.setInt(1, prof.idProfessor);
                    try (ResultSet rs = ps.executeQuery()) {
                        int preferredDays = 0;
                        if (rs.next()) {
                            preferredDays = rs.getInt("broj_dana");
                        }

                        if (preferredDays == 0) {
                            score *= 0.8;
                        } else if (preferredDays == 1) {
                            score *= 0.3;
                        } else if (preferredDays == 2) {
                            score *= 0.5;
                        } else if (preferredDays == 3) {
                            score *= 0.7;
                        } else {
                            score *= 1.0;
                        }
                    }
                }

                int busyTerms = 0;
                for (AcademicEvent event : academicEvents.values()) {
                    if (event.idProfessor == prof.idProfessor) {
                        busyTerms++;
                    }
                }

                if (busyTerms == 0) {
                    score *= 1.0;
                } else if (busyTerms <= 4) {
                    score *= 0.9;
                } else if (busyTerms <= 9) {
                    score *= 0.7;
                } else if (busyTerms <= 14) {
                    score *= 0.5;
                } else if (busyTerms <= 19) {
                    score *= 0.3;
                } else {
                    score *= 0.1;
                }
            } catch (SQLException e) {
                System.err.println("Error analyzing professor " + prof.idProfessor + ": " + e.getMessage());
                score = 0.5;
            }

            flexibility.put(prof.idProfessor, score);
        }

        return flexibility;
    }

    private List<CoursePriority> determinePriorities(Map<Integer, Double> professorFlexibility) {
        List<CoursePriority> priorities = new ArrayList<>();

        for (Course course : courses.values()) {
            try {
                CoursePriority cp = new CoursePriority();
                cp.course = course;

                String queryLec = "SELECT professor_id FROM course_professor WHERE course_id = ? " +
                        "AND is_assistant = false";
                try (PreparedStatement ps = conn.prepareStatement(queryLec)) {
                    ps.setInt(1, course.idCourse);
                    try (ResultSet rs = ps.executeQuery()) {
                        if (rs.next()) {
                            int profId = rs.getInt("professor_id");
                            cp.primaryProfessor = professors.get(profId);
                            cp.primaryFlexibility = professorFlexibility.getOrDefault(profId, 0.5);
                        }
                    }
                }

                String queryEx = "SELECT professor_id FROM course_professor WHERE course_id = ? " +
                        "AND is_assistant = true";
                try (PreparedStatement ps = conn.prepareStatement(queryEx)) {
                    ps.setInt(1, course.idCourse);
                    try (ResultSet rs = ps.executeQuery()) {
                        if (rs.next()) {
                            int profId = rs.getInt("professor_id");
                            cp.secondaryProfessor = professors.get(profId);
                            cp.secondaryFlexibility = professorFlexibility.getOrDefault(profId, 0.5);
                        }
                    }
                }

                double avgFlexibility = (cp.primaryFlexibility + cp.secondaryFlexibility) / 2.0;
                cp.priority = 1.0 - avgFlexibility;

                if (cp.primaryProfessor == null) {
                    cp.priority += 0.5;
                }
                if (cp.secondaryProfessor == null) {
                    cp.priority += 0.5;
                }

                // Dodaj samo predmete koji imaju bar jednog profesora ili asistenta
                if (cp.primaryProfessor != null || cp.secondaryProfessor != null) {
                    priorities.add(cp);
                }
            } catch (SQLException e) {
                System.err.println("Error determining priority for course " + course.idCourse + ": " +
                        e.getMessage());
            }
        }

        priorities.sort((a, b) -> Double.compare(b.priority, a.priority));
        return priorities;
    }

    public String generateLectureSchedule(int courseId) {
        return generateLectureSchedule(courseId, null);
    }

    /**
     * Checks if a course is online (reads is_online boolean from course table)
     */
    private boolean isOnlineClass(Course course) {
        if (course == null)
            return false;
        return course.isOnline;
    }

    public String generateLectureSchedule(int courseId, Integer scheduleIdParam) {
        try {
            Course course = courses.get(courseId);
            if (course == null) {
                return "ERROR: Course does not exist";
            }

            int scheduleId = (scheduleIdParam != null) ? scheduleIdParam : generateNewScheduleId();

            String queryProf = "SELECT professor_id FROM course_professor WHERE course_id = ? " +
                    "AND is_assistant = false";
            int professorId = 0;
            try (PreparedStatement ps = conn.prepareStatement(queryProf)) {
                ps.setInt(1, courseId);
                try (ResultSet rs = ps.executeQuery()) {
                    if (rs.next()) {
                        professorId = (int) rs.getLong("professor_id");
                    } else {
                        return "ERROR: No professor assigned for lectures";
                    }
                }
            }

            Professor professor = professors.get(professorId);
            if (professor == null || !professor.isActive) {
                return "ERROR: Professor not found or not active";
            }

            List<String> preferredDays = new ArrayList<>();
            Map<String, String> preferredTimes = new HashMap<>();

            // String queryPref = "SELECT day, starts_at FROM professor_availability WHERE
            // id = ?";
            String queryPref = "SELECT weekday, start_time FROM professor_availability WHERE id = ?";
            try (PreparedStatement ps = conn.prepareStatement(queryPref)) {
                ps.setInt(1, professorId);
                try (ResultSet rs = ps.executeQuery()) {
                    while (rs.next()) {
                        String day = rs.getString("weekday");
                        Time time = rs.getTime("start_time");
                        preferredDays.add(day);
                        preferredTimes.put(day, time != null ? time.toString() : "09:00:00");
                    }
                }
            }

            if (preferredDays.isEmpty()) {
                preferredDays.addAll(Arrays.asList("ponedeljak", "utorak", "srijeda"));
            }

            List<Room> suitableRooms = new ArrayList<>();
            for (Room room : rooms.values()) {
                // Proveravamo da li je sala aktivna i da li ima dovoljno kapaciteta
                if (room.isActive && room.capacity >= 30) {
                    suitableRooms.add(room);
                }
            }

            if (suitableRooms.isEmpty()) {
                return "ERROR: No suitable rooms for lectures";
            }

            int addedTerms = 0;
            for (String day : preferredDays) {
                for (Room room : suitableRooms) {
                    String timeStr = preferredTimes.getOrDefault(day, "09:00:00");
                    LocalTime startTime = parseTime(timeStr);
                    LocalTime endTime = startTime.plusHours(1);

                    if (!hasConflict(day, null, room.idRoom, professorId, startTime, endTime)) {
                        LocalDateTime startsAt = convertDayToDate(day, startTime);
                        LocalDateTime endsAt = convertDayToDate(day, endTime);

                        saveToAcademicEvent(scheduleId, courseId, professorId, day, startsAt, endsAt, room.idRoom,
                                "LECTURE");

                        AcademicEvent newEvent = new AcademicEvent();
                        newEvent.idCourse = courseId;
                        newEvent.idRoom = room.idRoom;
                        newEvent.idProfessor = professorId;
                        newEvent.day = day;
                        newEvent.startTime = startTime;
                        newEvent.endTime = endTime;
                        newEvent.typeEnum = "LECTURE";
                        newEvent.scheduleId = scheduleId;
                        academicEvents.put(newEvent.idAcademicEvent, newEvent);
                        addedTerms++;
                        break;
                    }
                }
            }

            return "OK: Lectures scheduled (" + addedTerms + " terms, schedule_id: " + scheduleId + ")";
        } catch (Exception e) {
            return "ERROR: " + e.getMessage();
        }
    }

    public String generateExerciseSchedule(int courseId) {
        return generateExerciseSchedule(courseId, null);
    }

    public String generateExerciseSchedule(int courseId, Integer scheduleIdParam) {
        try {
            Course course = courses.get(courseId);
            if (course == null) {
                return "ERROR: Course does not exist";
            }

            int scheduleId = (scheduleIdParam != null) ? scheduleIdParam : generateNewScheduleId();

            // Prvo pokušavamo da pronađemo asistenta
            String queryAssistant = "SELECT professor_id FROM course_professor WHERE course_id = ? " +
                    "AND is_assistant = true";
            int professorId = 0;
            boolean hasAssistant = false;

            try (PreparedStatement ps = conn.prepareStatement(queryAssistant)) {
                ps.setInt(1, courseId);
                try (ResultSet rs = ps.executeQuery()) {
                    if (rs.next()) {
                        professorId = rs.getInt("professor_id");
                        hasAssistant = true;
                    }
                }
            }

            // Ako nema asistenta, koristimo profesora
            if (!hasAssistant) {
                String queryProfessor = "SELECT professor_id FROM course_professor WHERE course_id = ? " +
                        "AND is_assistant = false";
                try (PreparedStatement ps = conn.prepareStatement(queryProfessor)) {
                    ps.setInt(1, courseId);
                    try (ResultSet rs = ps.executeQuery()) {
                        if (rs.next()) {
                            professorId = rs.getInt("professor_id");
                        } else {
                            return "ERROR: No professor assigned for exercises";
                        }
                    }
                }
            }

            Professor professor = professors.get(professorId);
            if (professor == null || !professor.isActive) {
                return "ERROR: Professor not found or not active";
            }

            List<String> preferredDays = new ArrayList<>();
            Map<String, String> preferredTimes = new HashMap<>();

            String queryPref = "SELECT weekday, start_time FROM professor_availability WHERE id = ?";
            try (PreparedStatement ps = conn.prepareStatement(queryPref)) {
                ps.setInt(1, professorId);
                try (ResultSet rs = ps.executeQuery()) {
                    while (rs.next()) {
                        String day = rs.getString("weekday");
                        Time time = rs.getTime("start_time");
                        preferredDays.add(day);
                        preferredTimes.put(day, time != null ? time.toString() : "10:00:00");
                    }
                }
            }

            if (preferredDays.isEmpty()) {
                preferredDays.addAll(Arrays.asList("utorak", "cetvrtak"));
            }

            List<Room> suitableRooms = new ArrayList<>();
            for (Room room : rooms.values()) {
                // Proveravamo da li je sala aktivna (za vežbe mogu i kompjuterske i obične
                // sale)
                if (room.isActive && room.capacity >= 20) {
                    suitableRooms.add(room);
                }
            }

            if (suitableRooms.isEmpty()) {
                return "ERROR: No suitable rooms for exercises";
            }

            int addedTerms = 0;
            for (String day : preferredDays) {
                for (Room room : suitableRooms) {
                    String timeStr = preferredTimes.getOrDefault(day, "10:00:00");
                    LocalTime startTime = parseTime(timeStr);
                    LocalTime endTime = startTime.plusHours(1);

                    if (!hasConflict(day, null, room.idRoom, professorId, startTime, endTime)) {
                        LocalDateTime startsAt = convertDayToDate(day, startTime);
                        LocalDateTime endsAt = convertDayToDate(day, endTime);

                        saveToAcademicEvent(scheduleId, courseId, professorId, day, startsAt, endsAt, room.idRoom,
                                "EXERCISE");

                        AcademicEvent newEvent = new AcademicEvent();
                        newEvent.idCourse = courseId;
                        newEvent.idRoom = room.idRoom;
                        newEvent.idProfessor = professorId;
                        newEvent.day = day;
                        newEvent.startTime = startTime;
                        newEvent.endTime = endTime;
                        newEvent.typeEnum = "EXERCISE";
                        newEvent.scheduleId = scheduleId;
                        academicEvents.put(newEvent.idAcademicEvent, newEvent);
                        addedTerms++;
                        break;
                    }
                }
            }

            return "OK: Exercises scheduled (" + addedTerms + " terms, schedule_id: " + scheduleId + ")";
        } catch (Exception e) {
            return "ERROR: " + e.getMessage();
        }
    }

    private LocalTime parseTime(String timeStr) throws IllegalArgumentException {
        try {
            return LocalTime.parse(timeStr);
        } catch (Exception e) {
            throw new IllegalArgumentException("Invalid time format: " + timeStr +
                    ". Expected HH:mm:ss or HH:mm", e);
        }
    }

    public List<AcademicEvent> getEventsBySchedule(int scheduleIdFilter) throws SQLException {
        List<AcademicEvent> result = new ArrayList<>();
        String sql = "SELECT id, course_id, created_by_professor, type_enum, " +
                "starts_at, ends_at, room_id, notes, schedule_id " +
                "FROM academic_event WHERE schedule_id = ? ORDER BY starts_at";

        try (PreparedStatement ps = conn.prepareStatement(sql)) {
            ps.setInt(1, scheduleIdFilter);
            try (ResultSet rs = ps.executeQuery()) {
                while (rs.next()) {
                    AcademicEvent event = new AcademicEvent();
                    event.idAcademicEvent = rs.getInt("id");
                    event.idCourse = rs.getInt("course_id");
                    event.idRoom = rs.getInt("room_id");
                    event.idProfessor = (int) rs.getLong("created_by_professor");
                    event.typeEnum = rs.getString("type_enum");
                    event.notes = rs.getString("notes");
                    event.scheduleId = rs.getInt("schedule_id");

                    Timestamp startTs = rs.getTimestamp("starts_at");
                    Timestamp endTs = rs.getTimestamp("ends_at");

                    if (startTs != null) {
                        LocalDateTime startLdt = startTs.toLocalDateTime();
                        event.date = startLdt.toLocalDate();
                        event.startTime = startLdt.toLocalTime();
                        event.startsAt = startLdt;
                    }
                    if (endTs != null) {
                        LocalDateTime endLdt = endTs.toLocalDateTime();
                        event.endTime = endLdt.toLocalTime();
                        event.endsAt = endLdt;
                    }

                    result.add(event);
                }
            }
        }
        return result;
    }

    /**
     * Generates 6 different weekly schedules with different year/semester
     * priorities
     * 
     * Priority Order for each schedule:
     * Schedule 1: Year 1 → Year 2 → Year 3
     * Schedule 2: Year 1 → Year 3 → Year 2
     * Schedule 3: Year 2 → Year 1 → Year 3
     * Schedule 4: Year 2 → Year 3 → Year 1
     * Schedule 5: Year 3 → Year 1 → Year 2
     * Schedule 6: Year 3 → Year 2 → Year 1
     * 
     * Within each schedule:
     * 1. Priority year LAB classes (lab classes have labs_per_week > 0)
     * 2. Other years LAB classes
     * 3. Priority year NON-LAB classes (labs_per_week == 0, not online)
     * 4. Other years NON-LAB classes
     * 5. Priority year ONLINE classes (course names: "SIZIS" or "IS")
     * 6. Other years ONLINE classes
     * 
     * Each schedule is independent - conflicts checked only within same schedule
     */

    //region Exams

    public ScheduleResult generateExamScheduleForPeriod(int academicYear, int period) {
        try {
            System.out.println("=== GENERATING EXAM SCHEDULE FOR " + 
                (period == 1 ? "WINTER" : "SUMMER") + " PERIOD ===\n");
            
            // Determine which semesters for this period
            int[] semesters;
            if (period == 1) {
                // Winter: 1, 3, 5
                semesters = new int[]{1, 3, 5};
            } else {
                // Summer: 2, 4, 6
                semesters = new int[]{2, 4, 6};
            }
            
            int totalSuccessful = 0;
            int totalFailed = 0;
            List<FailedCourse> allFailedCourses = new ArrayList<>();
            int mainScheduleId = generateNewScheduleId();
            
            // Generate for each semester
            for (int sem : semesters) {
                System.out.println("\n--- Processing Semester " + sem + " ---\n");
                
                ScheduleResult result = generateColloquiumScheduleForLockedCourses(academicYear, sem);
                
                totalSuccessful += result.successfulCourses;
                totalFailed += result.failedCourses;
                allFailedCourses.addAll(result.failedCoursesList);
            }
            
            // Summary
            ScheduleResult finalResult = new ScheduleResult();
            finalResult.scheduleId = mainScheduleId;
            finalResult.successfulCourses = totalSuccessful;
            finalResult.failedCourses = totalFailed;
            finalResult.failedCoursesList = allFailedCourses;
            finalResult.success = (totalFailed == 0);
            finalResult.message = String.format(
                "Period schedule completed: %d successful, %d failed across %d semesters",
                totalSuccessful, totalFailed, semesters.length
            );
            
            System.out.println("\n" + "=".repeat(60));
            System.out.println("PERIOD SCHEDULE GENERATION COMPLETED");
            System.out.println("=".repeat(60));
            System.out.println("Period: " + (period == 1 ? "WINTER (Sem 1,3,5)" : "SUMMER (Sem 2,4,6)"));
            System.out.println("Total successful events: " + totalSuccessful);
            System.out.println("Total failed courses: " + totalFailed);
            System.out.println("=".repeat(60));
            
            return finalResult;
            
        } catch (Exception e) {
            e.printStackTrace();
            ScheduleResult errorResult = new ScheduleResult();
            errorResult.success = false;
            errorResult.message = "ERROR: " + e.getMessage();
            return errorResult;
        }
    }

    public ScheduleResult generateColloquiumScheduleForLockedCourses(int academicYear, int semester) {
        ScheduleResult result = new ScheduleResult();
        
        try {
            System.out.println("=== GENERATING COLLOQUIUM AND EXAM SCHEDULE FOR LOCKED COURSES ===\n");
            System.out.println("Academic Year: " + academicYear + " | Semester: " + semester + "\n");
            
            // ═══════════════════════════════════════════════════════════════
            // PHASE 0: Find and validate LOCKED schedule
            // ═══════════════════════════════════════════════════════════════
            System.out.println("PHASE 0: Looking for locked schedule...\n");
            
            Integer lockedScheduleId = findLockedScheduleId();
            if (lockedScheduleId == 0) {
                result.success = false;
                result.message = "ERROR: No locked schedule found! ...";
                System.out.println(result.message);
                return result;
            }

            
            if (lockedScheduleId == null) {
                result.success = false;
                result.message = "ERROR: No locked schedule found!\n\n" +
                            "REQUIREMENT: A schedule must be locked by admin before generating exam/colloquium schedule.\n\n" +
                            "HOW TO LOCK A SCHEDULE:\n" +
                            "1. Generate course schedule (lectures and exercises)\n" +
                            "2. Review and approve the schedule\n" +
                            "3. Lock the schedule: UPDATE academic_event SET locked_by_admin = TRUE WHERE id = ?\n\n" +
                            "Once locked, the system will use that schedule as reference for exam scheduling.";
                System.out.println(result.message);
                return result;
            }
            
            System.out.println("✓ Found locked schedule ID: " + lockedScheduleId + "\n");
            
            // Load all events from locked schedule
            List<AcademicEvent> lockedEvents = getEventsByScheduleId(lockedScheduleId);
            System.out.println("✓ Loaded " + lockedEvents.size() + " events from locked schedule\n");
            
            // Filter courses by semester that have events in locked schedule
            List<Course> semesterCourses = new ArrayList<>();
            for (Course course : courses.values()) {
                if (course.semester != semester) continue;
                
                // Check if course has exercise events in locked schedule
                boolean hasExercises = false;
                for (AcademicEvent event : lockedEvents) {

                    // EXPERIMENTAL
                    if("LAB".equals(event.typeEnum)) event.typeEnum = "EXERCISE";
                    // FIX
                    
                    if (event.idCourse == course.idCourse && "EXERCISE".equals(event.typeEnum)) {
                        hasExercises = true;
                        break;
                    }
                }
                
                if (hasExercises) {
                    semesterCourses.add(course);
                    System.out.println("  ✓ " + course.name + ": Has exercise schedule");
                }
            }
            
            System.out.println();
            
            if (semesterCourses.isEmpty()) {
                result.success = false;
                result.message = "ERROR: No courses with exercise schedules found in locked schedule for semester " + semester;
                System.out.println(result.message);
                return result;
            }
            
            System.out.println("Found " + semesterCourses.size() + " courses with exercises in locked schedule\n");
            
            // ═══════════════════════════════════════════════════════════════
            // Generate new schedule ID for exams/colloquiums
            // ═══════════════════════════════════════════════════════════════
            int newScheduleId = generateNewScheduleId();
            List<FailedCourse> failedList = new ArrayList<>();
            
            // Determine schedule weeks
            LocalDate semStart = (semester % 2 != 0) ? winterStart : summerStart;
            if (semStart == null) {
                result.success = false;
                result.message = "ERROR: No active academic year defined";
                return result;
            }
            
            List<LocalDate> colloquium1Weeks = getWeekDates(academicYear, semester, 5, 7);
            List<LocalDate> colloquium2Weeks = getWeekDates(academicYear, semester, 9, 11);
            List<LocalDate> retakeColloquiumWeeks = getWeekDates(academicYear, semester, 12, 14);

            int colloquium1Count = 0;
            int colloquium2Count = 0;
            int retakeColloquiumCount = 0;
            
            int weekdayColloquiums = 0;
            int saturdayColloquiums = 0;
            
            System.out.println("PHASE 1: Scheduling Colloquium 1 (Week 5-7)...\n");
            
           List<Course> semesterCoursesAdded = new ArrayList<>();

            
            // fix this
            for (Course course : semesterCourses) {
                if (course.colloquium1Week != null && course.colloquium1Week > 0) {
                    int professorId = findLectureProfessor(course.idCourse);
                    if (professorId == 0) continue;
                    
                    SchedulingResult schedResult = scheduleColloquiumDuringExerciseTime(
                        newScheduleId, course, professorId, colloquium1Weeks, 
                        "COLLOQUIUM_1", failedList, lockedEvents
                    );

                    

                    
                    if (schedResult.success) {
                        if (!semesterCoursesAdded.contains(course)) {
                            semesterCoursesAdded.add(course);
                            colloquium1Count++;
                        }
                        weekdayColloquiums += schedResult.weekdayCount;
                        saturdayColloquiums += schedResult.saturdayCount;
                    }
                }
            }
            // fix this
            
            System.out.println("✓ Colloquium 1: " + colloquium1Count + " courses (" + 
                            weekdayColloquiums + " weekday, " + saturdayColloquiums + " Saturday)\n");
            
            int weekday1 = weekdayColloquiums;
            weekdayColloquiums = 0;
            saturdayColloquiums = 0;
            
            semesterCoursesAdded.clear();
            System.out.println("PHASE 2: Scheduling Colloquium 2 (Week 9-11)...\n");
            for (Course course : semesterCourses) {
                if (course.colloquium2Week != null && course.colloquium2Week > 0) {
                    int professorId = findLectureProfessor(course.idCourse);
                    if (professorId == 0) continue;
                    
                    SchedulingResult schedResult = scheduleColloquiumDuringExerciseTime(
                        newScheduleId, course, professorId, colloquium2Weeks, 
                        "COLLOQUIUM_2", failedList, lockedEvents
                    );
                    
                    if (schedResult.success) {
                        if (!semesterCoursesAdded.contains(course)) {
                            semesterCoursesAdded.add(course);
                            colloquium2Count++;
                        }
                        weekdayColloquiums += schedResult.weekdayCount;
                        saturdayColloquiums += schedResult.saturdayCount;
                    }
                }
            }
            System.out.println("✓ Colloquium 2: " + colloquium2Count + " courses (" + 
                            weekdayColloquiums + " weekday, " + saturdayColloquiums + " Saturday)\n");
            
            int weekday2 = weekdayColloquiums;
            weekdayColloquiums = 0;
            saturdayColloquiums = 0;
            
            semesterCoursesAdded.clear();
            System.out.println("PHASE 3: Scheduling Retake Colloquiums (Week 12-14)...\n");
            for (Course course : semesterCourses) {
                int professorId = findLectureProfessor(course.idCourse);
                if (professorId == 0) continue;
                
                SchedulingResult schedResult = scheduleColloquiumDuringExerciseTime(
                    newScheduleId, course, professorId, retakeColloquiumWeeks, 
                    "RETAKE_COLLOQUIUM", failedList, lockedEvents
                );
                
                if (schedResult.success) {
                    if (!semesterCoursesAdded.contains(course)) {
                        semesterCoursesAdded.add(course);
                        retakeColloquiumCount++;
                    }
                    weekdayColloquiums += schedResult.weekdayCount;
                    saturdayColloquiums += schedResult.saturdayCount;
                        
                }
            }
            System.out.println("✓ Retake Colloquiums: " + retakeColloquiumCount + " courses (" + 
                            weekdayColloquiums + " weekday, " + saturdayColloquiums + " Saturday)\n");
            
            int weekday3 = weekdayColloquiums;
            int totalWeekday = weekday1 + weekday2 + weekday3;
            
        
            // ═══════════════════════════════════════════════════════════════
            // VALIDATION: Check if ANY weekday colloquiums were scheduled
            // ═══════════════════════════════════════════════════════════════
            int totalEvents = colloquium1Count + colloquium2Count + retakeColloquiumCount;
            
            if (totalWeekday == 0 && (colloquium1Count + colloquium2Count + retakeColloquiumCount) > 0) {
                result.success = false;
                result.message = "ERROR: Schedule generation ABORTED!\n\n" +
                            "REASON: All colloquiums were scheduled on SATURDAY only.\n" +
                            "This means exercise time slots from locked schedule are NOT available in target weeks.\n\n" +
                            "LOCKED SCHEDULE: " + lockedScheduleId + "\n" +
                            "TARGET WEEKS: Colloquium 1 (week 7-8), Colloquium 2 (week 13-14), Retake (week 15)\n\n" +
                            "SOLUTION: Verify that locked schedule has exercise slots during the colloquium weeks.";
                
                System.out.println("\n" + "!".repeat(70));
                System.out.println(result.message);
                System.out.println("!".repeat(70));
                
                deleteSchedule(newScheduleId);
                return result;
            }
            
            // Generate summary
            StringBuilder summary = new StringBuilder();
            summary.append("\n").append("=".repeat(50)).append("\n");
            summary.append("COMPLETE SCHEDULE GENERATION COMPLETED\n");
            summary.append("=".repeat(50)).append("\n");
            summary.append("Schedule ID: ").append(newScheduleId).append("\n");
            summary.append("Based on locked schedule: ").append(lockedScheduleId).append("\n");
            summary.append("Academic Year: ").append(academicYear).append("\n");
            summary.append("Semester: ").append(semester).append("\n\n");
            summary.append("STATISTICS:\n");
            summary.append(" • Colloquium 1: ").append(colloquium1Count).append("\n");
            summary.append(" • Colloquium 2: ").append(colloquium2Count).append("\n");
            summary.append(" • Retake Colloquiums: ").append(retakeColloquiumCount).append("\n");
            summary.append(" • Total events: ").append(totalEvents).append("\n");
            summary.append(" • Weekday colloquiums: ").append(totalWeekday).append("\n");
            summary.append(" • Failed courses: ").append(failedList.size()).append("\n");
            summary.append("=".repeat(50));
            
            System.out.println(summary.toString());
            
            loadAcademicEvents();
            
            result.scheduleId = newScheduleId;
            result.successfulCourses = totalEvents;
            result.failedCourses = failedList.size();
            result.failedCoursesList = failedList;
            result.success = (failedList.size() == 0);
            result.message = result.success ? 
                "OK: All colloquiums, exams, and retakes scheduled successfully (based on locked schedule " + lockedScheduleId + ")" :
                "WARNING: Some courses could not be scheduled";
            
            return result;
            
        } catch (Exception e) {
            e.printStackTrace();
            result.success = false;
            result.message = "ERROR: " + e.getMessage();
            return result;
        }
    }


    private Integer findLockedScheduleId() throws SQLException {
        String query = "SELECT DISTINCT schedule_id FROM academic_event " +
                    "WHERE locked_by_admin = TRUE AND schedule_id IS NOT NULL " +
                    "ORDER BY schedule_id DESC LIMIT 1";
        
        try (Statement stmt = conn.createStatement(); 
            ResultSet rs = stmt.executeQuery(query)) {
            if (rs.next()) {
                int scheduleId = rs.getInt("schedule_id");
                System.out.println("✓ Found locked schedule ID: " + scheduleId);
                return scheduleId;
            }
        }
        System.out.println("⚠ No locked schedule found!");
        return null;  // Return null instead of 0
    }




    private List<AcademicEvent> getEventsByScheduleId(int scheduleIdFilter) throws SQLException {
    List<AcademicEvent> result = new ArrayList<>();
    String sql = "SELECT id, course_id, created_by_professor, type_enum, starts_at, ends_at, " +
                 "room_id, notes, schedule_id, day " +  // ← ADD 'day' HERE
                 "FROM academic_event WHERE schedule_id = ? ORDER BY starts_at";
    
    try (PreparedStatement ps = conn.prepareStatement(sql)) {
        ps.setInt(1, scheduleIdFilter);
        try (ResultSet rs = ps.executeQuery()) {
            while (rs.next()) {
                AcademicEvent event = new AcademicEvent();
                event.idAcademicEvent = rs.getInt("id");
                event.idCourse = rs.getInt("course_id");
                event.idRoom = rs.getInt("room_id");
                event.idProfessor = (int) rs.getLong("created_by_professor");
                event.typeEnum = rs.getString("type_enum");
                event.notes = rs.getString("notes");
                event.scheduleId = rs.getInt("schedule_id");
                event.day = rs.getString("day");  // ← ADD THIS LINE
                
                Timestamp startTs = rs.getTimestamp("starts_at");
                Timestamp endTs = rs.getTimestamp("ends_at");
                
                if (startTs != null) {
                    LocalDateTime startLdt = startTs.toLocalDateTime();
                    event.date = startLdt.toLocalDate();
                    event.startTime = startLdt.toLocalTime();
                    event.startsAt = startLdt;
                }
                
                if (endTs != null) {
                    LocalDateTime endLdt = endTs.toLocalDateTime();
                    event.endTime = endLdt.toLocalTime();
                    event.endsAt = endLdt;
                }
                
                result.add(event);
            }
        }
    }
    return result;
}




    private static class SchedulingResult {
        boolean success;
        int weekdayCount;
        int saturdayCount;
        Boolean moreThanOneGroup;
        
        SchedulingResult(boolean success, int weekday, int saturday, Boolean state) {
            this.success = success;
            this.weekdayCount = weekday;
            this.saturdayCount = saturday;
            moreThanOneGroup = state;
        }
    }

    private SchedulingResult scheduleColloquiumDuringExerciseTime(
        int scheduleId, 
        Course course, 
        int professorId,
        List<LocalDate> availableWeeks, 
        String colloquiumType,
        List<FailedCourse> failedList, 
        List<AcademicEvent> lockedEvents
    ) {
        try {
            // Find EXERCISE slots for this course FROM LOCKED SCHEDULE ONLY
            List<AcademicEvent> exerciseSlots = new ArrayList<>();
            
            for (AcademicEvent event : lockedEvents) {
                if (event.idCourse == course.idCourse) {
                    if ("EXERCISE".equals(event.typeEnum) || "LAB".equals(event.typeEnum)) {
                        exerciseSlots.add(event);
                    }
                }
            }
            
            // PRIORITY: Use LAB slots first (if available), otherwise EXERCISE
            List<AcademicEvent> targetSlots;
            String slotType;
            
            if (!exerciseSlots.isEmpty()) {
                targetSlots = exerciseSlots;
                slotType = "EXERCISE";
                System.out.println("  → " + course.name + ": Found " + exerciseSlots.size() + " EXERCISE groups in locked schedule");
            } else {
                failedList.add(new FailedCourse(course.idCourse, course.name, 
                    "No EXERCISE slots found in locked schedule", course.semester));
                System.out.println("  ✗ " + course.name + ": No EXERCISE slots in locked schedule");
                return new SchedulingResult(false, 0, 0, true);
            }
            
            int successfullyScheduled = 0;
            int weekdayCount = 0;
            int saturdayCount = 0;
            Boolean alreadyAdded = false;
            // For each LAB/EXERCISE group, schedule colloquium at same time
            for (int groupIndex = 0; groupIndex < targetSlots.size(); groupIndex++) {
                AcademicEvent slot = targetSlots.get(groupIndex);
                
                LocalDate colloquiumDate = null;
                Room selectedRoom = null;
                LocalTime colloquiumStart = slot.startTime;
                LocalTime colloquiumEnd = slot.endTime;
                boolean isSaturday = false;
                
                // PRIORITY 1: Try same time as LAB/EXERCISE but ANY weekday in target week
                for (LocalDate weekDate : availableWeeks) {
                    // Try Monday through Friday
                    DayOfWeek[] daysOfWeek = new DayOfWeek[] { 
                        DayOfWeek.MONDAY, DayOfWeek.TUESDAY, DayOfWeek.WEDNESDAY, 
                        DayOfWeek.THURSDAY, DayOfWeek.FRIDAY 
                    };
                    
                    for (DayOfWeek dow : daysOfWeek) {
                        LocalDate candidateDate = weekDate.with(TemporalAdjusters.nextOrSame(dow));
                        
                        // Must be within week range.
                        if (!isDateInWeekRange(candidateDate, availableWeeks)) continue;
                        if (isHoliday(candidateDate)) continue;
                        if (candidateDate.getDayOfWeek() == DayOfWeek.SUNDAY) continue;

                        // Ovdje prekida kod error glitch fix
                        // Kolokvijumi sa vise grupa se dodaju kao dupli
                        int colloquiumsThisWeek = countTestsInWeek(scheduleId, candidateDate, course);
                        System.out.println(" There's " + colloquiumsThisWeek + " quolloquiums ");
                        if (colloquiumsThisWeek >= 2) continue;
                        // Try same room as LAB/EXERCISE
                        Room slotRoom = rooms.get(slot.idRoom);
                        if (slotRoom != null && slotRoom.isActive) {
                            if (!hasConflictForDateInSchedule(scheduleId, candidateDate, slotRoom.idRoom, professorId, colloquiumStart, colloquiumEnd, course))
                            {
                                colloquiumDate = candidateDate;
                                selectedRoom = slotRoom;
            
                                System.out.println("    ✓ Group " + (groupIndex + 1) + ": Same time/room as " + slotType + " on " + dow + " - " + colloquiumDate + " at " + colloquiumStart + " in room " + slotRoom.idRoom + " with course id: " + course.idCourse);
                                break;
                            }
                        }
                        
                        // Try alternative room at same time
                        List<Room> alternativeRooms = getSuitableRooms(false, 20);
                        for (Room altRoom : alternativeRooms) {
                            if (!hasConflictForDateInSchedule(scheduleId, candidateDate, altRoom.idRoom, 
                                                            professorId, colloquiumStart, colloquiumEnd, 
                                                            course)) {
                                colloquiumDate = candidateDate;
                                selectedRoom = altRoom;
                                System.out.println("    ✓ Group " + (groupIndex + 1) + ": Same time on " + dow + ", different room");
                                break;
                            }
                        }
                        
                        if (colloquiumDate != null) break;
                    }
                    
                    if (colloquiumDate != null) break;
                }
                
                // PRIORITY 2: Try other weekday slots
                if (colloquiumDate == null) {
                    System.out.println("    → Group " + (groupIndex + 1) + ": Trying other weekday slots...");
                    
                    for (LocalDate weekDate : availableWeeks) {
                        DayOfWeek[] daysOfWeek = new DayOfWeek[] { DayOfWeek.MONDAY, DayOfWeek.TUESDAY, DayOfWeek.WEDNESDAY, DayOfWeek.THURSDAY, DayOfWeek.FRIDAY };
                        for (DayOfWeek dow : daysOfWeek) {
                            LocalDate candidateDate = weekDate.with(TemporalAdjusters.nextOrSame(dow));
                            
                            if (!isDateInWeekRange(candidateDate, availableWeeks)) continue;
                            if (isHoliday(candidateDate)) continue;
                            if (countTestsInWeek(scheduleId, candidateDate, course) >= 2) continue;
                            
                            LocalTime[] timeSlots = {
                                LocalTime.of(8, 0), LocalTime.of(8, 15), LocalTime.of(8, 30), LocalTime.of(8, 45),
                                LocalTime.of(9, 0), LocalTime.of(9, 15), LocalTime.of(9, 30), LocalTime.of(9, 45),
                                LocalTime.of(10, 0), LocalTime.of(10, 15), LocalTime.of(10, 30), LocalTime.of(10, 45),
                                LocalTime.of(11, 0), LocalTime.of(11, 15), LocalTime.of(11, 30), LocalTime.of(11, 45),
                                LocalTime.of(12, 0), LocalTime.of(12, 15), LocalTime.of(12, 30), LocalTime.of(12, 45),
                                LocalTime.of(13, 0), LocalTime.of(13, 15), LocalTime.of(13, 30), LocalTime.of(13, 45),
                                LocalTime.of(14, 0), LocalTime.of(14, 15), LocalTime.of(14, 30), LocalTime.of(14, 45),
                                LocalTime.of(15, 0), LocalTime.of(15, 15), LocalTime.of(15, 30), LocalTime.of(15, 45),
                                LocalTime.of(16, 0), LocalTime.of(16, 15), LocalTime.of(16, 30), LocalTime.of(16, 45),
                                LocalTime.of(17, 0), LocalTime.of(17, 15), LocalTime.of(17, 30), LocalTime.of(17, 45),
                                LocalTime.of(18, 0), LocalTime.of(18, 15), LocalTime.of(18, 30), LocalTime.of(18, 45)
                            };
                            
                            for (LocalTime start : timeSlots) {
                                LocalTime end = start.plusHours(2);
                                
                                List<Room> availableRooms = getSuitableRooms(false, 20);
                                for (Room room : availableRooms) {
                                    if (!hasConflictForDateInSchedule(scheduleId, candidateDate, room.idRoom, 
                                                                    professorId, start, end, 
                                                                    course)) {
                                        colloquiumDate = candidateDate;
                                        selectedRoom = room;
                                        colloquiumStart = start;
                                        colloquiumEnd = end;
                                        System.out.println("    ✓ Group " + (groupIndex + 1) + ": Weekday slot at " + start + " (" + getCroatianDayName(dow) + ")");
                                        break;
                                    }
                                }
                                if (colloquiumDate != null) break;
                            }
                            if (colloquiumDate != null) break;
                        }
                        if (colloquiumDate != null) break;
                    }
                }
                
                // PRIORITY 3: LAST RESORT - Saturday
                if (colloquiumDate == null) {
                    System.out.println("    → Group " + (groupIndex + 1) + ": Trying Saturday backup...");
                    
                    for (LocalDate weekDate : availableWeeks) {
                        LocalDate saturday = weekDate.with(TemporalAdjusters.nextOrSame(DayOfWeek.SATURDAY));
                        
                        if (isHoliday(saturday)) continue;
                        if (countTestsInWeek(scheduleId, saturday, course) >= 2) continue;
                        
                        List<Room> availableRooms = getSuitableRooms(false, 20);
                        for (Room room : availableRooms) {
                            if (!hasConflictForDateInSchedule(scheduleId, saturday, room.idRoom, 
                                                            professorId, colloquiumStart, colloquiumEnd, 
                                                            course)) {
                                colloquiumDate = saturday;
                                selectedRoom = room;
                                isSaturday = true;
                                System.out.println("    ✓ Group " + (groupIndex + 1) + ": Saturday backup");
                                break;
                            }
                        }
                        if (colloquiumDate != null) break;
                    }
                }
                
                // Could not schedule this group
                if (colloquiumDate == null || selectedRoom == null) {
                    System.out.println("    ✗ Group " + (groupIndex + 1) + ": Could not find slot");
                    continue;
                }
                
                // Save colloquium
                LocalDateTime startsAt = LocalDateTime.of(colloquiumDate, colloquiumStart);
                LocalDateTime endsAt = LocalDateTime.of(colloquiumDate, colloquiumEnd);
                String dayName = getCroatianDayName(colloquiumDate.getDayOfWeek());
                String notes = "Group " + (groupIndex + 1) + " - replaces " + slotType;
                
                saveColloquiumToDatabase(scheduleId, course.idCourse, professorId, dayName,
                                        startsAt, endsAt, selectedRoom.idRoom, colloquiumType, notes);
                
                // Count this group (but we'll only count it as 1 course overall)
                if(!alreadyAdded){
                    alreadyAdded = true;
                }
                successfullyScheduled++;
                if (isSaturday) {
                    saturdayCount++;
                } else {
                    weekdayCount++;
                }
            }
            
            // Check if at least one group was scheduled
            if (successfullyScheduled == 0) {
                failedList.add(new FailedCourse(course.idCourse, course.name, 
                    "Could not schedule any colloquium groups", course.semester));
                return new SchedulingResult(false, 0, 0, true);
            }
            
            // Return success=true for this COURSE (not per group)
            // But preserve the actual weekday/saturday counts (which may be > 1 if multiple groups)
            return new SchedulingResult(true, weekdayCount, saturdayCount, true);
            
        } catch (Exception e) {
            failedList.add(new FailedCourse(course.idCourse, course.name, 
                "Exception: " + e.getMessage(), course.semester));
            return new SchedulingResult(false, 0, 0, false);
        }
    }

    private boolean isDateInWeekRange(LocalDate date, List<LocalDate> weekDates) {
        if (weekDates.isEmpty()) return false;
        
        LocalDate firstDate = weekDates.get(0);
        LocalDate lastDate = weekDates.get(weekDates.size() - 1);
        return !date.isBefore(firstDate) && !date.isAfter(lastDate);
    }

    private boolean isHoliday(LocalDate date) {
        for (Holiday holiday : holidays) {
            if (holiday.date.equals(date)) {
                return true;
            }
        }
        return false;
    }


    private int countTestsInWeek(int scheduleId, LocalDate date, Course course) {
        LocalDate weekStart = date.with(TemporalAdjusters.previousOrSame(DayOfWeek.MONDAY));
        LocalDate weekEnd = date.with(TemporalAdjusters.nextOrSame(DayOfWeek.SUNDAY));
        
        int count = 0;
        List<Integer> couresIds = new ArrayList<Integer>();
        
        for (AcademicEvent event : academicEvents.values()) {
            if (event.scheduleId == scheduleId && event.date != null) {
                if (!event.date.isBefore(weekStart) && !event.date.isAfter(weekEnd)) {
                    if (event.typeEnum != null && (event.typeEnum.contains("COLLOQUIUM") || event.typeEnum.contains("EXAM"))) {
                        if(event.idCourse == course.idCourse) continue;
                        if(couresIds.contains(event.idCourse)) continue;
                        couresIds.add(event.idCourse);
                        count++;                                  
                    }
                }
            }
        }
        return count;
    }



    private void deleteSchedule(int scheduleId) throws SQLException {
        String delete1 = "DELETE FROM academic_event WHERE schedule_id = ?";
        String delete2 = "DELETE FROM schedule WHERE id = ?";
        
        try (PreparedStatement ps1 = conn.prepareStatement(delete1)) {
            ps1.setInt(1, scheduleId);
            ps1.executeUpdate();
        }
        
        try (PreparedStatement ps2 = conn.prepareStatement(delete2)) {
            ps2.setInt(1, scheduleId);
            ps2.executeUpdate();
        }
        
        System.out.println("Rolled back schedule ID: " + scheduleId);
    }





    private List<LocalDate> getWeekDates(int academicYear, int semester, int startWeek, int endWeek) {
        List<LocalDate> dates = new ArrayList<>();
        
        LocalDate semStart = (semester % 2 != 0) ? winterStart : summerStart;
        if (semStart == null) return dates;
        
        // Calculate actual weeks from semester start
        LocalDate weekStart = semStart.plusWeeks(startWeek - 1);
        LocalDate weekEnd = semStart.plusWeeks(endWeek);
        
        // Define which days you want (1=Monday, 2=Tuesday, ..., 7=Sunday)
        Set<DayOfWeek> targetDays = Set.of(
            DayOfWeek.MONDAY,
            DayOfWeek.TUESDAY,
            DayOfWeek.WEDNESDAY,
            DayOfWeek.THURSDAY,
            DayOfWeek.FRIDAY,
            DayOfWeek.SATURDAY,
            DayOfWeek.SUNDAY
        );
        
        LocalDate current = weekStart;
        while (!current.isAfter(weekEnd)) {
            if (targetDays.contains(current.getDayOfWeek())) {
                dates.add(current);
            }
            current = current.plusDays(1);
        }
        
        return dates;
    }


    private void saveColloquiumToDatabase(int scheduleId, int courseId, int professorId,
                                        String day, LocalDateTime startsAt, LocalDateTime endsAt,
                                        int roomId, String typeEnum, String notes) throws SQLException {
        String insert = "INSERT INTO academic_event (course_id, created_by_professor, type_enum, " +
                    "starts_at, ends_at, room_id, is_published, locked_by_admin, schedule_id, day, notes) " +
                    "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        try (PreparedStatement pstmt = conn.prepareStatement(insert)) {
            pstmt.setInt(1, courseId);
            pstmt.setLong(2, professorId);
            pstmt.setString(3, typeEnum);
            pstmt.setTimestamp(4, Timestamp.valueOf(startsAt));
            pstmt.setTimestamp(5, Timestamp.valueOf(endsAt));
            pstmt.setInt(6, roomId);
            pstmt.setBoolean(7, true); // ispublished
            pstmt.setBoolean(8, false); // lockedbyadmin
            pstmt.setInt(9, scheduleId);
            pstmt.setString(10, day);
            pstmt.setString(11, notes);
            pstmt.executeUpdate();
            
            // Also add to cache
            AcademicEvent newEvent = new AcademicEvent();
            newEvent.idCourse = courseId;
            newEvent.idProfessor = professorId;
            newEvent.typeEnum = typeEnum;
            newEvent.startsAt = startsAt;
            newEvent.endsAt = endsAt;
            newEvent.date = startsAt.toLocalDate();
            newEvent.startTime = startsAt.toLocalTime();
            newEvent.endTime = endsAt.toLocalTime();
            newEvent.idRoom = roomId;
            newEvent.scheduleId = scheduleId;
            newEvent.day = day;
            newEvent.notes = notes;
            academicEvents.put(newEvent.hashCode(), newEvent);
        }
    }


    private boolean hasConflictForDateInSchedule(int scheduleId, LocalDate date, int roomId, 
                                                int professorId, LocalTime startTime, LocalTime endTime, 
                                                Course course) {
        for (AcademicEvent event : academicEvents.values()) {
            // Only check events in SAME schedule
            if (event.scheduleId != scheduleId) continue;
            
            // Must have same date
            if (event.date == null || !event.date.equals(date)) continue;
            
            // Check time overlap
            if (event.startTime == null || event.endTime == null) continue;
            
            boolean timeOverlap = startTime.isBefore(event.endTime) && endTime.isAfter(event.startTime);
            if (!timeOverlap) continue;
            
            
            // Check room conflict
            if (event.idRoom == roomId) return true;
            
            // Check professor conflict
            if (event.idProfessor == professorId) return true;
            
            // Check semester conflict - students from same semester can't have overlapping events
            Course eventCourse = courses.get(event.idCourse);
            if (eventCourse != null && eventCourse.semester == course.semester) {
                return true;
            }
        }
        
        return false; // No conflict
    }


    //endregion



    //region SixSchedules

    public ScheduleResult generateSixSchedulesWithDifferentPriorities() {
        ScheduleResult result = new ScheduleResult();
        try {
            System.out.println("=== GENERATING 6 SCHEDULES WITH DIFFERENT YEAR PRIORITIES ===\n");

            // Popuni keševe (course_professor, professor_availability) JEDNOM za svih
            // 6 rasporeda umjesto ponovljenih upita po predmetu - vidi preloadStaticLookups().
            preloadStaticLookups();
            eventsByScheduleIdThisRun = new HashMap<>();
            ScheduleProgress.write(0, 6, 0, courses.size(), "Priprema generisanja...", false, null, null);

            // Cijelo generisanje (svih 6 rasporeda) u JEDNOJ transakciji umjesto
            // implicitnog auto-commit-a po pojedinačnom INSERT-u - drastično manje
            // network round-trip + commit latencije ka udaljenoj bazi.
            conn.setAutoCommit(false);

            // Define the 6 different priority orders
            int[][] priorityOrders = {
                    { 1, 2, 3 }, // Schedule 1
                    { 1, 3, 2 }, // Schedule 2
                    { 2, 1, 3 }, // Schedule 3
                    { 2, 3, 1 }, // Schedule 4
                    { 3, 1, 2 }, // Schedule 5
                    { 3, 2, 1 } // Schedule 6
            };

            List<Integer> generatedScheduleIds = new ArrayList<>();
            int successCount = 0;
            int failCount = 0;
            List<FailedCourse> allFailedCourses = new ArrayList<>();


            // Generate each of the 6 schedules
            for (int i = 0; i < 6; i++) {
                int[] priorityOrder = priorityOrders[i];
                System.out.println("\n>>> SCHEDULE " + (i + 1) + " <<<");
                System.out.println("Priority Order: Year " + priorityOrder[0] + " → Year " +
                        priorityOrder[1] + " → Year " + priorityOrder[2]);
                System.out.println("─".repeat(50));

                currentScheduleIndex = i + 1;
                currentCourseCounter = 0;
                ScheduleProgress.write(currentScheduleIndex, 6, 0, courses.size(),
                        "Raspored " + currentScheduleIndex + "/6 u toku...", false, null, null);

                int scheduleId = generateScheduleWithYearPriority(priorityOrder, allFailedCourses);
                if (scheduleId > 0) {
                    generatedScheduleIds.add(scheduleId);
                    successCount++;
                    System.out.println("✓ Schedule " + (i + 1) + " completed with ID: " + scheduleId);
                } else {
                    failCount++;
                    System.out.println("✗ Schedule " + (i + 1) + " failed to generate");
                }
            }

            // Print summary
            StringBuilder summary = new StringBuilder();
            summary.append("\n").append("=".repeat(50)).append("\n");
            summary.append("GENERATION COMPLETE\n");
            summary.append("=".repeat(50)).append("\n");
            summary.append("Successfully generated: ").append(successCount).append("/6 schedules\n\n");

            for (int i = 0; i < generatedScheduleIds.size(); i++) {
                summary.append("Schedule ").append(i + 1).append(": ID = ").append(generatedScheduleIds.get(i));
                int[] order = priorityOrders[i];
                summary.append(" | Priority: ").append(order[0]).append("→")
                        .append(order[1]).append("→").append(order[2]).append("\n");
            }
            summary.append("=".repeat(50));

            System.out.println(summary.toString());

            loadAcademicEvents(); // Reload to reflect all new events

            result.scheduleId = generatedScheduleIds.isEmpty() ? 0 : generatedScheduleIds.get(0);
            result.successfulCourses = successCount;
            result.failedCourses = failCount;
            result.failedCoursesList = allFailedCourses;  // This is the key
            result.success = (failCount == 0);
            
            if (failCount > 0) {
                result.message = "WARNING: Generated " + successCount + "/6 schedules. " +
                            allFailedCourses.size() + " courses failed";
            } else if (!allFailedCourses.isEmpty()) {
                // Rasporedi su generisani, ali neki predmeti nisu mogli biti raspoređeni -
                // admin mora da vidi koji i zašto (ranije je poruka uvijek bila "Ispravno
                // generisani svi rasporedi" iako je u rasporedu falilo i po 8 predmeta).
                // razlog -> (predmet -> u koliko od 6 rasporeda nije raspoređen)
                Map<String, Map<String, Integer>> byReason = new LinkedHashMap<>();
                for (FailedCourse fc : allFailedCourses) {
                    byReason.computeIfAbsent(fc.reason, r -> new TreeMap<>())
                            .merge(fc.courseName, 1, Integer::sum);
                }
                StringBuilder msg = new StringBuilder("Generisano " + successCount
                        + "/6 rasporeda. Neraspoređeni predmeti (u koliko od 6 rasporeda): ");
                int k = 0;
                for (Map.Entry<String, Map<String, Integer>> r : byReason.entrySet()) {
                    if (k++ > 0) msg.append(" | ");
                    int n = 0;
                    for (Map.Entry<String, Integer> e : r.getValue().entrySet()) {
                        if (n++ > 0) msg.append(", ");
                        msg.append(e.getKey()).append(" (").append(e.getValue()).append(")");
                    }
                    msg.append(" - ").append(r.getKey());
                }
                result.message = msg.toString();
            } else {
                result.message = "Ispravno generisani svi rasporedi";
            }

            conn.commit();
            ScheduleProgress.write(6, 6, courses.size(), courses.size(), result.message, true, result.success, null);
            return result;

        } catch (Exception e) {
            try {
                conn.rollback();
            } catch (SQLException rollbackEx) {
                System.err.println("Greska pri rollback-u: " + rollbackEx.getMessage());
            }
            result.success = false;
            result.message = "ERROR: " + e.getMessage();
            e.printStackTrace();
            ScheduleProgress.write(currentScheduleIndex, 6, currentCourseCounter, courses.size(),
                    result.message, true, false, e.getMessage());
            return result;
        } finally {
            try {
                conn.setAutoCommit(true);
            } catch (SQLException autoCommitEx) {
                System.err.println("Greska pri vracanju autoCommit: " + autoCommitEx.getMessage());
            }
        }
    }

    /**
     * Generates a single schedule with specified year priority order
     */
    private int generateScheduleWithYearPriority(int[] yearPriorityOrder, 
                                             List<FailedCourse> allFailedList) throws SQLException {
        int scheduleId = generateNewScheduleId();
        // Predmet koji ne uspije u prvom prolazu pokušava se ponovo u "fill gaps"
        // prolazu - u zajedničku listu ide samo jednom, i samo ako na kraju zaista
        // nije raspoređen u ovom rasporedu.
        List<FailedCourse> failedList = new ArrayList<>();

        // Group courses by year/semester
        Map<Integer, List<Course>> coursesByYear = new HashMap<>();
        for (int year = 1; year <= 3; year++) {
            coursesByYear.put(year, new ArrayList<>());
        }

        // Load and categorize all courses (convert semester 1-6 to year 1-3)
        for (Course course : courses.values()) {
            if (!course.isActive) {
                continue; // deaktiviran predmet ("Deaktiviraj" u adminu) ne ulazi u raspored
            }
            // Semester 1,2 -> Year 1; Semester 3,4 -> Year 2; Semester 5,6 -> Year 3
            int year = (course.semester + 1) / 2;
            // Ensure year is within valid range 1-3
            if (year < 1)
                year = 1;
            if (year > 3)
                year = 3;
            coursesByYear.get(year).add(course);
        }

        int totalScheduled = 0;

        // ===== PHASE 1: LAB CLASSES =====
        System.out.println("Phase 1: Scheduling LAB classes...");

        // First: Priority year labs
        for (int priorityYear : yearPriorityOrder) {
            List<Course> yearCourses = coursesByYear.get(priorityYear);
            for (Course course : yearCourses) {
                if (course.labsPerWeek > 0) {
                    String result = scheduleCourseForSchedule(scheduleId, course, failedList);
                    if (result.equals("OK")) {
                        totalScheduled++;
                    }
                }
            }
        }

        // Then: Non-priority year labs (fill gaps)
        for (int year = 1; year <= 3; year++) {
            List<Course> yearCourses = coursesByYear.get(year);
            for (Course course : yearCourses) {
                if (course.labsPerWeek > 0 &&
                        !isAlreadyScheduledInSchedule(scheduleId, course.idCourse)) {
                    String result = scheduleCourseForSchedule(scheduleId, course, failedList);
                    if (result.equals("OK")) {
                        totalScheduled++;
                    }
                }
            }
        }

        // ===== PHASE 2: NON-LAB CLASSES =====
        System.out.println("Phase 2: Scheduling NON-LAB classes...");

        // First: Priority year non-labs
        for (int priorityYear : yearPriorityOrder) {
            List<Course> yearCourses = coursesByYear.get(priorityYear);
            for (Course course : yearCourses) {
                if (course.labsPerWeek == 0 && !isOnlineClass(course)) {
                    String result = scheduleCourseForSchedule(scheduleId, course, failedList);
                    if (result.equals("OK")) {
                        totalScheduled++;
                    }
                }
            }
        }

        // Then: Non-priority year non-labs (fill gaps)
        for (int year = 1; year <= 3; year++) {
            List<Course> yearCourses = coursesByYear.get(year);
            for (Course course : yearCourses) {
                if (course.labsPerWeek == 0 && !isOnlineClass(course) &&
                        !isAlreadyScheduledInSchedule(scheduleId, course.idCourse)) {
                    String result = scheduleCourseForSchedule(scheduleId, course, failedList);
                    if (result.equals("OK")) {
                        totalScheduled++;
                    }
                }
            }
        }

        // ===== PHASE 3: ONLINE CLASSES =====
        System.out.println("Phase 3: Scheduling ONLINE classes...");

        // First: Priority year online
        for (int priorityYear : yearPriorityOrder) {
            List<Course> yearCourses = coursesByYear.get(priorityYear);
            for (Course course : yearCourses) {
                if (isOnlineClass(course)) {
                    String result = scheduleCourseForSchedule(scheduleId, course, failedList);
                    if (result.equals("OK")) {
                        totalScheduled++;
                    }
                }
            }
        }

        // Then: Non-priority year online (fill gaps)
        for (int year = 1; year <= 3; year++) {
            List<Course> yearCourses = coursesByYear.get(year);
            for (Course course : yearCourses) {
                if (isOnlineClass(course) &&
                        !isAlreadyScheduledInSchedule(scheduleId, course.idCourse)) {
                    String result = scheduleCourseForSchedule(scheduleId, course, failedList);
                    if (result.equals("OK")) {
                        totalScheduled++;
                    }
                }
            }
        }

        // ===== PHASE 4: EXAMS/COLLOQUIUMS =====
        // generateColloquiums(scheduleId, failedList);

        Set<Integer> reported = new HashSet<>();
        for (FailedCourse fc : failedList) {
            if (!isAlreadyScheduledInSchedule(scheduleId, fc.courseId) && reported.add(fc.courseId)) {
                allFailedList.add(fc);
            }
        }

        System.out.println("Total courses scheduled: " + totalScheduled);
        return scheduleId;
    }

    /**
     * Checks if a course is already scheduled in this schedule
     */
    private boolean isAlreadyScheduledInSchedule(int scheduleId, int courseId) {
        List<AcademicEvent> events = eventsByScheduleIdThisRun.get(scheduleId);
        if (events == null) {
            return false;
        }
        for (AcademicEvent event : events) {
            if (event.idCourse == courseId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Schedules a single course for the given schedule
     * Respects existing time slot constraints within the same schedule
     * Returns "OK" on success, error message on failure
     */
    private String scheduleCourseForSchedule(int scheduleId, Course course,
                                            List<FailedCourse> failedList) {
        try {
            if (course == null) {
                failedList.add(new FailedCourse(0, "Unknown", "Course is null", 0));
                return "SKIP";
            }

            currentCourseCounter++;
            ScheduleProgress.write(currentScheduleIndex, 6, currentCourseCounter, courses.size(),
                    "Raspored " + currentScheduleIndex + "/6 • Predmet: " + course.name, false, null, null);

            // Predmet može upasti u više od jedne faze u generateScheduleWithYearPriority
            // (npr. labsPerWeek > 0 I is_online = true istovremeno), pa bi bez ove provjere
            // svaka faza nezavisno rasporedila iste časove predmeta - rezultat su duplirani
            // termini "razbacani" po više dana (fond časova izgleda razbijen iako
            // lectures_per_week/exercises_per_week/labs_per_week nisu problem).
            if (isAlreadyScheduledInSchedule(scheduleId, course.idCourse)) {
                return "ALREADY_SCHEDULED";
            }

            // Predavanja idu u jednom bloku jednog dana, vježbe+lab u jednom bloku
            // drugog dana - nijedan blok ne smije preći dnevni limit semestra (6h).
            // Predmet koji ima SAMO predavanja ili SAMO vježbe/lab (npr. "Projekat",
            // 0+0+2) je ispravan - ranije je pravilo "ukupno 4-6h" takav predmet
            // tiho izbacivalo iz svakog rasporeda.
            int totalHours = course.getTotalHoursPerWeek();
            int exerciseBlockHours = course.exercisesPerWeek + course.labsPerWeek;
            if (totalHours <= 0 || course.lecturesPerWeek > MAX_DAILY_HOURS_PER_SEMESTER
                    || exerciseBlockHours > MAX_DAILY_HOURS_PER_SEMESTER) {
                failedList.add(new FailedCourse(course.idCourse, course.name,
                    "neispravan fond časova P=" + course.lecturesPerWeek + " V+L=" + exerciseBlockHours
                            + " (svaki blok 1-" + MAX_DAILY_HOURS_PER_SEMESTER + "h)", course.semester));
                return "SKIP";
            }

            int lectureProfId = findLectureProfessor(course.idCourse);
            int exerciseProfId = findExerciseProfessor(course.idCourse);
            if (lectureProfId == 0 && exerciseProfId == 0) {
                failedList.add(new FailedCourse(course.idCourse, course.name,
                    "nema dodijeljenog aktivnog profesora", course.semester));
                return "SKIP";
            }
            // Bez asistenta vježbe drži predavač, i obrnuto (predmet samo sa asistentom).
            if (exerciseProfId == 0) {
                exerciseProfId = lectureProfId;
            }
            if (lectureProfId == 0) {
                lectureProfId = exerciseProfId;
            }

            // Dani se biraju po raspoloživosti ONOGA ko drži taj dio nastave:
            // predavanja po predavaču, vježbe/lab po asistentu (ranije su se i vježbe
            // birale po danima predavača, pa asistentova raspoloživost nije imala efekta,
            // a predavač sa samo jednim slobodnim danom nije mogao dobiti nijedan predmet
            // jer vježbe moraju biti drugi dan). Poštuje i zahtjev "ovaj predmet baš
            // tim danima" - vidi getPreferredDaysForCourse.
            List<String> allDays = Arrays.asList("ponedeljak", "utorak", "srijeda", "cetvrtak", "petak");
            List<String> availableDays = getPreferredDaysForCourse(lectureProfId, course.idCourse);
            if (availableDays.isEmpty()) {
                availableDays = new ArrayList<>(allDays);
            }
            List<String> exerciseDays = getPreferredDaysForCourse(exerciseProfId, course.idCourse);
            if (exerciseDays.isEmpty()) {
                exerciseDays = new ArrayList<>(allDays);
            }

            // Get suitable rooms. requiresComputerLab primjenjuje se na predavanja isto
            // kao i na vježbe/lab (nezavisno od labsPerWeek); expectedStudents, kad je
            // postavljen, zamjenjuje default minimalni kapacitet sale.
            int lectureMinCapacity = (course.expectedStudents != null && course.expectedStudents > 0)
                    ? course.expectedStudents : 30;
            int labMinCapacity = (course.expectedStudents != null && course.expectedStudents > 0)
                    ? Math.min(course.expectedStudents, 20) : 20;
            List<Room> lectureRooms = getSuitableRooms(course.requiresComputerLab, lectureMinCapacity);
            List<Room> labRooms = getSuitableRooms(true, labMinCapacity);

            if (!course.isOnline && lectureRooms.isEmpty()) {
                failedList.add(new FailedCourse(course.idCourse, course.name,
                    "nema odgovarajuće sale (kapacitet/računarska sala)", course.semester));
                return "SKIP: No lecture rooms available";
            }

            if (course.labsPerWeek > 0 && labRooms.isEmpty()) {
                labRooms = lectureRooms;
            }

            // Onlajn predmet ne zahtijeva fizičku salu: zamijeni liste sala jednim
            // "virtuelnim" mjestom (idRoom = 0) tako da se dan/sat i dalje traže po
            // dostupnosti profesora, ali se pri upisu termina room_id upisuje kao NULL
            // i termin nikad ne "zauzima" niti sukobljava stvarnu salu.
            if (course.isOnline) {
                Room virtualRoom = new Room();
                virtualRoom.idRoom = 0;
                virtualRoom.code = "ONLINE";
                virtualRoom.capacity = Integer.MAX_VALUE;
                virtualRoom.isComputerLab = true;
                virtualRoom.isActive = true;
                lectureRooms = new ArrayList<>(List.of(virtualRoom));
                labRooms = new ArrayList<>(List.of(virtualRoom));
            }

            List<Room> exerciseRoomList = course.labsPerWeek > 0 ? labRooms : lectureRooms;
            int addedTerms;
            if (course.lecturesPerWeek == 0) {
                // Samo vježbe/lab - jedan blok, po raspoloživosti asistenta
                addedTerms = scheduleAsOneBlockWithinSchedule(scheduleId, course, exerciseProfId,
                        exerciseDays, exerciseRoomList);
            } else if (exerciseBlockHours == 0) {
                // Samo predavanja - jedan blok, po raspoloživosti predavača
                addedTerms = scheduleAsOneBlockWithinSchedule(scheduleId, course, lectureProfId,
                        availableDays, lectureRooms);
            } else {
                // Predavanja jedan dan, vježbe+lab drugi dan
                addedTerms = scheduleAsTwoDaysWithinSchedule(scheduleId, course,
                        lectureProfId, exerciseProfId,
                        availableDays, exerciseDays, lectureRooms, labRooms);

                // Ako ne može u dva dana, predmet od 4h može i u jednom bloku - tada
                // cijeli blok drži predavač, pa mora biti raspoloživ cijelim trajanjem.
                if (addedTerms == 0 && totalHours == 4) {
                    List<Room> blockRooms = lectureRooms;
                    if (course.labsPerWeek > 0 && !course.isOnline) {
                        blockRooms = new ArrayList<>(lectureRooms);
                        blockRooms.retainAll(labRooms);
                    }
                    addedTerms = scheduleAsOneBlockWithinSchedule(scheduleId, course, lectureProfId,
                            availableDays, blockRooms);
                }
            }

            if (addedTerms > 0) {
                if (course.parallelGroups > 1) {
                    scheduleParallelGroups(scheduleId, course, lectureRooms, labRooms);
                }
                return "OK";
            }

            failedList.add(new FailedCourse(course.idCourse, course.name,
                "nema slobodnog termina u raspoloživosti predavača/asistenta (uz slobodnu salu i dnevni limit)", course.semester));
            return "FAILED";


        } catch (SQLException e) {
            return "ERROR: " + e.getMessage();
        }
    }

    /**
     * Vraća SVE profesore/asistente dodijeljene predmetu za datu ulogu
     * (is_assistant = true/false), po redoslijedu dodavanja.
     */
    private List<Integer> getAllProfessorsForRole(int courseId, boolean isAssistant) throws SQLException {
        List<Integer> profs = courseProfessorsByCourseAndRole.get(courseId + "_" + isAssistant);
        return profs != null ? new ArrayList<>(profs) : new ArrayList<>();
    }

    /**
     * Provjerava sukob sale/profesora, BEZ provjere sukoba semestra - koristi se
     * isključivo za smještanje paralelnih grupa ISTOG predmeta u isti termin kao
     * grupa 1. hasConflictInSchedule() bi tu uvijek prijavio "sukob semestra" jer
     * bi upoređivao termin grupe 2 sa terminom grupe 1 istog predmeta/semestra -
     * što je namjerno dozvoljeno (to je definicija paralelne grupe), pa se ovdje
     * provjerava samo da druga grupa ne dijeli istu salu ili istog profesora.
     */
    private boolean hasRoomOrProfessorConflictInSchedule(int scheduleId, String day, int roomId,
            int professorId, LocalTime startTime, LocalTime endTime, int courseSemester) {
        List<AcademicEvent> scheduleEvents = eventsByScheduleIdThisRun.getOrDefault(scheduleId, Collections.emptyList());
        for (AcademicEvent event : scheduleEvents) {
            if (day == null || event.day == null || !event.day.equals(day)) continue;
            if (!isSameTerm(event, courseSemester)) continue;
            if (startTime == null || endTime == null || event.startTime == null || event.endTime == null) continue;

            boolean overlap = startTime.isBefore(event.endTime) && endTime.isAfter(event.startTime);
            if (!overlap) continue;

            if (roomId > 0 && event.idRoom == roomId) return true;
            if (participantsOf(event).contains(professorId)) return true;
        }
        return false;
    }

    /**
     * Za predmete sa parallel_groups > 1: replicira termine koje je grupa 1 upravo
     * dobila (isti dan/vrijeme/tip) za svaku dodatnu grupu, tražeći drugu slobodnu
     * salu i, ako je dostupan, drugog predavača/asistenta (course_professor može
     * imati više od jednog po ulozi). Ako za neku grupu/blok ne postoji slobodna
     * sala ili sukobljen je jedini dostupan predavač, ta se grupa jednostavno
     * preskače (best-effort, ne ruši generisanje ostatka rasporeda).
     */
    private void scheduleParallelGroups(int scheduleId, Course course,
            List<Room> lectureRooms, List<Room> labRooms) throws SQLException {

        // Termini grupe 1 za ovaj predmet u ovom rasporedu, grupisani po (dan, tip)
        Map<String, List<AcademicEvent>> blocks = new LinkedHashMap<>();
        for (AcademicEvent e : eventsByScheduleIdThisRun.getOrDefault(scheduleId, Collections.emptyList())) {
            if (e.idCourse == course.idCourse) {
                String key = e.day + "|" + e.typeEnum;
                blocks.computeIfAbsent(key, k -> new ArrayList<>()).add(e);
            }
        }
        if (blocks.isEmpty()) {
            return;
        }

        List<Integer> lectureProfs = getAllProfessorsForRole(course.idCourse, false);
        List<Integer> assistantProfs = getAllProfessorsForRole(course.idCourse, true);

        for (int group = 2; group <= course.parallelGroups; group++) {
            for (List<AcademicEvent> blockEvents : blocks.values()) {
                blockEvents.sort((a, b) -> a.startTime.compareTo(b.startTime));
                AcademicEvent first = blockEvents.get(0);
                boolean isAssistantRole = "EXERCISE".equals(first.typeEnum) || "LAB".equals(first.typeEnum);

                List<Integer> candidateProfs = isAssistantRole ? assistantProfs : lectureProfs;
                if (candidateProfs.isEmpty()) {
                    candidateProfs = isAssistantRole ? lectureProfs : assistantProfs;
                }
                if (candidateProfs.isEmpty()) {
                    continue; // nema uopšte dodijeljenih profesora za ovu ulogu
                }
                int altProfId = candidateProfs.get((group - 1) % candidateProfs.size());

                List<Room> candidateRooms = "LECTURE".equals(first.typeEnum)
                        ? lectureRooms
                        : (course.labsPerWeek > 0 ? labRooms : lectureRooms);

                // Nađi sobu koja je slobodna za SVE sate ovog bloka, za odabranog profesora
                // (candidateRooms je jedan virtuelni "idRoom=0" unos za onlajn predmete,
                // pa se u tom slučaju u praksi provjerava samo dostupnost profesora)
                Room chosenRoom = null;
                for (Room candidate : candidateRooms) {
                    boolean ok = true;
                    for (AcademicEvent ev : blockEvents) {
                        if (!isProfessorAvailable(altProfId, course.idCourse, ev.day, ev.startTime, ev.endTime)
                                || hasRoomOrProfessorConflictInSchedule(scheduleId, ev.day, candidate.idRoom, altProfId,
                                ev.startTime, ev.endTime, course.semester)
                                || isRoomOccupiedExternally(candidate.idRoom, ev.day, ev.startTime, ev.endTime)) {
                            ok = false;
                            break;
                        }
                    }
                    if (ok) {
                        chosenRoom = candidate;
                        break;
                    }
                }

                if (chosenRoom == null) {
                    continue; // nema slobodne sale (ili slobodnog profesorskog termina) za ovu grupu u ovom bloku
                }

                int roomIdToUse = chosenRoom.idRoom;
                for (AcademicEvent ev : blockEvents) {
                    LocalDateTime startsAt = convertDayToDate(ev.day, ev.startTime);
                    LocalDateTime endsAt = convertDayToDate(ev.day, ev.endTime);
                    saveToAcademicEvent(scheduleId, course.idCourse, altProfId, ev.day, startsAt, endsAt,
                            roomIdToUse, ev.typeEnum);
                    addEventToCache(course.idCourse, roomIdToUse, altProfId, ev.day, ev.startTime, ev.endTime,
                            ev.typeEnum, scheduleId);
                }
            }
        }
    }

    private static final int MAX_DAILY_HOURS_PER_SEMESTER = 6;

    /**
     * Da li je termin iz istog perioda (zimski = neparni semestri 1/3/5, ljetnji =
     * parni 2/4/6) kao predmet koji se raspoređuje. Jedan generisani raspored sadrži
     * sve semestre, ali zimski i ljetnji se nikad ne održavaju u isto vrijeme, pa
     * sala/profesor zauzet u zimskom semestru u ponedjeljak 8h mora biti slobodan za
     * ljetnji u ponedjeljak 8h. Ranije su se svi semestri sukobljavali međusobno,
     * što je (uz poštovanje vremena raspoloživosti) za profesore sa predmetima u oba
     * semestra dvostruko trošilo njihove slobodne termine.
     */
    private boolean isSameTerm(AcademicEvent event, int courseSemester) {
        Course eventCourse = courses.get(event.idCourse);
        if (eventCourse == null || courseSemester <= 0) {
            return true; // nepoznato - budi konzervativan i tretiraj kao isti period
        }
        return eventCourse.semester % 2 == courseSemester % 2;
    }

    /**
     * Vraća ukupan broj (spojenih, ne-preklapajućih) sati nastave za dati
     * semestar/godinu na dati dan u okviru ovog rasporeda - preko SVIH predmeta te
     * godine kombinovano, ne samo jednog predmeta. Preklapajući/susjedni termini
     * (npr. dvije paralelne grupe istog predmeta u isto vrijeme) se broje samo
     * jednom, da ne bi vještački naduvali dnevni fond.
     */
    private int getSemesterHoursOnDay(int scheduleId, int semester, String day) {
        List<LocalTime[]> intervals = new ArrayList<>();
        List<AcademicEvent> scheduleEvents = eventsByScheduleIdThisRun.getOrDefault(scheduleId, Collections.emptyList());
        for (AcademicEvent event : scheduleEvents) {
            if (day == null || event.day == null || !event.day.equals(day)) continue;
            if (event.startTime == null || event.endTime == null) continue;
            Course c = courses.get(event.idCourse);
            if (c == null || c.semester != semester) continue;
            intervals.add(new LocalTime[]{event.startTime, event.endTime});
        }
        if (intervals.isEmpty()) {
            return 0;
        }
        intervals.sort((a, b) -> a[0].compareTo(b[0]));
        long totalMinutes = 0;
        LocalTime curStart = intervals.get(0)[0];
        LocalTime curEnd = intervals.get(0)[1];
        for (int i = 1; i < intervals.size(); i++) {
            LocalTime[] iv = intervals.get(i);
            if (!iv[0].isAfter(curEnd)) {
                if (iv[1].isAfter(curEnd)) curEnd = iv[1];
            } else {
                totalMinutes += Duration.between(curStart, curEnd).toMinutes();
                curStart = iv[0];
                curEnd = iv[1];
            }
        }
        totalMinutes += Duration.between(curStart, curEnd).toMinutes();
        return (int) Math.round(totalMinutes / 60.0);
    }

    /**
     * Da li bi dodavanje bloka od blockHours sati na dati dan/semestar prešlo
     * dnevni limit od MAX_DAILY_HOURS_PER_SEMESTER časova za tu godinu/semestar
     * (profesorka je više puta naglasila da dnevni fond ne smije preći 6 časova).
     */
    private boolean wouldExceedDailyCap(int scheduleId, int semester, String day, int blockHours) {
        return getSemesterHoursOnDay(scheduleId, semester, day) + blockHours > MAX_DAILY_HOURS_PER_SEMESTER;
    }

    /**
     * Sortira dane po trenutno već zauzetom fondu (rastuće) za dati semestar/godinu
     * u ovom rasporedu - tako da se PRVO probaju dani koji su najmanje popunjeni.
     * Bez ovoga bi pohlepni algoritam (bez povratka/backtracking-a) sistematski prvo
     * pretrpavao isti (prvi u listi) dan do dnevnog limita od 6h, ostavljajući druge
     * dane prazne, pa bi kasniji predmeti tog semestra mnogo češće ostajali
     * neraspoređeni nego što je stvarno neophodno - dnevni fond od 6h je dovoljan za
     * cijelu sedmicu samo ako se ravnomjerno rasporedi po danima.
     */
    private List<String> sortDaysByLoad(int scheduleId, int semester, List<String> days) {
        List<String> sorted = new ArrayList<>(days);
        sorted.sort(Comparator.comparingInt(d -> getSemesterHoursOnDay(scheduleId, semester, d)));
        return sorted;
    }

    /**
     * Prvi slobodan termin (sala + početak) za blok od blockHours sati datog dana:
     * bez sukoba sale/profesora/semestra u ovom rasporedu, sala nije zauzeta od
     * drugog fakulteta, i profesor je raspoloživ cijelim trajanjem bloka.
     * Vraća null ako takav termin ne postoji.
     */
    private Object[] findFreeBlockOnDay(int scheduleId, Course course, int professorId, boolean isAssistantRole,
            String day, List<Room> roomList, int blockHours) {
        return findFreeBlockOnDay(scheduleId, course, getEventParticipants(course.idCourse, professorId, isAssistantRole),
                day, roomList, blockHours);
    }

    // Isto, za unaprijed zadat skup profesora termina. Ako je profesor uz termin
    // raspoloživosti tražio računarsku salu, druge sale se preskaču; ako je izabrao
    // salu, ona ima prednost (ali ako je zauzeta, uzima se prva druga slobodna).
    private Object[] findFreeBlockOnDay(int scheduleId, Course course, List<Integer> participants,
            String day, List<Room> roomList, int blockHours) {
        boolean anyPreference = hasRoomPreference(participants, course.idCourse, day);
        Object[] fallback = null;
        for (Room room : roomList) {
            for (int hour = 8; hour <= 17 - blockHours; hour++) {
                LocalTime start = LocalTime.of(hour, 0);
                LocalTime end = start.plusHours(blockHours);
                if (!areAllAvailable(participants, course.idCourse, day, start, end)) {
                    continue;
                }
                int[] req = getSlotRequirements(participants, course.idCourse, day, start, end);
                if (req[0] == 1 && !room.isComputerLab) {
                    continue;
                }
                if (!hasConflictInSchedule(scheduleId, day, room.idRoom, participants, start, end,
                        course.semester)
                        && !isRoomOccupiedExternally(room.idRoom, day, start, end)) {
                    if (!anyPreference || req[1] == room.idRoom) {
                        return new Object[] { room, start };
                    }
                    if (fallback == null) {
                        fallback = new Object[] { room, start };
                    }
                }
            }
        }
        return fallback;
    }

    /**
     * Schedules course as two days (Lectures on day1, Exercises+Labs on day2)
     * Only checks conflicts WITHIN the same schedule
     */
    private int scheduleAsTwoDaysWithinSchedule(int scheduleId, Course course,
            int lectureProfId, int exerciseProfId,
            List<String> lectureDays, List<String> exerciseDays, List<Room> lectureRooms,
            List<Room> labRooms) throws SQLException {
        String lectureDay = null;
        String exerciseDay = null;
        Room lectureRoom = null;
        Room exerciseRoom = null;
        LocalTime lectureStart = null;
        LocalTime exerciseStart = null;

        int exerciseHours = course.exercisesPerWeek + course.labsPerWeek;
        List<Room> exerciseRoomList = course.labsPerWeek > 0 ? labRooms : lectureRooms;

        // Probaj prvo najmanje popunjene dane (vidi sortDaysByLoad) da bi se dnevni
        // fond od 6h ravnomjerno iskoristio kroz cijelu sedmicu, ne samo prvi dan.
        List<String> lectureDaysByLoad = sortDaysByLoad(scheduleId, course.semester, lectureDays);
        List<String> exerciseDaysByLoad = sortDaysByLoad(scheduleId, course.semester, exerciseDays);

        // Za svaki mogući dan predavanja traži se dan vježbi (drugi dan). Ranije se
        // uzimao samo PRVI dan sa slobodnim terminom za predavanja, pa ako vježbe
        // tada nisu imale gdje (npr. asistent slobodan baš samo taj dan), predmet je
        // padao iako je postojala ispravna kombinacija sa drugim danom predavanja.
        for (String lDay : lectureDaysByLoad) {
            if (wouldExceedDailyCap(scheduleId, course.semester, lDay, course.lecturesPerWeek)) {
                continue; // ta godina/semestar bi tog dana prešla 6h - probaj drugi dan
            }
            Object[] lecSlot = findFreeBlockOnDay(scheduleId, course, lectureProfId, false, lDay,
                    lectureRooms, course.lecturesPerWeek);
            if (lecSlot == null) {
                continue;
            }
            for (String eDay : exerciseDaysByLoad) {
                if (eDay.equals(lDay))
                    continue; // Must be different day
                if (wouldExceedDailyCap(scheduleId, course.semester, eDay, exerciseHours)) {
                    continue;
                }
                Object[] exSlot = findFreeBlockOnDay(scheduleId, course, exerciseProfId, true, eDay,
                        exerciseRoomList, exerciseHours);
                if (exSlot != null) {
                    lectureDay = lDay;
                    lectureRoom = (Room) lecSlot[0];
                    lectureStart = (LocalTime) lecSlot[1];
                    exerciseDay = eDay;
                    exerciseRoom = (Room) exSlot[0];
                    exerciseStart = (LocalTime) exSlot[1];
                    break;
                }
            }
            if (lectureDay != null)
                break;
        }

        if (lectureDay == null || exerciseDay == null) {
            return 0;
        }

        // Save lectures (each hour separately)
        LocalTime currentTime = lectureStart;
        for (int i = 0; i < course.lecturesPerWeek; i++) {
            LocalDateTime startsAt = convertDayToDate(lectureDay, currentTime);
            LocalDateTime endsAt = startsAt.plusHours(1);
            saveToAcademicEvent(scheduleId, course.idCourse, lectureProfId,
                    lectureDay, startsAt, endsAt, lectureRoom.idRoom, "LECTURE");
            addEventToCache(course.idCourse, lectureRoom.idRoom, lectureProfId,
                    lectureDay, currentTime, currentTime.plusHours(1), "LECTURE", scheduleId);
            currentTime = currentTime.plusHours(1);
        }

        // Save exercises (each hour separately)
        currentTime = exerciseStart;
        for (int i = 0; i < course.exercisesPerWeek; i++) {
            LocalDateTime startsAt = convertDayToDate(exerciseDay, currentTime);
            LocalDateTime endsAt = startsAt.plusHours(1);
            saveToAcademicEvent(scheduleId, course.idCourse, exerciseProfId,
                    exerciseDay, startsAt, endsAt, exerciseRoom.idRoom, "EXERCISE");
            addEventToCache(course.idCourse, exerciseRoom.idRoom, exerciseProfId,
                    exerciseDay, currentTime, currentTime.plusHours(1), "EXERCISE", scheduleId);
            currentTime = currentTime.plusHours(1);
        }

        // Save labs (each hour separately)
        for (int i = 0; i < course.labsPerWeek; i++) {
            LocalDateTime startsAt = convertDayToDate(exerciseDay, currentTime);
            LocalDateTime endsAt = startsAt.plusHours(1);
            saveToAcademicEvent(scheduleId, course.idCourse, exerciseProfId,
                    exerciseDay, startsAt, endsAt, exerciseRoom.idRoom, "LAB");
            addEventToCache(course.idCourse, exerciseRoom.idRoom, exerciseProfId,
                    exerciseDay, currentTime, currentTime.plusHours(1), "LAB", scheduleId);
            currentTime = currentTime.plusHours(1);
        }

        return 2; // Successfully scheduled on 2 days
    }

    /**
     * Schedules course as one block (only for 4-hour courses if 2-day fails)
     * Only checks conflicts WITHIN the same schedule
     */
    private int scheduleAsOneBlockWithinSchedule(int scheduleId, Course course, int professorId,
            List<String> days, List<Room> rooms) throws SQLException {
        int totalHours = course.getTotalHoursPerWeek();
        List<String> daysByLoad = sortDaysByLoad(scheduleId, course.semester, days);
        // Blok sadrži i predavanja i vježbe/lab - učestvuju profesori obje uloge
        // (isto kao što se vezuju preko event_professor u saveToAcademicEvent).
        List<Integer> participants = new ArrayList<>();
        if (course.lecturesPerWeek > 0) {
            participants.addAll(getEventParticipants(course.idCourse, professorId, false));
        }
        if (course.exercisesPerWeek + course.labsPerWeek > 0) {
            for (int pid : getEventParticipants(course.idCourse, professorId, true)) {
                if (!participants.contains(pid)) participants.add(pid);
            }
        }

        for (String day : daysByLoad) {
            if (wouldExceedDailyCap(scheduleId, course.semester, day, totalHours)) {
                continue; // ta godina/semestar bi tog dana prešla 6h - probaj drugi dan
            }
            Object[] slot = findFreeBlockOnDay(scheduleId, course, participants, day, rooms, totalHours);
            if (slot != null) {
                Room room = (Room) slot[0];
                LocalTime startTime = (LocalTime) slot[1];
                // Save each hour separately
                LocalTime currentTime = startTime;

                // Lectures
                for (int i = 0; i < course.lecturesPerWeek; i++) {
                    LocalDateTime startsAt = convertDayToDate(day, currentTime);
                    LocalDateTime endsAt = startsAt.plusHours(1);
                    saveToAcademicEvent(scheduleId, course.idCourse, professorId,
                            day, startsAt, endsAt, room.idRoom, "LECTURE");
                    addEventToCache(course.idCourse, room.idRoom, professorId,
                            day, currentTime, currentTime.plusHours(1), "LECTURE", scheduleId);
                    currentTime = currentTime.plusHours(1);
                }

                // Exercises
                for (int i = 0; i < course.exercisesPerWeek; i++) {
                    LocalDateTime startsAt = convertDayToDate(day, currentTime);
                    LocalDateTime endsAt = startsAt.plusHours(1);
                    saveToAcademicEvent(scheduleId, course.idCourse, professorId,
                            day, startsAt, endsAt, room.idRoom, "EXERCISE");
                    addEventToCache(course.idCourse, room.idRoom, professorId,
                            day, currentTime, currentTime.plusHours(1), "EXERCISE", scheduleId);
                    currentTime = currentTime.plusHours(1);
                }

                // Labs
                for (int i = 0; i < course.labsPerWeek; i++) {
                    LocalDateTime startsAt = convertDayToDate(day, currentTime);
                    LocalDateTime endsAt = startsAt.plusHours(1);
                    saveToAcademicEvent(scheduleId, course.idCourse, professorId,
                            day, startsAt, endsAt, room.idRoom, "LAB");
                    addEventToCache(course.idCourse, room.idRoom, professorId,
                            day, currentTime, currentTime.plusHours(1), "LAB", scheduleId);
                    currentTime = currentTime.plusHours(1);
                }

                return 1; // Successfully scheduled as 1 block
            }
        }

        return 0;
    }

    /**
     * Checks for conflicts ONLY within the same schedule
     * Does NOT check against events in other schedules
     * Also checks for semester conflicts (two courses from the same semester at the
     * same time)
     */
    private boolean hasConflictInSchedule(int scheduleId, String day, int roomId, List<Integer> professorIds,
            LocalTime startTime, LocalTime endTime, int courseSemester) {
        List<AcademicEvent> scheduleEvents = eventsByScheduleIdThisRun.getOrDefault(scheduleId, Collections.emptyList());
        for (AcademicEvent event : scheduleEvents) {
            // Check day match
            boolean dayMatch = (day != null && event.day != null && event.day.equals(day));
            if (!dayMatch) {
                continue;
            }

            // Zimski i ljetnji semestar se nikad ne održavaju istovremeno - termin iz
            // drugog perioda ne zauzima ni salu ni profesora (vidi isSameTerm).
            if (!isSameTerm(event, courseSemester)) {
                continue;
            }

            // Check time overlap first
            boolean timeOverlap = false;
            if (startTime != null && endTime != null && event.startTime != null && event.endTime != null) {
                timeOverlap = startTime.isBefore(event.endTime) && endTime.isAfter(event.startTime);
            }

            if (!timeOverlap) {
                continue;
            }

            // Check room conflict (roomId <= 0 znači "nije potrebna sala", npr. onlajn predmet -
            // takav termin nikad ne "zauzima" niti sukobljava salu)
            if (roomId > 0 && event.idRoom == roomId) {
                return true; // Room conflict
            }

            // Check professor conflict (bilo koji profesor termina - i su-predavači)
            List<Integer> eventProfessors = participantsOf(event);
            for (int pid : professorIds) {
                if (eventProfessors.contains(pid)) {
                    return true; // Professor conflict
                }
            }

            // Check semester conflict - courses from the same semester cannot overlap
            Course eventCourse = courses.get(event.idCourse);
            if (eventCourse != null && eventCourse.semester == courseSemester) {
                return true; // Semester conflict - students can't attend both
            }
        }

        return false; // No conflict
    }

    // Ne znam od kad je ovo ovjde, izgleda lose, al za svaki slucaj ga necu brisati.
    
    // // Overloaded method for backward compatibility (without semester check)
    // private boolean hasConflictInSchedule(int scheduleId, String day, int roomId, int professorId,
    //         LocalTime startTime, LocalTime endTime) {
    //     return hasConflictInSchedule(scheduleId, day, roomId, professorId, startTime, endTime, -1);
    // }

    // private boolean hasConflictForDateInSchedule(int scheduleId, LocalDate date, int roomId, int professorId,
    //                                              LocalTime startTime, LocalTime endTime, int courseSemester) {
    //     for (AcademicEvent event : academicEvents.values()) {
    //         if (event.scheduleId != scheduleId) {
    //             continue;
    //         }

    //         // Check timing conflict
    //         boolean timeOverlap = false;
    //         if (startTime != null && endTime != null && event.startTime != null && event.endTime != null) {
    //             timeOverlap = startTime.isBefore(event.endTime) && endTime.isAfter(event.startTime);
    //         }
    //         if (!timeOverlap) continue;

    //         // Check date/day match
    //         boolean conflictMatch = false;
            
    //         // If event is date-specific
    //         if (event.date != null) {
    //             if (event.date.equals(date)) {
    //                 conflictMatch = true;
    //             }
    //         } 
    //         // If event is recurring (no date, just day)
    //         else if (event.day != null) {
    //              DayOfWeek eventDow = parseDayOfWeek(event.day);
    //              if (eventDow == date.getDayOfWeek()) {
    //                  conflictMatch = true;
    //              }
    //         }

    //         if (conflictMatch) {
    //             if (event.idRoom == roomId) return true;
    //             if (event.idProfessor == professorId) return true;
                
    //             // Check semester conflict
    //             Course eventCourse = courses.get(event.idCourse);
    //             if (eventCourse != null && eventCourse.semester == courseSemester) {
    //                 return true;
    //             }
    //         }
    //     }
    //     return false;
    // }

    // private void addColloquiumToSchedule(int scheduleId, int courseId, int roomId, int professorId, 
    //                                     LocalDate date, LocalTime startTime, LocalTime endTime, String type) throws SQLException {
    //     String insert = "INSERT INTO academic_event (course_id, created_by_professor, type_enum, " +
    //             "starts_at, ends_at, room_id, is_published, locked_by_admin, schedule_id, day) " +
    //             "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
    //     try (PreparedStatement pstmt = conn.prepareStatement(insert, Statement.RETURN_GENERATED_KEYS)) {
    //         LocalDateTime startsAt = LocalDateTime.of(date, startTime);
    //         LocalDateTime endsAt = LocalDateTime.of(date, endTime);
            
    //         String dayName = getCroatianDayName(date.getDayOfWeek());

    //         pstmt.setInt(1, courseId);
    //         pstmt.setLong(2, professorId);
    //         pstmt.setString(3, type);
    //         pstmt.setTimestamp(4, Timestamp.valueOf(startsAt));
    //         pstmt.setTimestamp(5, Timestamp.valueOf(endsAt));
    //         pstmt.setInt(6, roomId);
    //         pstmt.setBoolean(7, true);
    //         pstmt.setBoolean(8, false);
    //         pstmt.setInt(9, scheduleId);
    //         pstmt.setString(10, dayName);
    //         pstmt.executeUpdate();
            
    //         try (ResultSet rs = pstmt.getGeneratedKeys()) {
    //             if (rs.next()) {
    //                 int id = rs.getInt(1);
    //                 AcademicEvent newEvent = new AcademicEvent();
    //                 newEvent.idAcademicEvent = id;
    //                 newEvent.idCourse = courseId;
    //                 newEvent.idRoom = roomId;
    //                 newEvent.idProfessor = professorId;
    //                 newEvent.typeEnum = type;
    //                 newEvent.date = date;
    //                 newEvent.day = dayName;
    //                 newEvent.startTime = startTime;
    //                 newEvent.endTime = endTime;
    //                 newEvent.startsAt = startsAt;
    //                 newEvent.endsAt = endsAt;
    //                 newEvent.scheduleId = scheduleId;
    //                 academicEvents.put(newEvent.idAcademicEvent, newEvent);
    //             }
    //         }
    //     }
    // }

    private String getCroatianDayName(DayOfWeek dow) {
        switch (dow) {
            case MONDAY: return "ponedeljak";
            case TUESDAY: return "utorak";
            case WEDNESDAY: return "srijeda";
            case THURSDAY: return "cetvrtak";
            case FRIDAY: return "petak";
            case SATURDAY: return "subota";
            case SUNDAY: return "nedelja";
            default: return "ponedeljak";
        }
    }
    
    

    // private void generateColloquiums(int scheduleId, List<FailedCourse> failedList) {
    //     System.out.println("Phase 4: Scheduling EXAMS/COLLOQUIUMS...");
    //     System.out.println("Academic Year Check: Winter=" + winterStart + ", Summer=" + summerStart);
        
    //     if (winterStart == null && summerStart == null) {
    //         System.out.println("Skipping colloquiums: Semester dates not defined. Please define an active Academic Year in Admin Panel.");
    //         return;
    //     }

    //     // Use larger rooms for exams
    //     List<Room> examRooms = getSuitableRooms(false, 40); 
    //     if (examRooms.isEmpty()) examRooms = getSuitableRooms(false, 20); // Fallback

    //     int colloquiumsScheduled = 0;

    //     for (Course course : courses.values()) {
    //         // Determine active semester start
    //         // Odd semesters = Winter, Even = Summer
    //         LocalDate semStart = (course.semester % 2 != 0) ? winterStart : summerStart;
            
    //         if (semStart == null) {
    //             // System.out.println("Skipping course " + course.code + ": No start date for semester " + course.semester);
    //             continue;
    //         }

    //         try {
    //             int profId = findLectureProfessor(course.idCourse);
    //             if (profId == 0) {
    //                  // System.out.println("Skipping course " + course.code + ": No professor assigned.");
    //                  continue; 
    //             }

    //             // Schedule Colloquium 1
    //             if (course.colloquium1Week != null && course.colloquium1Week > 0) {
    //                  boolean success = scheduleColloquium(scheduleId, course, 1, course.colloquium1Week, semStart, profId, examRooms, failedList);
    //                  if (success) colloquiumsScheduled++;
    //             } else {
    //                 // System.out.println("Course " + course.code + " has no Colloquium 1 week set.");
    //             }

    //             // Schedule Colloquium 2
    //             if (course.colloquium2Week != null && course.colloquium2Week > 0) {
    //                  boolean success = scheduleColloquium(scheduleId, course, 2, course.colloquium2Week, semStart, profId, examRooms, failedList);
    //                  if (success) colloquiumsScheduled++;
    //             }

    //         } catch(Exception e) {
    //             System.out.println("Error scheduling colloquiums for course " + course.idCourse + ": " + e.getMessage());
    //         }
    //     }
    //     System.out.println("Total Colloquiums Scheduled: " + colloquiumsScheduled);
    // // }

    // private boolean scheduleColloquium(int scheduleId, Course course, int colNum, int week, LocalDate semStart, 
    //                                int profId, List<Room> rooms, List<FailedCourse> failedList) throws SQLException {
    //     // Calculate week start
    //     LocalDate weekStartDate = semStart.plusWeeks(week - 1);
    //     // Try Saturday of that week
    //     LocalDate targetDate = weekStartDate.with(TemporalAdjusters.nextOrSame(DayOfWeek.SATURDAY));
        
    //     // Check if date is a holiday
    //     for (Holiday holiday : holidays) {
    //         if (holiday.date.equals(targetDate)) {
    //              System.out.println("Skipping colloquium for " + course.name + " on " + targetDate + " due to holiday: " + holiday.name);
    //              return false;
    //         }
    //     }

    //     // Times to try
    //     LocalTime[] starts = { LocalTime.of(9, 0), LocalTime.of(12, 0), LocalTime.of(15, 0), LocalTime.of(8,0), LocalTime.of(16,0) };
    //     boolean scheduled = false;

    //     for (LocalTime start : starts) {
    //         LocalTime end = start.plusHours(3); // 3 hours duration
            
    //         for (Room room : rooms) {
    //             if (!hasConflictForDateInSchedule(scheduleId, targetDate, room.idRoom, profId, start, end, course.semester)) {
    //                 addColloquiumToSchedule(scheduleId, course.idCourse, room.idRoom, profId, 
    //                                       targetDate, start, end, "COLLOQUIUM"); 
    //                 scheduled = true;
    //                 break;
    //             }
    //         }
    //         if (scheduled) break;
    //     }
        
    //     if (!scheduled) {
    //          System.out.println("Failed to schedule Colloquium " + colNum + " for Course " + course.name + " (" + course.code + ") Week " + week + " at " + targetDate);
    //     }
    //     return scheduled;
    // }
}


//region Classes
class Course {
    public int idCourse;
    public String name;
    public int semester;
    public String code;
    public int lecturesPerWeek; // broj sati predavanja (P)
    public int exercisesPerWeek; // broj sati vježbi (V)
    public int labsPerWeek; // broj sati laboratorijskih vježbi (L)
    public boolean isOnline;
    public boolean requiresComputerLab; // zahtijeva rač. salu nezavisno od labsPerWeek
    public Integer expectedStudents; // očekivan broj studenata (poredi se sa kapacitetom sale)
    public int parallelGroups = 1; // broj paralelnih grupa u isto vrijeme (1 = jedna grupa)
    public boolean isActive = true; // deaktiviran predmet se ne raspoređuje
    public Integer colloquium1Week;
    public Integer colloquium2Week;

    // Vraća ukupan broj sati sedmično (P+V+L)
    public int getTotalHoursPerWeek() {
        return lecturesPerWeek + exercisesPerWeek + labsPerWeek;
    }

    // Da li zahtijeva dijeljenje na 2 dana (> 4 sata)
    public boolean requiresSplit() {
        return getTotalHoursPerWeek() > 4;
    }
}

class Professor {
    public int idProfessor;
    public String fullName;
    public String email;
    public boolean isActive;
}

class Room {
    public int idRoom;
    public String code;
    public int capacity;
    public boolean isComputerLab;
    public boolean isActive;
}

/** Jedan ručno unešeni termin zauzetosti sale (npr. od strane drugog fakulteta). */
class RoomOccupancySlot {
    public int roomId;
    public String day;
    public LocalTime start;
    public LocalTime end;
}

class AcademicEvent {
    public int idAcademicEvent;
    public int idCourse;
    public int idRoom;
    public int idProfessor;
    public String day;
    public LocalTime startTime;
    public LocalTime endTime;
    public String typeEnum;
    public LocalDate date;
    public boolean isOnline;
    public String notes;
    public boolean isPublished;
    public boolean lockedByAdmin;
    public int scheduleId;
    public LocalDateTime startsAt;
    public LocalDateTime endsAt;
    public List<Integer> participants; // svi profesori termina (samo za termine iz tekućeg generisanja)
}

class Holiday {
    public int idHoliday;
    public String name;
    public LocalDate date;
}

class CoursePriority {
    public Course course;
    public Professor primaryProfessor;
    public Professor secondaryProfessor;
    public double primaryFlexibility = 0.5;
    public double secondaryFlexibility = 0.5;
    public double priority = 0.5;
}

// Popravljene greske: day >> weekday

class ScheduleResult {
    public int scheduleId;
    public int successfulCourses;
    public int failedCourses;
    public List<FailedCourse> failedCoursesList;
    public String message;
    public boolean success;
    public long timestamp;
    
    public ScheduleResult() {
        this.failedCoursesList = new ArrayList<>();
        this.timestamp = System.currentTimeMillis();
    }
}

class FailedCourse {
    public int courseId;
    public String courseName;
    public String reason;
    public int semester;
    
    public FailedCourse(int courseId, String courseName, String reason, int semester) {
        this.courseId = courseId;
        this.courseName = courseName;
        this.reason = reason;
        this.semester = semester;
    }
}

//endregion