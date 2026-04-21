#!/bin/bash
# DotNetManager CWP Plugin Uninstallation Script
# Run as root

set -e

ADMIN_MODULES="/usr/local/cwpsrv/htdocs/resources/admin/modules"
USER_MODULES="/usr/local/cwpsrv/var/services/user_files/modules"
USER_THEME="/usr/local/cwpsrv/var/services/users/cwp_theme/original"
USER_LANG="/usr/local/cwpsrv/var/services/users/cwp_lang/en"
ADMIN_INCLUDE="/usr/local/cwpsrv/htdocs/resources/admin/include"
CONF_DIR="/usr/local/cwp/.conf/dotnetmanager"
SERVICE_DIR="/etc/systemd/system/DotNetManager"
DAEMON_DIR="/usr/local/cwpsrv/var/services/user_files/modules/dotnetmanager"

echo "========================================"
echo "  DotNetManager CWP Plugin Uninstaller"
echo "========================================"

if [ "$EUID" -ne 0 ]; then
    echo "Error: Please run as root"
    exit 1
fi

read -p "Are you sure you want to remove DotNetManager? [y/N] " confirm
if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
    echo "Aborted."
    exit 0
fi

echo "[*] Stopping socket daemon..."
systemctl stop dotnetmanager-socket.service 2>/dev/null || true
systemctl disable dotnetmanager-socket.service 2>/dev/null || true

echo "[*] Stopping all DotNetManager services..."
for svc in "$SERVICE_DIR"/*.service; do
    [ -e "$svc" ] || continue
    svcName=$(basename "$svc")
    systemctl stop "$svcName" 2>/dev/null || true
    systemctl disable "$svcName" 2>/dev/null || true
done

echo "[*] Removing admin modules..."
rm -f "$ADMIN_MODULES"/DotNetManager_*.php

echo "[*] Removing user module files..."
rm -f "$USER_MODULES"/DotNetManager.php
rm -f "$USER_MODULES"/DotNetManagerDB.php
rm -f "$USER_MODULES"/DotNetManagerNginx.php
rm -f "$USER_THEME"/mod_DotNetManager.html
rm -f "$USER_LANG"/DotNetManager.ini

echo "[*] Removing daemon and control files..."
rm -rf "$DAEMON_DIR"
rm -f /etc/systemd/system/dotnetmanager-socket.service
rm -f /etc/sudoers.d/dotnetmanager

echo "[*] Removing data directory and templates..."
rm -rf "$CONF_DIR"

echo "[*] Removing service directory..."
rm -rf "$SERVICE_DIR"

echo "[*] Reloading systemd and nginx..."
systemctl daemon-reload 2>/dev/null || true
systemctl reload nginx 2>/dev/null || true

echo ""
echo "[*] NOTE: You must manually remove the menu entries from:"
echo "    - $ADMIN_INCLUDE/3rdparty.php"
echo "    - $USER_THEME/menu_left.html"
echo ""
echo "[*] NOTE: Nginx vhosts generated for .NET apps are NOT automatically"
echo "    removed during uninstall to avoid breaking other services."
echo "    Review /etc/nginx/conf.d/vhosts/ and remove stale configs manually."
echo ""
echo "========================================"
echo "  Uninstallation Complete"
echo "========================================"
