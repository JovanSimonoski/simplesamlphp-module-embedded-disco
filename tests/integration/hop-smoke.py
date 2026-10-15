"""Local Docker HOP smoke tests; standard library only. Never use against production.

Start both Compose files, then run: python tests/integration/hop-smoke.py
TLS verification is disabled ONLY for this localhost development fixture.
"""
import base64
import html.parser
import http.cookiejar
import json
import ssl
import urllib.error
import urllib.parse
import urllib.request

RP = "https://localhost:8443/simplesaml/module.php/embeddeddisco/"
HOP = "https://localhost:8447/simplesaml/module.php/embeddeddisco/hop"
ENTITY = "https://host.docker.internal:8443/simplesaml/module.php/embeddeddisco/federation"
OP = "https://host.docker.internal:8444/simplesaml/module.php/oidc"


class Page(html.parser.HTMLParser):
    def __init__(self, body):
        super().__init__()
        self.links, self.forms = [], []
        self.current = None
        self.feed(body)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "a" and "href" in attrs:
            self.links.append(attrs["href"])
        if tag == "form":
            self.current = {"action": attrs.get("action", ""), "fields": {}}
            self.forms.append(self.current)
        if tag in ("input", "button") and self.current is not None and attrs.get("name"):
            self.current["fields"][attrs["name"]] = attrs.get("value", "")

    def handle_endtag(self, tag):
        if tag == "form":
            self.current = None


class Browser:
    def __init__(self):
        self.visited = []
        owner = self

        class Redirects(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, req, fp, code, msg, headers, newurl):
                owner.visited.append(newurl)
                return super().redirect_request(req, fp, code, msg, headers, newurl)

        self.opener = urllib.request.build_opener(
            urllib.request.ProxyHandler({}), Redirects(),
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),
            urllib.request.HTTPSHandler(context=ssl._create_unverified_context()),
        )

    def get(self, url, fields=None):
        body = None if fields is None else urllib.parse.urlencode(fields).encode()
        try:
            response = self.opener.open(url, body, timeout=45)
        except urllib.error.HTTPError as error:
            response = error
        return response.status, response.url, response.read().decode()

    def authenticate(self, url, fields=None):
        for _ in range(8):
            status, url, body = self.get(url, fields)
            assert status == 200, (status, url, body[-1500:])
            if url.startswith(RP + "session"):
                assert "student" in body and "embeddeddisco:issuer" in body, "Missing authenticated attributes"
                return url
            page = Page(body)
            choices = [link for link in page.links if link.startswith(RP + "initiate-login?")]
            if choices:
                url, fields = choices[0], None
                continue
            login = next((f for f in page.forms if "username" in f["fields"]), None)
            assert login is not None, ("Expected OP login form", url, body[-1500:])
            fields = login["fields"]
            fields.update(username="student", password="studentpass")
            url = urllib.parse.urljoin(url, login["action"])
        raise AssertionError("Login did not finish")


def main():
    browser = Browser()
    browser.authenticate(RP + "login")
    assert any(url.startswith(HOP) for url in browser.visited), "RP did not invoke HOP"
    assert any(url.startswith(RP + "hop/resume?") for url in browser.visited), "Original RP state was not resumed"
    print("PASS RP-initiated HOP -> OP login -> original RP continuation -> attributes")

    target = RP + "session?example=%2Fhome%3Fx%3D1&label=one+two"
    start = HOP + "?" + urllib.parse.urlencode({"entity_id": ENTITY, "target_link_uri": target, "hint": OP})
    browser = Browser()
    status, _, body = browser.get(start)
    assert status == 200
    links = [link for link in Page(body).links if link.startswith(RP + "initiate-login?")]
    assert len(links) == 2, "Expected both local providers"
    parameters = urllib.parse.parse_qs(urllib.parse.urlsplit(links[0]).query)
    assert parameters["iss"] == [OP], "Hinted OP was not promoted"
    assert parameters["target_link_uri"] == [target], "Target was changed by HOP"
    assert browser.authenticate(links[0]) == target, 'RP did not return to the supplied target'
    print("PASS picker-first login, OP hint promotion and unchanged target_link_uri")

    browser = Browser()
    browser.authenticate(RP + "initiate-login", {"iss": OP, "login_hint": "student", "target_link_uri": RP + "session"})
    authorize = next(url for url in browser.visited if "request=" in url and url.startswith(OP))
    jwt = urllib.parse.parse_qs(urllib.parse.urlsplit(authorize).query)["request"][0]
    claims = json.loads(base64.urlsafe_b64decode(jwt.split(".")[1] + "=="))
    assert claims["login_hint"] == "student", "User hint missing from signed authorization request"
    print("PASS POST login initiation and login_hint in signed OIDC request")

    browser = Browser()
    browser.authenticate(HOP + "?" + urllib.parse.urlencode({
        "entity_id": ENTITY, "query": "Local OpenID Provider 1",
    }))
    assert any(url.startswith(RP + "initiate-login?") for url in browser.visited), "Single OP was not auto-selected"
    print("PASS single matching OP automatically returns through RP initiation")

    for url in (
        HOP + "?entity_id=https%3A%2F%2Funknown.example",
        RP + "initiate-login?iss=" + urllib.parse.quote(OP, safe="") + "&target_link_uri=https%3A%2F%2Fevil.example",
        RP + "initiate-login?iss%5B%5D=invalid",
    ):
        status, _, _ = Browser().get(url)
        assert status in (400, 403), ("Expected rejection", status, url)
    print("PASS unenrolled RP, foreign target and malformed issuer are rejected")


if __name__ == "__main__":
    main()
