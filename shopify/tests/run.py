"""Run the isolated Shopify contract test with an ephemeral TLS endpoint and synthetic orders."""
import hashlib
import hmac
import json
import os
from pathlib import Path
import ssl
import subprocess
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs

HERE = Path(__file__).resolve().parent


class ShopifyFixture(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def reply(self, status, obj):
        body = json.dumps(obj).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        body = self.rfile.read(int(self.headers['Content-Length']))
        if self.path == '/admin/oauth/access_token':
            form = parse_qs(body.decode())
            if form != {'grant_type': ['client_credentials'], 'client_id': ['test-client'], 'client_secret': ['test-secret']}:
                return self.reply(401, {})
            self.server.tokens += 1
            return self.reply(200, {'access_token': 'fixture-token', 'expires_in': 86399})
        if self.path == '/ingest':
            ts = self.headers['X-LC-Timestamp']
            sig = hmac.new(b's'*32, ts.encode()+b'.'+body, hashlib.sha256).hexdigest()
            if self.headers['X-LC-Store'] != 'fixture' or abs(time.time()-int(ts)) > 300 or not hmac.compare_digest(self.headers['X-LC-Signature'], sig):
                return self.reply(401, {})
            with (self.server.directory / 'events').open('ab') as log:
                log.write(body+b'\n')
            return self.reply(200, {'status': 'accepted'})
        if self.path != '/admin/api/2026-07/graphql.json' or self.headers.get('X-Shopify-Access-Token') != 'fixture-token':
            return self.reply(401, {})
        payload = json.loads(body)
        query = payload['query']
        args = payload['variables']
        fixture = json.loads((self.server.directory / 'state').read_text())
        if query.startswith('query Discover'):
            assert 'sortKey: UPDATED_AT' in query and 'created_at:>=' in args['query'] and 'updated_at:<' in args['query']
            if args['after'] is None:
                page = {'nodes': [{'id': 'gid://shopify/Order/27'}], 'pageInfo': {'hasNextPage': True, 'endCursor': 'cursor1'}}
            else:
                assert args['after'] == 'cursor1'
                if fixture.get('failPage'):
                    return self.reply(429, {})
                page = {'nodes': [], 'pageInfo': {'hasNextPage': False, 'endCursor': None}}
            return self.reply(200, {'data': {'orders': page}})
        if fixture.get('error'):
            return self.reply(200, {'errors': [{'message': 'fixture failure'}], 'data': {'order': None}})
        if query.startswith('query ReadOrder'):
            assert args['id'] == 'gid://shopify/Order/27' and 'currentQuantity' in query
            return self.reply(200, {'data': {'order': fixture['order']}})
        if query.startswith('query ReadRefund'):
            assert args['id'] == fixture['refund']['id']
            return self.reply(200, {'data': {'node': fixture['refund']}})
        if query.startswith('query CheckOrder'):
            return self.reply(200, {'data': {'order': {'updatedAt': fixture.get('raced', fixture['order']['updatedAt'])}}})
        return self.reply(400, {})


with tempfile.TemporaryDirectory() as tmp:
    directory = Path(tmp)
    cert, key = directory / 'fixture.pem', directory / 'key.pem'
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=localhost',
                    '-addext', 'subjectAltName=DNS:localhost,IP:127.0.0.1', '-keyout', str(key), '-out', str(cert)], check=True, stderr=subprocess.DEVNULL)
    with ThreadingHTTPServer(('127.0.0.1', 0), ShopifyFixture) as server:
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.load_cert_chain(cert, key)
        server.socket = ctx.wrap_socket(server.socket, server_side=True)
        server.directory, server.tokens = directory, 0
        threading.Thread(target=server.serve_forever, daemon=True).start()
        env = {**os.environ, 'FIXTURE_DIR': str(directory), 'FIXTURE_URL': 'https://127.0.0.1:'+str(server.server_port)}
        try:
            subprocess.run(['php', '-d', 'curl.cainfo='+str(cert), '-d', 'openssl.cafile='+str(cert), str(HERE / 'contract.php')], env=env, check=True)
            assert server.tokens == 1
        finally:
            server.shutdown()
