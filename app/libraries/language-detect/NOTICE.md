# Text_LanguageDetect (vendored)

## Code: `LanguageDetect.php`, `LanguageDetect/*.php`

- Package: Text_LanguageDetect, PEAR (https://pear.php.net/package/Text_LanguageDetect).
- Author and copyright: Nicholas Pisarro, 2005-2006 (file headers).
- Licence selection: **BSD 3-Clause**, full text in `LICENSE-BSD-3-Clause.txt`.

### What this selection is, and is not

- The file header says `@license http://www.debian.org/misc/bsd.license BSD`. The PEAR
  `package.xml` says `BSD` with a generic `opensource.org` link. Neither states the clause
  count or the exact wording.
- The upstream repository (`pear/Text_LanguageDetect`) has no LICENSE file.
- `LICENSE-BSD-3-Clause.txt` is the standard 3-Clause BSD text, taken from the OSI licence
  page. It is the licence this repository **selects** for the vendored code, on the basis
  that the package says BSD and the 3-Clause text is the usual reading.
- It is **not** a verified copy of the original package's licence notice. The 2005-2006
  author's own notice has not been seen. If the original notice differs, the original
  wording governs and this file should be replaced.

### Required attribution

The copyright line above and the three conditions in `LICENSE-BSD-3-Clause.txt` apply to
the vendored code. Keep both when redistributing.

## Data: `LanguageDetect/lang.dat`

**Licence: unverified. This is separate from the code selection above.**

- The BSD 3-Clause selection covers the code only. It does not cover `lang.dat` and does
  not make `lang.dat` licensed for redistribution.
- Upstream provenance: the upstream README says the author does not remember where the
  training data came from. It says the 3-letter frequency data "could be generated from a
  few random Wikipedia pages".
- Shipping it does **not** establish permission to redistribute it. Wikipedia text is
  under CC BY-SA, but the data here is an unknown derivation, so no licence can be
  asserted for it.
- Impact if removed: language detection (`detect_language` and the `dc:language` element)
  stops working.

Maintainers who can establish the data's source and licence should update this notice.

### Release decision

The maintainer of this repository chose to redistribute `lang.dat` to keep language detection, accepting the risk. That choice does not verify the data's provenance or licence, and the data is not BSD-licensed on that basis.
