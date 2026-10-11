(function ($) {
	'use strict';

	var cfg = window.CZListsAdmin || {};
	var LANGS = ['en', 'sa', 'pi', 'bo', 'zh', 'ja'];
	var ITEM_LANGS = ['it'].concat(LANGS);
	var labels = { terms: $.extend({}, cfg.labels && cfg.labels.terms), posts: $.extend({}, cfg.labels && cfg.labels.posts) };

	var $field, $root, state;

	function emptyItem() {
		return { pos: '', it: '', en: '', sa: '', pi: '', bo: '', zh: '', ja: '', lemma: 0, list: 0, level: 0, heading: false };
	}

	function emptyVariant() {
		return { intro: '', items: [emptyItem()] };
	}

	function emptyAlias() {
		return { it: '', en: '', sa: '', pi: '', bo: '', zh: '', ja: '' };
	}

	function langLabel(code) {
		return (cfg.langs && cfg.langs[code]) || code;
	}

	// Ogni modifica aggiorna subito il campo nascosto: l'editor a blocchi
	// salva i meta box serializzando il form, senza passare da un submit.
	function sync() {
		$field.val(JSON.stringify(state));
	}

	function textInput(obj, key, placeholder, extraClass) {
		return $('<input type="text">')
			.addClass(extraClass || '')
			.attr('placeholder', placeholder)
			.val(obj[key] || '')
			.on('input', function () {
				obj[key] = this.value;
				sync();
			});
	}

	function langFields(obj, langs) {
		var $wrap = $('<div class="cz-lists-langs">');
		langs.forEach(function (lang) {
			$wrap.append(
				$('<label class="cz-lists-lang">').append(
					$('<span>').text(langLabel(lang)),
					textInput(obj, lang, langLabel(lang), 'cz-lists-lang-' + lang)
				)
			);
		});
		return $wrap;
	}

	/**
	 * Selettore con ricerca per un lemma (post_tag) o una lista (cz_list).
	 * type: 'terms' | 'posts'
	 */
	function picker(obj, key, type, placeholder) {
		var $wrap = $('<div class="cz-lists-picker">');
		var $chip = $('<span class="cz-lists-chip">');
		var $input = $('<input type="search">').attr('placeholder', placeholder);
		var $results = $('<ul class="cz-lists-results">').hide();
		var timer = null;

		function showChip() {
			var id = obj[key];
			if (id) {
				$chip.empty().append(
					$('<span>').text(labels[type][id] || '#' + id),
					$('<button type="button" class="cz-lists-chip-remove" aria-label="Rimuovi">×</button>').on('click', function () {
						obj[key] = 0;
						sync();
						showChip();
					})
				).show();
				$input.hide();
			} else {
				$chip.hide();
				$input.val('').show();
			}
		}

		function search(term) {
			var path = type === 'terms'
				? '/wp/v2/tags?per_page=10&_fields=id,name&search=' + encodeURIComponent(term)
				: '/wp/v2/cz-lists?per_page=10&status=publish,draft&_fields=id,title&search=' + encodeURIComponent(term);

			wp.apiFetch({ path: path }).then(function (rows) {
				$results.empty();
				rows.forEach(function (row) {
					if (type === 'posts' && row.id === cfg.postId) {
						return;
					}
					var name = type === 'terms' ? row.name : $('<div>').html(row.title.rendered).text();
					$('<li>').text(name).on('mousedown', function (e) {
						e.preventDefault();
						labels[type][row.id] = name;
						obj[key] = row.id;
						sync();
						$results.hide();
						showChip();
					}).appendTo($results);
				});
				$results.toggle(rows.length > 0);
			}).catch(function () {
				$results.hide();
			});
		}

		$input
			.on('input', function () {
				var term = this.value.trim();
				clearTimeout(timer);
				if (term.length < 2) {
					$results.hide();
					return;
				}
				timer = setTimeout(function () { search(term); }, 250);
			})
			.on('blur', function () {
				$results.hide();
			})
			.on('keydown', function (e) {
				// Invio nella ricerca non deve inviare il form del post.
				if (e.key === 'Enter') {
					e.preventDefault();
				}
			});

		$wrap.append($chip, $input, $results);
		showChip();
		return $wrap;
	}

	function removeButton(label, onClick) {
		return $('<button type="button" class="button-link cz-lists-remove">').text(label).on('click', onClick);
	}

	function levelSelect(item, $item) {
		var $select = $('<select class="cz-lists-level" title="Livello di annidamento">');
		[0, 1, 2, 3].forEach(function (l) {
			$('<option>').val(l).text((l ? new Array(l + 1).join('↳') + ' ' : '') + 'Livello ' + (l + 1)).appendTo($select);
		});
		return $select.val(item.level || 0).on('change', function () {
			item.level = parseInt(this.value, 10) || 0;
			$item.attr('data-level', item.level);
			sync();
		});
	}

	function headingToggle(item, $item) {
		var $input = $('<input type="checkbox">').prop('checked', !!item.heading).on('change', function () {
			item.heading = this.checked;
			$item.toggleClass('is-heading', item.heading);
			sync();
		});
		return $('<label class="cz-lists-heading-toggle" title="Intestazione di sezione: solo testo, senza numero">').append($input, ' Intestazione');
	}

	function renderItem(variant, item, index) {
		var $item = $('<li class="cz-lists-item">').attr('data-level', item.level || 0).toggleClass('is-heading', !!item.heading);
		var $head = $('<div class="cz-lists-item-head">').append(
			$('<span class="cz-lists-handle dashicons dashicons-menu" title="Trascina per riordinare">'),
			textInput(item, 'pos', String(index + 1), 'cz-lists-pos').attr('title', 'Posizione: vuoto = automatica, oppure es. "1–4"'),
			textInput(item, 'it', 'Elemento in italiano', 'cz-lists-item-it'),
			levelSelect(item, $item),
			headingToggle(item, $item),
			removeButton('Rimuovi', function () {
				variant.items.splice(variant.items.indexOf(item), 1);
				sync();
				render();
			})
		);

		var $links = $('<div class="cz-lists-links">').append(
			$('<label>').append($('<span>').text('Lemma'), picker(item, 'lemma', 'terms', 'Cerca un lemma…')),
			$('<label>').append($('<span>').text('Rimanda a lista'), picker(item, 'list', 'posts', 'Cerca una lista…'))
		);

		return $item.data('item', item).append($head, langFields(item, LANGS), $links);
	}

	function renderVariant(variant, vIndex) {
		var $variant = $('<div class="cz-lists-variant">');
		var title = state.variants.length > 1 ? 'Variante ' + (vIndex + 1) : 'Elementi';
		var $head = $('<div class="cz-lists-variant-head">').append($('<h4>').text(title));

		if (state.variants.length > 1) {
			$head.append(removeButton('Rimuovi variante', function () {
				state.variants.splice(vIndex, 1);
				sync();
				render();
			}));
		}

		var $intro = $('<label class="cz-lists-intro">').append(
			$('<span>').text('Introduzione (facoltativa)'),
			textInput(variant, 'intro', 'es. "Contemplazione di:"', 'widefat')
		);

		var $items = $('<ol class="cz-lists-items">');
		variant.items.forEach(function (item, i) {
			$items.append(renderItem(variant, item, i));
		});

		$items.sortable({
			handle: '.cz-lists-handle',
			axis: 'y',
			update: function () {
				variant.items = $items.children().map(function () { return $(this).data('item'); }).get();
				sync();
				// Ridisegna dopo che sortable ha finito, per aggiornare le posizioni automatiche.
				setTimeout(render, 0);
			}
		});

		var $add = $('<button type="button" class="button">').text('+ Aggiungi elemento').on('click', function () {
			variant.items.push(emptyItem());
			sync();
			render();
			$root.find('.cz-lists-variant').eq(vIndex).find('.cz-lists-item-it').last().trigger('focus');
		});

		return $variant.append($head, $intro, $items, $add);
	}

	function renderHeader() {
		var $box = $('<div class="cz-lists-section">').append($('<h4>').text('Nome della lista nelle altre lingue'));
		$box.append($('<p class="description">').text('Il nome italiano è il titolo del post.'));
		$box.append(langFields(state.names, LANGS));
		$box.append(
			$('<div class="cz-lists-links">').append(
				$('<label>').append($('<span>').text('Lemma della lista'), picker(state, 'lemma', 'terms', 'Cerca un lemma…'))
			)
		);
		return $box;
	}

	function renderAliases() {
		var $box = $('<div class="cz-lists-section">').append($('<h4>').text('Nomi alternativi'));
		$box.append($('<p class="description">').text('Altri nomi con cui la lista è conosciuta (es. "otto figli spirituali").'));

		state.aliases.forEach(function (alias, i) {
			$box.append(
				$('<div class="cz-lists-alias">').append(
					langFields(alias, ITEM_LANGS),
					removeButton('Rimuovi', function () {
						state.aliases.splice(i, 1);
						sync();
						render();
					})
				)
			);
		});

		$box.append($('<button type="button" class="button">').text('+ Aggiungi nome alternativo').on('click', function () {
			state.aliases.push(emptyAlias());
			sync();
			render();
		}));
		return $box;
	}

	function render() {
		$root.empty().append(renderHeader());

		var $variants = $('<div class="cz-lists-section">');
		state.variants.forEach(function (variant, i) {
			$variants.append(renderVariant(variant, i));
		});
		$variants.append($('<button type="button" class="button">').text('+ Aggiungi variante alternativa').on('click', function () {
			state.variants.push(emptyVariant());
			sync();
			render();
		}));

		$root.append($variants, renderAliases());
	}

	$(function () {
		$field = $('#cz_list_data');
		$root = $('#cz-lists-editor');
		if (!$field.length || !$root.length) {
			return;
		}

		try {
			state = JSON.parse($field.val());
		} catch (e) {
			state = null;
		}
		state = $.extend({ names: {}, lemma: 0, aliases: [], variants: [] }, state || {});
		if (!state.variants.length) {
			state.variants.push(emptyVariant());
		}
		state.variants.forEach(function (v) {
			if (!v.items.length) {
				v.items.push(emptyItem());
			}
		});

		render();
		sync();
	});
})(jQuery);
