import io
import json
import os
import sys
import unittest
from contextlib import redirect_stderr, redirect_stdout
from email.message import Message
from pathlib import Path
from urllib.error import HTTPError


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from scripts import llm_relay


class FakeHandler(llm_relay.RelayHandler):
    def __init__(self, body):
        self.path = "/chat"
        self.headers = Message()
        self.headers["Content-Length"] = str(len(body))
        self.rfile = io.BytesIO(body)
        self.wfile = io.BytesIO()
        self.request_version = "HTTP/1.1"
        self.command = "POST"
        self.requestline = "POST /chat HTTP/1.1"
        self.client_address = ("127.0.0.1", 12345)
        self.server = object()


class RelayErrorTest(unittest.TestCase):
    def setUp(self):
        self.original_providers = llm_relay.PROVIDERS.copy()
        self.original_build_provider_opener = llm_relay.build_provider_opener
        self.original_env_timeout = os.environ.get("BIOLOGY_LLM_RELAY_TIMEOUT")
        self.proxy_env = {
            name: os.environ.get(name)
            for name in (
                "BIOLOGY_LLM_RELAY_BYPASS_PROXY",
                "BIOLOGY_LLM_RELAY_HTTPS_PROXY",
                "BIOLOGY_LLM_RELAY_HTTP_PROXY",
            )
        }
        for name in self.proxy_env:
            os.environ.pop(name, None)
        llm_relay.PROVIDERS["qwen"] = {
            "url": "https://upstream.example/chat",
            "model": "test-model",
            "key": "test-key",
        }

    def tearDown(self):
        llm_relay.PROVIDERS.clear()
        llm_relay.PROVIDERS.update(self.original_providers)
        llm_relay.build_provider_opener = self.original_build_provider_opener
        if self.original_env_timeout is None:
            os.environ.pop("BIOLOGY_LLM_RELAY_TIMEOUT", None)
        else:
            os.environ["BIOLOGY_LLM_RELAY_TIMEOUT"] = self.original_env_timeout
        for name, value in self.proxy_env.items():
            if value is None:
                os.environ.pop(name, None)
            else:
                os.environ[name] = value

    def response_payload(self, handler):
        raw = handler.wfile.getvalue().decode("utf-8")
        return json.loads(raw.split("\r\n\r\n", 1)[1])

    def test_http_error_includes_upstream_status_body_error_type_and_uses_request_timeout(self):
        class FailingOpener:
            def open(self, req, timeout):
                self.timeout = timeout
                raise HTTPError(
                    req.full_url,
                    429,
                    "Too Many Requests",
                    hdrs=None,
                    fp=io.BytesIO(b'{"error":"rate limited"}'),
                )

        opener = FailingOpener()
        llm_relay.build_provider_opener = lambda url: opener
        body = json.dumps({
            "messages": [{"role": "user", "content": "hi"}],
            "timeout": 12,
        }).encode("utf-8")
        handler = FakeHandler(body)
        stdout = io.StringIO()

        with redirect_stdout(stdout):
            handler.do_POST()

        payload = self.response_payload(handler)
        self.assertEqual(502, int(handler.wfile.getvalue().split(b" ")[1]))
        self.assertEqual(12, opener.timeout)
        self.assertFalse(payload["ok"])
        self.assertEqual("HTTPError", payload["error_type"])
        self.assertEqual(429, payload["upstream_status"])
        self.assertEqual('{"error":"rate limited"}', payload["upstream_body"])
        log = stdout.getvalue()
        self.assertIn("provider=qwen", log)
        self.assertIn("model=test-model", log)
        self.assertIn("duration=", log)
        self.assertIn("error_type=HTTPError", log)

    def test_http_error_redacts_secrets_from_upstream_body(self):
        class FailingOpener:
            def open(self, req, timeout):
                raise HTTPError(
                    req.full_url,
                    401,
                    "Unauthorized",
                    hdrs=None,
                    fp=io.BytesIO(
                        b'{"message":"Bearer abc.def.ghi failed",'
                        b'"api_key":"sk-live-secret",'
                        b'"access_token":"token-value",'
                        b'"authorization":"Basic private-value"}'
                    ),
                )

        llm_relay.build_provider_opener = lambda url: FailingOpener()
        body = json.dumps({
            "messages": [{"role": "user", "content": "hi"}],
        }).encode("utf-8")
        handler = FakeHandler(body)

        handler.do_POST()

        payload = self.response_payload(handler)
        upstream_body = payload["upstream_body"]
        self.assertIn("[REDACTED]", upstream_body)
        self.assertNotIn("abc.def.ghi", upstream_body)
        self.assertNotIn("sk-live-secret", upstream_body)
        self.assertNotIn("token-value", upstream_body)
        self.assertNotIn("private-value", upstream_body)

    def test_timeout_defaults_to_environment(self):
        class SuccessfulResponse:
            def __enter__(self):
                return self

            def __exit__(self, exc_type, exc, tb):
                return False

            def read(self):
                return b'{"choices":[]}'

        class SuccessfulOpener:
            def open(self, req, timeout):
                self.timeout = timeout
                return SuccessfulResponse()

        os.environ["BIOLOGY_LLM_RELAY_TIMEOUT"] = "7"
        opener = SuccessfulOpener()
        llm_relay.build_provider_opener = lambda url: opener
        body = json.dumps({
            "messages": [{"role": "user", "content": "hi"}],
        }).encode("utf-8")
        handler = FakeHandler(body)

        handler.do_POST()

        self.assertEqual(7, opener.timeout)
        payload = self.response_payload(handler)
        self.assertTrue(payload["ok"])

    def test_regular_exception_includes_error_type_and_traceback(self):
        class FailingOpener:
            def open(self, req, timeout):
                raise RuntimeError("boom")

        llm_relay.build_provider_opener = lambda url: FailingOpener()
        body = json.dumps({
            "messages": [{"role": "user", "content": "hi"}],
        }).encode("utf-8")
        handler = FakeHandler(body)
        stderr = io.StringIO()
        stdout = io.StringIO()

        with redirect_stdout(stdout), redirect_stderr(stderr):
            handler.do_POST()

        payload = self.response_payload(handler)
        self.assertEqual("RuntimeError", payload["error_type"])
        log = stdout.getvalue()
        self.assertIn("provider=qwen", log)
        self.assertIn("model=test-model", log)
        self.assertIn("duration=", log)
        self.assertIn("error_type=RuntimeError", log)
        self.assertIn("RuntimeError: boom", stderr.getvalue())

    def test_json_quietly_records_client_disconnect(self):
        class BrokenWriter(io.BytesIO):
            def write(self, data):
                raise BrokenPipeError("client left")

        handler = FakeHandler(b"{}")
        handler.wfile = BrokenWriter()
        stdout = io.StringIO()

        with redirect_stdout(stdout):
            handler._json(200, {"ok": True})

        self.assertIn("client disconnected", stdout.getvalue())

    def test_build_provider_opener_bypasses_proxy_by_default(self):
        calls = []
        original_proxy_handler = llm_relay.ProxyHandler
        original_build_opener = llm_relay.build_opener

        class FakeProxyHandler:
            def __init__(self, proxies=None):
                calls.append(proxies)

        try:
            llm_relay.ProxyHandler = FakeProxyHandler
            llm_relay.build_opener = lambda *handlers: handlers

            llm_relay.build_provider_opener("http://upstream.example/chat")
        finally:
            llm_relay.ProxyHandler = original_proxy_handler
            llm_relay.build_opener = original_build_opener

        self.assertEqual([{}], calls)

    def test_build_provider_opener_uses_system_proxy_when_bypass_disabled(self):
        calls = []
        original_proxy_handler = llm_relay.ProxyHandler
        original_build_opener = llm_relay.build_opener

        class FakeProxyHandler:
            def __init__(self, proxies=None):
                calls.append(proxies)

        try:
            os.environ["BIOLOGY_LLM_RELAY_BYPASS_PROXY"] = "false"
            llm_relay.ProxyHandler = FakeProxyHandler
            llm_relay.build_opener = lambda *handlers: handlers

            llm_relay.build_provider_opener("http://upstream.example/chat")
        finally:
            llm_relay.ProxyHandler = original_proxy_handler
            llm_relay.build_opener = original_build_opener

        self.assertEqual([None], calls)

    def test_health_reports_redacted_explicit_proxy_mode(self):
        os.environ["BIOLOGY_LLM_RELAY_HTTPS_PROXY"] = "http://user:secret@proxy.local:8080"
        handler = FakeHandler(b"")
        handler.path = "/health"

        handler.do_GET()

        payload = self.response_payload(handler)
        proxy = payload["proxy"]
        self.assertEqual("explicit", proxy["mode"])
        self.assertFalse(proxy["bypassed"])
        self.assertEqual("http://[REDACTED]@proxy.local:8080", proxy["https_proxy"])
        self.assertNotIn("secret", json.dumps(proxy))


if __name__ == "__main__":
    unittest.main()
