"""Sets up demo data between recordings through the app's own API (local copy only)."""
import json, urllib.request, http.cookiejar
B = "http://127.0.0.1:8000/api/?a="
cj = http.cookiejar.CookieJar(); op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
def call(a, body=None):
    req = urllib.request.Request(B + a, data=json.dumps(body).encode() if body is not None else None, headers={"X-Req": "1", "Content-Type": "application/json"})
    r = json.loads(op.open(req).read())
    if isinstance(r, dict) and r.get("error"): raise RuntimeError(f"{a}: {r['error']['message']}")
    return r
def login(email="admin@example.com", pw="admin1234"): call("login", {"email": email, "password": pw})
def data(): return call("data")
def hid(name): return next(h["id"] for h in data()["hostels"] if h["name"] == name)
def iid(name): return next(i["id"] for i in data()["items"] if i["name"] == name)
def cid(name): return next(c["id"] for c in data()["categories"] if c["name"] == name)
def master(coll, d, id=None): return call("master.save", {"coll": coll, "id": id, "data": d})
