#!/bin/bash
# DotNetManager privileged control wrapper
# Runs via sudo to execute systemctl/journalctl as root after ownership check

set -e

ACTION="$1"
SERVICE="$2"
CALLER="${SUDO_USER:-$USER}"
DB_PATH="/usr/local/cwp/.conf/dotnetmanager/apps.db"
PHP_DB_CLASS="/usr/local/cwpsrv/var/services/user_files/modules/DotNetManagerDB.php"

if [[ -z "$ACTION" || -z "$SERVICE" ]]; then
    echo "Usage: $0 <action> <service> [lines]"
    exit 1
fi

# Only allow DotNetManager services
if [[ ! "$SERVICE" =~ ^[a-zA-Z0-9_-]+\.service$ ]]; then
    echo "Invalid service name"
    exit 1
fi

if [[ ! -f "/etc/systemd/system/DotNetManager/$SERVICE" ]]; then
    echo "Service not found"
    exit 1
fi

APP_NAME=$(basename "$SERVICE" .service)

# Verify ownership via PHP DB class
RESULT=$(php -d open_basedir=none -r "
require '$PHP_DB_CLASS';
\$db = new DotNetManagerDB('$DB_PATH');
\$app = \$db->getAppByName('$APP_NAME');
echo (\$app && \$app['username'] === '$CALLER') ? '1' : '0';
")

if [ "$RESULT" != "1" ]; then
    echo "Access denied: you do not own this application."
    exit 1
fi

case "$ACTION" in
    start|stop|restart|status|show)
        /usr/bin/systemctl "$ACTION" "$SERVICE"
        ;;
    logs)
        LINES="${3:-50}"
        if ! [[ "$LINES" =~ ^[0-9]+$ ]]; then
            echo "Invalid line count"
            exit 1
        fi
        /usr/bin/journalctl -u "$SERVICE" --no-pager -n "$LINES"
        ;;
    *)
        echo "Unknown action: $ACTION"
        exit 1
        ;;
esac
