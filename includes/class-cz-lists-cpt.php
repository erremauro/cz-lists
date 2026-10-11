<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CZ_Lists_CPT {
	const CPT         = 'cz_list';
	const TAX_COUNT   = 'cz_list_count';
	const TERM_NUMBER = 'cz_list_number';

	public static function register() {
		// La tassonomia va registrata prima del CPT: così le sue regole di rewrite
		// (/liste/numero/...) precedono quelle del singolo (/liste/{slug}).
		register_taxonomy(
			self::TAX_COUNT,
			self::CPT,
			array(
				'labels'            => array(
					'name'          => __( 'Numero di elementi', 'cz-lists' ),
					'singular_name' => __( 'Numero di elementi', 'cz-lists' ),
					'menu_name'     => __( 'Numeri', 'cz-lists' ),
					'all_items'     => __( 'Tutti i numeri', 'cz-lists' ),
					'edit_item'     => __( 'Modifica numero', 'cz-lists' ),
					'add_new_item'  => __( 'Aggiungi numero', 'cz-lists' ),
				),
				'public'            => true,
				'hierarchical'      => false,
				'show_admin_column' => true,
				// Il numero si sceglie dal meta box del plugin, non dal pannello standard.
				'meta_box_cb'       => false,
				'show_in_rest'      => false,
				'rewrite'           => array( 'slug' => 'liste/numero', 'with_front' => false ),
			)
		);

		register_term_meta(
			self::TAX_COUNT,
			self::TERM_NUMBER,
			array(
				'type'              => 'integer',
				'single'            => true,
				'sanitize_callback' => 'absint',
			)
		);

		register_post_type(
			self::CPT,
			array(
				'labels'       => array(
					'name'               => __( 'Liste', 'cz-lists' ),
					'singular_name'      => __( 'Lista', 'cz-lists' ),
					'menu_name'          => __( 'Liste', 'cz-lists' ),
					'add_new'            => __( 'Aggiungi Lista', 'cz-lists' ),
					'add_new_item'       => __( 'Aggiungi Nuova Lista', 'cz-lists' ),
					'edit_item'          => __( 'Modifica Lista', 'cz-lists' ),
					'new_item'           => __( 'Nuova Lista', 'cz-lists' ),
					'view_item'          => __( 'Visualizza Lista', 'cz-lists' ),
					'search_items'       => __( 'Cerca Liste', 'cz-lists' ),
					'not_found'          => __( 'Nessuna lista trovata', 'cz-lists' ),
					'not_found_in_trash' => __( 'Nessuna lista nel cestino', 'cz-lists' ),
				),
				'public'       => true,
				'menu_icon'    => 'dashicons-editor-ol',
				'supports'     => array( 'title', 'editor', 'excerpt', 'custom-fields' ),
				'has_archive'  => true,
				'rewrite'      => array( 'slug' => 'liste', 'with_front' => false ),
				'show_in_rest' => true,
				'rest_base'    => 'cz-lists',
			)
		);
	}

	/**
	 * Crea i termini da Uno a Nove (quelli presenti nel Princeton Dictionary).
	 */
	public static function seed_count_terms() {
		for ( $n = 1; $n <= 9; $n++ ) {
			self::get_or_create_count_term( $n );
		}
	}

	/**
	 * Restituisce l'ID del termine per un dato numero, creandolo se manca.
	 *
	 * @param int $number Numero di elementi.
	 * @return int ID del termine, 0 in caso di errore.
	 */
	public static function get_or_create_count_term( $number ) {
		$number = absint( $number );
		if ( $number < 1 ) {
			return 0;
		}

		$existing = get_terms(
			array(
				'taxonomy'   => self::TAX_COUNT,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
				'meta_key'   => self::TERM_NUMBER,
				'meta_value' => $number,
			)
		);
		if ( ! is_wp_error( $existing ) && ! empty( $existing ) ) {
			return (int) $existing[0];
		}

		$name   = self::number_word( $number );
		$result = wp_insert_term( $name, self::TAX_COUNT, array( 'slug' => sanitize_title( $name ) ) );
		if ( is_wp_error( $result ) ) {
			return 0;
		}

		update_term_meta( $result['term_id'], self::TERM_NUMBER, $number );

		return (int) $result['term_id'];
	}

	/**
	 * Numero in lettere, con l'iniziale maiuscola (1–100; oltre si usa la cifra).
	 *
	 * @param int $number
	 * @return string
	 */
	public static function number_word( $number ) {
		$units = array( '', 'uno', 'due', 'tre', 'quattro', 'cinque', 'sei', 'sette', 'otto', 'nove' );
		$teens = array( 'dieci', 'undici', 'dodici', 'tredici', 'quattordici', 'quindici', 'sedici', 'diciassette', 'diciotto', 'diciannove' );
		$tens  = array( 2 => 'venti', 'trenta', 'quaranta', 'cinquanta', 'sessanta', 'settanta', 'ottanta', 'novanta' );

		$number = absint( $number );
		if ( $number < 1 || $number > 100 ) {
			return (string) $number;
		}

		if ( 100 === $number ) {
			$word = 'cento';
		} elseif ( $number < 10 ) {
			$word = $units[ $number ];
		} elseif ( $number < 20 ) {
			$word = $teens[ $number - 10 ];
		} else {
			$word = $tens[ intdiv( $number, 10 ) ];
			$unit = $number % 10;
			// Elisione davanti a uno e otto (ventuno, ventotto); "tre" accentato in composizione (ventitré).
			if ( 1 === $unit || 8 === $unit ) {
				$word = substr( $word, 0, -1 );
			}
			$word .= 3 === $unit ? 'tré' : $units[ $unit ];
		}

		return ucfirst( $word );
	}

	/**
	 * Tutti i termini numero, ordinati per valore.
	 *
	 * @return WP_Term[]
	 */
	public static function get_count_terms() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAX_COUNT,
				'hide_empty' => false,
				'meta_key'   => self::TERM_NUMBER,
				'orderby'    => 'meta_value_num',
				'order'      => 'ASC',
			)
		);

		return is_wp_error( $terms ) ? array() : $terms;
	}
}
