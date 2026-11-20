# FeedWriter (upstream, vendored)

These files are the upstream "Universal Feed Writer" library, taken from
https://github.com/mibe/FeedWriter, tag `v1.1.2`, commit
`b32ed2a68e612a1f6045d00d342df6928ecfecad` (tag object `705df79ce07983177945de899c2e963b9d100556`,
signed by the maintainer). The tag is verified by GitHub.

Files: `Feed.php`, `Item.php`, `RSS2.php`, `InvalidOperationException.php`, `COPYING`.

Licence: GNU General Public License version 3 (`COPYING`). The copyright notices are kept
in each file (Anis uddin Ahmad, 2008; Michael Bemmerl, 2010–2023).

Changes made in this repository (marked so they can be reviewed against the upstream commit):

- `Feed.php`: added a typed private `$encoding` property (PHP 8.2+ dynamic-property deprecation).
- `Feed.php`: `makeNode()` and `setChannelElement()` take `?array $attributes`, which PHP 8.4 requires
  for an implicit null default.
- `Item.php`: `addElement()` takes `?array $attributes` for the same reason.

No other changes were made.

The adapter that calls these classes is `app/libraries/feedwriter/FeedWriter.php` and
`FeedItem.php`. Those two files are part of Full-Text RSS and are covered by the project's
AGPL-3.0 licence.

The original FiveFilters copy (an unversioned, early "Univarsel Feed Writer" by Anis uddin Ahmad)
that this replaces had no licence header. The FiveFilters page at `app/index.php` described it
as GPL v2. That is a statement by FiveFilters, not a licence file we could verify, so the old
copy is no longer in the tree.
