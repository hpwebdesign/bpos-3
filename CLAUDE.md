# CLAUDE.md

Notes for working on Barik POS (BPOS, OpenCart 3.0.x).

## Branding and URLs

- The website is **bariklabs.com** (it was hpwebdesign.io). Use it in articles, marketplace listings, banner copy, `install.xml` and support links.
- Old `hpwebdesign.io` / `hpwebdesign.id` links still exist in `article/`, `Product Article/` and `upload/admin/controller/extension/module/bpos.php` (`ticket_link`). Replace them once the exact new demo and support URLs are known.

## Packages

- A patch package is named `bpos-v.{version}.ocmod.zip`. Bump `<version>` in `install.xml` for every patch.
- A patch zip contains only the changed files under `upload/`, plus an `install.xml` with a distinct code (for example `BPOS_PATCH`), because the code `BPOS` is already installed on the target.
- A full package contains `install.xml` (code `BPOS`) and the whole `upload/` folder. Exclude `.DS_Store`.
