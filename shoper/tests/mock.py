"""Loopback TLS proxy emulating exactly two synthetic hosts. No outbound connections."""
import hashlib
import hmac
import json
import os
import ssl
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse

root = Path(sys.argv[1])
tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
tls.load_cert_chain(root / "cert.pem", root / "key.pem")


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_CONNECT(self):
        if self.path not in ("shoper.invalid:443", "receiver.invalid:443"):
            return self.send_error(403)
        self.target = self.path.split(":")[0]
        self.send_response_only(200)
        self.end_headers()
        self.connection = tls.wrap_socket(self.connection, server_side=True)
        self.rfile = self.connection.makefile("rb")
        self.wfile = self.connection.makefile("wb")
        self.handle_one_request()

    def reply(self, value, code=200):
        raw = json.dumps(value).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)
        self.wfile.flush()

    def do_GET(self):
        if self.target != "shoper.invalid" or self.headers.get("Authorization") != "Bearer fixture-token":
            return self.reply({}, 401)
        f = json.loads((root / "orders.json").read_text())
        p = urlparse(self.path)
        query = parse_qs(p.query)
        if p.path.startswith("/webapi/rest/orders/"):
            order = f["orders"].get(p.path.split("/")[-1])
            return self.reply(order or {}, 200 if order else 404)
        if p.path == "/webapi/rest/orders":
            orders = sorted(f["orders"].values(), key=lambda r: int(r["order_id"]))
            if query["order"] == ["-order_id"]:
                orders.reverse()
            else:
                after = int(query["filters[order_id][>]"][0])
                orders = [r for r in orders if int(r["order_id"]) > after]
            rows = orders
        elif p.path == "/webapi/rest/order-products":
            rows = f["lines"][query["filters[order_id][=]"][0]]
        else:
            return self.reply({}, 404)
        limit = int(query["limit"][0])
        page = int(query.get("page", ["1"])[0])
        return self.reply(dict(count=len(rows), pages=(len(rows) + limit - 1) // limit,
                               page=page, list=rows[(page - 1) * limit:page * limit]))

    def do_POST(self):
        if self.target != "receiver.invalid" or self.path != "/ingest":
            return self.reply({}, 404)
        raw = self.rfile.read(int(self.headers["content-length"]))
        timestamp = self.headers["X-LC-Timestamp"].encode()
        signature = hmac.new(os.environ["LC_SIGNING_SECRET"].encode(), timestamp + b"." + raw, hashlib.sha256).hexdigest()
        if self.headers["X-LC-Store"] != "fixture" or not hmac.compare_digest(signature, self.headers["X-LC-Signature"]):
            return self.reply({}, 401)
        with (root / "events.jsonl").open("ab") as out:
            out.write(raw + b"\n")
        return self.reply({"status": "accepted"})


server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
(root / "port").write_text(str(server.server_port))
server.serve_forever()
