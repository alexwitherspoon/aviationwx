#!/usr/bin/env python3
"""Verify FTP sessions are jailed to the authenticated user's inbox (SFTP-style containment)."""

from __future__ import annotations

import argparse
import json
import os
import sys
from io import BytesIO
from ftplib import FTP, error_perm


def normalize_host(host: str) -> str:
    host = host.strip()
    if host.startswith("[") and host.endswith("]"):
        return host[1:-1]
    return host


def resolve_password(password: str, password_env: str) -> str:
    if password_env:
        return os.environ.get(password_env, "")
    return password


def try_cwd(ftp: FTP, path: str) -> dict:
    try:
        ftp.cwd(path)
        return {"path": path, "blocked": False, "pwd": ftp.pwd()}
    except error_perm as exc:
        return {"path": path, "blocked": True, "error": str(exc)}
    except OSError as exc:
        return {"path": path, "blocked": True, "error": str(exc)}


def try_stor(ftp: FTP, remote_name: str, payload: bytes) -> dict:
    try:
        ftp.storbinary(f"STOR {remote_name}", BytesIO(payload))
        return {"remote_name": remote_name, "blocked": False}
    except error_perm as exc:
        return {"remote_name": remote_name, "blocked": True, "error": str(exc)}
    except OSError as exc:
        return {"remote_name": remote_name, "blocked": True, "error": str(exc)}


def probe_isolation(
    host: str,
    port: int,
    user_a: str,
    password_a: str,
    other_home: str,
    other_user: str,
) -> dict:
    host = normalize_host(host)
    ftp = FTP()
    ftp.connect(host, port, timeout=15)
    ftp.login(user_a, password_a)
    login_pwd = ftp.pwd()

    own_upload = try_stor(ftp, "isolation-probe-own.txt", b"own inbox ok\n")

    parent = os.path.dirname(other_home.rstrip("/"))
    traversal_paths = [
        other_home,
        f"{parent}/{other_user}" if parent else other_user,
        f"../{other_user}",
        "/var/www/html/cache/ftp",
        "/var/www/html",
        "/var",
    ]

    cwd_checks = [try_cwd(ftp, path) for path in traversal_paths]

    # Inside a chroot jail, "/" is the inbox root (cameras upload to /). Verify ".." cannot escape.
    parent_escape = try_cwd(ftp, "..")
    root_pwd = try_cwd(ftp, "/")

    stor_in_other = {"remote_name": "isolation-probe-cross-user.txt", "blocked": True, "error": "not attempted"}
    try:
        ftp.cwd(other_home)
        stor_in_other = try_stor(ftp, "isolation-probe-cross-user.txt", b"isolation probe\n")
    except error_perm as exc:
        stor_in_other = {
            "remote_name": "isolation-probe-cross-user.txt",
            "blocked": True,
            "error": str(exc),
        }
    except OSError as exc:
        stor_in_other = {
            "remote_name": "isolation-probe-cross-user.txt",
            "blocked": True,
            "error": str(exc),
        }

    ftp.quit()

    containment_ok = (
        all(check["blocked"] for check in cwd_checks)
        and stor_in_other["blocked"]
        and (parent_escape["blocked"] or parent_escape.get("pwd") == login_pwd)
        and root_pwd.get("pwd") == "/"
    )
    own_ok = not own_upload["blocked"]

    return {
        "ok": containment_ok and own_ok,
        "containment_ok": containment_ok,
        "own_upload_ok": own_ok,
        "login_pwd": login_pwd,
        "cwd_checks": cwd_checks,
        "parent_escape": parent_escape,
        "root_pwd": root_pwd,
        "stor_other_inbox": stor_in_other,
        "stor_own_inbox": own_upload,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="FTP per-user session containment probe")
    parser.add_argument("--host", required=True)
    parser.add_argument("--port", type=int, default=2121)
    parser.add_argument("--user-a", required=True, help="Authenticated camera username")
    parser.add_argument("--user-b", required=True, help="Other camera username (must not be reachable)")
    parser.add_argument("--home-b", required=True, help="Absolute homedir for user-b")
    parser.add_argument("--password", default="")
    parser.add_argument(
        "--password-env",
        default="AVIATIONWX_FTP_PROBE_PASSWORD",
        help="Environment variable holding user-a password",
    )
    parser.add_argument("--json", action="store_true", help="Emit JSON only")
    args = parser.parse_args()

    password = resolve_password(args.password, args.password_env)
    if password == "":
        payload = {"ok": False, "error": "password required via --password-env or --password"}
        print(json.dumps(payload))
        return 1

    try:
        result = probe_isolation(
            args.host,
            args.port,
            args.user_a,
            password,
            args.home_b,
            args.user_b,
        )
    except (error_perm, OSError, TimeoutError) as exc:
        payload = {"ok": False, "error": str(exc)}
        print(json.dumps(payload))
        return 1

    if args.json:
        print(json.dumps(result, indent=2))
    else:
        print(json.dumps(result))
    return 0 if result["ok"] else 2


if __name__ == "__main__":
    sys.exit(main())
