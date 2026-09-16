# PRD Validation — BLOCKED (source corruption)

- **Target:** `Paystar Market PRD.md` (173 KB, 13,369 lines, 6,889 text runs)
- **Intent:** validate (bmad-prd)
- **Verdict:** **Cannot validate.** The file is a damaged PDF text extraction, not a usable requirements document.

## Evidence of corruption

1. **Character-order reversal.** Every text run is stored in visual RTL order, not logical order.
   `یفرعم` → `معرفی`, `دنتسم` → `مستند`, `لوصحم` → `محصول`.
2. **Token-order reversal within visual lines, with unrecoverable group boundaries.**
   62% of lines (4,331 of 6,889) hold a single word. Words within a wrapped line are in reverse
   order, but the extractor left no marker for where one visual line ends and the next begins.
   The head of the file reads correctly only when reversed; the tail reads correctly as-is.
   No single transform recovers the whole document.
3. **Broken lam-alef ligature** in 71 places: `اطالعات` appears where `اطلاعات` belongs.
   Confirms glyph-level (not text-level) extraction.

## Why this blocks validation rather than merely degrading it

A PRD validation judges whether requirements are testable, whether IDs are contiguous, and
whether metrics carry targets. All three depend on **which number is attached to which
requirement**. In this file token adjacency is not trustworthy, so any such finding would be
an artifact of the corruption rather than a property of the PRD. A confident-sounding review
built on scrambled input is worse than no review.

## What IS reliably recoverable

Latin-script tokens survive intact (they are only character-reversed, and are individually
delimited). The recovered vocabulary gives the PRD's subject surface with high confidence:

**Section names present:** Product Overview, Value Proposition, Personas, Users, Use Cases,
Core, Architecture, Integrations, Operations, Security, Risks, Dependencies, Open Decisions.

**Dominant domain terms by frequency:**
Merchant ×200 · PayStar ×121 · **B2B ×93** · POS ×70 · Market ×62 · Tenant ×51 · **B2C ×44** ·
White Label ×33 · **RFM ×28** · PSP ×17 · Sync ×10 · Audit ×10 · OTP ×9 · Service ×8 · Web ×8 ·
Backend ×7 · Segment ×7 · Agent ×6 · Token ×6 · API ×6 · SaaS ×5 · Session ×4 · Role ×4 ·
Auth ×4 · Async ×4 · Notification ×3 · Multi-Tenancy ×3 · Permission ×3 · Idempotency ×3 ·
Progressive Access ×3 · Compliance ×3

## Two substantive findings that survive the corruption

These rest on term *presence and frequency*, not on token order, so they hold:

- **[high] The PRD carries a B2B/B2C axis the PAD does not.** B2B (×93) and B2C (×44) are
  among the most frequent terms in the PRD, yet neither appears as a framing dimension
  anywhere in PAD Ch.1 — which segments by micro-merchant / software company / mid-size /
  enterprise instead. Two documents are describing the same product along different axes.
  *Fix:* reconcile the segmentation model across PRD and PAD before either feeds downstream work.

- **[medium] RFM segmentation (×28) is a significant PRD feature with no PAD MVP mandate.**
  PAD §1.30 keeps only "basic customer information registration" in MVP scope and defers the
  customer-club capability to a provider. An RFM engine with configurable recency windows and
  weighting thresholds (visible in the recoverable tail text) is a materially larger build.
  *Fix:* confirm whether RFM is MVP or post-MVP; PAD and PRD currently disagree.

## Required to proceed

Re-export the PRD from its source (the `.docx`/Google Doc/original PDF) with logical-order
text — or provide the source file directly. No source document exists anywhere under
`/home/berlin/Desktop/Foundry`; only the three `.md` files are present.
