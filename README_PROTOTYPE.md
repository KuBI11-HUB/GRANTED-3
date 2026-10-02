# GRANTED — Prototype Setup & Demo Script (Phases 1–3, Document Upload/Review complete)

## What's in this build
- Phase 1: login, roles, session security
- Phase 2: scholarship types, rules, scholar enrollment, grade entry,
  **document upload (scholar) + document review (admin)**
- Phase 3: the rule engine — evaluates GPA, UNIT_LOAD, and now DOCUMENT
  rules automatically, every time a grade is saved or a document is
  reviewed

Only DEADLINE rules still return PENDING always — that needs a
"today vs. due date" concept that isn't scoped yet. Fine to say so if asked.

## Setup
1. Copy this `GRANTED` folder into your web root (replacing any older copy
   entirely — don't merge files from an older version in)
2. Import `database/granted_schema.sql`
3. Run `database/generate_password_hash.php`, copy the hash, then in
   phpMyAdmin's SQL tab run:
   ```sql
   INSERT INTO users (email, password_hash, role)
   VALUES ('admin@granted.local', 'PASTE_YOUR_HASH_HERE', 'admin')
   ON DUPLICATE KEY UPDATE password_hash = 'PASTE_YOUR_HASH_HERE';
   ```
4. Delete `generate_password_hash.php` afterward
5. Check `config/database.php` matches your local MySQL credentials
6. Make sure `uploads/documents/` is writable (XAMPP usually is, by default)

## The demo script — follow this exact order

**1. Log in as admin** (`admin@granted.local` / `admin123`).

**2. Add two rules** for "Academic Scholarship": one GPA rule
(`<= 2.00`), and one DOCUMENT rule — for the DOCUMENT rule, set the
**threshold value to the exact document name**, e.g. `Certificate of Registration`.
This exact string is what the scholar will see on their checklist.

**3. Enroll a scholar**, enter a passing grade for them (e.g. `1.75`, 3 units).
Status should calculate to **PENDING** — not PASS — because the document
requirement hasn't been met yet. This is worth narrating out loud: it
shows the engine correctly waits on *every* rule, not just the numeric one.

**4. Log out, log in as that scholar.** Show the dashboard: GPA rule
shows PASS, but overall status is PENDING, with the Pending Checklist
card naming exactly which document is missing.

**5. Go to Upload Documents**, pick that document from the dropdown,
upload any PDF/JPG/PNG. Status shows "Pending Review" — not verified yet,
on purpose.

**6. Log out, log in as admin, go to Document Review.** Click **Verify**
on that document (add a remark first if you want to show that field
working). The moment you click it, the rule engine re-runs.

**7. Log back in as the scholar.** Status has flipped to **PASS** —
both rules now satisfied, checklist shows nothing outstanding.

**That full loop — grade entered, document uploaded, document verified,
status recalculating live at every step — is the strongest version of
the demo you can give right now.** It proves the engine really does wait
on every rule type, not just the easy numeric one.

## What to say if asked "what's next"
- DEADLINE rule type (needs a defined submission window)
- Gmail API notifications when status changes (Phase 4)
- CSV export of the results table, renewal decision recording (Phase 5)
