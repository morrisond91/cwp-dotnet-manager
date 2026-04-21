#!/bin/bash
# DotNetManager CWP Plugin Installation Script
# Run as root

set -e

PLUGIN_DIR="/home/newplugin"
ADMIN_MODULES="/usr/local/cwpsrv/htdocs/resources/admin/modules"
ADMIN_INCLUDE="/usr/local/cwpsrv/htdocs/resources/admin/include"
USER_MODULES="/usr/local/cwpsrv/var/services/user_files/modules"
USER_THEME="/usr/local/cwpsrv/var/services/users/cwp_theme/original"
USER_LANG="/usr/local/cwpsrv/var/services/users/cwp_lang/en"
CONF_DIR="/usr/local/cwp/.conf/dotnetmanager"
SERVICE_DIR="/etc/systemd/system/DotNetManager"
DAEMON_DIR="/usr/local/cwpsrv/var/services/user_files/modules/dotnetmanager"

echo "========================================"
echo "  DotNetManager CWP Plugin Installer"
echo "========================================"

# Check root
if [ "$EUID" -ne 0 ]; then
    echo "Error: Please run as root"
    exit 1
fi

# Check nginx is installed (prerequisite)
echo "[*] Checking nginx prerequisite..."
if ! command -v nginx &> /dev/null; then
    echo "Error: nginx is not installed or not in PATH."
    echo "This plugin requires nginx to proxy .NET applications."
    echo "Please install nginx first (e.g., yum install nginx or dnf install nginx)."
    exit 1
fi

NGINX_CONF_DIR="/etc/nginx/conf.d"
if [ ! -d "$NGINX_CONF_DIR" ]; then
    echo "Error: nginx config directory not found at $NGINX_CONF_DIR"
    echo "Please ensure nginx is properly installed."
    exit 1
fi

echo "    nginx found: $(nginx -v 2>&1 | head -n1)"

# Check CWP paths exist
if [ ! -d "$ADMIN_MODULES" ]; then
    echo "Error: CWP admin modules directory not found at $ADMIN_MODULES"
    exit 1
fi

if [ ! -d "$USER_MODULES" ]; then
    echo "Error: CWP user modules directory not found at $USER_MODULES"
    exit 1
fi

# Create required directories
mkdir -p "$CONF_DIR"
chmod 755 "$CONF_DIR"
mkdir -p "$SERVICE_DIR"
mkdir -p "$DAEMON_DIR"
chmod 755 "$DAEMON_DIR"
mkdir -p "$PLUGIN_DIR"

# Install admin modules
echo "[*] Installing admin panel modules..."
cp -f "$PLUGIN_DIR/admin/modules/DotNetManager_Dashboard.php" "$ADMIN_MODULES/"
cp -f "$PLUGIN_DIR/admin/modules/DotNetManager_Apps.php" "$ADMIN_MODULES/"
cp -f "$PLUGIN_DIR/admin/modules/DotNetManager_Edit.php" "$ADMIN_MODULES/"
cp -f "$PLUGIN_DIR/admin/modules/DotNetManager_Logs.php" "$ADMIN_MODULES/"
cp -f "$PLUGIN_DIR/admin/modules/DotNetManager_Runtimes.php" "$ADMIN_MODULES/"

# Install shared libraries
echo "[*] Installing shared libraries..."
mkdir -p "$CONF_DIR/templates"
cp -f "$PLUGIN_DIR/lib/DotNetManagerDB.php" "$USER_MODULES/DotNetManagerDB.php"
cp -f "$PLUGIN_DIR/lib/DotNetManagerNginx.php" "$USER_MODULES/DotNetManagerNginx.php"
cp -f "$PLUGIN_DIR/templates/nginx_http.template" "$CONF_DIR/templates/nginx_http.template"
cp -f "$PLUGIN_DIR/templates/nginx_ssl.template" "$CONF_DIR/templates/nginx_ssl.template"
chown cwpsvc:cwpsvc "$USER_MODULES/DotNetManagerDB.php"
chown cwpsvc:cwpsvc "$USER_MODULES/DotNetManagerNginx.php"
chmod 644 "$USER_MODULES/DotNetManagerDB.php"
chmod 644 "$USER_MODULES/DotNetManagerNginx.php"
chmod 644 "$CONF_DIR/templates/nginx_http.template"
chmod 644 "$CONF_DIR/templates/nginx_ssl.template"

# Install user panel module
echo "[*] Installing user panel module..."
cp -f "$PLUGIN_DIR/user/modules/DotNetManager.php" "$USER_MODULES/"

# Install user theme template
echo "[*] Installing user panel template..."
cp -f "$PLUGIN_DIR/user/theme/mod_DotNetManager.html" "$USER_THEME/"

# Install user language file
echo "[*] Installing user language file..."
cp -f "$PLUGIN_DIR/user/lang/en/DotNetManager.ini" "$USER_LANG/"

# Install control script & socket daemon
echo "[*] Installing privileged control wrapper and socket daemon..."
cp -f "$PLUGIN_DIR/user/modules/dotnetmanager/control.sh" "$DAEMON_DIR/control.sh"
chmod 755 "$DAEMON_DIR/control.sh"
chown root:root "$DAEMON_DIR/control.sh"

cp -f "$PLUGIN_DIR/user/modules/dotnetmanager/socketd.py" "$DAEMON_DIR/socketd.py"
chmod 755 "$DAEMON_DIR/socketd.py"
chown root:root "$DAEMON_DIR/socketd.py"

# Install systemd service for socket daemon
SOCKET_SERVICE="/etc/systemd/system/dotnetmanager-socket.service"
cp -f "$PLUGIN_DIR/user/modules/dotnetmanager/dotnetmanager-socket.service" "$SOCKET_SERVICE"
chmod 644 "$SOCKET_SERVICE"

# Set up sudoers for control.sh fallback
SUDOERS_FILE="/etc/sudoers.d/dotnetmanager"
cat > /tmp/dotnetmanager-sudoers << 'EOF'
# DotNetManager CWP Plugin - allow users to manage their own .NET services
Cmnd_Alias DOTNETMANAGER_CTRL = /usr/local/cwpsrv/var/services/user_files/modules/dotnetmanager/control.sh
ALL ALL=(root) NOPASSWD: DOTNETMANAGER_CTRL
EOF
if visudo -c -f /tmp/dotnetmanager-sudoers >/dev/null 2>&1; then
    cp /tmp/dotnetmanager-sudoers "$SUDOERS_FILE"
    chmod 440 "$SUDOERS_FILE"
    chown root:root "$SUDOERS_FILE"
    echo "    Sudo wrapper installed."
else
    echo "    Warning: sudoers validation failed. You must manually configure sudo for $DAEMON_DIR/control.sh"
fi
rm -f /tmp/dotnetmanager-sudoers

# Enable and start socket daemon
systemctl daemon-reload
systemctl enable dotnetmanager-socket.service
if systemctl is-active dotnetmanager-socket.service >/dev/null 2>&1; then
    systemctl restart dotnetmanager-socket.service
    echo "    Socket daemon restarted."
else
    systemctl start dotnetmanager-socket.service
    echo "    Socket daemon started."
fi

# Fix permissions
chown -R root:root "$ADMIN_MODULES"/DotNetManager_*.php
chmod 644 "$ADMIN_MODULES"/DotNetManager_*.php
chown -R cwpsvc:cwpsvc "$USER_MODULES"/DotNetManager.php
chmod 644 "$USER_MODULES"/DotNetManager.php
chown -R cwpsvc:cwpsvc "$USER_THEME"/mod_DotNetManager.html
chmod 644 "$USER_THEME"/mod_DotNetManager.html
chown -R cwpsvc:cwpsvc "$USER_LANG"/DotNetManager.ini
chmod 644 "$USER_LANG"/DotNetManager.ini
chmod 755 "$SERVICE_DIR"
chmod 755 "$CONF_DIR"
chmod 755 "$DAEMON_DIR"
if [ -f "$CONF_DIR/apps.db" ]; then
    chmod 666 "$CONF_DIR/apps.db"
fi

# Admin menu integration
echo "[*] Checking admin menu integration..."
ADMIN_3RDPARTY="$ADMIN_INCLUDE/3rdparty.php"
if [ -f "$ADMIN_3RDPARTY" ]; then
    if grep -q "DotNetManager_Dashboard" "$ADMIN_3RDPARTY"; then
        echo "    Admin menu already integrated."
    else
        echo "    Patching admin 3rdparty menu..."
        sed -i '/<li style="display:none;"><ul>/i \
<li class="custom-menu">\
    <a class="hasUl" href="#"><span class="icon16 icomoon-icon-cloud"></span> .NET Apps Manager<span class="hasDrop icon16 icomoon-icon-arrow-down-2"></span></a>\
    <ul class="sub">\
        <li><a href="index.php?module=DotNetManager_Dashboard"><span class="icon16 icomoon-icon-arrow-right-3"></span>Dashboard</a></li>\
        <li><a href="index.php?module=DotNetManager_Apps"><span class="icon16 icomoon-icon-arrow-right-3"></span>Manage Applications</a></li>\
        <li><a href="index.php?module=DotNetManager_Edit"><span class="icon16 icomoon-icon-arrow-right-3"></span>Create Application</a></li>\
        <li><a href="index.php?module=DotNetManager_Runtimes"><span class="icon16 icomoon-icon-arrow-right-3"></span>Runtimes</a></li>\
    </ul>\
</li>\
' "$ADMIN_3RDPARTY"
        echo "    Done."
    fi
else
    echo "    Warning: $ADMIN_3RDPARTY not found. You must add the menu manually."
fi

# User menu integration
echo "[*] Checking user menu integration..."
USER_MENU="$USER_THEME/menu_left.html"
if [ -f "$USER_MENU" ]; then
    if grep -q "DotNetManager" "$USER_MENU"; then
        echo "    User menu already integrated."
    else
        echo "    Patching user menu_left.html..."
        if grep -q 'mysql_manager' "$USER_MENU"; then
            sed -i '/{% if ("mysql_manager" in rmenu )/i \
<li class="searchmenu">\
    <a href="?module=DotNetManager"><i class="fa fa-cubes"></i> <span class="nav-label">.NET Apps Manager</span></a>\
</li>\
' "$USER_MENU"
        else
            echo "    Warning: mysql_manager anchor not found. You must add the user menu manually."
        fi
        echo "    Done."
    fi
else
    echo "    Warning: $USER_MENU not found. You must add the menu manually."
fi

# Check if .NET is installed
echo "[*] Checking .NET runtime..."
if command -v dotnet &> /dev/null; then
    DOTNET_VER=$(dotnet --version 2>/dev/null | head -n1)
    echo "    .NET found: $DOTNET_VER"
else
    echo "    WARNING: dotnet command not found. Install .NET runtime for plugin to work."
    echo "    See: https://learn.microsoft.com/en-us/dotnet/core/install/linux"
fi

echo ""
echo "========================================"
echo "  Installation Complete!"
echo "========================================"
echo ""
echo "Admin Panel (port 2030):"
echo "  Dashboard     -> index.php?module=DotNetManager_Dashboard"
echo "  Manage Apps   -> index.php?module=DotNetManager_Apps"
echo "  Create App    -> index.php?module=DotNetManager_Edit"
echo "  Runtimes      -> index.php?module=DotNetManager_Runtimes"
echo ""
echo "User Panel (port 2031):"
echo "  My Apps       -> ?module=DotNetManager"
echo ""
echo "To enable the menu for users, add 'dotnetmanager' to the user's"
echo "right menu array (rmenu) in CWP user settings, or set swmenu=1."
echo ""
echo "Database: $CONF_DIR/apps.db"
echo "Services: $SERVICE_DIR/"
echo "Daemon:   $DAEMON_DIR/"
echo ""
