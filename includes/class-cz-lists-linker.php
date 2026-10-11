<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collegamento automatico fra elementi delle liste e lemmi del dizionario (post_tag).
 *
 * Un elemento (o la lista stessa) senza lemma viene collegato quando uno dei suoi termini
 * coincide esattamente, a meno di maiuscole e diacritici, con una chiave di un lemma.
 * Il confronto avviene per tipo di scrittura, per evitare falsi positivi:
 *
 * - sanscrito e pāli dell'elemento ↔ chiavi latine del lemma (nome, show_as, sinonimi,
 *   traslitterazioni sanscrite e pāli tra parentesi in "readings");
 * - tibetano ↔ solo la traslitterazione tibetana di "readings";
 * - cinese e giapponese ↔ solo i caratteri, e solo parole di almeno due caratteri (le
 *   trascrizioni come "yi" o "dao" e i caratteri singoli come 欲 o 空 sono troppo ambigui);
 * - italiano dell'elemento ↔ solo il nome del lemma (nomi propri: "Maitreya", "buddha");
 *   per la lista intera anche i sinonimi ("Cinque poteri" ↔ pañcabala).
 *
 * I collegamenti esistenti non vengono mai toccati.
 */
class CZ_Lists_Linker {
	const NOTICE_TRANSIENT = 'cz_lists_linker_notice_';

	const CJK = '/[\p{Han}\p{Hiragana}\p{Katakana}]/u';

	public function __construct() {
		// saved_term arriva dopo created_term/edited_term, dove ACF salva i suoi campi:
		// così i sinonimi appena inseriti sono già leggibili.
		add_action( 'saved_term', array( $this, 'on_saved_term' ), 20, 3 );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		add_action( 'delete_post_tag', array( __CLASS__, 'unlink_lemma' ) );
	}

	/**
	 * Quando un lemma viene cancellato, toglie i collegamenti che puntano a lui.
	 *
	 * @param int $term_id
	 */
	public static function unlink_lemma( $term_id ) {
		$term_id  = (int) $term_id;
		$post_ids = get_posts(
			array(
				'post_type'      => CZ_Lists_CPT::CPT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => CZ_Lists_Data::META_LEMMA_INDEX,
				'meta_value'     => $term_id,
			)
		);

		foreach ( $post_ids as $post_id ) {
			$data = CZ_Lists_Data::get( $post_id );
			if ( $term_id === $data['lemma'] ) {
				$data['lemma'] = 0;
			}
			foreach ( $data['variants'] as $v => $variant ) {
				foreach ( $variant['items'] as $i => $item ) {
					if ( $term_id === $item['lemma'] ) {
						$data['variants'][ $v ]['items'][ $i ]['lemma'] = 0;
					}
				}
			}
			CZ_Lists_Data::save( $post_id, $data );
		}
	}

	public function on_saved_term( $term_id, $tt_id, $taxonomy ) {
		if ( 'post_tag' !== $taxonomy ) {
			return;
		}

		$result = self::link_lemma( $term_id );
		if ( $result['items'] || $result['lists'] ) {
			set_transient( self::NOTICE_TRANSIENT . get_current_user_id(), $result, 60 );
		}
	}

	public function admin_notice() {
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$result = get_transient( $key );
		if ( ! $result ) {
			return;
		}
		delete_transient( $key );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: elementi collegati, 2: liste modificate */
					__( 'Liste: il lemma è stato collegato automaticamente a %1$d elementi in %2$d liste.', 'cz-lists' ),
					$result['items'],
					$result['lists']
				)
			)
		);
	}

	/**
	 * Collega un lemma a tutti gli elementi (e liste) ancora scollegati che gli corrispondono.
	 *
	 * @param int $term_id
	 * @return array{items:int, lists:int}
	 */
	public static function link_lemma( $term_id ) {
		// Solo le chiavi non ambigue che appartengono a questo lemma.
		$keys = array_map(
			fn( $map ) => array_filter( $map, fn( $id ) => (int) $term_id === $id ),
			self::all_lemma_keys()
		);

		return self::link_posts( self::all_list_ids(), $keys );
	}

	/**
	 * Collega gli elementi scollegati di una lista a qualsiasi lemma corrispondente
	 * (usato dall'importazione e dai comandi di manutenzione).
	 *
	 * @param int[] $post_ids
	 * @return array{items:int, lists:int}
	 */
	public static function link_lists( $post_ids ) {
		return self::link_posts( $post_ids, self::all_lemma_keys() );
	}

	/**
	 * @param int[] $post_ids
	 * @param array $keys Mappe 'name', 'latin', 'bo', 'cjk': chiave normalizzata => term_id.
	 * @return array{items:int, lists:int}
	 */
	private static function link_posts( $post_ids, $keys ) {
		$result = array(
			'items' => 0,
			'lists' => 0,
		);
		if ( ! array_filter( $keys ) ) {
			return $result;
		}

		foreach ( $post_ids as $post_id ) {
			$data    = CZ_Lists_Data::get( $post_id );
			$changed = 0;

			if ( ! $data['lemma'] ) {
				$names       = $data['names'];
				$names['it'] = get_the_title( $post_id );
				$term_id     = self::match( $names, $keys, true );
				if ( $term_id ) {
					$data['lemma'] = $term_id;
					++$changed;
				}
			}

			foreach ( $data['variants'] as $v => $variant ) {
				foreach ( $variant['items'] as $i => $item ) {
					if ( $item['lemma'] || $item['heading'] ) {
						continue;
					}
					$term_id = self::match( $item, $keys, false );
					if ( $term_id ) {
						$data['variants'][ $v ]['items'][ $i ]['lemma'] = $term_id;
						++$changed;
					}
				}
			}

			if ( $changed ) {
				CZ_Lists_Data::save( $post_id, $data );
				$result['items'] += $changed;
				++$result['lists'];
			}
		}

		return $result;
	}

	/**
	 * Primo lemma la cui chiave coincide con uno dei termini, confrontando ogni campo
	 * solo con le chiavi dello stesso tipo di scrittura.
	 *
	 * @param array $texts   Valori per campo (it, sa, pi, zh, ja).
	 * @param array $keys    Mappe 'name', 'latin', 'bo', 'cjk'.
	 * @param bool  $is_list true per il nome della lista (l'italiano vale anche per i sinonimi).
	 * @return int
	 */
	private static function match( $texts, $keys, $is_list ) {
		$rules = array(
			'sa' => 'latin',
			'pi' => 'latin',
			'bo' => 'bo',
			'it' => $is_list ? 'latin' : 'name',
			'zh' => 'cjk',
			'ja' => 'cjk',
		);

		foreach ( $rules as $field => $map ) {
			if ( empty( $texts[ $field ] ) || empty( $keys[ $map ] ) ) {
				continue;
			}
			// L'italiano si confronta per intero: dividerlo sulle virgole farebbe corrispondere
			// "stūpa …, a Ṛṣipatana/Sārnāth" al lemma Sārnāth.
			$candidates = 'it' === $field ? array( self::normalize( $texts[ $field ] ) ) : self::candidates( $texts[ $field ], 'cjk' === $map );
			foreach ( $candidates as $candidate ) {
				if ( isset( $keys[ $map ][ $candidate ] ) ) {
					return $keys[ $map ][ $candidate ];
				}
			}
		}

		return 0;
	}

	/**
	 * Termini confrontabili di un campo, separando le alternative ("svārtha/ātmahita",
	 * "citta, viññāṇa"). Per cinese e giapponese tiene solo i caratteri: "ziliang 資糧" → "資糧".
	 *
	 * @param string $text
	 * @param bool   $cjk_only
	 * @return string[] Chiavi normalizzate.
	 */
	private static function candidates( $text, $cjk_only ) {
		$out = array();
		foreach ( preg_split( '/\s*[\/,;]\s*/u', $text ) as $part ) {
			if ( $cjk_only ) {
				$part = preg_replace( '/[^\p{Han}\p{Hiragana}\p{Katakana}]/u', '', $part );
				if ( mb_strlen( $part ) < 2 ) {
					continue;
				}
			}
			$out[] = $part;
		}

		return array_values( array_filter( array_unique( array_map( array( __CLASS__, 'normalize' ), $out ) ) ) );
	}

	/**
	 * Minuscolo, senza diacritici né parentesi quadre, spazi compattati.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = str_replace( array( '[', ']', '*' ), '', $text );
		if ( class_exists( 'Normalizer' ) ) {
			$text = preg_replace( '/\p{Mn}/u', '', Normalizer::normalize( $text, Normalizer::FORM_D ) );
		} else {
			$text = remove_accents( $text );
		}
		$text = preg_replace( '/[’\'‘]/u', '', $text );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( mb_strtolower( $text ) );
	}

	/**
	 * Chiavi normalizzate di un lemma, divise per tipo.
	 *
	 * @param int $term_id
	 * @return array{name: string[], latin: string[], bo: string[], cjk: string[]}
	 */
	public static function lemma_keys( $term_id ) {
		$keys = array(
			'name'  => array(),
			'latin' => array(),
			'bo'    => array(),
			'cjk'   => array(),
		);
		$term = get_term( $term_id, 'post_tag' );
		if ( ! $term || is_wp_error( $term ) ) {
			return $keys;
		}

		$raw   = array( $term->name, (string) get_term_meta( $term_id, 'show_as', true ) );
		$raw   = array_merge( $raw, explode( ',', (string) get_term_meta( $term_id, 'synonyms_csv', true ) ) );
		$latin = array();
		$cjk   = array();
		foreach ( $raw as $value ) {
			if ( preg_match( self::CJK, $value ) ) {
				$cjk[] = preg_replace( '/[^\p{Han}\p{Hiragana}\p{Katakana}]/u', '', $value );
			} else {
				$latin[] = $value;
			}
		}

		// "Sanscrito: समाधि (samādhi); Tibetano: … (ting nge ’dzin); Cinese: 三昧 (sānmèi); …"
		// Le trascrizioni di cinese, giapponese e coreano restano fuori: sillabe come "mi",
		// "yul" o "sha" coinciderebbero con parole tibetane o sanscrite che non c'entrano.
		$tibetan = array();
		foreach ( preg_split( '/;\s*/u', (string) get_term_meta( $term_id, 'readings', true ) ) as $reading ) {
			if ( ! preg_match( '/^\s*([^:]+):\s*([^(]*)\(([^)]+)\)/u', $reading, $m ) ) {
				continue;
			}
			$lang = mb_strtolower( trim( $m[1] ) );
			if ( in_array( $lang, array( 'sanscrito', 'pāli', 'pali' ), true ) ) {
				$latin[] = $m[3];
			} elseif ( 'tibetano' === $lang ) {
				$tibetan[] = $m[3];
			} elseif ( preg_match( self::CJK, $m[2] ) ) {
				$cjk[] = preg_replace( '/[^\p{Han}\p{Hiragana}\p{Katakana}]/u', '', $m[2] );
			}
		}

		$clean = fn( $list ) => array_values( array_filter( array_unique( array_map( array( __CLASS__, 'normalize' ), $list ) ) ) );

		$keys['name']  = $clean( array( $term->name ) );
		$keys['latin'] = $clean( $latin );
		$keys['bo']    = $clean( $tibetan );
		$keys['cjk']   = array_values( array_filter( $clean( $cjk ), fn( $k ) => mb_strlen( $k ) >= 2 ) );

		return $keys;
	}

	/**
	 * Mappe chiave → term_id di tutti i lemmi, per tipo. Le chiavi condivise da più
	 * lemmi sono ambigue e vengono scartate.
	 *
	 * @return array{name: array<string,int>, latin: array<string,int>, bo: array<string,int>, cjk: array<string,int>}
	 */
	public static function all_lemma_keys() {
		$maps      = array(
			'name'  => array(),
			'latin' => array(),
			'bo'    => array(),
			'cjk'   => array(),
		);
		$ambiguous = $maps;
		$terms     = get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
				// Oggetti completi (non solo ID) così WordPress precarica i term meta in blocco.
				'fields'     => 'all',
			)
		);

		foreach ( is_wp_error( $terms ) ? array() : wp_list_pluck( $terms, 'term_id' ) as $term_id ) {
			foreach ( self::lemma_keys( $term_id ) as $type => $keys ) {
				foreach ( $keys as $key ) {
					if ( isset( $maps[ $type ][ $key ] ) && $maps[ $type ][ $key ] !== (int) $term_id ) {
						$ambiguous[ $type ][ $key ] = true;
					}
					$maps[ $type ][ $key ] = (int) $term_id;
				}
			}
		}

		foreach ( $maps as $type => $map ) {
			$maps[ $type ] = array_diff_key( $map, $ambiguous[ $type ] );
		}

		return $maps;
	}

	/**
	 * @return int[]
	 */
	private static function all_list_ids() {
		return get_posts(
			array(
				'post_type'      => CZ_Lists_CPT::CPT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
	}
}
