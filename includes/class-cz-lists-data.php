<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Struttura dati di una lista, salvata nel meta META_DATA:
 *
 * {
 *   "names":    { "en": "", "sa": "", "pi": "", "bo": "", "zh": "", "ja": "" },
 *   "lemma":    0,                       // ID del post_tag corrispondente alla lista
 *   "aliases":  [ { "it": "", "en": "", "sa": "", "pi": "", "bo": "", "zh": "", "ja": "" } ],
 *   "variants": [
 *     {
 *       "intro": "",                     // es. "Contemplazione di:"
 *       "items": [
 *         { "pos": "", "it": "", "en": "", "sa": "", "pi": "", "bo": "", "zh": "", "ja": "",
 *           "lemma": 0, "list": 0,       // "pos" vuoto = numerazione automatica
 *           "level": 0,                  // annidamento 0–3
 *           "heading": false }           // intestazione di sezione: solo testo, niente numero
 *       ]
 *     }
 *   ]
 * }
 *
 * Il nome italiano della lista è il titolo del post.
 * Al salvataggio vengono scritti due meta piatti (multipli) per le ricerche inverse:
 * META_LEMMA_INDEX (ID dei lemmi citati) e META_LIST_INDEX (ID delle liste collegate).
 */
class CZ_Lists_Data {
	const META_DATA        = '_cz_list_data';
	const META_LEMMA_INDEX = '_cz_list_lemma';
	const META_LIST_INDEX  = '_cz_list_ref';

	const LANGS      = array( 'en', 'sa', 'pi', 'bo', 'zh', 'ja' );
	const ITEM_TEXTS = array( 'pos', 'it', 'en', 'sa', 'pi', 'bo', 'zh', 'ja' );

	public static function register_meta() {
		register_post_meta(
			CZ_Lists_CPT::CPT,
			self::META_DATA,
			array(
				'type'              => 'object',
				'single'            => true,
				'default'           => self::empty_data(),
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'auth_callback'     => fn() => current_user_can( 'edit_posts' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
				),
			)
		);
	}

	public static function empty_data() {
		return array(
			'names'    => array_fill_keys( self::LANGS, '' ),
			'lemma'    => 0,
			'aliases'  => array(),
			'variants' => array(
				array(
					'intro' => '',
					'items' => array(),
				),
			),
		);
	}

	/**
	 * @param int $post_id
	 * @return array
	 */
	public static function get( $post_id ) {
		$data = get_post_meta( $post_id, self::META_DATA, true );

		return is_array( $data ) ? self::sanitize( $data ) : self::empty_data();
	}

	/**
	 * Salva i dati e aggiorna gli indici per le ricerche inverse.
	 *
	 * @param int   $post_id
	 * @param array $data
	 */
	public static function save( $post_id, $data ) {
		$data = self::sanitize( $data );
		update_post_meta( $post_id, self::META_DATA, $data );
		update_post_meta( $post_id, CZ_Lists_Search::META_SEARCH, CZ_Lists_Search::build_text( $data ) );

		$lemmas = array();
		$lists  = array();
		if ( $data['lemma'] ) {
			$lemmas[] = $data['lemma'];
		}
		foreach ( $data['variants'] as $variant ) {
			foreach ( $variant['items'] as $item ) {
				if ( $item['lemma'] ) {
					$lemmas[] = $item['lemma'];
				}
				if ( $item['list'] && (int) $item['list'] !== (int) $post_id ) {
					$lists[] = $item['list'];
				}
			}
		}

		self::replace_index( $post_id, self::META_LEMMA_INDEX, $lemmas );
		self::replace_index( $post_id, self::META_LIST_INDEX, $lists );
	}

	private static function replace_index( $post_id, $key, $ids ) {
		delete_post_meta( $post_id, $key );
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			add_post_meta( $post_id, $key, $id );
		}
	}

	/**
	 * Normalizza qualsiasi input nella struttura attesa, scartando chiavi sconosciute.
	 *
	 * @param mixed $raw
	 * @return array
	 */
	public static function sanitize( $raw ) {
		$raw  = is_array( $raw ) ? $raw : array();
		$data = self::empty_data();

		$names = isset( $raw['names'] ) && is_array( $raw['names'] ) ? $raw['names'] : array();
		foreach ( self::LANGS as $lang ) {
			$data['names'][ $lang ] = isset( $names[ $lang ] ) ? sanitize_text_field( $names[ $lang ] ) : '';
		}

		$data['lemma'] = isset( $raw['lemma'] ) ? absint( $raw['lemma'] ) : 0;

		$data['aliases'] = array();
		if ( isset( $raw['aliases'] ) && is_array( $raw['aliases'] ) ) {
			foreach ( $raw['aliases'] as $alias ) {
				$clean = self::sanitize_texts( $alias, array_merge( array( 'it' ), self::LANGS ) );
				if ( implode( '', $clean ) !== '' ) {
					$data['aliases'][] = $clean;
				}
			}
		}

		$variants = isset( $raw['variants'] ) && is_array( $raw['variants'] ) ? $raw['variants'] : array();
		if ( $variants ) {
			$data['variants'] = array();
		}
		foreach ( $variants as $variant ) {
			$variant = is_array( $variant ) ? $variant : array();
			$clean   = array(
				'intro' => isset( $variant['intro'] ) ? sanitize_text_field( $variant['intro'] ) : '',
				'items' => array(),
			);

			$items = isset( $variant['items'] ) && is_array( $variant['items'] ) ? $variant['items'] : array();
			foreach ( $items as $item ) {
				$item                = is_array( $item ) ? $item : array();
				$clean_item          = self::sanitize_texts( $item, self::ITEM_TEXTS );
				$texts               = implode( '', array_diff_key( $clean_item, array( 'pos' => 1 ) ) );
				$clean_item['lemma'] = isset( $item['lemma'] ) ? absint( $item['lemma'] ) : 0;
				$clean_item['list']  = isset( $item['list'] ) ? absint( $item['list'] ) : 0;
				// Annidamento (0 = primo livello) e intestazioni di sezione ("Primo dhyāna").
				$clean_item['level']   = isset( $item['level'] ) ? min( 3, absint( $item['level'] ) ) : 0;
				$clean_item['heading'] = ! empty( $item['heading'] );

				// Le righe completamente vuote non vengono salvate.
				if ( '' !== $texts || $clean_item['lemma'] || $clean_item['list'] ) {
					$clean['items'][] = $clean_item;
				}
			}

			$data['variants'][] = $clean;
		}

		return $data;
	}

	private static function sanitize_texts( $source, $keys ) {
		$source = is_array( $source ) ? $source : array();
		$out    = array();
		foreach ( $keys as $key ) {
			$out[ $key ] = isset( $source[ $key ] ) ? sanitize_text_field( $source[ $key ] ) : '';
		}

		return $out;
	}

	/**
	 * Numero di elementi ricavato dalla prima variante.
	 * Una posizione come "1–4" conta fino a 4; senza posizione conta l'indice.
	 *
	 * @param array $data
	 * @return int
	 */
	public static function derive_count( $data ) {
		if ( empty( $data['variants'][0]['items'] ) ) {
			return 0;
		}

		// Contano solo gli elementi di primo livello, non le intestazioni.
		$max   = 0;
		$index = 0;
		foreach ( $data['variants'][0]['items'] as $item ) {
			if ( $item['heading'] || $item['level'] > 0 ) {
				continue;
			}
			$n = ++$index;
			if ( '' !== $item['pos'] && preg_match_all( '/\d+/', $item['pos'], $m ) ) {
				$n = max( array_map( 'intval', $m[0] ) );
			}
			$max = max( $max, $n );
		}

		return $max;
	}

	/**
	 * Liste che citano un lemma (per la pagina del lemma).
	 *
	 * @param int $term_id
	 * @return WP_Post[]
	 */
	public static function lists_for_lemma( $term_id ) {
		return get_posts(
			array(
				'post_type'      => CZ_Lists_CPT::CPT,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_key'       => self::META_LEMMA_INDEX,
				'meta_value'     => absint( $term_id ),
			)
		);
	}

	/**
	 * Liste che contengono un rimando alla lista data.
	 *
	 * @param int $post_id
	 * @return WP_Post[]
	 */
	public static function lists_referencing( $post_id ) {
		return get_posts(
			array(
				'post_type'      => CZ_Lists_CPT::CPT,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_key'       => self::META_LIST_INDEX,
				'meta_value'     => absint( $post_id ),
			)
		);
	}
}
