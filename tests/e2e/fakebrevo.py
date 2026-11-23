"""A stand-in for Brevo's API, so the email checks can run without sending anything. It accepts one key, logs each send
to FAKEBREVO_LOG, and refuses a sender that starts with "unverified@" the way Brevo refuses an unverified one."""
import json, os, http.server
LOG = os.environ.get('FAKEBREVO_LOG', '/tmp/fakebrevo.log')
PORT = int(os.environ.get('FAKEBREVO_PORT', '8099'))
class H(http.server.BaseHTTPRequestHandler):
    def _send(self, code, obj):
        b = json.dumps(obj).encode(); self.send_response(code); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(b))); self.end_headers(); self.wfile.write(b)
    def do_GET(self):
        if self.headers.get('api-key') == 'xkeysib-GOODKEY-9aZq': self._send(200, {'email': 'owner@parker-lambert.com', 'companyName': 'Parker-Lambert'})
        else: self._send(401, {'code': 'unauthorized', 'message': 'Key not found'})
    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers.get('Content-Length', 0))) or b'{}')
        open(LOG, 'a').write(json.dumps({'key': self.headers.get('api-key'), 'path': self.path, 'body': body}) + '\n')
        if body.get('sender', {}).get('email', '').startswith('unverified@'): self._send(400, {'code': 'invalid_parameter', 'message': 'Sending has been rejected because the sender you used is not valid.'})
        else: self._send(201, {'messageId': '<test-1@smtp-relay.brevo.com>'})
    def log_message(self, *a): pass
http.server.HTTPServer(('127.0.0.1', PORT), H).serve_forever()
