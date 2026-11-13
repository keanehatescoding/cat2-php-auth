#!/usr/bin/env python3
"""Local stand-in for a Lambda function URL.

Serves a feedback form on http://127.0.0.1:8090/ and forwards POST /feedback
to the Lambda Runtime Interface Emulator running the cat2-feedback-lambda
image on http://127.0.0.1:9000. This simulates a function URL; it is not AWS.
"""
import html
import json
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

EMULATOR = "http://127.0.0.1:9000/2015-03-31/functions/function/invocations"
LISTEN = ("127.0.0.1", 8090)

FORM = """<!doctype html>
<meta charset="utf-8">
<title>Feedback (simulated Lambda)</title>
<h1>Feedback</h1>
<form method="post" action="/feedback">
  <p><label>Name <input name="name" required></label></p>
  <p><label>Message <textarea name="message" required></textarea></label></p>
  <p><button type="submit">Send</button></p>
</form>
"""


class Handler(BaseHTTPRequestHandler):
    def _send(self, status, body, ctype="text/html; charset=utf-8"):
        data = body.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        if self.path == "/":
            self._send(200, FORM)
        else:
            self._send(404, "not found", "text/plain; charset=utf-8")

    def do_POST(self):
        if self.path != "/feedback":
            self._send(404, "not found", "text/plain; charset=utf-8")
            return

        length = int(self.headers.get("Content-Length", "0"))
        raw = self.rfile.read(length).decode("utf-8", errors="replace")

        # Shape of a Lambda function URL event (payload format 2.0), trimmed.
        event = {
            "version": "2.0",
            "requestContext": {"http": {"method": "POST", "path": "/feedback"}},
            "headers": {"content-type": "application/x-www-form-urlencoded"},
            "body": raw,
            "isBase64Encoded": False,
        }
        req = urllib.request.Request(
            EMULATOR,
            data=json.dumps(event).encode("utf-8"),
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                result = json.loads(resp.read().decode("utf-8"))
        except (urllib.error.URLError, TimeoutError, ValueError) as exc:
            self._send(502, f"<p>Function unavailable: {html.escape(str(exc))}</p>")
            return

        status = int(result.get("statusCode", 500))
        body = result.get("body", "")
        ctype = result.get("headers", {}).get("content-type", "application/json")
        self._send(status, body, ctype)

    def log_message(self, fmt, *args):
        # Do not log request bodies: they contain the user's message text.
        print("%s %s" % (self.command, self.path), args[1] if len(args) > 1 else "")


if __name__ == "__main__":
    print(f"Listening on http://{LISTEN[0]}:{LISTEN[1]}/")
    ThreadingHTTPServer(LISTEN, Handler).serve_forever()
