# Post Domain — Implementation Handoff

## What this is

The complete first implementation of the `post-domain` WordPress plugin, built
from the eleven plans under `docs/superpowers/plans/` against the design
specification at `docs/superpowers/specs/2026-08-27-post-domain-design.md`.

The plugin maps a domain name to a single post and **resolves** it rather than
redirecting: the address bar keeps the mapped domain, and the post's permalink
path never appears.

## Provenance

| | |
|---|---|
| Starting commit | `89fd1f4` (final reviewed plan set) |
| Branch | `implementation/initial-build` |
| Specification | `docs/superpowers/specs/2026-08-27-post-domain-design.md` |
| Journal | `docs/superpowers/implementation-journal.md` |

Where a planned example conflicted with the specification, the specification was
implemented and the deviation recorded in the journal. Deviations are numbered
there; this document does not duplicate them.

<!-- SECTIONS BELOW COMPLETED AT END OF SESSION -->
