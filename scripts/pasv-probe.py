#!/usr/bin/env python3
"""Probe FTP passive responses (227/229) for upload daemon validation."""

from __future__ import annotations

import argparse
import json
import re
import sys
from io import BytesIO
from ftplib import FTP, FTP_TLS, error_perm


def parse_pasv_ip(response: str) -> str | None:
    match = re.search(r"\(([^)]+)\)", response)
    if not match:
        return None
    body = match.group(1)
    if body.startswith("|"):
        return None
    parts = body.split(",")
    if len(parts) == 6:
        return ".".join(parts[:4])
    return body


def bad_pasv(response: str) -> bool:
    return "0,0,0,0" in response


def probe_plain(host: str, port: int, user: str, password: str, use_epsv: bool) -> dict:
    ftp = FTP()
    ftp.connect(host, port, timeout=15)
    ftp.login(user, password)
    cmd = "EPSV" if use_epsv else "PASV"
    response = ftp.sendcmd(cmd)
    ftp.quit()
    return {"command": cmd, "response": response, "pasv_ip": parse_pasv_ip(response), "bad_pasv": bad_pasv(response)}


def probe_ftps(host: str, port: int, user: str, password: str, use_epsv: bool) -> dict:
    ftp = FTP_TLS()
    ftp.connect(host, port, timeout=15)
    ftp.auth()
    ftp.login(user, password)
    ftp.sendcmd("PBSZ 0")
    ftp.prot_p()
    cmd = "EPSV" if use_epsv else "PASV"
    response = ftp.sendcmd(cmd)
    ftp.quit()
    return {"command": cmd, "response": response, "pasv_ip": parse_pasv_ip(response), "bad_pasv": bad_pasv(response)}


def probe_stor_plain(host: str, port: int, user: str, password: str, remote_name: str, payload: bytes) -> None:
    ftp = FTP()
    ftp.connect(host, port, timeout=15)
    ftp.login(user, password)
    try:
        ftp.delete(remote_name)
    except error_perm:
        pass

    ftp.storbinary(f"STOR {remote_name}", BytesIO(payload))
    ftp.quit()


def probe_stor_ftps(host: str, port: int, user: str, password: str, remote_name: str, payload: bytes) -> None:
    ftp = FTP_TLS()
    ftp.connect(host, port, timeout=15)
    ftp.auth()
    ftp.login(user, password)
    ftp.sendcmd("PBSZ 0")
    ftp.prot_p()
    try:
        ftp.delete(remote_name)
    except error_perm:
        pass

    ftp.storbinary(f"STOR {remote_name}", BytesIO(payload))
    ftp.quit()


MODES = {
    "pasv-plain": ("plain", False),
    "epsv-plain": ("plain", True),
    "pasv-ftps": ("ftps", False),
    "epsv-ftps": ("ftps", True),
}


def main() -> int:
    parser = argparse.ArgumentParser(description="FTP PASV/EPSV probe")
    parser.add_argument("--host", required=True)
    parser.add_argument("--port", type=int, default=2121)
    parser.add_argument("--user", required=True)
    parser.add_argument("--password", required=True)
    parser.add_argument("--mode", choices=sorted(MODES.keys()), required=True)
    parser.add_argument("--family", default="ipv4", choices=["ipv4", "ipv6"])
    parser.add_argument("--stor", metavar="REMOTE_NAME", help="After connect/login, STOR this filename (payload from --stor-data or stdin)")
    parser.add_argument("--stor-data", default="", help="Payload bytes for --stor (default: generated timestamp line)")
    args = parser.parse_args()

    if args.stor:
        payload = args.stor_data.encode("utf-8") if args.stor_data else (
            f"aviationwx validate upload\n".encode("utf-8")
        )
        kind, _epsv = MODES[args.mode]
        try:
            if kind == "plain":
                probe_stor_plain(args.host, args.port, args.user, args.password, args.stor, payload)
            else:
                probe_stor_ftps(args.host, args.port, args.user, args.password, args.stor, payload)
        except (error_perm, OSError, TimeoutError) as exc:
            print(json.dumps({"error": str(exc), "mode": args.mode, "stor": args.stor}))
            return 1
        print(json.dumps({"mode": args.mode, "stor": args.stor, "ok": True}))
        return 0

    kind, epsv = MODES[args.mode]
    try:
        if kind == "plain":
            result = probe_plain(args.host, args.port, args.user, args.password, epsv)
        else:
            result = probe_ftps(args.host, args.port, args.user, args.password, epsv)
    except (error_perm, OSError, TimeoutError) as exc:
        print(json.dumps({"error": str(exc), "mode": args.mode, "bad_pasv": False}))
        return 1

    result["mode"] = args.mode
    result["family"] = args.family
    print(json.dumps(result))
    return 2 if result.get("bad_pasv") else 0


if __name__ == "__main__":
    sys.exit(main())
