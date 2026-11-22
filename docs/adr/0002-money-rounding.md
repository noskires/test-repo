# Money totals stay exact per line; only the document total is rounded to the centavo, half-up

Quantities on a BOQ, a Work Order and a validated completion can be decimals (up to 3 places, e.g. 2.5 m), and unit prices are whole centavos. So a line total can fall between two centavos (0.5 x ₱1.01 = 50.5 centavos). The human decided (2026-11-22) that:

- **Line totals are kept exact**: never rounded, and never computed with floats.
- **Only the document total is rounded**, once, to the whole centavo, **half-up**. It is stored as integer centavos.
- The rule applies to every money total PSS computes:
  - the **BOQ total** (issue 16);
  - the **Work Order estimated amount** (issue 18);
  - the **Billing Amount** (issue 21).
- **Billing Thresholds** are compared against the rounded Billing Amount (issue 23).

## Considered Options

- **Round each line, then sum**: rejected. The total drifts from the true sum as the number of lines grows (two lines of 50.5 give 102 instead of 101).
- **Keep every amount exact, with no rounding anywhere**: rejected. Billed amounts must be payable whole centavos, and the threshold comparison needs one definite value.

## Consequences

- Line totals need a storage type finer than a centavo: a scaled integer (e.g. thousandths of a centavo) or a decimal column with enough scale. Arithmetic is integer or bcmath.
- Screens may show line totals rounded for display, but the stored total is always the rounded exact sum, not the sum of displayed lines.
