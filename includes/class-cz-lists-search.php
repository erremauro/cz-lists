<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rende le liste trovabili dalla ricerca del sito anche per i termini che stanno nei
 * dati strutturati (nomi originali, elementi, nomi alternativi), non solo per il titolo.
 *
 * Il testo ricercabile è scritto in META_SEARCH a ogni salvataggio (CZ_Lists_Data::save)
 * e viene incluso sia nella ricerca MySQL di WordPress sia in ElasticPress.
 */
class CZ_Lists_Search {
	const META_SEARCH = '_cz_list_search';

	/**
	 * Campi testuali indicizzati. L'inglese resta fuori: non è mostrato sul sito e
	 * produrrebbe risultati con estratti incomprensibili.
	 */
	const FIELDS = array( 'it', 'sa', 'pi', 'bo', 'zh', 'ja' );

	public function __construct() {
		add_filter( 'posts_search', array( $this, 'mysql_search' ), 10, 2 );
		add_filter( 'ep_prepare_meta_allowed_protected_keys', array( $this, 'ep_allow_meta' ) );
		add_filter( 'ep_search_fields', array( $this, 'ep_search_fields' ) );
	}

	/**
	 * Testo ricercabile di una lista: ogni valore in forma originale e, se diversa,
	 * senza diacritici ("saṃbhāra sambhara"), così anche MySQL trova le forme semplificate.
	 *
	 * @param array $data Dati già sanificati.
	 * @return string
	 */
	public static function build_text( $data ) {
		$values = array();

		foreach ( self::FIELDS as $field ) {
			$values[] = $data['names'][ $field ] ?? '';
		}
		foreach ( $data['aliases'] as $alias ) {
			foreach ( self::FIELDS as $field ) {
				$values[] = $alias[ $field ] ?? '';
			}
		}
		foreach ( $data['variants'] as $variant ) {
			$values[] = $variant['intro'];
			foreach ( $variant['items'] as $item ) {
				foreach ( self::FIELDS as $field ) {
					$values[] = $item[ $field ];
				}
			}
		}

		$out = array();
		foreach ( array_filter( $values, 'strlen' ) as $value ) {
			$out[]      = $value;
			$normalized = CZ_Lists_Linker::normalize( $value );
			if ( $normalized !== mb_strtolower( $value ) ) {
				$out[] = $normalized;
			}
		}

		return implode( "\n", array_unique( $out ) );
	}

	/**
	 * Aggiunge alla ricerca MySQL di WordPress il confronto con META_SEARCH: ogni condizione
	 * "post_title LIKE '…'" diventa "(post_title LIKE '…' OR testo della lista LIKE '…')".
	 * Il letterale viene riusato così com'è, già preparato da WordPress.
	 *
	 * @param string   $search
	 * @param WP_Query $query
	 * @return string
	 */
	public function mysql_search( $search, $query ) {
		global $wpdb;

		if ( '' === $search || ! $query->get( 's' ) || ! self::query_includes_lists( $query ) ) {
			return $search;
		}
		// Se ElasticPress gestisce la query, questo SQL non viene eseguito: nessun conflitto.
		$meta_key = esc_sql( self::META_SEARCH );

		return preg_replace(
			"/\(\s*{$wpdb->posts}\.post_title\s+LIKE\s*('[^']*')\s*\)/",
			"({$wpdb->posts}.post_title LIKE $1) OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} czl WHERE czl.post_id = {$wpdb->posts}.ID AND czl.meta_key = '{$meta_key}' AND czl.meta_value LIKE $1)",
			$search
		);
	}

	private static function query_includes_lists( $query ) {
		$types = $query->get( 'post_type' );
		if ( empty( $types ) || 'any' === $types ) {
			// Ricerca principale del sito: WordPress cerca in tutti i tipi pubblici.
			return true;
		}

		return in_array( CZ_Lists_CPT::CPT, (array) $types, true );
	}

	public function ep_allow_meta( $keys ) {
		$keys[] = self::META_SEARCH;

		return $keys;
	}

	public function ep_search_fields( $fields ) {
		$fields[] = 'meta.' . self::META_SEARCH . '.value';

		return $fields;
	}

	/**
	 * Estratto per la pagina dei risultati: nomi originali e gli elementi che contengono
	 * il termine cercato (o i primi tre, se il termine è solo nel titolo), con evidenziazione.
	 *
	 * @param int    $post_id
	 * @param string $search_term
	 * @return string HTML con soli <p>, <span>, <mark>.
	 */
	public static function snippet( $post_id, $search_term ) {
		$data   = CZ_Lists_Data::get( $post_id );
		$needle = CZ_Lists_Linker::normalize( $search_term );
		$html   = '';

		$names = self::format_langs( $data['names'] );
		if ( '' !== $names ) {
			$html .= '<p class="cz-list-search-names">' . self::highlight( $names, $search_term ) . '</p>';
		}

		$all     = array();
		$matches = array();
		foreach ( $data['variants'] as $variant ) {
			foreach ( $variant['items'] as $i => $item ) {
				if ( $item['heading'] ) {
					continue;
				}
				$line = ( '' !== $item['pos'] ? $item['pos'] : ( $i + 1 ) ) . '. ' . $item['it'];
				$lang = self::format_langs( $item );
				if ( '' !== $lang ) {
					$line .= ' (' . $lang . ')';
				}
				$all[] = $line;

				if ( '' !== $needle && false !== mb_strpos( CZ_Lists_Linker::normalize( $line ), $needle ) ) {
					$matches[] = $line;
				}
			}
		}

		$lines = $matches ? $matches : array_slice( $all, 0, 3 );
		foreach ( array_slice( $lines, 0, 5 ) as $line ) {
			$html .= '<p class="cz-list-search-item">' . self::highlight( $line, $search_term ) . '</p>';
		}

		return $html;
	}

	private static function format_langs( $texts ) {
		$abbr  = array(
			'sa' => 'S.',
			'pi' => 'P.',
			'bo' => 'T.',
			'zh' => 'C.',
			'ja' => 'J.',
		);
		$parts = array();
		foreach ( $abbr as $code => $label ) {
			if ( ! empty( $texts[ $code ] ) ) {
				$parts[] = $label . ' ' . $texts[ $code ];
			}
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Escape del testo ed evidenziazione del termine (senza distinguere le maiuscole).
	 */
	private static function highlight( $text, $search_term ) {
		$text = esc_html( $text );
		$term = trim( (string) $search_term );
		if ( '' === $term ) {
			return $text;
		}

		return preg_replace( '/(' . preg_quote( esc_html( $term ), '/' ) . ')/iu', '<mark class="highlight">$1</mark>', $text );
	}
}

if ( ! function_exists( 'cz_lists_get_search_snippet' ) ) {
	/**
	 * Template tag: estratto di una lista per la pagina dei risultati di ricerca.
	 *
	 * @param int    $post_id
	 * @param string $search_term
	 * @return string
	 */
	function cz_lists_get_search_snippet( $post_id, $search_term ) {
		return CZ_Lists_Search::snippet( $post_id, $search_term );
	}
}
