import java.sql.Connection;
import java.sql.DriverManager;
import java.sql.SQLException;
import java.util.List;

public class TestEventValidationService {

    public static void main(String[] args) {
        try {
            // 1. KONEKCIJA NA BAZU (prilagodi connection string)
            String url = "jdbc:mysql://localhost:3306/university_db";
            String user = "root";
            String password = "password";

            Class.forName("com.mysql.cj.jdbc.Driver");
            Connection conn = DriverManager.getConnection(url, user, password);
            System.out.println("✓ Konekcija na bazu uspješna!\n");

            // 2. INICIJALIZACIJA SERVISA
            EventValidationService service = new EventValidationService(conn);
            System.out.println("\n=== INICIJALIZACIJA ZAVRŠENA ===\n");

            // 3. TESTIRANJE OSNOVNIH METODA
            System.out.println("=== TEST 1: DODAVANJE PREDAVANJA ===");
            String rezultatPredavanja = service.addLecture(1, 1, 1, "ponedeljak", "09:00:00", "10:00:00");
            System.out.println("Rezultat: " + rezultatPredavanja);

            System.out.println("\n=== TEST 2: DODAVANJE VJEŽBI ===");
            String rezultatVjezbi = service.addExercise(1, 2, 2, "utorak", "10:00:00", "11:00:00");
            System.out.println("Rezultat: " + rezultatVjezbi);

            System.out.println("\n=== TEST 3: DODAVANJE KOLOKVIJUMA ===");
            String rezultatKolokvijum = service.addColloquium(1, 1, 1, 3, "2025-12-15", "12:00:00", "13:00:00");
            System.out.println("Rezultat: " + rezultatKolokvijum);

            System.out.println("\n=== TEST 4: DODAVANJE ISPITA ===");
            String rezultatIspit = service.addExam(1, 1, 1, "2025-12-20", "09:00:00", "11:00:00", "pismeni");
            System.out.println("Rezultat: " + rezultatIspit);

            System.out.println("\n=== TEST 5: PROVJERA FONDA ČASOVA ===");
            String proveraFonda = service.checkCourseHours(1);
            System.out.println(proveraFonda);

            System.out.println("\n=== TEST 6: GENERISANJE RASPOREDA PREDAVANJA ===");
            String rasporedPredavanja = service.generateLectureSchedule(2);
            System.out.println("Rezultat: " + rasporedPredavanja);

            System.out.println("\n=== TEST 7: GENERISANJE RASPOREDA VJEŽBI ===");
            String rasporedVjezbi = service.generateExerciseSchedule(2);
            System.out.println("Rezultat: " + rasporedVjezbi);

            System.out.println("\n=== TEST 8: GENERISANJE KOMPLETNOG RASPOREDA ===");
            String kompletanRaspored = service.generateCompleteSchedule();
            System.out.println("Rezultat: " + kompletanRaspored);

            System.out.println("\n=== TEST 9: PREUZIMANJE TERMINA ZA RASPORED ===");
            List<AcademicEvent> eventi = service.getEventsBySchedule(1);
            System.out.println("Broj pronađenih termina: " + eventi.size());
            for (AcademicEvent event : eventi) {
                System.out.println("  - Predmet ID: " + event.idCourse +
                        ", Sala: " + event.idRoom +
                        ", Tip: " + event.typeEnum);
            }

            // 4. TESTIRANJE KONFLIKTNIH SCENARIJA
            System.out.println("\n=== TEST 10: KONFLIKT - ISTA SALA ===");
            String konfliktSala = service.addLecture(1, 1, 2, "ponedeljak", "09:00:00", "10:00:00");
            System.out.println("Rezultat: " + konfliktSala);

            System.out.println("\n=== TEST 11: KONFLIKT - ISTI PROFESOR ===");
            String konfliktProfesor = service.addLecture(2, 2, 1, "ponedeljak", "09:30:00", "10:30:00");
            System.out.println("Rezultat: " + konfliktProfesor);

            System.out.println("\n=== TEST 12: NEDOVOLJAN KAPACITET SALE ===");
            String nedovoljnKapacitet = service.addExercise(3, 5, 2, "srijeda", "11:00:00", "12:00:00");
            System.out.println("Rezultat: " + nedovoljnKapacitet);

            System.out.println("\n=== TEST 13: KOLOKVIJUM U NEDJELJU (GREŠKA) ===");
            String kolokvijumNedjelja = service.addColloquium(1, 1, 1, 3, "2025-12-21", "10:00:00", "11:00:00");
            System.out.println("Rezultat: " + kolokvijumNedjelja);

            System.out.println("\n=== TEST 14: ISPIT NA PRAZNIK (GREŠKA) ===");
            String ispitPraznik = service.addExam(2, 1, 2, "2025-12-25", "09:00:00", "11:00:00", "pismeni");
            System.out.println("Rezultat: " + ispitPraznik);

            System.out.println("\n=== TEST 15: NEPOSTOJEĆI KURS ===");
            String nepostojeci = service.addLecture(999, 1, 1, "ponedeljak", "09:00:00", "10:00:00");
            System.out.println("Rezultat: " + nepostojeci);

            System.out.println("\n=== TEST 16: NEPOSTOJEĆI PROFESOR ===");
            String nepostojProfesor = service.addLecture(1, 1, 999, "ponedeljak", "09:00:00", "10:00:00");
            System.out.println("Rezultat: " + nepostojProfesor);

            System.out.println("\n=== TEST 17: NEPOSTOJEĆA SALA ===");
            String nepostojeciSala = service.addLecture(1, 999, 1, "ponedeljak", "09:00:00", "10:00:00");
            System.out.println("Rezultat: " + nepostojeciSala);

            System.out.println("\n=== TEST 18: NEISPRAVAN FORMAT VREMENA ===");
            String losoVreme = service.addLecture(1, 1, 1, "cetvrtak", "25:99:99", "26:00:00");
            System.out.println("Rezultat: " + losoVreme);

            System.out.println("\n=== TEST 19: NEISPRAVAN FORMAT DATUMA ===");
            String losoDatum = service.addColloquium(1, 1, 1, 3, "invalid-date", "10:00:00", "11:00:00");
            System.out.println("Rezultat: " + losoDatum);

            System.out.println("\n=== TEST 20: VIŠE KOLOKVIJUMA U SEDMICI ===");
            String viseKolokvijuma1 = service.addColloquium(1, 1, 1, 3, "2025-12-15", "09:00:00", "10:00:00");
            String viseKolokvijuma2 = service.addColloquium(2, 2, 1, 3, "2025-12-16", "10:00:00", "11:00:00");
            String viseKolokvijuma3 = service.addColloquium(3, 3, 1, 3, "2025-12-17", "11:00:00", "12:00:00");
            System.out.println("Kolokvijum 1: " + viseKolokvijuma1);
            System.out.println("Kolokvijum 2: " + viseKolokvijuma2);
            System.out.println("Kolokvijum 3: " + viseKolokvijuma3);

            // 5. ZAVRŠETAK
            System.out.println("\n\n=== SVI TESTOVI ZAVRŠENI ===\n");
            conn.close();

        } catch (ClassNotFoundException e) {
            System.err.println("GRESKA: JDBC driver nije pronađen!");
            e.printStackTrace();
        } catch (SQLException e) {
            System.err.println("GRESKA: Problema sa bazom podataka!");
            e.printStackTrace();
        } catch (Exception e) {
            System.err.println("GRESKA: Neočekivana greška!");
            e.printStackTrace();
        }
    }
}