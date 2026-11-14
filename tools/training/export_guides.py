"""Writes public/training/guides.json from the video scripts (titles, written steps, roles, durations)."""
import os, json, subprocess, sys
os.environ["EXPORT"] = "1"
sys.argv = [sys.argv[0]]
import rec
for m in ["v_foundation", "v_masters_rest"]:
    __import__(m)
import v_flows
for k in v_flows.ORDER:
    n, _, st = v_flows.V[k]
    rec.record(n, st[0]["cap"], st)
OUT = "/home/claude/training-build/out"
META = {  # name: (group, roles or None for everyone, page to open)
    "getting-started": ("Getting Started", None, "home"),
    "masters-foundation": ("Masters", ["Admin"], "foundations"), "masters-unit": ("Masters", ["Admin"], "hostels"),
    "masters-category": ("Masters", ["Admin"], "categories"), "masters-measuring-unit": ("Masters", ["Admin"], "units"),
    "masters-damage-destination": ("Masters", ["Admin"], "places"), "masters-item": ("Masters", ["Admin"], "items"), "masters-user": ("Masters", ["Admin"], "users"),
    "in-purchase": ("In Register", None, "stock"), "in-opening-stock": ("In Register", None, "stock"), "in-warranty-item": ("In Register", None, "stock"), "in-bulk-upload": ("In Register", None, "stock"),
    "out-transfer": ("Out Register", None, "issues"), "out-warranty-item": ("Out Register", None, "issues"), "out-repair": ("Out Register", None, "issues"),
    "out-damage": ("Out Register", None, "issues"), "out-cancel": ("Out Register", None, "issues"),
    "approve-receive-transfer": ("Approval", None, "issues?transfer"), "approve-damage": ("Approval", ["Admin", "Organization", "Foundation"], "issues?damage"),
    "approve-repair-return": ("Approval", None, "issues?repair"),
    "inventory": ("Records", None, "inventory"), "warranties": ("Records", None, "warranties"), "settings-telegram": ("Settings", ["Admin"], "settings"),
}
order = list(META)
guides = []
for name, title, steps in sorted(rec.EXPORTED, key=lambda x: order.index(x[0])):
    f = f"{OUT}/{name}.mp4"
    if not os.path.exists(f):
        continue
    dur = float(subprocess.check_output(["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", f]))
    g, roles, page = META[name]
    guides.append({"id": name, "group": g, "title": title, "roles": roles, "video": f"{name}.mp4", "dur": round(dur, 1), "open": page,
                   "intro": steps[0]["say"], "steps": ["Sign in with your Email and Password"] + [s["cap"] for s in steps[1:]]})
dst = "/home/claude/hostelassetmanagement/public/training"
os.makedirs(dst, exist_ok=True)
json.dump(guides, open(f"{dst}/guides.json", "w"), indent=1, ensure_ascii=False)
for g in guides:
    subprocess.run(["cp", f"{OUT}/{g['video']}", dst], check=True)
    # Poster: a frame after sign-in, so the player never shows a blank first frame.
    subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-ss", "9", "-i", f"{OUT}/{g['video']}", "-frames:v", "1", "-vf", "scale=640:-1", "-q:v", "4", f"{dst}/{g['id']}.jpg"], check=True)
    g["poster"] = f"{g['id']}.jpg"
json.dump(guides, open(f"{dst}/guides.json", "w"), indent=1, ensure_ascii=False)
print(len(guides), "guides exported")
