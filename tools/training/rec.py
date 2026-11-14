"""
Records one training video: the real app in a browser, a visible cursor, a ring on every click,
a caption bar, and a male voice-over (Piper, en_US ryan) lined up with each step.

A script is a list of steps: {"say": narration, "cap": caption, "do": [actions]}.
Actions: ("nav", hash) | ("click", selector) | ("pick", select-name, option text) | ("type", selector, text)
         | ("seg", field, value) | ("wait", ms) | ("hl", selector)  (point at something without clicking)
"""
import json, os, subprocess, sys, time, wave
from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:8000/"
W, H = 1280, 720          # video size
VW, VH = 1152, 648        # browser size: a little smaller, so everything looks bigger in the video
TTS = "/tmp/tts/ryan.onnx"
OUT = "/home/claude/training-build/out"
LOGIN = {}  # video name -> (email, password) when it should sign in as someone other than the Admin
os.makedirs(OUT, exist_ok=True)

OVERLAY = """
(() => {
  if (document.getElementById('tcur')) return;
  const st = document.createElement('style');
  st.textContent = `
  #tcur{position:fixed;left:0;top:0;width:26px;height:26px;z-index:99999;pointer-events:none;transition:transform .7s cubic-bezier(.4,.1,.2,1);transform:translate(640px,360px)}
  #tcur svg{filter:drop-shadow(0 2px 3px rgba(0,0,0,.35))}
  .tring{position:fixed;z-index:99998;pointer-events:none;border:3px solid #FACC15;border-radius:12px;box-shadow:0 0 0 4px rgba(250,204,21,.35);animation:tr 1.1s ease-out forwards}
  @keyframes tr{0%{opacity:0;transform:scale(1.15)}20%{opacity:1;transform:scale(1)}80%{opacity:1}100%{opacity:0}}
  #tcap{position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:99997;pointer-events:none;background:rgba(17,24,39,.88);color:#fff;font:600 19px/1.35 Inter,system-ui,sans-serif;padding:10px 20px;border-radius:12px;max-width:86%;text-align:center;opacity:0;transition:opacity .3s}
  #tstep{display:inline-block;background:#FACC15;color:#111827;border-radius:99px;padding:0 9px;margin-right:9px;font-size:15px}`;
  document.head.appendChild(st);
  const c = document.createElement('div'); c.id = 'tcur';
  c.innerHTML = '<svg width="26" height="26" viewBox="0 0 24 24"><path d="M4 2l15 11-7 1-4 7z" fill="#111827" stroke="#fff" stroke-width="1.5" stroke-linejoin="round"/></svg>';
  document.body.appendChild(c);
  const cap = document.createElement('div'); cap.id = 'tcap'; document.body.appendChild(cap);
  window.__tc = {x: 640, y: 360};
  // Forms fill the frame: scale each dialog up to fit the screen (less empty space around it).
  const fit = () => document.querySelectorAll('.dlg > form, .dlg > .box').forEach((f) => {
    f.style.transform = ''; const r = f.getBoundingClientRect();
    const k = Math.max(1, Math.min(1.5, (innerHeight - 130) / r.height, (innerWidth * 0.86) / r.width));
    f.style.transform = `translateY(-30px) scale(${k.toFixed(3)})`; f.style.transformOrigin = 'center center';
  });
  new MutationObserver(() => requestAnimationFrame(fit)).observe(document.body, {childList: true, subtree: true});
})()
"""


def tts(text, path):
    subprocess.run([sys.executable, "-m", "piper", "-m", TTS, "-f", path, "--length-scale", "1.05", "--sentence-silence", "0.25"],
                   input=text.encode(), check=True, capture_output=True)
    with wave.open(path) as w:
        return w.getnframes() / w.getframerate()


EXPORTED = []


def record(name, title, steps, setup=None):
    if os.environ.get("EXPORT"):
        EXPORTED.append((name, title, [dict(s) for s in steps]))
        return 0
    work = f"/tmp/trec/{name}"
    os.makedirs(work, exist_ok=True)
    for f in os.listdir(work):
        os.remove(os.path.join(work, f))
    email, pw = LOGIN.get(name, ("admin@example.com", "admin1234"))
    steps = steps[:1] + [{"say": "Open the app and sign in with your email and password.", "cap": "Sign in with your Email and Password",
                          "do": [("type", "input[name=email]", email), ("type", "input[name=password]", pw), ("click", "form button[type=submit]"), ("wait", 900)]}] + steps[1:]
    # Voice for every step first, so the recording can wait for each line to finish.
    for k, s in enumerate(steps):
        s["wav"] = f"{work}/s{k}.wav"
        s["dur"] = tts(s["say"], s["wav"])
    with sync_playwright() as p:
        b = p.chromium.launch()
        # Every video starts on the login page, signed out.
        ctx = b.new_context(viewport={"width": VW, "height": VH}, record_video_dir=work, record_video_size={"width": VW, "height": VH})
        pg = ctx.new_page()
        t0 = time.monotonic()
        pg.goto(BASE); pg.wait_for_timeout(1200)
        pg.evaluate(OVERLAY)
        if setup:
            setup(pg)

        def overlay():
            pg.evaluate(OVERLAY)

        def move_to(sel):
            overlay()
            el = pg.locator(sel).first
            el.scroll_into_view_if_needed()
            bb = el.bounding_box()
            x, y = bb["x"] + bb["width"] / 2, bb["y"] + bb["height"] / 2
            pg.evaluate("([x,y])=>{const c=document.getElementById('tcur');c.style.transform=`translate(${x-4}px,${y-2}px)`;}", [x, y])
            pg.wait_for_timeout(750)
            return bb

        def ring(bb):
            pg.evaluate("""(b)=>{const r=document.createElement('div');r.className='tring';const p=6;
              Object.assign(r.style,{left:(b.x-p)+'px',top:(b.y-p)+'px',width:(b.width+2*p)+'px',height:(b.height+2*p)+'px'});
              document.body.appendChild(r);setTimeout(()=>r.remove(),1200);}""", bb)

        def click(sel):
            bb = move_to(sel); ring(bb); pg.wait_for_timeout(250)
            pg.locator(sel).first.click(); pg.wait_for_timeout(450)

        marks = []
        n = 0
        for k, s in enumerate(steps):
            if s.get("num", True):
                n += 1
            overlay()
            pg.evaluate("([n,t])=>{const c=document.getElementById('tcap');c.innerHTML=`<span id=tstep>${n}</span>`+t;c.style.opacity=1;}", [str(n) if s.get("num", True) else "▶", s["cap"]])
            start = time.monotonic() - t0
            marks.append(start)
            for a in s.get("do", []):
                kind = a[0]
                if kind == "nav":
                    pg.evaluate(f"location.hash='{a[1]}'"); pg.wait_for_timeout(700)
                elif kind == "click":
                    click(a[1])
                elif kind == "hl":
                    bb = move_to(a[1]); ring(bb); pg.wait_for_timeout(600)
                elif kind == "type":
                    click(a[1]); pg.locator(a[1]).first.press_sequentially(a[2], delay=70); pg.wait_for_timeout(250)
                elif kind == "pick":
                    click(f".dlg select[name={a[1]}] ~ .ss-btn")
                    opt = f".ss-pop .ss-o:text-is('{a[2]}')"
                    pg.wait_for_selector(opt)
                    click(opt)
                elif kind == "seg":
                    click(f".dlg [data-seg={a[1]}][data-v='{a[2]}']")
                elif kind == "fill":
                    click(a[1]); pg.locator(a[1]).first.fill(a[2]); pg.locator(a[1]).first.dispatch_event("input"); pg.locator(a[1]).first.dispatch_event("change"); pg.wait_for_timeout(300)
                elif kind == "clear":
                    click(a[1]); pg.locator(a[1]).first.fill(""); pg.locator(a[1]).first.press_sequentially(a[2], delay=90); pg.wait_for_timeout(250)
                elif kind == "file":
                    bb = move_to(a[1]); ring(bb); pg.locator(a[1]).first.set_input_files(a[2]); pg.wait_for_timeout(1500)
                elif kind == "pickopt":
                    # a dropdown that is not in a form dialog: click its button, then the option
                    click(a[1]); opt = f".ss-pop .ss-o:text-is('{a[2]}')"; pg.wait_for_selector(opt); click(opt)
                elif kind == "scroll":
                    pg.mouse.wheel(0, a[1]); pg.wait_for_timeout(600)
                elif kind == "wait":
                    pg.wait_for_timeout(a[1])
            left = s["dur"] + 0.45 - (time.monotonic() - t0 - start)
            if left > 0:
                pg.wait_for_timeout(int(left * 1000))
        pg.wait_for_timeout(900)
        vid = pg.video.path()
        ctx.close(); b.close()

    # Voice track: each line placed where its step started.
    inputs, filt = [], []
    for k, (s, m) in enumerate(zip(steps, marks)):
        inputs += ["-i", s["wav"]]
        filt.append(f"[{k + 1}:a]adelay={int(m * 1000)}|{int(m * 1000)},aresample=48000[a{k}]")
    mix = "".join(f"[a{k}]" for k in range(len(steps))) + f"amix=inputs={len(steps)}:normalize=0,volume=1.6[aout]"
    tmp = f"{OUT}/{name}.part.mp4"
    subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", vid, *inputs, "-filter_complex", ";".join(filt) + ";" + mix,
                    "-map", "0:v", "-map", "[aout]", "-vf", f"scale={W}:{H}:flags=lanczos", "-c:v", "libx264", "-preset", "veryfast", "-crf", "23", "-pix_fmt", "yuv420p",
                    "-r", "30", "-c:a", "aac", "-b:a", "96k", "-movflags", "+faststart", tmp], check=True)
    os.replace(tmp, f"{OUT}/{name}.mp4")
    dur = float(subprocess.check_output(["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", f"{OUT}/{name}.mp4"]))
    print(f"{name}: {dur:.1f}s")
    return dur
