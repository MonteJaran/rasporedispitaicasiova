import java.sql.*;
import java.util.List;

public class ValidacijaTermina {
    public static void main(String[] args) {
        if (args.length < 1) {
            System.out.println("GRESKA: Nedostaje akcija");
            return;
        }

        String akcija = args[0];

        // Posebno obrađeno PRIJE konstruisanja EventValidationService: taj konstruktor
        // eager-loaduje CIJELU bazu (sve academic_event redove - 15000+ i raste sa svakim
        // generisanjem rasporeda, sve courses/rooms/professors/holidays), a ColloquiumService
        // tu instancu uopšte ne koristi - otvara sopstvenu, mnogo jeftiniju konekciju. Taj
        // nepotrebni eager-load je ono što je 'Generiši kolokvijume' činilo dovoljno sporim
        // da Render-ov proxy prekine konekciju sa HTTP 520 prije nego što Java stigne da
        // odgovori.
        if (akcija.equals("generisiKolokvijume")) {
            ColloquiumService colService = new ColloquiumService();
            String rezultat = colService.generateColloquiums();
            boolean uspjesno = "OK".equals(rezultat.trim());
            String poruka = uspjesno ? "Kolokvijumi su uspješno generisani." : rezultat;
            ScheduleProgress.writeSimple("colloquium_progress.json", poruka, true, uspjesno,
                    uspjesno ? null : rezultat);
            System.out.println(rezultat);
            return;
        }

        Connection conn = null;
        try {
            conn = BazaInicijalizacija.uspostaviKonekciju();
            EventValidationService service = new EventValidationService(conn);
            String rezultat = "";

            if (akcija.equals("dodajPredavanje")) {
                if (args.length < 7) {
                    System.out.println("GRESKA: Nedostaju parametri");
                    return;
                }
                int idPredmet = Integer.parseInt(args[1]);
                int idSala = Integer.parseInt(args[2]);
                int idProfesor = Integer.parseInt(args[3]);
                String dan = args[4];
                String vremeOd = args[5];
                String vremeDo = args[6];
                rezultat = service.addLecture(idPredmet, idSala, idProfesor, dan, vremeOd, vremeDo);

            } else if (akcija.equals("dodajVjezbe")) {
                if (args.length < 7) {
                    System.out.println("GRESKA: Nedostaju parametri");
                    return;
                }
                int idPredmet = Integer.parseInt(args[1]);
                int idSala = Integer.parseInt(args[2]);
                int idProfesor = Integer.parseInt(args[3]);
                String dan = args[4];
                String vremeOd = args[5];
                String vremeDo = args[6];
                rezultat = service.addExercise(idPredmet, idSala, idProfesor, dan, vremeOd, vremeDo);

            } else if (akcija.equals("dodajKolokvijum")) {
                if (args.length < 8) {
                    System.out.println("GRESKA: Nedostaju parametri");
                    return;
                }
                int idPredmet = Integer.parseInt(args[1]);
                int idSala = Integer.parseInt(args[2]);
                int idProfesor = Integer.parseInt(args[3]);
                int idDezurni = Integer.parseInt(args[4]);
                String datum = args[5];
                String vremeOd = args[6];
                String vremeDo = args[7];
                rezultat = service.addColloquium(idPredmet, idSala, idProfesor, idDezurni, datum, vremeOd, vremeDo);

            } else if (akcija.equals("dodajIspit")) {
                if (args.length < 8) {
                    System.out.println("GRESKA: Nedostaju parametri");
                    return;
                }
                int idPredmet = Integer.parseInt(args[1]);
                int idSala = Integer.parseInt(args[2]);
                int idProfesor = Integer.parseInt(args[3]);
                String datum = args[4];
                String vremeOd = args[5];
                String vremeDo = args[6];
                String tipIspita = args[7];
                rezultat = service.addExam(idPredmet, idSala, idProfesor, datum, vremeOd, vremeDo, tipIspita);

            } else if (akcija.equals("generisiPredavanja")) {
                if (args.length < 2) {
                    System.out.println("GRESKA: Nedostaje ID predmeta");
                    return;
                }
                int idPredmet = Integer.parseInt(args[1]);
                rezultat = service.generateLectureSchedule(idPredmet);

            } else if (akcija.equals("generisiVjezbe")) {
                if (args.length < 2) {
                    System.out.println("GRESKA: Nedostaje ID predmeta");
                    return;
                }
                int idPredmet = Integer.parseInt(args[1]);
                rezultat = service.generateExerciseSchedule(idPredmet);

            } else if (akcija.equals("prikaziRaspored")) {
                if (args.length != 2) {
                    System.out.println("GRESKA Nedostaje ID rasporeda");
                    return;
                }

                int scheduleId = Integer.parseInt(args[1]);

                try {
                    List<AcademicEvent> events = service.getEventsBySchedule(scheduleId);

                    if (events.isEmpty()) {
                        System.out.println("NEMA ZAPISA za schedule_id = " + scheduleId);
                        return;
                    }

                    System.out.println(
                            "idTermin;rasporedId;idCourse;idProfessor;idRoom;dan;datum;vremeOd;vremeDo;tipTermina;jeOnline;teze");

                    for (AcademicEvent ev : events) {
                        String notesSafe = (ev.notes != null) ? ev.notes.replace(";", ",") : "";

                        System.out.println(
                                ev.idAcademicEvent + ";" +
                                        ev.scheduleId + ";" +
                                        ev.idCourse + ";" +
                                        ev.idProfessor + ";" +
                                        ev.idRoom + ";" +
                                        (ev.day != null ? ev.day : "") + ";" +
                                        (ev.date != null ? ev.date.toString() : "") + ";" +
                                        (ev.startTime != null ? ev.startTime.toString() : "") + ";" +
                                        (ev.endTime != null ? ev.endTime.toString() : "") + ";" +
                                        (ev.typeEnum != null ? ev.typeEnum : "") + ";" +
                                        ev.isOnline + ";" +
                                        notesSafe);
                    }
                } catch (Exception e) {
                    System.out.println("GRESKA " + e.getMessage());
                    e.printStackTrace();
                }

                return;
            } else if (akcija.equals("generisiKompletan")) {
                ScheduleResult scheduleResult = service.generateSixSchedulesWithDifferentPriorities();
                rezultat = scheduleResult.message;

            } else {
                System.out.println("GRESKA: Nepoznata akcija");
                System.out.println("Dostupne akcije:");
                System.out.println(
                        "  - dodajPredavanje <id_predmet> <id_sala> <id_profesor> <dan> <vreme_od> <vreme_do>");
                System.out.println("  - dodajVjezbe <id_predmet> <id_sala> <id_profesor> <dan> <vreme_od> <vreme_do>");
                System.out.println(
                        "  - dodajKolokvijum <id_predmet> <id_sala> <id_profesor> <id_dezurni> <datum> <vreme_od> <vreme_do>");
                System.out.println(
                        "  - dodajIspit <id_predmet> <id_sala> <id_profesor> <datum> <vreme_od> <vreme_do> <tip_ispita>");
                System.out.println("  - generisiPredavanja <id_predmet>");
                System.out.println("  - generisiVjezbe <id_predmet>");
                System.out.println("  - generisiKompletan");
                return;
            }

            System.out.println(rezultat);

        } catch (Exception e) {
            System.out.println("GRESKA: " + e.getMessage());
            e.printStackTrace();
        } finally {
            if (conn != null) {
                try {
                    conn.close();
                } catch (SQLException e) {
                    System.err.println("Greska pri zatvaranju konekcije");
                }
            }
        }
    }
}
