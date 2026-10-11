<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CZ_Lists_Admin {
	const NONCE_ACTION = 'cz_lists_save';
	const NONCE_FIELD  = 'cz_lists_nonce';
	const FIELD_DATA   = 'cz_list_data';
	const FIELD_COUNT  = 'cz_list_count';
	const META_MANUAL  = '_cz_list_count_manual';

	public function __construct() {
		add_action( 'add_meta_boxes_' . CZ_Lists_CPT::CPT, array( $this, 'register_metaboxes' ) );
		add_action( 'save_post_' . CZ_Lists_CPT::CPT, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function register_metaboxes() {
		add_meta_box(
			'cz_lists_data',
			__( 'Elementi della lista', 'cz-lists' ),
			array( $this, 'render_data_metabox' ),
			CZ_Lists_CPT::CPT,
			'normal',
			'high'
		);

		add_meta_box(
			'cz_lists_count',
			__( 'Numero di elementi', 'cz-lists' ),
			array( $this, 'render_count_metabox' ),
			CZ_Lists_CPT::CPT,
			'side',
			'default'
		);
	}

	public function enqueue( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || CZ_Lists_CPT::CPT !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		list( $js_url, $js_ver )   = cz_get_asset( CZ_LISTS_PATH, CZ_LISTS_URL, 'assets/js/admin.js', CZ_LISTS_VERSION );
		list( $css_url, $css_ver ) = cz_get_asset( CZ_LISTS_PATH, CZ_LISTS_URL, 'assets/css/admin.css', CZ_LISTS_VERSION );

		wp_enqueue_style( 'cz-lists-admin', $css_url, array(), $css_ver );
		wp_enqueue_script( 'cz-lists-admin', $js_url, array( 'jquery', 'jquery-ui-sortable', 'wp-api-fetch' ), $js_ver, true );

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$data    = $post_id ? CZ_Lists_Data::get( $post_id ) : CZ_Lists_Data::empty_data();

		wp_localize_script(
			'cz-lists-admin',
			'CZListsAdmin',
			array(
				'postId' => $post_id,
				'labels' => $this->collect_labels( $data ),
				'langs'  => array(
					'it' => __( 'Italiano', 'cz-lists' ),
					'en' => __( 'Inglese', 'cz-lists' ),
					'sa' => __( 'Sanscrito', 'cz-lists' ),
					'pi' => __( 'Pāli', 'cz-lists' ),
					'bo' => __( 'Tibetano', 'cz-lists' ),
					'zh' => __( 'Cinese', 'cz-lists' ),
					'ja' => __( 'Giapponese', 'cz-lists' ),
				),
			)
		);
	}

	/**
	 * Nomi di lemmi e liste già collegati, così il JS può mostrarli senza richieste REST.
	 *
	 * @param array $data
	 * @return array{terms: array<int,string>, posts: array<int,string>}
	 */
	private function collect_labels( $data ) {
		$term_ids = array( $data['lemma'] );
		$post_ids = array();
		foreach ( $data['variants'] as $variant ) {
			foreach ( $variant['items'] as $item ) {
				$term_ids[] = $item['lemma'];
				$post_ids[] = $item['list'];
			}
		}

		$labels = array(
			'terms' => new stdClass(),
			'posts' => new stdClass(),
		);

		foreach ( array_filter( array_unique( $term_ids ) ) as $id ) {
			$term = get_term( $id, 'post_tag' );
			if ( $term && ! is_wp_error( $term ) ) {
				$labels['terms']->{$id} = $term->name;
			}
		}
		foreach ( array_filter( array_unique( $post_ids ) ) as $id ) {
			$title = get_the_title( $id );
			if ( '' !== $title ) {
				$labels['posts']->{$id} = $title;
			}
		}

		return $labels;
	}

	public function render_data_metabox( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		$data = CZ_Lists_Data::get( $post->ID );

		// Il JS costruisce l'interfaccia e tiene aggiornato il campo nascosto a ogni modifica:
		// l'editor a blocchi salva i meta box serializzando il form, senza un vero submit.
		printf(
			'<input type="hidden" name="%1$s" id="%1$s" value="%2$s" />',
			esc_attr( self::FIELD_DATA ),
			esc_attr( wp_json_encode( $data ) )
		);
		echo '<div id="cz-lists-editor" class="cz-lists-editor"><p>' . esc_html__( 'Caricamento…', 'cz-lists' ) . '</p></div>';
	}

	public function render_count_metabox( $post ) {
		// Se il numero è stato assegnato in automatico la select resta su "Automatico",
		// così continua ad aggiornarsi quando cambiano gli elementi.
		$current = 0;
		if ( get_post_meta( $post->ID, self::META_MANUAL, true ) ) {
			$terms   = wp_get_object_terms( $post->ID, CZ_Lists_CPT::TAX_COUNT, array( 'fields' => 'ids' ) );
			$current = ( ! is_wp_error( $terms ) && $terms ) ? (int) $terms[0] : 0;
		}

		echo '<select name="' . esc_attr( self::FIELD_COUNT ) . '" class="widefat">';
		echo '<option value="0">' . esc_html__( 'Automatico (dagli elementi)', 'cz-lists' ) . '</option>';
		foreach ( CZ_Lists_CPT::get_count_terms() as $term ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $term->term_id,
				selected( $current, $term->term_id, false ),
				esc_html( $term->name )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'In automatico si conta la prima variante; una posizione come "1–4" vale fino a 4.', 'cz-lists' ) . '</p>';
	}

	public function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST[ self::FIELD_DATA ] ) ) {
			// Il JSON viene sanificato campo per campo in CZ_Lists_Data::sanitize().
			$decoded = json_decode( wp_unslash( $_POST[ self::FIELD_DATA ] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_array( $decoded ) ) {
				CZ_Lists_Data::save( $post_id, $decoded );
			}
		}

		$term_id = isset( $_POST[ self::FIELD_COUNT ] ) ? absint( $_POST[ self::FIELD_COUNT ] ) : 0;
		if ( $term_id ) {
			update_post_meta( $post_id, self::META_MANUAL, 1 );
		} else {
			delete_post_meta( $post_id, self::META_MANUAL );
			$term_id = CZ_Lists_CPT::get_or_create_count_term( CZ_Lists_Data::derive_count( CZ_Lists_Data::get( $post_id ) ) );
		}
		wp_set_object_terms( $post_id, $term_id ? array( $term_id ) : array(), CZ_Lists_CPT::TAX_COUNT );
	}
}
