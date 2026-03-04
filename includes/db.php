<?php
// Database initialization and migration
//
// This script automatically creates any missing tables / columns on first run, so
// you can simply drop the project files in place and visit the site – no need to
// run SQL by hand.

date_default_timezone_set('Asia/Shanghai');

$dbFile = __DIR__ . '/../data.db';
$first  = !file_exists($dbFile);

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    /**
     * ----------------------------------------------------------------------
     * Core schema – created on first run
     * ----------------------------------------------------------------------
     */
    if ($first) {
        $db->exec("
            PRAGMA foreign_keys = ON;

            CREATE TABLE IF NOT EXISTS users (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                username       TEXT    UNIQUE,
                password_hash  TEXT,
                is_admin       INTEGER DEFAULT 0,
                email          TEXT,
                email_verified INTEGER DEFAULT 0,
                notif_email    TEXT,
                notify_on      INTEGER DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS codes (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER,
                name       TEXT,
                token      TEXT UNIQUE,
                created_at DATETIME DEFAULT (datetime('now')),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS logs (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                code_id     INTEGER,
                ip          TEXT,
                location    TEXT,
                user_agent  TEXT,
                created_at  DATETIME DEFAULT (datetime('now')),
                FOREIGN KEY (code_id) REFERENCES codes(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS configs (
                key   TEXT PRIMARY KEY,
                value TEXT
            );

            
            CREATE TABLE IF NOT EXISTS invite_codes (
                code TEXT PRIMARY KEY,
                used INTEGER DEFAULT 0,
                used_at DATETIME
            );


            
            CREATE TABLE IF NOT EXISTS pending (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                username    TEXT,
                password_hash TEXT,
                email       TEXT,
                token       TEXT,
                invite_code TEXT,
                created_at  DATETIME DEFAULT (datetime('now'))
            );

        ");

        // ----------------- Insert default data -----------------
        $defaults = [
            'allow_register' => '0',
            'require_invite' => '0',
            'site_name'      => 'Email‑Tracker',
            'smtp_host'      => '',
            'smtp_port'      => '',
            'smtp_user'      => '',
            'smtp_pass'      => '',
            'smtp_secure'    => 'ssl',
            'smtp_from'      => '',
            'smtp_debug'     => '0',
            'login_captcha'  => '1'
        ];

        $ins = $db->prepare('INSERT INTO configs(key, value) VALUES (:k, :v)');
        foreach ($defaults as $k => $v) {
            $ins->execute([':k' => $k, ':v' => $v]);
        }

        // Default admin account: admin / admin123  (remember to change!)
        $db->prepare('INSERT INTO users(username, password_hash, is_admin) VALUES (?,?,1)')
           ->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT)]);
    }

    /**
     * ----------------------------------------------------------------------
     *  Migrations – run every request (lightweight)
     * ----------------------------------------------------------------------
     * These migrations make sure older databases are silently upgraded.
     */

    // 1. pending table (added in v1.4.0)
    $db->exec("
        CREATE TABLE IF NOT EXISTS pending (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            username    TEXT,
            email       TEXT,
            token       TEXT,
            invite_code TEXT,
            created_at  DATETIME DEFAULT (datetime('now'))
        );
    ");

    // 2. Add missing columns (if the column already exists SQLite will throw,
    //    so we wrap them in try/catch blocks to ignore the error)
    $maybeAddColumn = function (string $table, string $def) use ($db) {
        try {
            $db->exec("ALTER TABLE {$table} ADD COLUMN {$def};");
        } catch (PDOException $e) {
            // Likely "duplicate column": ignore
        }
    };

    $maybeAddColumn('pending', 'invite_code TEXT');
    $maybeAddColumn('pending', 'created_at DATETIME');
    $maybeAddColumn('users', 'created_at DATETIME');
    $maybeAddColumn('pending', 'password_hash TEXT');
    $maybeAddColumn('invite_codes', 'used_at DATETIME');

    // 3. Ensure newly introduced config keys exist
    $db->exec("INSERT OR IGNORE INTO configs(key, value) VALUES ('login_captcha', '1');");

} catch (PDOException $e) {
    die('DB错误:' . $e->getMessage());
}
?>
