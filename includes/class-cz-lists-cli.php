<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Comandi WP-CLI delle liste.
 *
 * Esportazione e importazione usano un JSON in cui lemmi e liste collegate sono indicati
 * per slug, non per ID: gli ID cambiano fra locale e produzione, gli slug no.
 */
class CZ_Lists_CLI {
	const FORMAT_VERSION = 1;

	/**
	 * Esporta le liste in JSON.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : File di destinazione. Senza, il JSON viene scritto sullo standard output.
	 *
	 * [--status=<status>]
	 * : Stati da esportare, separati da virgola.
	 * ---
	 * default: publish,draft,pending,private,future
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp cz-lists export --file=liste.json
	 *     wp cz-lists export --status=publish > liste.json
	 *
	 * @param array $args
	 * @param array $assoc
	 */
	public function export( $args, $assoc ) {
		$posts = get_posts(
			array(
				'post_type'      => CZ_Lists_CPT::CPT,
				'post_status'    => explode( ',', $assoc['status'] ),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$lists = array();
		foreach ( $posts as $post ) {
			$data = CZ_Lists_Data::get( $post->ID );

			$data['lemma'] = self::term_slug( $data['lemma'] );
			foreach ( $data['variants'] as $v => $variant ) {
				foreach ( $variant['items'] as $i => $item ) {
					$data['variants'][ $v ]['items'][ $i ]['lemma'] = self::term_slug( $item['lemma'] );
					$data['variants'][ $v ]['items'][ $i ]['list']  = $item['list'] ? get_post_field( 'post_name', $item['list'] ) : '';
				}
			}

			$terms  = wp_get_object_terms( $post->ID, CZ_Lists_CPT::TAX_COUNT, array( 'fields' => 'ids' ) );
			$number = ( ! is_wp_error( $terms ) && $terms ) ? (int) get_term_meta( $terms[0], CZ_Lists_CPT::TERM_NUMBER, true ) : 0;

			$lists[] = array(
				'slug'         => $post->post_name,
				'title'        => $post->post_title,
				'status'       => $post->post_status,
				'content'      => $post->post_content,
				'excerpt'      => $post->post_excerpt,
				'count'        => $number,
				'count_manual' => (bool) get_post_meta( $post->ID, CZ_Lists_Admin::META_MANUAL, true ),
				'data'         => $data,
			);
		}

		$json = wp_json_encode(
			array(
				'format'   => self::FORMAT_VERSION,
				'exported' => gmdate( 'c' ),
				'site'     => home_url(),
				'lists'    => $lists,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		if ( empty( $assoc['file'] ) ) {
			WP_CLI::line( $json );
			return;
		}

		if ( false === file_put_contents( $assoc['file'], $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			WP_CLI::error( "Impossibile scrivere {$assoc['file']}." );
		}
		WP_CLI::success( sprintf( 'Esportate %d liste in %s.', count( $lists ), $assoc['file'] ) );
	}

	/**
	 * Importa le liste da un JSON creato con `wp cz-lists export`.
	 *
	 * Ogni lista è riconosciuta dallo slug: se esiste viene aggiornata, altrimenti creata.
	 * I lemmi vengono cercati per slug; quelli che non esistono lasciano l'elemento
	 * scollegato. Alla fine gira il collegamento automatico sulle liste importate.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : File JSON da importare.
	 *
	 * [--dry-run]
	 * : Mostra cosa verrebbe fatto senza scrivere nulla.
	 *
	 * [--no-link]
	 * : Non eseguire il collegamento automatico ai lemmi dopo l'importazione.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cz-lists import liste.json --dry-run
	 *     wp cz-lists import liste.json
	 *
	 * @param array $args
	 * @param array $assoc
	 */
	public function import( $args, $assoc ) {
		$dry_run = ! empty( $assoc['dry-run'] );
		$raw     = file_get_contents( $args[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$payload = false === $raw ? null : json_decode( $raw, true );

		if ( ! is_array( $payload ) || ! isset( $payload['lists'] ) || ! is_array( $payload['lists'] ) ) {
			WP_CLI::error( 'File non valido: manca l\'elenco "lists".' );
		}
		if ( (int) ( $payload['format'] ?? 0 ) !== self::FORMAT_VERSION ) {
			WP_CLI::error( 'Versione del formato non supportata.' );
		}

		// Primo passaggio: crea o aggiorna i post, così nel secondo tutti i rimandi
		// fra liste trovano il loro destinatario.
		$ids     = array();
		$created = 0;
		$updated = 0;
		foreach ( $payload['lists'] as $list ) {
			$slug = sanitize_title( $list['slug'] ?? '' );
			if ( '' === $slug ) {
				WP_CLI::warning( 'Lista senza slug ignorata: ' . ( $list['title'] ?? '?' ) );
				continue;
			}

			$existing = get_page_by_path( $slug, OBJECT, CZ_Lists_CPT::CPT );
			$existing ? ++$updated : ++$created;

			if ( $dry_run ) {
				WP_CLI::log( ( $existing ? 'aggiorna ' : 'crea     ' ) . $slug );
				$ids[ $slug ] = $existing ? $existing->ID : -1; // -1: verrebbe creata
				continue;
			}

			$postarr = array(
				'post_type'    => CZ_Lists_CPT::CPT,
				'post_name'    => $slug,
				'post_title'   => $list['title'] ?? '',
				'post_status'  => $list['status'] ?? 'draft',
				'post_content' => $list['content'] ?? '',
				'post_excerpt' => $list['excerpt'] ?? '',
			);
			if ( $existing ) {
				$postarr['ID'] = $existing->ID;
			}

			$id = wp_insert_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $id ) ) {
				WP_CLI::warning( "$slug: " . $id->get_error_message() );
				continue;
			}
			$ids[ $slug ] = $id;
		}

		// Secondo passaggio: dati strutturati con slug → ID, e numero di elementi.
		$missing = array();
		foreach ( $payload['lists'] as $list ) {
			$slug = sanitize_title( $list['slug'] ?? '' );
			if ( empty( $ids[ $slug ] ) || $dry_run ) {
				if ( $dry_run ) {
					self::resolve_data( $list['data'] ?? array(), $ids, $missing );
				}
				continue;
			}

			$post_id = $ids[ $slug ];
			CZ_Lists_Data::save( $post_id, self::resolve_data( $list['data'] ?? array(), $ids, $missing ) );

			$number = (int) ( $list['count'] ?? 0 );
			$term   = $number ? CZ_Lists_CPT::get_or_create_count_term( $number ) : 0;
			wp_set_object_terms( $post_id, $term ? array( $term ) : array(), CZ_Lists_CPT::TAX_COUNT );

			if ( ! empty( $list['count_manual'] ) ) {
				update_post_meta( $post_id, CZ_Lists_Admin::META_MANUAL, 1 );
			} else {
				delete_post_meta( $post_id, CZ_Lists_Admin::META_MANUAL );
			}
		}

		if ( $missing ) {
			ksort( $missing );
			WP_CLI::warning( sprintf( '%d riferimenti non trovati (gli elementi restano scollegati):', count( $missing ) ) );
			foreach ( $missing as $ref => $n ) {
				WP_CLI::log( "  $ref ($n)" );
			}
		}

		$verb = $dry_run ? 'Verrebbero create' : 'Create';
		$msg  = sprintf( '%s %d liste, aggiornate %d.', $verb, $created, $updated );

		if ( ! $dry_run && empty( $assoc['no-link'] ) ) {
			$linked = CZ_Lists_Linker::link_lists( array_values( $ids ) );
			$msg   .= sprintf( ' Collegamento automatico: %d elementi in %d liste.', $linked['items'], $linked['lists'] );
		}

		WP_CLI::success( $msg );
	}

	/**
	 * Collega ai lemmi gli elementi delle liste ancora scollegati.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cz-lists link
	 *
	 * @param array $args
	 * @param array $assoc
	 */
	public function link( $args, $assoc ) {
		$ids = get_posts(
			array(
				'post_type'      => CZ_Lists_CPT::CPT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$result = CZ_Lists_Linker::link_lists( $ids );
		WP_CLI::success( sprintf( 'Collegati %d elementi in %d liste.', $result['items'], $result['lists'] ) );
	}

	/**
	 * Converte gli slug di lemmi e liste negli ID di questo sito.
	 *
	 * @param array              $data
	 * @param array<string,int>  $list_ids Liste appena importate, slug => ID.
	 * @param array<string,int>  $missing  Riferimenti non trovati (accumulati), "tipo:slug" => occorrenze.
	 * @return array
	 */
	private static function resolve_data( $data, $list_ids, &$missing ) {
		$data          = is_array( $data ) ? $data : array();
		$data['lemma'] = self::resolve_term( $data['lemma'] ?? '', $missing );

		$variants = isset( $data['variants'] ) && is_array( $data['variants'] ) ? $data['variants'] : array();
		foreach ( $variants as $v => $variant ) {
			$items = isset( $variant['items'] ) && is_array( $variant['items'] ) ? $variant['items'] : array();
			foreach ( $items as $i => $item ) {
				$items[ $i ]['lemma'] = self::resolve_term( $item['lemma'] ?? '', $missing );
				$items[ $i ]['list']  = self::resolve_list( $item['list'] ?? '', $list_ids, $missing );
			}
			$variants[ $v ]['items'] = $items;
		}
		$data['variants'] = $variants;

		return $data;
	}

	private static function resolve_term( $slug, &$missing ) {
		if ( ! is_string( $slug ) || '' === $slug ) {
			return 0;
		}

		$term = get_term_by( 'slug', $slug, 'post_tag' );
		if ( $term ) {
			return (int) $term->term_id;
		}

		$missing[ "lemma:$slug" ] = ( $missing[ "lemma:$slug" ] ?? 0 ) + 1;
		return 0;
	}

	private static function resolve_list( $slug, $list_ids, &$missing ) {
		if ( ! is_string( $slug ) || '' === $slug ) {
			return 0;
		}
		if ( ! empty( $list_ids[ $slug ] ) ) {
			return (int) $list_ids[ $slug ];
		}

		$post = get_page_by_path( $slug, OBJECT, CZ_Lists_CPT::CPT );
		if ( $post ) {
			return (int) $post->ID;
		}

		$missing[ "lista:$slug" ] = ( $missing[ "lista:$slug" ] ?? 0 ) + 1;
		return 0;
	}

	private static function term_slug( $term_id ) {
		if ( ! $term_id ) {
			return '';
		}
		$term = get_term( $term_id, 'post_tag' );

		return ( $term && ! is_wp_error( $term ) ) ? $term->slug : '';
	}
}
