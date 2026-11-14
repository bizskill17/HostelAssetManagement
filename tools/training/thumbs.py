"""Designed thumbnails (1280x720) for the training videos: coloured title card + a still from the video."""
import json, subprocess, sys
from PIL import Image, ImageDraw, ImageFilter, ImageFont

DST = "/home/claude/hostelassetmanagement/public/training"
SRC = "/home/claude/training-build/out"
F = "/usr/share/fonts/opentype/inter/"
bold = lambda n: ImageFont.truetype(F + "InterDisplay-Bold.otf", n)
semi = lambda n: ImageFont.truetype(F + "Inter-SemiBold.otf", n)
med = lambda n: ImageFont.truetype(F + "Inter-Medium.otf", n)
COL = {  # group: (gradient from, gradient to)
    "Getting Started": ("#2F54EB", "#4F7BFF"), "Masters": ("#334155", "#5B6478"), "In Register": ("#0F766E", "#14B8A6"),
    "Out Register": ("#6D28D9", "#A855F7"), "Approval": ("#C2410C", "#F59E0B"), "Records": ("#4338CA", "#7C3AED"), "Settings": ("#1F2937", "#475569"),
}
W, H = 1280, 720


def hexrgb(h):
    h = h.lstrip("#"); return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))


def wrap(d, text, font, width):
    lines, cur = [], ""
    for w in text.split():
        t = (cur + " " + w).strip()
        if d.textlength(t, font=font) <= width:
            cur = t
        else:
            lines.append(cur); cur = w
    return lines + [cur]


def make(g):
    a, b = (hexrgb(c) for c in COL.get(g["group"], ("#2F54EB", "#7C3AED")))
    im = Image.new("RGB", (W, H))
    px = im.load()
    for y in range(H):
        for x in range(0, W, 4):
            t = (x / W) * 0.7 + (y / H) * 0.3
            c = tuple(int(a[i] + (b[i] - a[i]) * t) for i in range(3))
            for k in range(4):
                if x + k < W:
                    px[x + k, y] = c
    d = ImageDraw.Draw(im)
    # soft circles for depth
    deco = Image.new("RGBA", (W, H), (0, 0, 0, 0)); dd = ImageDraw.Draw(deco)
    dd.ellipse((-160, 420, 360, 940), fill=(255, 255, 255, 22)); dd.ellipse((900, -220, 1400, 280), fill=(255, 255, 255, 18))
    im.paste(deco, (0, 0), deco)
    # still from the video, framed on the right
    frame = "/tmp/thumb_frame.jpg"
    at = max(4.0, g["dur"] * 0.6)
    subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-ss", f"{at:.1f}", "-i", f"{SRC}/{g['video']}", "-frames:v", "1", "-q:v", "2", frame], check=True)
    sh = Image.open(frame).convert("RGB").resize((600, 338), Image.LANCZOS)
    mask = Image.new("L", sh.size, 0); ImageDraw.Draw(mask).rounded_rectangle((0, 0, *sh.size), 18, fill=255)
    shadow = Image.new("RGBA", (W, H), (0, 0, 0, 0)); ImageDraw.Draw(shadow).rounded_rectangle((640, 214, 1240, 552), 20, fill=(0, 0, 0, 110))
    shadow = shadow.filter(ImageFilter.GaussianBlur(18)); im.paste(shadow, (0, 0), shadow)
    border = Image.new("RGBA", (612, 350), (255, 255, 255, 0)); ImageDraw.Draw(border).rounded_rectangle((0, 0, 611, 349), 22, fill=(255, 255, 255, 235))
    im.paste(border, (628, 190), border)
    im.paste(sh, (634, 196), mask)
    # play button over the still
    cx, cy, r = 934, 365, 46
    pb = Image.new("RGBA", (W, H), (0, 0, 0, 0)); pd = ImageDraw.Draw(pb)
    pd.ellipse((cx - r, cy - r, cx + r, cy + r), fill=(255, 255, 255, 238))
    pd.polygon([(cx - 14, cy - 22), (cx - 14, cy + 22), (cx + 24, cy)], fill=a + (255,))
    im.paste(pb, (0, 0), pb)
    # text on the left
    d = ImageDraw.Draw(im)
    tag = g["group"].upper()
    tf = semi(22); tw = d.textlength(tag, font=tf)
    d.rounded_rectangle((60, 120, 60 + tw + 36, 162), 21, fill=(255, 255, 255))
    d.text((78, 128), tag, font=tf, fill=a)
    tfont = bold(66)
    lines = wrap(d, g["title"], tfont, 540)
    if len(lines) > 3:
        tfont = bold(54); lines = wrap(d, g["title"], tfont, 540)
    y = 196
    for ln in lines:
        d.text((60, y), ln, font=tfont, fill="white"); y += int(tfont.size * 1.12)
    meta = f"{len(g['steps'])} steps · {int(round(g['dur']))} sec"
    d.text((62, y + 18), meta, font=med(26), fill=(255, 255, 255, 230))
    d.text((60, 640), "Hostel Asset Management · Training", font=semi(24), fill=(255, 255, 255))
    im.save(f"{DST}/{g['id']}.jpg", quality=86, optimize=True)


if __name__ == "__main__":
    guides = json.load(open(f"{DST}/guides.json"))
    for g in guides:
        make(g)
        g["poster"] = f"{g['id']}.jpg"
    json.dump(guides, open(f"{DST}/guides.json", "w"), indent=1, ensure_ascii=False)
    print(len(guides), "thumbnails")
