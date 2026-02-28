#!/bin/sh
set -e

CONFIG_FILE="/var/www/pidoors/includes/config.php"
MARKER="/var/www/pidoors/.docker-configured"

# Always configure for Docker on first run
if [ ! -f "$MARKER" ]; then
    # Start from the example template
    cp /var/www/pidoors/includes/config.php.example "$CONFIG_FILE"

    # Configure database connection for Docker
    sed -i "s|'sqladdr' => '127.0.0.1'|'sqladdr' => 'db'|" "$CONFIG_FILE"
    sed -i "s|'sqlpass' => ''|'sqlpass' => 'pidoors_pass'|" "$CONFIG_FILE"
    sed -i "s|'url' => 'http://localhost'|'url' => ''|" "$CONFIG_FILE"

    chown www-data:www-data "$CONFIG_FILE"

    # Set admin password hash via PHP (avoids shell escaping issues with bcrypt $ signs)
    php -r '
    $pdo = new PDO("mysql:host=db;dbname=users;charset=utf8mb4", "pidoors", "pidoors_pass");
    $hash = password_hash("PiDoors2024!", PASSWORD_BCRYPT, ["cost" => 12]);
    $stmt = $pdo->prepare("UPDATE users SET user_pass = ? WHERE id = 1");
    $stmt->execute([$hash]);
    echo "Admin password hash set successfully.\n";
    '

    touch "$MARKER"
    echo "Config file created and configured for Docker."
fi

exec "$@"
