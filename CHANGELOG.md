# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] 2026-10-11
### Added
- Initial release: custom post type `cz_list` ("Liste", `/liste/`) for Buddhist numerical lists, with the `cz_list_count` taxonomy (`/liste/numero/{n}/`) and term meta `cz_list_number` for numeric ordering. Number terms are named in Italian words up to one hundred.
- Structured list data in the `_cz_list_data` post meta, without ACF: names in English, Sanskrit, Pāli, Tibetan, Chinese and Japanese, related lemma, alternative names, alternative variants, introductions, section headings and up to four nesting levels per item, item-level lemma and cross-list references.
- Admin meta box to edit the structured data (sortable items, lemma and list pickers with REST search) and a side box for the number of items, computed automatically from the items unless set manually.
- Front-end rendering of single lists (names under the title, items with smaller pronunciations, alternative variants, "see list" references, alternative names, related lemma and "appears also in" back-references) and a list index grouped by number, by decade from twenty upwards, with a digit navigation bar.
- Automatic linking of items to dictionary lemmas (`post_tag`) when a lemma is created or edited, matching per script: Sanskrit/Pāli against Latin keys, Tibetan against Tibetan readings, Chinese/Japanese only on words of at least two characters, Italian only against the lemma name. Ambiguous keys are ignored; links are removed when a lemma is deleted.
- Search integration: lists are found by original names, items and alternative names through the `_cz_list_search` meta (MySQL search and ElasticPress), with a dedicated excerpt in search results.
- WP-CLI commands: `wp cz-lists export` / `wp cz-lists import` (lemmas and list references by slug, idempotent, with `--dry-run`) and `wp cz-lists link`.
