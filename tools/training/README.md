# Training videos

Scripts that record the Training videos (screen, cursor, captions and a male voice-over) from a local copy of the app.

- `rec.py` records one video: Playwright drives the app, Piper (`en_US-ryan-medium` voice) speaks each step, ffmpeg joins them.
- `v_foundation.py`, `v_masters_rest.py`, `v_flows.py` hold the step-by-step scripts.
- `run_all.sh` records everything from an empty local database; `export_guides.py` copies the videos, posters and `guides.json` into `public/training/`.

Needs a local app at http://127.0.0.1:8000 with an Admin `admin@example.com` / `admin1234`, plus `pip install piper-tts playwright openpyxl` and the voice model in `/tmp/tts/ryan.onnx`. Re-record a video whenever its screen changes.
