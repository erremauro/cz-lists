# CZ Lists

Liste numeriche del buddhismo (dalla "List of Lists" del Princeton Dictionary of Buddhism) come CPT `cz_list`, con elementi strutturati e collegamenti ai lemmi (`post_tag`). Non dipende da ACF.

## Struttura
- CPT `cz_list` → `/liste/{slug}`, archivio `/liste/`
- Tassonomia `cz_list_count` → `/liste/numero/{uno|due|…}`; ogni termine ha il term meta `cz_list_number` per l'ordinamento.
- Meta `_cz_list_data` (oggetto): nomi in en/sa/pi/bo/zh, lemma, nomi alternativi, varianti → elementi. Schema in `includes/class-cz-lists-data.php`.
- Indici per ricerche inverse: `_cz_list_lemma` (lemmi citati) e `_cz_list_ref` (liste collegate), riscritti a ogni salvataggio.

## Helper
- `CZ_Lists_Data::get( $post_id )`
- `CZ_Lists_Data::lists_for_lemma( $term_id )`
- `CZ_Lists_Data::lists_referencing( $post_id )`

## Collegamento automatico ai lemmi
Quando un lemma (`post_tag`) viene creato o modificato, gli elementi senza lemma il cui termine (it, sa, pi, zh, ja) coincide esattamente — senza maiuscole e diacritici — con il nome, `show_as`, `synonyms_csv` o le traslitterazioni di `readings` vengono collegati. I collegamenti esistenti non vengono toccati; le chiavi condivise da più lemmi sono ignorate. Cancellando un lemma i collegamenti vengono rimossi.

Per ricollegare tutto: `wp cz-lists link`

## Portare le liste in produzione
Lemmi e rimandi fra liste sono salvati per ID, che cambiano da un sito all'altro: l'export li converte in slug, l'import li riconverte negli ID del sito di destinazione.

```
# in locale
wp cz-lists export --file=liste.json
# copiare liste.json sul server, poi in produzione
wp cz-lists import liste.json --dry-run
wp cz-lists import liste.json
```

Le liste sono riconosciute per slug (aggiornate se esistono, create altrimenti): l'import si può ripetere. I lemmi mancanti vengono elencati e gli elementi restano scollegati finché il lemma non viene creato.
