#!/usr/bin/env python3
"""
DotNetManager Socket Daemon
Runs as root, listens on Unix socket for user-panel requests.
Uses SO_PEERCRED to verify caller identity.
"""

import os
import re
import sys
import json
import socket
import struct
import sqlite3
import pwd
import subprocess
import time

SOCKET_PATH = '/usr/local/cwpsrv/var/services/user_files/modules/dotnetmanager/control.sock'
DB_PATH = '/usr/local/cwp/.conf/dotnetmanager/apps.db'


def validate_app_name(name):
    """Allow ASCII alphanumeric, underscore, hyphen only."""
    if not name:
        return False
    return bool(re.match(r'^[a-zA-Z0-9_-]+$', name))


def get_peer_cred(conn):
    """Return (pid, uid, gid) for the connected peer via SO_PEERCRED."""
    cred = conn.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, struct.calcsize('3i'))
    return struct.unpack('3i', cred)


def get_app_owner(app_name):
    """Query SQLite for the app's owner username."""
    try:
        conn = sqlite3.connect(DB_PATH)
        conn.row_factory = sqlite3.Row
        cur = conn.cursor()
        cur.execute("SELECT username FROM applications WHERE name = ?", (app_name,))
        row = cur.fetchone()
        conn.close()
        return row['username'] if row else None
    except Exception as e:
        sys.stderr.write(f"DB error: {e}\n")
        return None


def run_systemctl(action, service):
    cmd = ["/usr/bin/systemctl", action, service]
    try:
        result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30)
        output = result.stdout.decode("utf-8", errors="replace") + result.stderr.decode("utf-8", errors="replace")
        return {"success": True, "output": output}
    except Exception as e:
        return {"success": False, "message": str(e)}


def get_journal(service, lines):
    cmd = ["/usr/bin/journalctl", "-u", service, "--no-pager", "-n", str(lines)]
    try:
        result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30)
        output = result.stdout.decode("utf-8", errors="replace") + result.stderr.decode("utf-8", errors="replace")
        return {"success": True, "output": output}
    except Exception as e:
        return {"success": False, "message": str(e)}


def get_status(service):
    cmd = ["/usr/bin/systemctl", "show", service, "--property=ActiveState,SubState,MainPID"]
    try:
        result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30)
        out = result.stdout.decode("utf-8", errors="replace")
        active = "unknown"
        sub = "unknown"
        pid = 0
        for line in out.splitlines():
            if line.startswith("ActiveState="):
                active = line.split("=", 1)[1]
            elif line.startswith("SubState="):
                sub = line.split("=", 1)[1]
            elif line.startswith("MainPID="):
                pid = int(line.split("=", 1)[1] or 0)
        return {"success": True, "active": active, "sub": sub, "pid": pid}
    except Exception as e:
        return {"success": False, "message": str(e)}


def handle_client(conn):
    try:
        pid, uid, gid = get_peer_cred(conn)
        try:
            caller = pwd.getpwuid(uid).pw_name
        except KeyError:
            caller = ""

        data = b""
        while b"\n" not in data:
            chunk = conn.recv(4096)
            if not chunk:
                break
            data += chunk

        try:
            req = json.loads(data.decode("utf-8").strip())
        except (json.JSONDecodeError, UnicodeDecodeError):
            conn.sendall(json.dumps({"success": False, "message": "Invalid JSON"}).encode() + b"\n")
            return

        action = req.get("action", "")
        app_name = req.get("app", "")

        if not validate_app_name(app_name):
            conn.sendall(json.dumps({"success": False, "message": "Invalid app name"}).encode() + b"\n")
            return

        owner = get_app_owner(app_name)
        if owner is None or owner != caller:
            conn.sendall(json.dumps({"success": False, "message": "Access denied"}).encode() + b"\n")
            return

        service_name = app_name + ".service"

        if action in ("start", "stop", "restart"):
            result = run_systemctl(action, service_name)
            if result.get("success"):
                time.sleep(0.3)
                result = get_status(service_name)
        elif action == "status":
            result = get_status(service_name)
        elif action == "show":
            result = run_systemctl("show", service_name)
        elif action == "logs":
            lines = min(500, max(10, int(req.get("lines", 50))))
            result = get_journal(service_name, lines)
        else:
            result = {"success": False, "message": "Unknown action"}

        conn.sendall(json.dumps(result).encode() + b"\n")
    except Exception as e:
        try:
            conn.sendall(json.dumps({"success": False, "message": str(e)}).encode() + b"\n")
        except Exception:
            pass
    finally:
        conn.close()


def main():
    if os.path.exists(SOCKET_PATH):
        os.unlink(SOCKET_PATH)

    # Ensure socket directory has sticky bit (prevents non-owners from
    # deleting/renaming the socket file — mitigates symlink attacks).
    socket_dir = os.path.dirname(SOCKET_PATH)
    if os.path.isdir(socket_dir):
        current_mode = os.stat(socket_dir).st_mode
        if not (current_mode & 0o1000):
            os.chmod(socket_dir, current_mode | 0o1000)

    s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    s.bind(SOCKET_PATH)
    os.chmod(SOCKET_PATH, 0o777)
    s.listen(5)

    sys.stderr.write(f"Daemon listening on {SOCKET_PATH}\n")
    sys.stderr.flush()

    try:
        while True:
            conn, _ = s.accept()
            handle_client(conn)
    except KeyboardInterrupt:
        pass
    finally:
        s.close()
        if os.path.exists(SOCKET_PATH):
            os.unlink(SOCKET_PATH)


if __name__ == "__main__":
    main()
