import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.nio.file.StandardCopyOption;

/**
 * Upisuje napredak generisanja rasporeda u storage/schedule_progress.json
 * da bi admin_panel.php mogao da ga pollinguje dok Java proces radi u pozadini.
 * Pisanje je atomično (tmp + move) i nikad ne baca izuzetak napolje - ako upis
 * ne uspije, generisanje rasporeda mora nastaviti nesmetano.
 */
public class ScheduleProgress {

    // Isti fallback obrazac kao EnvLoader.load(): proces se pokreće ili iz
    // public/java (ručno testiranje) ili iz korijena projekta (admin_panel.php
    // radi chdir prije shell_exec/proc_open), pa se korijen projekta prepoznaje
    // po istoj provjeri kao ".env".
    private static Path resolveProjectRoot() {
        Path fromJavaDir = Paths.get("../../.env");
        if (Files.exists(fromJavaDir)) {
            return Paths.get("../..").toAbsolutePath().normalize();
        }
        return Paths.get(".").toAbsolutePath().normalize();
    }

    public static synchronized void write(int curSchedule, int totalSchedules, int curCourse, int totalCourses,
            String message, boolean done, Boolean success, String error) {
        StringBuilder sb = new StringBuilder();
        sb.append("{");
        sb.append("\"running\":").append(!done).append(",");
        sb.append("\"done\":").append(done).append(",");
        sb.append("\"success\":").append(success == null ? "null" : success.toString()).append(",");
        sb.append("\"current_schedule\":").append(curSchedule).append(",");
        sb.append("\"total_schedules\":").append(totalSchedules).append(",");
        sb.append("\"current_course\":").append(curCourse).append(",");
        sb.append("\"total_courses\":").append(totalCourses).append(",");
        sb.append("\"message\":\"").append(escape(message)).append("\",");
        sb.append("\"error\":").append(error == null ? "null" : "\"" + escape(error) + "\"").append(",");
        sb.append("\"updated_at\":").append(System.currentTimeMillis() / 1000L);
        sb.append("}");
        writeJson("schedule_progress.json", sb.toString());
    }

    // Isti oblik napretka (running/done/success/message/error), ali bez
    // schedule/course brojača - za generisanja koja nemaju međukorake da
    // prijave (npr. kolokvijumi), samo početak i kraj.
    public static synchronized void writeSimple(String fileName, String message, boolean done, Boolean success,
            String error) {
        StringBuilder sb = new StringBuilder();
        sb.append("{");
        sb.append("\"running\":").append(!done).append(",");
        sb.append("\"done\":").append(done).append(",");
        sb.append("\"success\":").append(success == null ? "null" : success.toString()).append(",");
        sb.append("\"message\":\"").append(escape(message)).append("\",");
        sb.append("\"error\":").append(error == null ? "null" : "\"" + escape(error) + "\"").append(",");
        sb.append("\"updated_at\":").append(System.currentTimeMillis() / 1000L);
        sb.append("}");
        writeJson(fileName, sb.toString());
    }

    private static void writeJson(String fileName, String json) {
        try {
            Path dir = resolveProjectRoot().resolve("storage");
            Files.createDirectories(dir);
            Path target = dir.resolve(fileName);
            Path tmp = dir.resolve(fileName + ".tmp");

            Files.write(tmp, json.getBytes(StandardCharsets.UTF_8));
            try {
                Files.move(tmp, target, StandardCopyOption.REPLACE_EXISTING, StandardCopyOption.ATOMIC_MOVE);
            } catch (IOException atomicNotSupported) {
                Files.move(tmp, target, StandardCopyOption.REPLACE_EXISTING);
            }
        } catch (Exception e) {
            System.err.println("Napomena: progress fajl nije upisan (" + e.getMessage() + ") - generisanje nastavlja.");
        }
    }

    private static String escape(String s) {
        if (s == null) {
            return "";
        }
        StringBuilder out = new StringBuilder();
        for (char c : s.toCharArray()) {
            switch (c) {
                case '"':
                    out.append("\\\"");
                    break;
                case '\\':
                    out.append("\\\\");
                    break;
                case '\n':
                    out.append("\\n");
                    break;
                case '\r':
                    out.append("\\r");
                    break;
                case '\t':
                    out.append("\\t");
                    break;
                default:
                    if (c < 0x20) {
                        out.append(String.format("\\u%04x", (int) c));
                    } else {
                        out.append(c);
                    }
            }
        }
        return out.toString();
    }
}
