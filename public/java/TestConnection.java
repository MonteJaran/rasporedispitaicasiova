import java.sql.*;

public class TestConnection {
    public static void main(String[] args) {
        String url = "jdbc:postgresql://drsfit-2025-luka-7e2c.e.aivencloud.com:21379/RasporedCasova";
        String user = "avnadmin";
        String pass = "AVNS_9FPAtFu";

        try (Connection conn = DriverManager.getConnection(url, user, pass)) {
            System.out.println("✔ PostgreSQL konekcija uspješna!");

            Statement stmt = conn.createStatement();
            ResultSet rs = stmt.executeQuery("SELECT 1;");
            if (rs.next()) {
                System.out.println("✔ Upit radi! Rezultat = " + rs.getInt(1));
            }
            rs.close();

            // Test učitavanja podataka
            System.out.println("\n=== Test učitavanja podataka sa novim klasama ===");
            // EventValidationService service = new EventValidationService(conn);
            // System.out.println("✔ EventValidationService inicijalizovan uspješno!");

            stmt.close();

        } catch (Exception e) {
            System.err.println("✗ Greška:");
            e.printStackTrace();
        }
    }
}
