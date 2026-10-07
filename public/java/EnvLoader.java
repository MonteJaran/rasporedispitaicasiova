import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.io.IOException;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

public class EnvLoader {
    private final Map<String, String> env = new HashMap<>();

    private EnvLoader() {
    }

    public static EnvLoader load() throws IOException {
        EnvLoader loader = new EnvLoader();
        // Traži .env u root direktorijumu projekta (dva nivoa iznad public/java)
        Path envPath = Paths.get("../../.env");
        if (!Files.exists(envPath)) {
            // Pokušaj i sa trenutnim direktorijumom kao fallback
            envPath = Paths.get(".env");
            if (!Files.exists(envPath)) {
                throw new IOException(".env file not found at " + envPath.toAbsolutePath());
            }
        }

        List<String> lines = Files.readAllLines(envPath);
        for (String line : lines) {
            line = line.trim();
            if (line.isEmpty() || line.startsWith("#"))
                continue;
            int idx = line.indexOf('=');
            if (idx <= 0)
                continue;
            String key = line.substring(0, idx).trim();
            String val = line.substring(idx + 1).trim();
            if (val.length() >= 2
                    && ((val.startsWith("\"") && val.endsWith("\"")) || (val.startsWith("'") && val.endsWith("'")))) {
                val = val.substring(1, val.length() - 1);
            }
            loader.env.put(key, val);
        }
        return loader;
    }

    public String get(String key) {
        return env.get(key);
    }

    public String getOrDefault(String key, String def) {
        return env.getOrDefault(key, def);
    }

    public String resolvePathAbsolute(String key) {
        String p = get(key);
        if (p == null)
            return null;
        Path path = Paths.get(p);
        return path.toAbsolutePath().toString();
    }
}
