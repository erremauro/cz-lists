<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CZ_Lists_Frontend {
	/**
	 * Sigle delle lingue come nel Princeton Dictionary of Buddhism.
	 * L'inglese resta nei dati come riferimento ma non viene mostrato.
	 */
	const LANG_ABBR = array(
		'sa' => array( 'S.', 'Sanscrito', 'sa-Latn' ),
		'pi' => array( 'P.', 'Pāli', 'pi-Latn' ),
		'bo' => array( 'T.', 'Tibetano', 'bo-Latn' ),
		'zh' => array( 'C.', 'Cinese', 'zh' ),
		'ja' => array( 'J.', 'Giapponese', 'ja' ),
	);

	/**
	 * true quando il tema ha già stampato i nomi nell'header (cz_lists_the_names()).
	 *
	 * @var bool
	 */
	private static $names_printed = false;

	public function __construct() {
		// Dopo wpautop (10) e do_shortcode (11), così il markup generato non viene toccato.
		add_filter( 'the_content', array( $this, 'filter_content' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'pre_get_posts', array( $this, 'index_query' ) );
	}

	/**
	 * Archivio e pagine per numero mostrano tutte le liste, in ordine alfabetico:
	 * il raggruppamento per numero lo fa render_index().
	 *
	 * @param WP_Query $query
	 */
	public function index_query( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->is_post_type_archive( CZ_Lists_CPT::CPT ) || $query->is_tax( CZ_Lists_CPT::TAX_COUNT ) ) {
			$query->set( 'posts_per_page', -1 );
			$query->set( 'orderby', 'title' );
			$query->set( 'order', 'ASC' );
		}
	}

	public function enqueue() {
		if ( ! is_singular( CZ_Lists_CPT::CPT ) && ! is_post_type_archive( CZ_Lists_CPT::CPT ) && ! is_tax( CZ_Lists_CPT::TAX_COUNT ) && ! is_search() ) {
			return;
		}

		list( $css_url, $css_ver ) = cz_get_asset( CZ_LISTS_PATH, CZ_LISTS_URL, 'assets/css/frontend.css', CZ_LISTS_VERSION );
		wp_enqueue_style( 'cz-lists-frontend', $css_url, array(), $css_ver );
	}

	public function filter_content( $content ) {
		if ( ! is_singular( CZ_Lists_CPT::CPT ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id = get_the_ID();
		$data    = CZ_Lists_Data::get( $post_id );

		$html  = '<div class="cz-list">';
		if ( ! self::$names_printed ) {
			$html .= self::render_names( $data['names'], 'cz-list-names' );
		}
		$html .= $this->render_variants( $data['variants'] );
		$html .= '</div>';

		// Informazioni aggiuntive, separate dalla lista da un divisore (solo se ce ne sono).
		$extra  = $this->render_aliases( $data['aliases'] );
		$extra .= $this->render_lemma( $data['lemma'] );
		if ( '' !== trim( $content ) ) {
			$extra .= '<div class="cz-list-commentary">' . $content . '</div>';
		}
		$extra .= $this->render_referencing( $post_id );

		if ( '' !== $extra ) {
			$html .= '<div class="cz-list-extra">' . $extra . '</div>';
		}

		return $html;
	}

	/**
	 * Riga "S. … · T. … · C. …" con le lingue non vuote.
	 *
	 * @param array  $texts Valori indicizzati per codice lingua.
	 * @param string $class Classe CSS del contenitore.
	 */
	public static function render_names( $texts, $class ) {
		$parts = array();
		foreach ( self::LANG_ABBR as $code => list( $abbr, $label, $lang ) ) {
			if ( empty( $texts[ $code ] ) ) {
				continue;
			}
			$parts[] = sprintf(
				'<span class="cz-list-lang cz-list-lang--%1$s"><abbr title="%2$s">%3$s</abbr> <span lang="%4$s">%5$s</span></span>',
				esc_attr( $code ),
				esc_attr( $label ),
				esc_html( $abbr ),
				esc_attr( $lang ),
				esc_html( $texts[ $code ] )
			);
		}

		if ( ! $parts ) {
			return '';
		}

		return '<p class="' . esc_attr( $class ) . '">' . implode( '<span class="cz-list-sep" aria-hidden="true"> · </span>', $parts ) . '</p>';
	}

	/**
	 * Stampa i nomi della lista nelle altre lingue (da usare nell'header del template).
	 *
	 * @param int $post_id
	 */
	public static function the_names( $post_id = 0 ) {
		$post_id = $post_id ? $post_id : get_the_ID();
		$data    = CZ_Lists_Data::get( $post_id );

		self::$names_printed = true;
		echo self::render_names( $data['names'], 'cz-list-names' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escape in render_names().
	}

	/**
	 * Indice delle liste della query principale, raggruppate per numero di elementi.
	 * Sotto il venti un gruppo per numero, dal venti in su un gruppo per decina (come nel
	 * Princeton Dictionary). Con più di un gruppo mostra in testa l'indice a cifre.
	 */
	public static function render_index() {
		global $wp_query;

		$groups = array();
		foreach ( $wp_query->posts as $post ) {
			$terms  = get_the_terms( $post, CZ_Lists_CPT::TAX_COUNT );
			$term   = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0] : null;
			$number = $term ? (int) get_term_meta( $term->term_id, CZ_Lists_CPT::TERM_NUMBER, true ) : 0;
			$key    = self::group_key( $number );

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					// Solo i gruppi di un numero esatto hanno una pagina da linkare.
					'term'  => ( $key === $number ) ? $term : null,
					'posts' => array(),
				);
			}
			$groups[ $key ]['posts'][] = array(
				'post'   => $post,
				'number' => $number,
			);
		}
		ksort( $groups );

		if ( ! $groups ) {
			return '<p class="cz-list-index-empty">' . esc_html__( 'Nessuna lista trovata.', 'cz-lists' ) . '</p>';
		}

		$show_groups = count( $groups ) > 1;
		$html        = '';

		if ( $show_groups ) {
			$links = array();
			foreach ( array_keys( $groups ) as $key ) {
				$links[] = sprintf(
					'<li><a href="#%s" title="%s">%s</a></li>',
					esc_attr( self::group_anchor( $key ) ),
					esc_attr( self::group_label( $key ) ),
					esc_html( PHP_INT_MAX === $key ? '…' : (string) $key )
				);
			}
			$html .= '<nav class="cz-list-index-nav" id="cz-list-index-nav" aria-label="' . esc_attr__( 'Numero di elementi', 'cz-lists' ) . '"><ul>' . implode( '', $links ) . '</ul></nav>';
		}

		foreach ( $groups as $key => $group ) {
			// Dentro una decina: prima per numero, poi per titolo (la query è già in ordine di titolo).
			usort( $group['posts'], fn( $a, $b ) => $a['number'] <=> $b['number'] );

			$html .= '<section class="cz-list-index-group" id="' . esc_attr( self::group_anchor( $key ) ) . '">';
			if ( $show_groups ) {
				$heading = esc_html( self::group_label( $key ) );
				$heading = $group['term'] ? '<a href="' . esc_url( get_term_link( $group['term'] ) ) . '">' . $heading . '</a>' : $heading;
				$html   .= '<div class="cz-list-index-head"><h2>' . $heading . '</h2>';
				$html   .= '<a class="cz-list-index-top" href="#cz-list-index-nav">' . esc_html__( '↑ indice', 'cz-lists' ) . '</a></div>';
			}

			$html .= '<ul class="cz-list-index-items">';
			foreach ( $group['posts'] as $entry ) {
				$post  = $entry['post'];
				$data  = CZ_Lists_Data::get( $post->ID );
				$html .= '<li class="cz-list-index-item">';
				$html .= '<a class="cz-list-index-title" href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a>';
				$html .= self::render_names( $data['names'], 'cz-list-index-names' );
				$html .= '</li>';
			}
			$html .= '</ul></section>';
		}

		return '<div class="cz-list-index">' . $html . '</div>';
	}

	/**
	 * Gruppo dell'indice: il numero stesso sotto il venti, la decina dal venti in su.
	 * Le liste senza numero finiscono in fondo.
	 */
	private static function group_key( $number ) {
		if ( $number < 1 ) {
			return PHP_INT_MAX;
		}

		return $number < 20 ? $number : intdiv( $number, 10 ) * 10;
	}

	private static function group_label( $key ) {
		return PHP_INT_MAX === $key ? __( 'Altre', 'cz-lists' ) : CZ_Lists_CPT::number_word( $key );
	}

	private static function group_anchor( $key ) {
		return 'n-' . ( PHP_INT_MAX === $key ? 'altre' : $key );
	}

	private function render_variants( $variants ) {
		$variants = array_values(
			array_filter(
				$variants,
				fn( $v ) => $v['items'] || '' !== $v['intro']
			)
		);

		$html = '';
		foreach ( $variants as $index => $variant ) {
			if ( $index > 0 ) {
				$html .= '<p class="cz-list-or">' . esc_html__( 'oppure', 'cz-lists' ) . '</p>';
			}

			$html .= '<div class="cz-list-variant">';
			if ( '' !== $variant['intro'] ) {
				$html .= '<p class="cz-list-intro">' . esc_html( $variant['intro'] ) . '</p>';
			}

			// Numerazione automatica per livello: riparte dopo ogni intestazione e
			// sotto ogni nuovo elemento padre.
			$counters = array( 0, 0, 0, 0 );
			$html    .= '<ol class="cz-list-items">';
			foreach ( $variant['items'] as $item ) {
				if ( $item['heading'] ) {
					$counters = array( 0, 0, 0, 0 );
					$html    .= '<li class="cz-list-heading">' . esc_html( '' !== $item['it'] ? $item['it'] : $item['en'] ) . '</li>';
					continue;
				}
				$level = $item['level'];
				++$counters[ $level ];
				for ( $l = $level + 1; $l < 4; $l++ ) {
					$counters[ $l ] = 0;
				}
				$html .= $this->render_item( $item, $counters[ $level ] );
			}
			$html .= '</ol></div>';
		}

		return $html;
	}

	private function render_item( $item, $auto_pos ) {
		$pos   = '' !== $item['pos'] ? $item['pos'] : (string) $auto_pos;
		$label = '' !== $item['it'] ? $item['it'] : $item['en'];
		$link  = $this->lemma_link( $item['lemma'] );

		$html  = '<li class="cz-list-item cz-list-item--l' . (int) $item['level'] . '">';
		$html .= '<span class="cz-list-pos">' . esc_html( $pos ) . '</span>';
		$html .= '<div class="cz-list-body">';
		$html .= '<span class="cz-list-label">';
		$html .= $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label );
		$html .= '</span>';
		$html .= self::render_names( $item, 'cz-list-item-langs' );

		if ( $item['list'] && 'publish' === get_post_status( $item['list'] ) ) {
			$html .= sprintf(
				'<p class="cz-list-see">%s <a href="%s">%s</a></p>',
				esc_html__( 'Vedi la lista:', 'cz-lists' ),
				esc_url( get_permalink( $item['list'] ) ),
				esc_html( get_the_title( $item['list'] ) )
			);
		}

		$html .= '</div></li>';

		return $html;
	}

	private function render_aliases( $aliases ) {
		if ( ! $aliases ) {
			return '';
		}

		$parts = array();
		foreach ( $aliases as $alias ) {
			$name  = '' !== $alias['it'] ? '<strong>' . esc_html( $alias['it'] ) . '</strong>' : '';
			$langs = wp_strip_all_tags( self::render_names( $alias, '' ) );
			if ( '' !== $langs ) {
				$name .= ( '' !== $name ? ' ' : '' ) . '<span class="cz-list-alias-langs">(' . esc_html( trim( $langs ) ) . ')</span>';
			}
			$parts[] = $name;
		}

		return '<p class="cz-list-aliases"><span class="cz-list-meta-label">' . esc_html__( 'Nota anche come', 'cz-lists' ) . '</span> ' . implode( '; ', $parts ) . '</p>';
	}

	private function render_lemma( $term_id ) {
		$link = $this->lemma_link( $term_id );
		if ( ! $link ) {
			return '';
		}

		return sprintf(
			'<p class="cz-list-lemma"><span class="cz-list-meta-label">%s</span> <a href="%s">%s</a></p>',
			esc_html__( 'Dal Dizionario', 'cz-lists' ),
			esc_url( $link ),
			esc_html( get_term( $term_id, 'post_tag' )->name )
		);
	}

	private function render_referencing( $post_id ) {
		$posts = CZ_Lists_Data::lists_referencing( $post_id );
		if ( ! $posts ) {
			return '';
		}

		$links = array_map(
			fn( $p ) => '<a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a>',
			$posts
		);

		return '<p class="cz-list-referencing"><span class="cz-list-meta-label">' . esc_html__( 'Compare anche in', 'cz-lists' ) . '</span> ' . implode( ', ', $links ) . '</p>';
	}

	private function lemma_link( $term_id ) {
		if ( ! $term_id ) {
			return '';
		}

		$link = get_term_link( (int) $term_id, 'post_tag' );

		return is_wp_error( $link ) ? '' : $link;
	}
}

if ( ! function_exists( 'cz_lists_the_names' ) ) {
	/**
	 * Template tag: nomi della lista nelle altre lingue.
	 *
	 * @param int $post_id
	 */
	function cz_lists_the_names( $post_id = 0 ) {
		CZ_Lists_Frontend::the_names( $post_id );
	}
}

if ( ! function_exists( 'cz_lists_the_index' ) ) {
	/**
	 * Template tag: indice delle liste della query principale, raggruppato per numero.
	 */
	function cz_lists_the_index() {
		echo CZ_Lists_Frontend::render_index(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escape in render_index().
	}
}
