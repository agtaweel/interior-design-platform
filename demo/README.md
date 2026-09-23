# Demo Assets

- **`Interior_Design_Platform_Demo.pdf`** — the full walkthrough as a PDF (title page + 13
  numbered steps, each with narration and real screenshots).
- **`walkthrough.mp4`** — the same walkthrough as a real screen-recorded video (H.264, 1440x900,
  ~47s), captured via Playwright driving an actual browser against the running app.
- **`SCRIPT.md`** — the shot-by-shot storyboard/narration script both artifacts were built from.
  Usable standalone if someone wants to re-record this with a live voiceover.
- **`stills/`** — the 24 individual screenshots captured during recording (used in the PDF).
- **`scripts/`** — the tooling used to generate all of the above, for regenerating later:
  - `seed_demo.php` — seeds one clean fictional demo organization ("Nile & Co. Interiors") with
    a realistic client, project, BOQ, and pricing rules. Run via `docker compose exec -T app php
    artisan tinker` with the `<?php` tag and `use` statements stripped first (tinker's REPL
    already aliases `App\Models\*` classes and rejects raw `<?php` open tags piped via stdin).
  - `record_demo.py` — the Playwright script that logs in, drives the full product lifecycle
    (BOQ → pricing → proposal → public approval → contract → payment → change order → apply →
    reports → notifications), captures a still at each step, and records the whole session to
    MP4. Needs `pip install playwright && playwright install chromium` and the app stack running
    (`docker compose up -d` + `npm run dev` in `frontend/`). Update `PROJECT_ID` at the top to
    match whatever project id `seed_demo.php` reports after seeding.
  - `build_pdf.py` — assembles the PDF from `stills/` + the narration text (mirrors `SCRIPT.md`).
    Needs `pip install reportlab pillow`.

## Regenerating

```
# 1. Seed fresh demo data (prints the new org_id/project_id)
docker compose exec -T app php artisan tinker <<< "$(tail -n +2 demo/scripts/seed_demo.php | grep -v '^use ')"

# 2. Update PROJECT_ID in record_demo.py to match, then record
python3 demo/scripts/record_demo.py

# 3. Rebuild the PDF from the fresh stills
python3 demo/scripts/build_pdf.py
```

The demo data lives in its own organization and doesn't interfere with any other data in the
database — safe to re-run repeatedly (each run's seed script creates a new org; delete the old
one first if you don't want it to accumulate).
