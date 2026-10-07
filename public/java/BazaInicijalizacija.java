import java.sql.*;
import java.nio.file.Paths;
import java.io.IOException;

public class BazaInicijalizacija {

    public static Connection uspostaviKonekciju() throws SQLException {
        try {
            EnvLoader env = EnvLoader.load();
            String host = env.get("DB_HOST");
            String port = env.get("DB_PORT");
            String name = env.get("DB_NAME");
            String user = env.get("DB_USER");
            String pass = env.get("DB_PASS");
            String sslmode = env.getOrDefault("DB_SSLMODE", "require");
            String sslroot = env.get("DB_SSLROOTCERT");

            String url = "jdbc:postgresql://" + host + ":" + port + "/" + name;
            String sep = url.contains("?") ? "&" : "?";

            if (sslmode != null && !sslmode.isEmpty()) {
                url += sep + "sslmode=" + sslmode;
                sep = "&";
            }

            if (sslroot != null && !sslroot.isEmpty()) {
                String sslrootAbs = Paths.get(sslroot).toAbsolutePath().toString();
                url += sep + "sslrootcert=" + sslrootAbs;
            }

            Class.forName("org.postgresql.Driver");
            Connection conn = DriverManager.getConnection(url, user, pass);
            System.out.println("✔ Konekcija uspjesna!");
            return conn;

        } catch (ClassNotFoundException e) {
            System.err.println("✗ Postgres driver nije pronadjen: " + e.getMessage());
            throw new SQLException("Driver error", e);
        } catch (IOException e) {
            System.err.println("✗ Greska pri ucitavanju .env: " + e.getMessage());
            throw new SQLException("Env load error", e);
        } catch (SQLException e) {
            System.err.println("✗ Greska pri povezivanju na bazu: " + e.getMessage());
            throw e;
        }
    }

    public static void main(String[] args) {
        Connection conn = null;
        try {
            System.out.println("=== INICIJALIZACIJA SISTEMA ===\n");
            conn = uspostaviKonekciju();
            
            System.out.println("\n=== TESTIRANJE NOVE STRUKTURE ===\n");
            EventValidationService service = new EventValidationService(conn);
            
            System.out.println("\n✔ Sistem je spreman za rad!");
            System.out.println("\nDostupne komande:");
            System.out.println("  java ValidacijaTermina dodajPredavanje <args>");
            System.out.println("  java ValidacijaTermina dodajVjezbe <args>");
            System.out.println("  java ValidacijaTermina dodajKolokvijum <args>");
            System.out.println("  java ValidacijaTermina dodajIspit <args>");
            System.out.println("  java ValidacijaTermina generisiPredavanja <id_predmet>");
            System.out.println("  java ValidacijaTermina generisiVjezbe <id_predmet>");
            System.out.println("  java ValidacijaTermina generisiKompletan");

        } catch (SQLException e) {
            System.err.println("✗ Greska: " + e.getMessage());
            e.printStackTrace();
        } finally {
            if (conn != null) {
                try {
                    conn.close();
                    System.out.println("\n✔ Konekcija zatvorena.");
                } catch (SQLException e) {
                    System.err.println("✗ Greska pri zatvaranju konekcije: " + e.getMessage());
                }
            }
        }
    }
}
