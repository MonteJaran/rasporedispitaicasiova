import java.sql.*;
import java.time.*;
import java.time.temporal.TemporalAdjusters;
import java.util.*;
import java.util.stream.Collectors;

public class ColloquiumService {

    private static final String SE_MAJOR = "Softverski inženjering";
    private static final String ICT_MAJOR = "Informaciono komunikacione tehnologije";

    private static class Course {
        int id;
        String name;
        int semester;
        Integer c1Week;
        Integer c2Week;
        Integer examWeek;
        String major;

        public Course(int id, String name, int semester, Integer c1Week, Integer c2Week, Integer examWeek, String major) {
            this.id = id;
            this.name = name;
            this.semester = semester;
            this.c1Week = c1Week;
            this.c2Week = c2Week;
            this.examWeek = examWeek;
            this.major = major;
        }
    }

    private static class TemplateEvent {
        int courseId;
        String day;
        LocalTime startTime;
        LocalTime endTime;
        Integer roomId;
        boolean isOnline;
        long professorId;
        int scheduleId;
    }

    private static class ProposedColloquium {
        Course course;
        String type; 
        int week;
        TemplateEvent template;
        LocalDate finalDate;
        LocalDate semesterStart;

        public ProposedColloquium(Course course, String type, int week, TemplateEvent template) {
            this.course = course;
            this.type = type;
            this.week = week;
            this.template = template;
        }
    }

    private static class AcademicYear {
        LocalDate winterStart;
        LocalDate summerStart;
    }

    // Neradni praznici (holiday.is_working_day = 0) - kolokvijum/ispit ne smije pasti
    // na takav dan (ranije je npr. kolokvijum 2 padao na Novu godinu ili Veliki petak).
    private Set<LocalDate> nonWorkingDays = new HashSet<>();

    public String generateColloquiums() {
        Connection conn = null;
        try {
            conn = BazaInicijalizacija.uspostaviKonekciju();

            if (!isScheduleLocked(conn)) {
                return "Raspored predavanja nije zaključan od strane admina. Nije moguće generisati kolokvijume.";
            }

            AcademicYear year = loadAcademicYear(conn);
            if (year == null) return "GRESKA: Nema aktivne akademske godine.";

            List<Course> courses = loadCourses(conn);
            Map<Integer, TemplateEvent> templates = loadTemplates(conn);
            nonWorkingDays = loadNonWorkingDays(conn);

            List<ProposedColloquium> finalProposals = new ArrayList<>();

            Map<Integer, List<Course>> bySemester = courses.stream().collect(Collectors.groupingBy(c -> c.semester));

            for (Map.Entry<Integer, List<Course>> entry : bySemester.entrySet()) {
                int semester = entry.getKey();
                List<Course> semCourses = entry.getValue();
                
                boolean isWinter = Arrays.asList(1, 3, 5).contains(semester);
                boolean isSummer = Arrays.asList(2, 4, 6).contains(semester);
                if (!isWinter && !isSummer) continue; 

                LocalDate semStart = isWinter ? year.winterStart : year.summerStart;
                if (semStart == null) continue;

                List<ProposedColloquium> col1List = new ArrayList<>();
                List<ProposedColloquium> col2List = new ArrayList<>();
                // Za razliku od kolokvijuma, final_exam_week nije obavezno polje - ova
                // funkcija je nova i za postojeće predmete niko je još nije popunio, pa
                // izostanak (null ili 0) samo znači da se za taj predmet ispit ne
                // generiše automatski, umjesto da blokira cijelo generisanje.
                List<ProposedColloquium> examList = new ArrayList<>();

                for (Course c : semCourses) {
                    TemplateEvent t = templates.get(c.id);
                    if (t == null) continue;

                    if (c.c1Week == null) return "Za predmet " + c.name + " nije definisana sedmica za Kolokvijum 1.";
                    if (c.c1Week > 0) col1List.add(new ProposedColloquium(c, "COLLOQUIUM_1", c.c1Week, t));

                    if (c.c2Week == null) return "Za predmet " + c.name + " nije definisana sedmica za Kolokvijum 2.";
                    if (c.c2Week > 0) col2List.add(new ProposedColloquium(c, "COLLOQUIUM_2", c.c2Week, t));

                    if (c.examWeek != null && c.examWeek > 0) examList.add(new ProposedColloquium(c, "EXAM", c.examWeek, t));
                }
                for (List<ProposedColloquium> round : Arrays.asList(col1List, col2List, examList)) {
                    for (ProposedColloquium p : round) {
                        p.semesterStart = semStart;
                    }
                }

                try {
                    processSemester(col1List, col2List, examList, semester);
                } catch (Exception e) {
                    return "GRESKA: " + e.getMessage();
                }

                for (ProposedColloquium p : col1List) {
                    calculateDate(p, semStart);
                    finalProposals.add(p);
                }
                for (ProposedColloquium p : col2List) {
                    calculateDate(p, semStart);
                    finalProposals.add(p);
                }
                for (ProposedColloquium p : examList) {
                    calculateDate(p, semStart);
                    finalProposals.add(p);
                }
            }

            // Delete and insert must stand or fall together - a failure halfway through
            // used to leave the old colloquiums deleted and no new ones in their place.
            boolean previousAutoCommit = conn.getAutoCommit();
            conn.setAutoCommit(false);
            try {
                deleteOldColloquiums(conn);
                insertNewColloquiums(conn, finalProposals);
                conn.commit();
            } catch (SQLException e) {
                conn.rollback();
                throw e;
            } finally {
                conn.setAutoCommit(previousAutoCommit);
            }

            return "OK";

        } catch (Exception e) {
            e.printStackTrace();
            return "GRESKA: " + e.getMessage();
        } finally {
            if (conn != null) try { conn.close(); } catch (SQLException e) {}
        }
    }

    private void processSemester(List<ProposedColloquium> col1, List<ProposedColloquium> col2,
            List<ProposedColloquium> exams, int semester) throws Exception {
        // All three rounds share one occupancy map, so the weekly limits count colloquium 1,
        // colloquium 2 and the final exam together instead of each round filling a week on
        // its own (a course's exam could otherwise land in the same week as its colloquium).
        Map<Integer, WeekStatus> schedule = new TreeMap<>();
        placeRound(col1, semester, schedule);
        placeRound(col2, semester, schedule);
        placeRound(exams, semester, schedule);
    }

    private void placeRound(List<ProposedColloquium> proposals, int semester, Map<Integer, WeekStatus> schedule)
            throws Exception {
        if (proposals.isEmpty()) return;

        // Sort proposals by requested week to respect original preferences where possible
        proposals.sort(Comparator.comparingInt(p -> p.week));

        // Remember the requested weeks so a failed attempt can be rolled back
        List<Integer> requested = new ArrayList<>();
        for (ProposedColloquium p : proposals) {
            requested.add(p.week);
        }

        // Each colloquium may be balanced across this many weeks starting from the one
        // the professor asked for. Without it every colloquium lands in the first week
        // that still has a free place, which fills the early weeks up to the limit and
        // leaves the later ones empty.
        int spread = balancedSpread(proposals, semester);

        Map<Integer, WeekStatus> attempt = copyOf(schedule);
        try {
            fillWeeks(proposals, semester, attempt, spread);
            validateSpan(proposals, semester);
        } catch (Exception balancingFailed) {
            // Spreading can push a lower semester over its 3 week limit. Pack the weeks
            // tightly instead of refusing a round that could still be scheduled.
            for (int i = 0; i < proposals.size(); i++) {
                proposals.get(i).week = requested.get(i);
            }
            attempt = copyOf(schedule);
            fillWeeks(proposals, semester, attempt, 1);
            validateSpan(proposals, semester);
        }

        schedule.clear();
        schedule.putAll(attempt);
    }

    private void fillWeeks(List<ProposedColloquium> proposals, int semester,
            Map<Integer, WeekStatus> schedule, int spread) throws Exception {
        int capacity = weeklyCapacity(semester);
        boolean majorRules = usesMajorRules(semester);

        for (ProposedColloquium p : proposals) {
            int requested = p.week;
            int limit = requested + spread - 1;
            Integer chosen = leastLoadedWeek(p, requested, limit, schedule, capacity, majorRules);

            // Look further ahead only when the balanced window has no legal slot left
            while (chosen == null) {
                if (limit > requested + 15) {
                    throw new Exception("Nemoguće rasporediti kolokvijume za semestar " + semester +
                        " (Previše konflikata za predmet " + p.course.name + ")");
                }
                limit++;
                chosen = leastLoadedWeek(p, requested, limit, schedule, capacity, majorRules);
            }

            p.week = chosen;
            occupy(schedule, chosen, p);
        }
    }

    // Fewest weeks this round can occupy without breaking a rule - spreading the load
    // over exactly that many weeks keeps the peak per week as low as possible.
    private int balancedSpread(List<ProposedColloquium> proposals, int semester) {
        int needed = (int) Math.ceil(proposals.size() / (double) weeklyCapacity(semester));
        if (usesMajorRules(semester)) {
            needed = Math.max(needed, maxMajorCount(proposals));
        }
        return Math.max(1, needed);
    }

    private Map<Integer, WeekStatus> copyOf(Map<Integer, WeekStatus> schedule) {
        Map<Integer, WeekStatus> copy = new TreeMap<>();
        for (Map.Entry<Integer, WeekStatus> entry : schedule.entrySet()) {
            copy.put(entry.getKey(), new WeekStatus(entry.getValue()));
        }
        return copy;
    }

    // Returns the emptiest week in [fromWeek, toWeek] that accepts this colloquium.
    // Ties go to the earliest week, so a proposal only moves past its requested week
    // when a later one is genuinely less loaded.
    private Integer leastLoadedWeek(ProposedColloquium p, int fromWeek, int toWeek,
            Map<Integer, WeekStatus> schedule, int capacity, boolean majorRules) {
        Integer best = null;
        int bestLoad = Integer.MAX_VALUE;
        for (int w = fromWeek; w <= toWeek; w++) {
            WeekStatus status = schedule.get(w);
            if (!fits(p, status, capacity, majorRules)) continue;
            if (p.semesterStart != null && nonWorkingDays.contains(dateForWeek(p, p.semesterStart, w))) continue;
            int load = (status == null) ? 0 : status.count;
            if (load < bestLoad) {
                bestLoad = load;
                best = w;
            }
        }
        return best;
    }

    private boolean fits(ProposedColloquium p, WeekStatus status, int capacity, boolean majorRules) {
        if (status == null) return true;

        // 1. Max colloquiums per week
        if (status.count >= capacity) return false;

        // 2. One course never holds both colloquiums in the same week - they reuse the
        // same exercise slot, so they would end up on the very same date and time.
        if (status.courseIds.contains(p.course.id)) return false;

        if (!majorRules) return true;

        // 3. Common courses MUST be in different weeks
        if (p.course.major == null) return !status.hasCommon;

        // 4. Two courses of the same major CANNOT be in the same week
        if (SE_MAJOR.equals(p.course.major)) return !status.hasSE;
        if (ICT_MAJOR.equals(p.course.major)) return !status.hasICT;
        return true;
    }

    private void occupy(Map<Integer, WeekStatus> schedule, int week, ProposedColloquium p) {
        WeekStatus status = schedule.computeIfAbsent(week, k -> new WeekStatus());
        status.count++;
        status.courseIds.add(p.course.id);
        if (p.course.major == null) status.hasCommon = true;
        else if (SE_MAJOR.equals(p.course.major)) status.hasSE = true;
        else if (ICT_MAJOR.equals(p.course.major)) status.hasICT = true;
    }

    // A week holds at most one course per major, so a round can never be shorter
    // than the biggest single major group.
    private int maxMajorCount(List<ProposedColloquium> proposals) {
        int common = 0, se = 0, ict = 0;
        for (ProposedColloquium p : proposals) {
            if (p.course.major == null) common++;
            else if (SE_MAJOR.equals(p.course.major)) se++;
            else if (ICT_MAJOR.equals(p.course.major)) ict++;
        }
        return Math.max(common, Math.max(se, ict));
    }

    private void validateSpan(List<ProposedColloquium> proposals, int semester) throws Exception {
        if (usesMajorRules(semester)) return;

        Set<Integer> weeksUsed = new HashSet<>();
        for (ProposedColloquium p : proposals) {
            weeksUsed.add(p.week);
        }

        if (weeksUsed.size() > 3) {
            throw new Exception("Kolokvijumi za semestar " + semester + " se rasprostiru na vise od 3 sedmice.");
        }
    }

    private int weeklyCapacity(int semester) {
        return usesMajorRules(semester) ? 3 : 2;
    }

    // Special rules apply to 4th, 5th and 6th semester, where courses split by major
    private boolean usesMajorRules(int semester) {
        return semester == 4 || semester == 5 || semester == 6;
    }

    private static class WeekStatus {
        int count = 0;
        boolean hasCommon = false;
        boolean hasSE = false;
        boolean hasICT = false;
        final Set<Integer> courseIds = new HashSet<>();

        WeekStatus() {
        }

        WeekStatus(WeekStatus other) {
            this.count = other.count;
            this.hasCommon = other.hasCommon;
            this.hasSE = other.hasSE;
            this.hasICT = other.hasICT;
            this.courseIds.addAll(other.courseIds);
        }
    }

    private boolean isScheduleLocked(Connection conn) throws SQLException {
        // Samo termini nastave - generisani kolokvijumi su i sami locked_by_admin, pa bi
        // inače raspored ostao "zaključan" i poslije otključavanja.
        String query = "SELECT 1 FROM academic_event WHERE locked_by_admin = true " +
                "AND type_enum IN ('LECTURE', 'EXERCISE', 'LAB') LIMIT 1";
        try (Statement stmt = conn.createStatement(); ResultSet rs = stmt.executeQuery(query)) {
            return rs.next();
        }
    }

    private AcademicYear loadAcademicYear(Connection conn) throws SQLException {
        String query = "SELECT winter_semester_start, summer_semester_start FROM academic_year WHERE is_active = TRUE LIMIT 1";
        try (Statement stmt = conn.createStatement(); ResultSet rs = stmt.executeQuery(query)) {
            if (rs.next()) {
                AcademicYear ay = new AcademicYear();
                java.sql.Date winter = rs.getDate("winter_semester_start");
                java.sql.Date summer = rs.getDate("summer_semester_start");
                if (winter != null) ay.winterStart = winter.toLocalDate();
                if (summer != null) ay.summerStart = summer.toLocalDate();
                return ay;
            }
        }
        return null;
    }

    private List<Course> loadCourses(Connection conn) throws SQLException {
        List<Course> list = new ArrayList<>();
        // Deaktiviran predmet ne dobija kolokvijume
        String query = "SELECT id, name, semester, colloquium_1_week, colloquium_2_week, final_exam_week, major FROM course " +
                "WHERE COALESCE(is_active, TRUE) = TRUE";
        try (Statement stmt = conn.createStatement(); ResultSet rs = stmt.executeQuery(query)) {
            while (rs.next()) {
                Integer c1 = rs.getInt("colloquium_1_week");
                if (rs.wasNull()) c1 = null;
                Integer c2 = rs.getInt("colloquium_2_week");
                if (rs.wasNull()) c2 = null;
                Integer examWeek = rs.getInt("final_exam_week");
                if (rs.wasNull()) examWeek = null;
                String major = rs.getString("major");
                if (rs.wasNull()) major = null;
                list.add(new Course(rs.getInt("id"), rs.getString("name"), rs.getInt("semester"), c1, c2, examWeek, major));
            }
        }
        return list;
    }

    private Map<Integer, TemplateEvent> loadTemplates(Connection conn) throws SQLException {
        Map<Integer, TemplateEvent> map = new HashMap<>();
        // Kolokvijum ide u termin vježbi; predmet bez vježbi (npr. "Projekat" - samo lab)
        // koristi lab, a predmet samo sa predavanjima predavanje. Ranije su se gledale
        // samo vježbe, pa takvi predmeti nikad nisu dobijali kolokvijume. Redoslijed je
        // deterministički (najnoviji zaključani raspored, pa najraniji termin), a ne
        // proizvoljan red iz baze.
        String query = "SELECT course_id, day, starts_at, ends_at, room_id, is_online, created_by_professor, schedule_id " +
                       "FROM academic_event " +
                       "WHERE type_enum IN ('EXERCISE', 'LAB', 'LECTURE') AND locked_by_admin = true " +
                       "ORDER BY course_id, schedule_id DESC, " +
                       "CASE type_enum WHEN 'EXERCISE' THEN 0 WHEN 'LAB' THEN 1 ELSE 2 END, starts_at";
        try (Statement stmt = conn.createStatement(); ResultSet rs = stmt.executeQuery(query)) {
            while (rs.next()) {
                TemplateEvent t = new TemplateEvent();
                t.courseId = rs.getInt("course_id");
                t.day = rs.getString("day");
                int rid = rs.getInt("room_id");
                t.roomId = rs.wasNull() ? null : rid;
                t.isOnline = rs.getBoolean("is_online");
                t.professorId = rs.getLong("created_by_professor");
                t.scheduleId = rs.getInt("schedule_id");
                Timestamp start = rs.getTimestamp("starts_at");
                Timestamp end = rs.getTimestamp("ends_at");
                if (start != null) t.startTime = start.toLocalDateTime().toLocalTime();
                if (end != null) t.endTime = end.toLocalDateTime().toLocalTime();
                map.putIfAbsent(t.courseId, t);
            }
        }
        return map;
    }
    
    private void calculateDate(ProposedColloquium p, LocalDate semesterStart) {
        if (p.week <= 0) return;
        p.finalDate = dateForWeek(p, semesterStart, p.week);
    }

    // Datum termina vježbi predmeta (dan iz šablona) u datoj sedmici semestra
    private LocalDate dateForWeek(ProposedColloquium p, LocalDate semesterStart, int week) {
        LocalDate startOfWeek1 = semesterStart.with(TemporalAdjusters.previousOrSame(DayOfWeek.MONDAY));
        LocalDate date = startOfWeek1.plusWeeks(week - 1);
        DayOfWeek dow = parseDayOfWeek(p.template.day);
        while (date.getDayOfWeek() != dow) {
            date = date.plusDays(1);
        }
        return date;
    }

    private Set<LocalDate> loadNonWorkingDays(Connection conn) throws SQLException {
        Set<LocalDate> days = new HashSet<>();
        String query = "SELECT date FROM holiday WHERE is_working_day = 0";
        try (Statement stmt = conn.createStatement(); ResultSet rs = stmt.executeQuery(query)) {
            while (rs.next()) {
                java.sql.Date d = rs.getDate("date");
                if (d != null) days.add(d.toLocalDate());
            }
        }
        return days;
    }

    private DayOfWeek parseDayOfWeek(String day) {
        if (day == null) return DayOfWeek.MONDAY;
        String d = day.toLowerCase();
        if (d.contains("pon")) return DayOfWeek.MONDAY;
        if (d.contains("uto")) return DayOfWeek.TUESDAY;
        if (d.contains("sri") || d.contains("sre")) return DayOfWeek.WEDNESDAY;
        if (d.contains("cet") || d.contains("čet")) return DayOfWeek.THURSDAY;
        if (d.contains("pet")) return DayOfWeek.FRIDAY;
        if (d.contains("sub")) return DayOfWeek.SATURDAY;
        if (d.contains("ned")) return DayOfWeek.SUNDAY;
        return DayOfWeek.MONDAY; 
    }

    private void deleteOldColloquiums(Connection conn) throws SQLException {
        // Only wipe what a previous run of this generator produced. Colloquiums/exams
        // entered by hand (or the seeded 'Redovni'/'Popravni ispitni rok' demo rows)
        // carry no 'generated' note and must survive.
        String sql = "DELETE FROM academic_event " +
                "WHERE type_enum IN ('COLLOQUIUM', 'COLLOQUIUM_1', 'COLLOQUIUM_2', 'EXAM') " +
                "AND notes = 'generated'";
        try (Statement stmt = conn.createStatement()) {
            stmt.executeUpdate(sql);
        }
    }

    private void insertNewColloquiums(Connection conn, List<ProposedColloquium> proposals) throws SQLException {
        String sql = "INSERT INTO academic_event " +
                "(course_id, type_enum, starts_at, ends_at, room_id, is_online, created_by_professor, schedule_id, locked_by_admin, notes, day, is_published) " +
                "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        try (PreparedStatement ps = conn.prepareStatement(sql)) {
            for (ProposedColloquium p : proposals) {
                LocalDateTime startDt = LocalDateTime.of(p.finalDate, p.template.startTime);
                LocalDateTime endDt = LocalDateTime.of(p.finalDate, p.template.endTime);

                ps.setInt(1, p.course.id);
                ps.setString(2, p.type);
                ps.setTimestamp(3, Timestamp.valueOf(startDt));
                ps.setTimestamp(4, Timestamp.valueOf(endDt));
                // Vježbe bez sale (online) imaju room_id NULL - getInt() bi to tiho
                // pretvorio u 0, sto krsi FK ka room, pa se ovdje eksplicitno salje NULL.
                if (p.template.roomId == null) {
                    ps.setNull(5, java.sql.Types.BIGINT);
                } else {
                    ps.setInt(5, p.template.roomId);
                }
                ps.setBoolean(6, p.template.isOnline);
                ps.setLong(7, p.template.professorId);
                ps.setInt(8, p.template.scheduleId);
                // Prompt rule: "ne smijes mijenjati... raspored predavanja".
                // But this is inserting NEW colloquiums.
                // Prompt: "Ako vrijednost 0 -> taj kolokvijum se ne odrzava" (Handled by >0 check)

                // Are we locking these? The prompt implies they are generated ON TOP OF locked schedule.
                // It doesn't strictly say if colloquiums are locked. Usually they are visible.
                ps.setBoolean(9, true); // Let's lock them to avoid accidental manual move that breaks the logic, or false?
                // Actually, if we lock them, admins can't move them. Usually they need to be adjustable.
                // BUT "Kolokvijumi se ne raspoređuju slobodno... u istom terminu kao vježbe".
                // If the user wants specific rules, maybe locking is correct. I'll stick to true as per prev logic.

                ps.setString(10, "generated");
                ps.setString(11, p.template.day);
                ps.setBoolean(12, true);

                ps.addBatch();
            }
            ps.executeBatch();
        }
    }
}
