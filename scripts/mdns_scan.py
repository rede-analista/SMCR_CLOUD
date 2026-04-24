#!/usr/bin/env python3
"""
Descobre serviços _http._tcp via mDNS e imprime no formato parseable do avahi-browse.
Saída: =;<iface>;IPv4;<name>;<type>;<domain>;<hostname>;<ip>;<port>;<txt>
"""
import sys
import time
from zeroconf import ServiceBrowser, Zeroconf

results = []

class Listener:
    def add_service(self, zeroconf, type_, name):
        try:
            info = zeroconf.get_service_info(type_, name, timeout=2000)
            if not info:
                return
            addresses = info.parsed_addresses()
            if not addresses:
                return
            ip = addresses[0]
            port = info.port
            hostname = (info.server or name).rstrip('.')
            txt_parts = []
            for k, v in info.properties.items():
                k = k.decode('utf-8', errors='replace') if isinstance(k, bytes) else str(k)
                v = v.decode('utf-8', errors='replace') if isinstance(v, bytes) else ('' if v is None else str(v))
                txt_parts.append(f'"{k}={v}"')
            txt = ' '.join(txt_parts)
            results.append(f'=;eth0;IPv4;{name};_http._tcp;local;{hostname};{ip};{port};{txt}')
        except Exception:
            pass

    def remove_service(self, *_): pass
    def update_service(self, *_): pass

zc = Zeroconf()
ServiceBrowser(zc, "_http._tcp.local.", Listener())
time.sleep(3)
zc.close()

for line in results:
    print(line)
