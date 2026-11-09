#!/usr/bin/env python3
"""SSH ProxyCommand that forces the TCP connection out of a given interface
(bypasses a full-tunnel VPN without touching system routes).
Usage in ssh: -o ProxyCommand='python3 direct_proxy.py %h %p wlp5s0'"""
import os, select, socket, sys
host, port = sys.argv[1], int(sys.argv[2])
dev = sys.argv[3] if len(sys.argv) > 3 else os.environ.get("DIRECT_IF", "wlp5s0")
s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
s.setsockopt(socket.SOL_SOCKET, socket.SO_BINDTODEVICE, dev.encode())
s.settimeout(15); s.connect((host, port)); s.settimeout(None)
inp, out = sys.stdin.buffer.raw, sys.stdout.buffer.raw
while True:
    r, _, _ = select.select([inp, s], [], [])
    if inp in r:
        d = os.read(inp.fileno(), 65536)
        if not d: s.shutdown(socket.SHUT_WR); inp = None
        else: s.sendall(d)
    if s in r:
        d = s.recv(65536)
        if not d: break
        out.write(d); out.flush()
    if inp is None:
        # keep draining the socket until remote closes
        while True:
            d = s.recv(65536)
            if not d: sys.exit(0)
            out.write(d); out.flush()
