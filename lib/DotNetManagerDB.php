<?php
/**
 * DotNetManager Database Helper
 * Manages SQLite storage for .NET application metadata
 */

define('DOTNETMANAGER_VERSION', '1.0.0');

class DotNetManagerDB {
    private $db;
    private $dbPath;

    public function __construct($dbPath = '/usr/local/cwpsrv/var/services/user_files/modules/dotnetmanager/apps.db') {
        $this->dbPath = $dbPath;
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->db = new PDO('sqlite:' . $dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->initTables();
    }

    private function initTables() {
        $this->db->exec("CREATE TABLE IF NOT EXISTS applications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            domain TEXT NOT NULL,
            username TEXT NOT NULL DEFAULT 'root',
            working_directory TEXT NOT NULL,
            dll_path TEXT NOT NULL,
            urls TEXT NOT NULL,
            environment TEXT DEFAULT 'Production',
            port INTEGER DEFAULT 5000,
            nginx_config_path TEXT DEFAULT '',
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            status_override TEXT DEFAULT ''
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS app_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            app_name TEXT NOT NULL,
            action TEXT NOT NULL,
            details TEXT,
            performed_by TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_app_name ON app_logs(app_name)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_username ON applications(username)");
        
        // Migration: add port column if missing (for upgrades)
        try {
            $this->db->query("SELECT port FROM applications LIMIT 1");
        } catch (PDOException $e) {
            $this->db->exec("ALTER TABLE applications ADD COLUMN port INTEGER DEFAULT 5000");
        }
        // Migration: add nginx_config_path column if missing
        try {
            $this->db->query("SELECT nginx_config_path FROM applications LIMIT 1");
        } catch (PDOException $e) {
            $this->db->exec("ALTER TABLE applications ADD COLUMN nginx_config_path TEXT DEFAULT ''");
        }
    }

    public function getAllApps() {
        $stmt = $this->db->query("SELECT * FROM applications ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAppsByUser($username) {
        $stmt = $this->db->prepare("SELECT * FROM applications WHERE username = ? ORDER BY created_at DESC");
        $stmt->execute([$username]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAppByName($name) {
        $stmt = $this->db->prepare("SELECT * FROM applications WHERE name = ?");
        $stmt->execute([$name]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function saveApp($data) {
        $existing = $this->getAppByName($data['name']);
        if ($existing) {
            $stmt = $this->db->prepare("UPDATE applications SET 
                domain = ?, username = ?, working_directory = ?, dll_path = ?, 
                urls = ?, environment = ?, port = ?, nginx_config_path = ?, updated_at = datetime('now') 
                WHERE name = ?");
            $stmt->execute([
                $data['domain'], $data['username'], $data['working_directory'],
                $data['dll_path'], $data['urls'], $data['environment'],
                isset($data['port']) ? $data['port'] : 5000,
                isset($data['nginx_config_path']) ? $data['nginx_config_path'] : '',
                $data['name']
            ]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO applications 
                (name, domain, username, working_directory, dll_path, urls, environment, port, nginx_config_path) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['name'], $data['domain'], $data['username'],
                $data['working_directory'], $data['dll_path'], $data['urls'], $data['environment'],
                isset($data['port']) ? $data['port'] : 5000,
                isset($data['nginx_config_path']) ? $data['nginx_config_path'] : ''
            ]);
        }
        return true;
    }

    public function deleteApp($name) {
        $stmt = $this->db->prepare("DELETE FROM applications WHERE name = ?");
        $stmt->execute([$name]);
        return true;
    }

    public function logAction($appName, $action, $details = '', $performedBy = 'system') {
        $stmt = $this->db->prepare("INSERT INTO app_logs (app_name, action, details, performed_by) VALUES (?, ?, ?, ?)");
        $stmt->execute([$appName, $action, $details, $performedBy]);
    }

    public function getLogs($appName = null, $limit = 100) {
        if ($appName) {
            $stmt = $this->db->prepare("SELECT * FROM app_logs WHERE app_name = ? ORDER BY created_at DESC LIMIT ?");
            $stmt->execute([$appName, $limit]);
        } else {
            $stmt = $this->db->prepare("SELECT * FROM app_logs ORDER BY created_at DESC LIMIT ?");
            $stmt->execute([$limit]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStats() {
        $total = $this->db->query("SELECT COUNT(*) FROM applications")->fetchColumn();
        $users = $this->db->query("SELECT COUNT(DISTINCT username) FROM applications")->fetchColumn();
        return ['total' => $total, 'users' => $users];
    }
}
