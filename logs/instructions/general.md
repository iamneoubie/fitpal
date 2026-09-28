**No code. No snippets. No "here's a starting point." Nothing.**

---

## 3. PROGRESSION PROTOCOL

- **`proceed`** = start / initialization.
- **`next`** = continuation to the next file or step.
- Revise **one file at a time**.
- **Never** proceed past a step without the user's explicit `proceed` / `next`.

**If the user has not said `proceed` or `next` → HARD STOP. Wait.**

---

## 4. REVISION PROTOCOL

When the user attaches syntax, code, or a file:

1. Review it.
2. Conclude best practice **without going out of scope**.
3. **Only then** finalize.
4. Output a **full rewrite** — never a snippet.
5. Construct **step by step**, flowing with the user's prompt.

**Recommendations, suggestions, or "you could also..." sections are FORBIDDEN unless the user's message contains the word `suggest`.**

---

## 5. FILE SEPARATION MATRIX

| File type    | Allowed         | Forbidden                |
| ------------ | --------------- | ------------------------ |
| Handlers     | Handler logic   | SQL, non-handler logic   |
| Queries      | SQL logic       | Non-query logic          |
| View helpers | —               | Not used in this project |
| PHP pages    | HTML + PHP echo | Inline CSS, inline JS    |
| JS files     | JS only         | CSS                      |
| CSS files    | CSS only        | JS                       |

---

## 6. PAGE STRUCTURE

- **Never** hard-code `<svg>` tags.
- Only use SVGs that **exist** in `shared/assets/images/icons/`.
- If unsure whether an SVG exists → **HARD STOP**. Ask. Do not invent. Do not guess.
- Use the **related or nearest related** SVG. Never an unrelated one.
- **Never** use `window.alert`, `confirm()`, or `prompt()`. Use modals.

---

## 7. BUTTON RULES

### Colors (binary — no interpretation)

| Situation                 | Background | Text  |
| ------------------------- | ---------- | ----- |
| Confirm / positive        | `primary`  | white |
| Neutral                   | black      | white |
| Negative (delete, remove) | `danger`   | white |

**Never** use transparent background + outline + black text as a global default.

### Scale (binary — no interpretation)

- Container 100% width → button 100%.
- Two buttons, desktop → 50/50 horizontal.
- Two buttons, mobile → may stack 100/100 vertical.
- **Tabs never stack vertically.** Under any viewport. Ever.

---

## 8. PAGE SHELL STRETCHING TO FOOTER

- The page wrapper, `.content`, `.main-content`, and any inner container that needs to reach the footer **must all be flex columns with `flex: 1`.**
- The chain must be verified **end to end**:
  `html → body → .main-content → .content → page wrapper → .container → last child → footer sibling`
- If **any** link in that chain is missing `flex: 1` or is not a flex column, the stretch **silently stops** there — the container will not reach the footer, even if the bottom of the chain looks correct.
- **Never** claim a "spans to footer" fix works without reading `global.css` and the shared footer to confirm `body` and `.main-content` are part of the chain.
- The footer itself stays `flex-shrink: 0` so it keeps its natural height.

---

## 9. MODAL RULES

- Every modal **must** contain an SVG.
- No SVG = modal is invalid. Redo it.

---

## 10. CSRF ACROSS ROLES

### Core principle

All roles (**admin, customer, rider, restaurant**) share **one PHP session** — one cookie. Not one per role.

### Naming (binary)

- **Never** store a role's token under `csrf_token`.
- Use `{role}_csrf_token` (e.g., `admin_csrf_token`, `customer_csrf_token`).
- The **POST field name** stays `csrf_token` (generic is fine).
- The **handler** reads `$_SESSION['{role}_csrf_token']`. Never `$_SESSION['csrf_token']`.

### Why this matters

If two roles both read/write `$_SESSION['csrf_token']`, whichever handler runs `unset($_SESSION['csrf_token'])` on sign-in success **deletes the token the other role's already-rendered form depends on**.

### Symptom

- First role to log in → works.
- Second role, same browser session → fails first submit with **"Security validation failed"**, succeeds only on retry.

### Fix pattern

- `sign-in.php` generates/reads its own `{role}_csrf_token`.
- On success → unset **only** `{role}_csrf_token`. Never the shared key.
- Apply the same per-role key pattern to **any session value** deleted/rotated on one role's success path, if another role's page could read it.

---

## 11. CSS RULES

- `textarea { resize: none; }` — always, no exceptions.

---

## 12. PRE-RESPONSE CHECKLIST

**Run this before sending EVERY response. If any box is unchecked → fix before sending.**

- [ ] GATE CHECK (§0) filled in at the top?
- [ ] Waited for `proceed` / `next` before continuing?
- [ ] No snippets — full rewrite only?
- [ ] Stayed inside the given scope?
- [ ] No unrequested recommendations? (unless user said `suggest`)
- [ ] No `window.alert` / `confirm` / `prompt`?
- [ ] No hard-coded `<svg>`?
- [ ] All SVGs exist in `shared/assets/images/icons/`?
- [ ] No inline CSS or JS in PHP files?
- [ ] No CSS inside JS files?
- [ ] Handlers contain handler logic only?
- [ ] Queries contain SQL logic only?
- [ ] Buttons follow color + scale rules?
- [ ] Modal contains an SVG?
- [ ] Textarea has `resize: none`?
- [ ] Footer stretch chain has `flex: 1` end-to-end?
- [ ] CSRF uses `{role}_csrf_token` — never shared `csrf_token`?
- [ ] On login success, only that role's own token key is unset?

---

## 13. ENFORCEMENT TABLE

| Rule            | Enforcement                                       |
| --------------- | ------------------------------------------------- |
| Gate            | Mandatory first lines of every response           |
| Scope           | Given scope only                                  |
| Revisions       | Full rewrite, step by step                        |
| Snippets        | Never                                             |
| Progression     | Wait for `proceed` / `next`                       |
| Recommendations | Only if user says `suggest`                       |
| PHP files       | No inline CSS / JS                                |
| JS files        | No CSS                                            |
| Handlers        | Handler logic only                                |
| Queries         | SQL logic only                                    |
| Alerts          | Modals only                                       |
| SVGs            | Existing only, from `shared/assets/images/icons/` |
| Buttons         | Color + scale rules strictly                      |
| Modals          | Must contain SVG                                  |
| Textarea        | `resize: none`                                    |
| Footer stretch  | Full `flex: 1` chain verified end-to-end          |
| CSRF            | Per-role session keys only                        |

---

## 14. SELF-AUDIT LOOP

**Before sending:**

1. Fill in the GATE CHECK (§0).
2. Read §12. Tick every box.
3. If any box fails → fix the response, then re-tick.
4. Only send when all boxes are ticked.

**After sending (if the user flags a violation):**

1. Identify which Law from §1 was broken.
2. Reply with only: _"Law [N] violated. Re-reading MANDATORY_RULES.md. Redoing."_
3. Redo the response correctly.

---

## 15. FAILURE PROTOCOL

If you cannot obey a rule (missing info, unclear SVG, ambiguous scope):

- **STOP.**
- Output only a GATE CHECK + one clarifying question.
- **Do not guess. Do not improvise. Do not silently skip.**

---

> **FINAL REMINDER — RE-READ BEFORE EVERY RESPONSE:**
> Every response begins with the GATE CHECK (§0).
> If the gate fails → HARD STOP (§2). Ask. Output nothing else.
> If the gate passes → obey all Laws (§1).
> This file is the **authoritative ruleset**.
> Do not deviate. Do not assume. Do not skip steps.
> If unsure → **ASK**.
> Wait for `proceed` or `next` before continuing.
> Violation = failure.
