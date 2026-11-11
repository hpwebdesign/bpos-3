# CLAUDE.md

Notes for working on Barik POS (BPOS, OpenCart 3.0.x).

## Branding and URLs

- The website is **bariklabs.com** (it was hpwebdesign.io). Use it in articles, marketplace listings, banner copy, `install.xml` and support links.
- Links now point to bariklabs.com. The support/docs links in the admin Help tab use `https://bariklabs.com/support` and `/docs/bpos`; confirm those paths exist on the site.

## Packages

- A patch package is named `bpos-v.{version}.ocmod.zip`. Bump `<version>` in `install.xml` for every patch.
- A patch zip contains only the changed files under `upload/`, plus an `install.xml` with a distinct code (for example `BPOS_PATCH`), because the code `BPOS` is already installed on the target.
- A full package contains `install.xml` (code `BPOS`) and the whole `upload/` folder. Exclude `.DS_Store`.
