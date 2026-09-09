import json
import os
import re
import ssl
import time
import traceback
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.error import HTTPError
from urllib.parse import urlsplit, urlunsplit
from urllib.request import Request, build_opener, ProxyHandler, HTTPSHandler


HOST = "127.0.0.1"
PORT = int(os.getenv("BIOLOGY_LLM_RELAY_PORT", "18087"))
DEFAULT_TIMEOUT = 90
SSL_VERIFY = os.getenv("DASHSCOPE_SSL_VERIFY_BIOLOGY", os.getenv("DASHSCOPE_SSL_VERIFY", "0")).lower() in {
    "1", "true", "yes", "on"
}

PROVIDERS = {
    "qwen": {
        "url": os.getenv(
            "DASHSCOPE_API_URL_BIOLOGY",
            os.getenv("DASHSCOPE_API_URL", "https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions"),
        ),
        "model": os.getenv(
            "DASHSCOPE_MODEL_BIOLOGY",
            os.getenv("DASHSCOPE_MODEL", "qwen3.5-plus"),
        ),
        "key": os.getenv(
            "DASHSCOPE_API_KEY_BIOLOGY",
            os.getenv("DASHSCOPE_API_KEY", ""),
        ),
    },
    "deepseek": {
        "url": os.getenv("DEEPSEEK_API_URL", "https://api.deepseek.com/v1/chat/completions"),
        "model": os.getenv("DEEPSEEK_MODEL", "deepseek-chat"),
        "key": os.getenv("DEEPSEEK_API_KEY", ""),
    },
}


def env_value_is_false(value):
    return str(value or "").strip().lower() in {"0", "false", "no", "off"}


def explicit_proxy_config():
    proxies = {}
    https_proxy = os.getenv("BIOLOGY_LLM_RELAY_HTTPS_PROXY")
    http_proxy = os.getenv("BIOLOGY_LLM_RELAY_HTTP_PROXY")
    if https_proxy:
        proxies["https"] = https_proxy
    if http_proxy:
        proxies["http"] = http_proxy
    return proxies


def redact_proxy_url(url):
    if not url:
        return url
    try:
        parsed = urlsplit(url)
    except ValueError:
        return "[REDACTED]"
    if "@" not in parsed.netloc:
        return url
    host = parsed.hostname or ""
    if parsed.port:
        host = f"{host}:{parsed.port}"
    return urlunsplit((parsed.scheme, f"[REDACTED]@{host}", parsed.path, parsed.query, parsed.fragment))


def proxy_mode():
    explicit = explicit_proxy_config()
    if explicit:
        return {
            "mode": "explicit",
            "bypassed": False,
            "proxies": explicit,
        }
    bypass_value = os.getenv("BIOLOGY_LLM_RELAY_BYPASS_PROXY")
    if env_value_is_false(bypass_value):
        return {
            "mode": "system",
            "bypassed": False,
            "proxies": None,
        }
    return {
        "mode": "bypass",
        "bypassed": True,
        "proxies": {},
    }


def public_proxy_status():
    mode = proxy_mode()
    proxies = mode.get("proxies") or {}
    status = {
        "mode": mode["mode"],
        "bypassed": mode["bypassed"],
    }
    if mode["mode"] == "explicit":
        if "https" in proxies:
            status["https_proxy"] = redact_proxy_url(proxies["https"])
        if "http" in proxies:
            status["http_proxy"] = redact_proxy_url(proxies["http"])
    return status


def build_provider_opener(url: str):
    mode = proxy_mode()
    if mode["mode"] == "system":
        handlers = [ProxyHandler()]
    else:
        handlers = [ProxyHandler(mode["proxies"])]
    if url.startswith("https://"):
        context = ssl.create_default_context()
        if not SSL_VERIFY:
            context.check_hostname = False
            context.verify_mode = ssl.CERT_NONE
        handlers.append(HTTPSHandler(context=context))
    return build_opener(*handlers)


def relay_timeout(incoming):
    timeout = incoming.get("timeout")
    if timeout is None:
        timeout = os.getenv("BIOLOGY_LLM_RELAY_TIMEOUT", DEFAULT_TIMEOUT)
    try:
        timeout = float(timeout)
    except (TypeError, ValueError):
        return DEFAULT_TIMEOUT
    if timeout <= 0:
        return DEFAULT_TIMEOUT
    if timeout.is_integer():
        return int(timeout)
    return timeout


def read_http_error_body(exc):
    if exc.fp is None:
        return ""
    try:
        raw = exc.fp.read()
    except Exception:
        return ""
    if isinstance(raw, bytes):
        return raw.decode("utf-8", errors="replace")
    return str(raw)


def redact_secrets(text):
    if not text:
        return text

    redacted = re.sub(
        r"(?i)\bBearer\s+[A-Za-z0-9._~+/=-]+",
        "Bearer [REDACTED]",
        text,
    )
    redacted = re.sub(
        r"(?i)\bsk-[A-Za-z0-9][A-Za-z0-9._-]*",
        "sk-[REDACTED]",
        redacted,
    )
    redacted = re.sub(
        r"(?i)((?:\"|')?(?:api[_-]?key|access[_-]?token|authorization)(?:\"|')?\s*[:=]\s*(?:\"|'))[^\"']*((?:\"|'))",
        r"\1[REDACTED]\2",
        redacted,
    )
    redacted = re.sub(
        r"(?i)(\b(?:api[_-]?key|access[_-]?token|authorization)\b\s*[:=]\s*)[^\s,;}]+",
        r"\1[REDACTED]",
        redacted,
    )
    return redacted


def upstream_body_payload(body, limit=4000):
    body = redact_secrets(body)
    if len(body) <= limit:
        return {"upstream_body": body}
    return {
        "upstream_body_summary": body[:limit],
        "upstream_body_truncated": True,
        "upstream_body_length": len(body),
    }


class RelayHandler(BaseHTTPRequestHandler):
    def _json(self, status, payload):
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        try:
            self.send_response(status)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        except (BrokenPipeError, ConnectionAbortedError, ConnectionResetError) as exc:
            print(f"relay client disconnected while writing response: {type(exc).__name__}", flush=True)

    def do_GET(self):
        if self.path != "/health":
            self._json(404, {"ok": False, "error": "Not found"})
            return
        self._json(200, {
            "ok": True,
            "service": "biology-llm-relay",
            "default_provider": "qwen",
            "providers": {
                name: {
                    "url": cfg["url"],
                    "model": cfg["model"],
                    "key_present": bool(cfg["key"]),
                }
                for name, cfg in PROVIDERS.items()
            },
            "ssl_verify": SSL_VERIFY,
            "proxy_bypassed": public_proxy_status()["bypassed"],
            "proxy": public_proxy_status(),
        })

    def do_POST(self):
        if self.path != "/chat":
            self._json(404, {"ok": False, "error": "Not found"})
            return

        started = time.monotonic()
        provider = "unknown"
        model = "unknown"
        try:
            length = int(self.headers.get("Content-Length", "0"))
            raw = self.rfile.read(length)
            incoming = json.loads(raw.decode("utf-8") or "{}")
            messages = incoming.get("messages") or []
            temperature = incoming.get("temperature", 0.2)
            provider = str(incoming.get("provider") or incoming.get("model_provider") or "qwen").strip().lower()
            if provider not in PROVIDERS:
                self._json(400, {"ok": False, "error": f"Unsupported provider: {provider}"})
                return
            provider_config = PROVIDERS[provider]
            model = incoming.get("model") or provider_config["model"]
            enable_thinking = incoming.get("enable_thinking", False)
            timeout = relay_timeout(incoming)

            if not provider_config["key"]:
                self._json(500, {"ok": False, "error": f"{provider} API key is missing"})
                return

            payload = json.dumps({
                "model": model,
                "messages": messages,
                "temperature": temperature,
                "enable_thinking": enable_thinking,
            }, ensure_ascii=False).encode("utf-8")

            req = Request(
                provider_config["url"],
                data=payload,
                headers={
                    "Content-Type": "application/json",
                    "Authorization": f"Bearer {provider_config['key']}",
                    "Connection": "close",
                    "Accept": "application/json",
                },
                method="POST",
            )

            opener = build_provider_opener(provider_config["url"])
            with opener.open(req, timeout=timeout) as resp:
                content = resp.read().decode("utf-8")
            decoded = json.loads(content)
            self._json(200, {"ok": True, "response": decoded})
        except HTTPError as exc:
            duration = time.monotonic() - started
            error_type = type(exc).__name__
            body = read_http_error_body(exc)
            print(
                f"relay upstream error provider={provider} model={model} "
                f"duration={duration:.3f}s error_type={error_type}",
                flush=True,
            )
            payload = {
                "ok": False,
                "error": str(exc),
                "error_type": error_type,
                "upstream_status": exc.code,
            }
            payload.update(upstream_body_payload(body))
            self._json(502, payload)
        except Exception as exc:
            duration = time.monotonic() - started
            error_type = type(exc).__name__
            print(
                f"relay error provider={provider} model={model} "
                f"duration={duration:.3f}s error_type={error_type}",
                flush=True,
            )
            traceback.print_exc()
            self._json(500, {"ok": False, "error": str(exc), "error_type": error_type})

    def log_message(self, format, *args):
        return


if __name__ == "__main__":
    server = ThreadingHTTPServer((HOST, PORT), RelayHandler)
    print(f"LLM relay listening on http://{HOST}:{PORT}")
    server.serve_forever()
