( function ( $ ) {
	'use strict';

	function productOptionsHtml( selectedId ) {
		var placeholder = tavoxPromotions.labels && tavoxPromotions.labels.placeholder
			? tavoxPromotions.labels.placeholder
			: 'Selecciona un producto';

		return [
			'<option value="">' + placeholder + '</option>',
			( tavoxPromotions.products || [] )
				.map( function ( product ) {
					var selected = Number( selectedId ) === Number( product.id ) ? ' selected' : '';
					var suffix = product.manages_stock && ! product.promotion_available ? ' - Sin disponibilidad' : '';
					return '<option value="' + product.id + '"' + selected + '>' + product.name + suffix + '</option>';
				} )
				.join( '' ),
		].join( '' );
	}

	function getProductById( productId ) {
		var parsedId = Number( productId );

		return ( tavoxPromotions.products || [] ).find( function ( product ) {
			return Number( product.id ) === parsedId;
		} ) || null;
	}

	function clampFocusValue( value ) {
		var number = Number( value );

		if ( ! Number.isFinite( number ) ) {
			return 50;
		}

		return Math.max( 0, Math.min( 100, Math.round( number ) ) );
	}

	function brandScopeOptionsHtml( selectedScope ) {
		var labels = tavoxPromotions.labels || {};
		var currentScope = String( selectedScope || 'zona_b' );

		return [
			'<option value="zona_b"' + ( currentScope === 'zona_b' ? ' selected' : '' ) + '>' + ( labels.brandZonaB || 'Zona B' ) + '</option>',
			'<option value="isola"' + ( currentScope === 'isola' ? ' selected' : '' ) + '>' + ( labels.brandIsola || 'ISOLA' ) + '</option>',
			'<option value="common"' + ( currentScope === 'common' ? ' selected' : '' ) + '>' + ( labels.brandCommon || 'Común' ) + '</option>',
		].join( '' );
	}

	function getBrandScopeLabel( scope ) {
		var labels = tavoxPromotions.labels || {};
		var currentScope = String( scope || 'zona_b' );

		if ( currentScope === 'isola' ) {
			return labels.brandIsola || 'ISOLA';
		}

		if ( currentScope === 'common' ) {
			return labels.brandCommon || 'Común';
		}

		return labels.brandZonaB || 'Zona B';
	}

	function buildField( label, $control, spanClass, helpText ) {
		var $field = $( '<div />', { class: 'tavox-field ' + spanClass } );
		$field.append( $( '<label />' ).text( label ) );
		$field.append( $control );

		if ( helpText ) {
			$field.append( $( '<p />', { class: 'description', text: helpText } ) );
		}

		return $field;
	}

	function buildToggleField( label, checked, spanClass, helpText ) {
		var $field = $( '<div />', { class: 'tavox-field tavox-field--toggle ' + spanClass } );
		var $toggle = $( '<label />', { class: 'tavox-inline-toggle' } );
		var $checkbox = $( '<input />', { type: 'checkbox', class: 'tavox-promo-show-in-search' } ).prop( 'checked', !! checked );

		$toggle.append( $checkbox );
		$toggle.append( $( '<span />', { class: 'tavox-inline-toggle-text', text: label } ) );
		$field.append( $toggle );

		if ( helpText ) {
			$field.append( $( '<p />', { class: 'description', text: helpText } ) );
		}

		return $field;
	}

	function getEffectivePreviewImage( $field ) {
		var overrideUrl = String( $field.find( '.tavox-promo-image' ).val() || '' ).trim();
		var productId = parseInt( $field.closest( '.tavox-promo-card' ).find( '.tavox-promo-product' ).val(), 10 );
		var product = getProductById( productId );
		var productUrl = product && product.image ? String( product.image ).trim() : '';

		if ( overrideUrl ) {
			return {
				url: overrideUrl,
				source: 'override',
			};
		}

		if ( productUrl ) {
			return {
				url: productUrl,
				source: 'product',
			};
		}

		return {
			url: '',
			source: '',
		};
	}

	function updateImagePreview( $field ) {
		var labels = tavoxPromotions.labels || {};
		var $preview = $field.find( '.tavox-promo-image-preview' );
		var $previewImage = $field.find( '.tavox-promo-image-preview-img' );
		var $button = $field.find( '.tavox-promo-image-select' );
		var $source = $field.find( '.tavox-promo-image-source' );
		var effectiveImage = getEffectivePreviewImage( $field );
		var hasImage = !! effectiveImage.url;
		var focusX = clampFocusValue( $field.find( '.tavox-promo-image-focus-x' ).val() );
		var focusY = clampFocusValue( $field.find( '.tavox-promo-image-focus-y' ).val() );

		$preview.toggleClass( 'has-image', hasImage );
		$preview.toggleClass( 'is-product-image', effectiveImage.source === 'product' );
		$previewImage.attr( 'src', hasImage ? effectiveImage.url : '' );
		$previewImage.css( 'object-position', focusX + '% ' + focusY + '%' );
		$button.text( String( $field.find( '.tavox-promo-image' ).val() || '' ).trim() ? ( labels.imageReplace || 'Cambiar imagen' ) : ( labels.imageSelect || 'Elegir o subir imagen' ) );

		if ( ! hasImage ) {
			$source.text( labels.imagePreviewEmpty || 'Selecciona un producto con imagen o sube una imagen para ver el encuadre.' );
			return;
		}

		$source.text(
			effectiveImage.source === 'override'
				? ( labels.imagePreviewOverride || 'Vista previa usando la imagen override.' )
				: ( labels.imagePreviewProduct || 'Vista previa usando la imagen del producto.' )
		);
	}

	function updateImageFocus( $field, x, y ) {
		$field.find( '.tavox-promo-image-focus-x' ).val( clampFocusValue( x ) );
		$field.find( '.tavox-promo-image-focus-y' ).val( clampFocusValue( y ) );
		updateImagePreview( $field );
	}

	function openImageMediaFrame( $field ) {
		var labels = tavoxPromotions.labels || {};
		var $input = $field.find( '.tavox-promo-image' );
		var frame = wp.media( {
			title: labels.imageSelect || 'Elegir o subir imagen',
			button: {
				text: labels.imageSelect || 'Usar imagen',
			},
			library: {
				type: 'image',
			},
			multiple: false,
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			var imageUrl = attachment.url || '';
			$input.val( imageUrl ).trigger( 'change' );
		} );

		frame.open();
	}

	function buildImageField( promo ) {
		var labels = tavoxPromotions.labels || {};
		var defaults = tavoxPromotions.defaults || {};
		var imageValue = promo.image || '';
		var focusX = clampFocusValue( typeof promo.image_focus_x !== 'undefined' ? promo.image_focus_x : defaults.imageFocusX );
		var focusY = clampFocusValue( typeof promo.image_focus_y !== 'undefined' ? promo.image_focus_y : defaults.imageFocusY );
		var $input = $( '<input />', {
			type: 'url',
			class: 'tavox-promo-image regular-text',
			value: imageValue,
			placeholder: 'https://...',
		} );
		var $field = $( '<div />', { class: 'tavox-field tavox-span-4 tavox-promo-image-field' } );
		var $preview = $( '<div />', { class: 'tavox-promo-image-preview' } );
		var $previewImage = $( '<img />', {
			class: 'tavox-promo-image-preview-img',
			alt: labels.imagePreview || 'Vista previa de la imagen promocional',
		} );
		var $source = $( '<div />', { class: 'tavox-promo-image-source' } );
		var $focus = $( '<div />', { class: 'tavox-promo-image-focus' } );
		var $focusX = $( '<input />', {
			type: 'range',
			class: 'tavox-promo-image-focus-x',
			min: 0,
			max: 100,
			step: 1,
			value: focusX,
		} );
		var $focusY = $( '<input />', {
			type: 'range',
			class: 'tavox-promo-image-focus-y',
			min: 0,
			max: 100,
			step: 1,
			value: focusY,
		} );
		var $actions = $( '<div />', { class: 'tavox-promo-image-actions' } );
		var $select = $( '<button />', {
			type: 'button',
			class: 'button button-secondary tavox-promo-image-select',
			text: imageValue ? ( labels.imageReplace || 'Cambiar imagen' ) : ( labels.imageSelect || 'Elegir o subir imagen' ),
		} );
		var $remove = $( '<button />', {
			type: 'button',
			class: 'button tavox-promo-image-remove',
			text: labels.imageRemove || 'Quitar imagen',
		} );

		$preview.append( $previewImage );
		$preview.append( $( '<div />', { class: 'tavox-promo-image-preview-crosshair' } ) );
		$actions.append( $select ).append( $remove );
		$focus.append(
			buildField(
				labels.imageFocusX || 'Mover horizontal',
				$focusX,
				'tavox-span-6'
			)
		);
		$focus.append(
			buildField(
				labels.imageFocusY || 'Mover vertical',
				$focusY,
				'tavox-span-6'
			)
		);

		$field.append( $( '<label />' ).text( labels.image || 'Imagen override' ) );
		$field.append( $preview );
		$field.append( $source );
		$field.append( $actions );
		$field.append( $( '<label />', { class: 'tavox-promo-image-focus-label', text: labels.imageFocus || 'Encuadre visible' } ) );
		$field.append( $focus );
		$field.append( $input );

		if ( labels.imageHelp ) {
			$field.append( $( '<p />', { class: 'description', text: labels.imageHelp } ) );
		}

		if ( labels.imageFocusHelp ) {
			$field.append( $( '<p />', { class: 'description tavox-promo-image-focus-help', text: labels.imageFocusHelp } ) );
		}

		updateImagePreview( $field );

		return $field;
	}

	function buildStockValidationField( productId ) {
		var labels = tavoxPromotions.labels || {};
		var $field = $( '<div />', { class: 'tavox-field tavox-span-4' } );
		var $status = $( '<div />', { class: 'tavox-promo-stock-status' } );

		$field.append( $( '<label />' ).text( labels.stockHelp || 'Disponibilidad para promoción' ) );
		$field.append( $status );
		updateStockValidationField( $field, productId );

		return $field;
	}

	function updateStockValidationField( $scope, productId ) {
		var labels = tavoxPromotions.labels || {};
		var $status = $scope.find( '.tavox-promo-stock-status' );
		var product = getProductById( productId );

		if ( ! $status.length ) {
			return;
		}

		$status.removeClass( 'is-valid is-invalid is-neutral' );

		if ( ! product ) {
			$status.addClass( 'is-neutral' ).text( labels.stockHelp || '' );
			return;
		}

		if ( product.manages_stock && ! product.promotion_available ) {
			$status.addClass( 'is-invalid' ).text( labels.stockInvalid || 'Producto sin disponibilidad para promoción.' );
			return;
		}

		$status.addClass( 'is-valid' ).text( labels.stockOk || 'Disponible para promocionar.' );
	}

	function updatePromoStyleState( $scope, promoStyle ) {
		var isEvent = 'event' === String( promoStyle || 'default' );
		$scope.toggleClass( 'tavox-promo-card--event-mode', isEvent );
		$scope.find( '.tavox-event-only' ).toggleClass( 'is-hidden', ! isEvent );
	}

	function updateBrandScopeField( $scope, productId ) {
		var labels = tavoxPromotions.labels || {};
		var $field = $scope.find( '.tavox-promo-brand-scope' );
		var $description = $scope.find( '.tavox-promo-brand-scope-help' );
		var product = getProductById( productId );

		if ( ! $field.length ) {
			return;
		}

		if ( product && product.brand_scope ) {
			$field.val( String( product.brand_scope ) ).prop( 'disabled', true );
			$description.text( ( labels.brandAuto || 'Se hereda del producto' ) + ': ' + getBrandScopeLabel( product.brand_scope ) + '.' );
			return;
		}

		$field.prop( 'disabled', false );
		$description.text( labels.brandScopeHelp || 'Las promociones sin producto usan el área elegida aquí.' );
	}

	function formatDateTimeValue( value ) {
		var raw = String( value || '' ).trim();
		var match;

		if ( ! raw ) {
			return '';
		}

		match = raw.match( /^(\d{4})-(\d{2})-(\d{2})[T\s](\d{2}):(\d{2})/ );
		if ( ! match ) {
			return raw;
		}

		return match[3] + '/' + match[2] + '/' + match[1] + ' · ' + match[4] + ':' + match[5];
	}

	function updateSummaryText( $element, text ) {
		var hasText = !! String( text || '' ).trim();

		$element.text( hasText ? text : '' );
		$element.toggle( hasText );
	}

	function updatePromoSummary( $card ) {
		var labels = tavoxPromotions.labels || {};
		var productId = parseInt( $card.find( '.tavox-promo-product' ).val(), 10 );
		var product = getProductById( productId );
		var title = String( $card.find( '.tavox-promo-title' ).val() || '' ).trim();
		var badge = String( $card.find( '.tavox-promo-badge' ).val() || '' ).trim();
		var promoStyle = String( $card.find( '.tavox-promo-style' ).val() || 'default' );
		var eventMeta = String( $card.find( '.tavox-promo-event-meta' ).val() || '' ).trim();
		var eventGuests = String( $card.find( '.tavox-promo-event-guests' ).val() || '' ).trim();
		var startsAt = formatDateTimeValue( $card.find( '.tavox-promo-starts-at' ).val() );
		var endsAt = formatDateTimeValue( $card.find( '.tavox-promo-ends-at' ).val() );
		var showInSearch = $card.find( '.tavox-promo-show-in-search' ).is( ':checked' );
		var enabled = $card.find( '.tavox-promo-enabled' ).is( ':checked' );
		var brandScope = String( $card.find( '.tavox-promo-brand-scope' ).val() || 'zona_b' );
		var summaryTitle = title || ( product ? product.name : '' ) || ( labels.summaryNoProduct || 'Promoción sin producto' );
		var summarySubtitle = '';
		var summaryDetails = [];
		var summaryDates = '';
		var $summary = $card.find( '.tavox-promo-summary' );
		var $tags = $summary.find( '.tavox-promo-summary-tags' );

		if ( product && title && title !== product.name ) {
			summarySubtitle = ( labels.summaryProduct || 'Producto' ) + ': ' + product.name;
		} else if ( product && ! title ) {
			summarySubtitle = product.name;
		}

		if ( badge ) {
			summaryDetails.push( badge );
		}

		if ( 'event' === promoStyle ) {
			if ( eventMeta ) {
				summaryDetails.push( eventMeta );
			}

			if ( eventGuests ) {
				summaryDetails.push( eventGuests );
			}
		}

		if ( startsAt || endsAt ) {
			summaryDates = ( labels.summaryDates || 'Vigencia' ) + ': ' + [ startsAt, endsAt ].filter( Boolean ).join( ' → ' );
		} else {
			summaryDates = labels.summaryNoDates || 'Sin fechas definidas';
		}

		updateSummaryText( $summary.find( '.tavox-promo-summary-title' ), summaryTitle );
		updateSummaryText( $summary.find( '.tavox-promo-summary-subtitle' ), summarySubtitle );
		updateSummaryText( $summary.find( '.tavox-promo-summary-detail' ), summaryDetails.join( ' · ' ) );
		updateSummaryText( $summary.find( '.tavox-promo-summary-dates' ), summaryDates );

		$tags.empty();
		[
			'event' === promoStyle ? ( labels.styleEvent || 'Evento' ) : ( labels.styleDefault || 'Promo regular' ),
			enabled ? ( labels.summaryActive || 'Activa' ) : ( labels.summaryPaused || 'Pausada' ),
			showInSearch ? ( labels.summarySearchOn || 'Sale en búsqueda' ) : ( labels.summarySearchOff || 'Sólo en promociones' ),
			brandScope === 'isola'
				? ( labels.brandIsola || 'ISOLA' )
				: brandScope === 'common'
					? ( labels.brandCommon || 'Común' )
					: ( labels.brandZonaB || 'Zona B' ),
		].forEach( function ( text ) {
			$tags.append( $( '<span />', { class: 'tavox-promo-summary-tag', text: text } ) );
		} );
	}

	function updateCollapseState( $card, collapsed ) {
		var labels = tavoxPromotions.labels || {};
		var isCollapsed = !! collapsed;
		var $button = $card.find( '.tavox-promo-collapse' );

		$card.toggleClass( 'tavox-promo-card--collapsed', isCollapsed );
		$button.text( isCollapsed ? ( labels.expand || 'Desplegar' ) : ( labels.collapse || 'Plegar' ) );
		$button.attr( 'aria-expanded', isCollapsed ? 'false' : 'true' );
	}

	function buildCard( item, index ) {
		var promo = item || {};
		var labels = tavoxPromotions.labels || {};
		var defaults = tavoxPromotions.defaults || {};
		var promoStyle = promo.promo_style || 'default';
		var $card = $( '<article />', { class: 'tavox-promo-card', 'data-product-id': promo.product_id || '' } );
		var $header = $( '<div />', { class: 'tavox-promo-card-header' } );
		var $priority = $( '<div />', { class: 'tavox-promo-priority' } );
		var $priorityCopy = $( '<div />', { class: 'tavox-promo-priority-copy' } );
		var $fields = $( '<div />', { class: 'tavox-promo-fields' } );
		var $summary = $( '<div />', { class: 'tavox-promo-summary' } );
		var $enabled = $( '<input />', { type: 'checkbox', class: 'tavox-promo-enabled' } ).prop( 'checked', !! promo.enabled );
		var $select = $( '<select />', { class: 'tavox-promo-product' } ).html( productOptionsHtml( promo.product_id || 0 ) );
		var $moveButtons = $( '<div />', { class: 'tavox-promo-move-buttons' } );
		var $moveUp = $( '<button />', {
			type: 'button',
			class: 'button tavox-promo-move-up',
			text: labels.moveUp || 'Subir',
		} );
		var $moveDown = $( '<button />', {
			type: 'button',
			class: 'button tavox-promo-move-down',
			text: labels.moveDown || 'Bajar',
		} );
		var $remove = $( '<button />', {
			type: 'button',
			class: 'button tavox-promo-remove',
			text: labels.remove || 'Eliminar',
		} );
		var $collapse = $( '<button />', {
			type: 'button',
			class: 'button tavox-promo-collapse',
			text: labels.collapse || 'Plegar',
			'aria-expanded': 'true',
		} );

		$priorityCopy.append( $( '<span />', { class: 'tavox-promo-priority-label', text: labels.priority || 'Prioridad' } ) );
		$priorityCopy.append( $( '<span />', { class: 'tavox-promo-priority-help', text: labels.priorityHelp || '' } ) );
		$priority.append( $( '<span />', { class: 'tavox-order-pill tavox-promo-order', text: index + 1 } ) );
		$priority.append( $priorityCopy );
		$moveButtons.append( $moveUp ).append( $moveDown );

		$header.append( $priority );
		$header.append( $( '<button />', { type: 'button', class: 'tavox-handle', text: '\u22EE\u22EE', 'aria-label': 'Reordenar' } ) );
		$header.append( $moveButtons );
		$header.append(
			$( '<label />', { class: 'tavox-promo-status' } )
				.append( $enabled )
				.append( $( '<span />' ).text( labels.active || 'Activa' ) )
		);
		$header.append( $collapse );
		$header.append( $remove );

		$summary.append( $( '<div />', { class: 'tavox-promo-summary-title' } ) );
		$summary.append( $( '<div />', { class: 'tavox-promo-summary-subtitle' } ) );
		$summary.append( $( '<div />', { class: 'tavox-promo-summary-tags' } ) );
		$summary.append( $( '<div />', { class: 'tavox-promo-summary-detail' } ) );
		$summary.append( $( '<div />', { class: 'tavox-promo-summary-dates' } ) );

		$fields.append( buildField( labels.product || 'Producto', $select, 'tavox-span-4' ) );
		$fields.append(
			buildField(
				labels.brandScope || 'Área visual',
				$( '<select />', { class: 'tavox-promo-brand-scope' } ).html( brandScopeOptionsHtml( promo.brand_scope || 'zona_b' ) ),
				'tavox-span-3 tavox-field--brand-scope',
				''
			).append( $( '<p />', { class: 'description tavox-promo-brand-scope-help', text: labels.brandScopeHelp || '' } ) )
		);
		$fields.append(
			buildField(
				labels.style || 'Estilo',
				$( '<select />', { class: 'tavox-promo-style' } )
					.append( $( '<option />', { value: 'default', text: labels.styleDefault || 'Promo regular' } ) )
					.append( $( '<option />', { value: 'event', text: labels.styleEvent || 'Evento' } ) )
					.val( promoStyle ),
				'tavox-span-2',
				labels.styleHelp || ''
			)
		);
		$fields.append(
			buildField(
				labels.badge || 'Badge',
				$( '<input />', { type: 'text', class: 'tavox-promo-badge regular-text', value: promo.badge || '' } ),
				'tavox-span-2'
			)
		);
		$fields.append(
			buildField(
				labels.title || 'Título',
				$( '<input />', { type: 'text', class: 'tavox-promo-title regular-text', value: promo.title || '' } ),
				'tavox-span-3'
			)
		);
		$fields.append(
			buildField(
				labels.copy || 'Frase promocional',
				$( '<textarea />', {
					class: 'tavox-promo-copy large-text',
					rows: 3,
					placeholder: tavoxPromotions.defaults && tavoxPromotions.defaults.copy ? tavoxPromotions.defaults.copy : '',
				} ).val( promo.copy || '' ),
				'tavox-span-3',
				labels.copyHelp || ''
			)
		);
		$fields.append(
			buildField(
				labels.eventMeta || 'Agenda del evento',
				$( '<input />', {
					type: 'text',
					class: 'tavox-promo-event-meta regular-text',
					value: promo.event_meta || '',
					placeholder: defaults.eventMeta || '',
				} ),
				'tavox-span-2 tavox-event-only',
				labels.eventMetaHelp || ''
			)
		);
		$fields.append(
			buildField(
				labels.eventGuests || 'Gancho / invitados',
				$( '<input />', {
					type: 'text',
					class: 'tavox-promo-event-guests regular-text',
					value: promo.event_guests || '',
					placeholder: defaults.eventGuests || '',
				} ),
				'tavox-span-4 tavox-event-only',
				labels.eventGuestsHelp || ''
			)
		);
		$fields.append(
			buildToggleField(
				labels.showInSearch || 'Mostrar también en búsqueda',
				typeof promo.show_in_search === 'undefined' ? true : !! promo.show_in_search,
				'tavox-span-3',
				labels.searchHelp || ''
			)
		);
		$fields.append( buildImageField( promo ) );
		$fields.append(
			buildField(
				labels.startsAt || 'Inicio',
				$( '<input />', {
					type: 'datetime-local',
					class: 'tavox-promo-starts-at',
					value: promo.starts_at || '',
				} ),
				'tavox-span-2'
			)
		);
		$fields.append(
			buildField(
				labels.endsAt || 'Fin',
				$( '<input />', {
					type: 'datetime-local',
					class: 'tavox-promo-ends-at',
					value: promo.ends_at || '',
				} ),
				'tavox-span-2'
			)
		);
		$fields.append( buildStockValidationField( promo.product_id || 0 ) );

		$card.append( $header ).append( $summary ).append( $fields );
		updatePromoStyleState( $card, promoStyle );
		updateBrandScopeField( $card, promo.product_id || 0 );
		updateImagePreview( $card.find( '.tavox-promo-image-field' ) );
		updatePromoSummary( $card );
		updateCollapseState( $card, false );

		return $card;
	}

	function refreshOrderLabels( $list ) {
		var total = $list.children( '.tavox-promo-card' ).length;
		$list.children( '.tavox-promo-card' ).each( function ( index ) {
			var $card = $( this );
			$card.find( '.tavox-promo-order' ).text( index + 1 );
			$card.find( '.tavox-promo-move-up' ).prop( 'disabled', index === 0 );
			$card.find( '.tavox-promo-move-down' ).prop( 'disabled', index === total - 1 );
		} );
	}

	function moveCard( $card, direction ) {
		if ( ! $card || ! $card.length ) {
			return;
		}

		if ( 'up' === direction ) {
			var $prev = $card.prev( '.tavox-promo-card' );
			if ( $prev.length ) {
				$card.insertBefore( $prev );
			}
			return;
		}

		var $next = $card.next( '.tavox-promo-card' );
		if ( $next.length ) {
			$card.insertAfter( $next );
		}
	}

	function collectCards( $list ) {
		var list = [];

		$list.children( '.tavox-promo-card' ).each( function ( index ) {
			var $card = $( this );
			var productId = parseInt( $card.find( '.tavox-promo-product' ).val(), 10 );
			var promoStyle = $card.find( '.tavox-promo-style' ).val() || 'default';
			var title = $card.find( '.tavox-promo-title' ).val();
			var badge = $card.find( '.tavox-promo-badge' ).val();
			var brandScope = $card.find( '.tavox-promo-brand-scope' ).val() || 'zona_b';
			var copy = $card.find( '.tavox-promo-copy' ).val();
			var eventMeta = $card.find( '.tavox-promo-event-meta' ).val();
			var eventGuests = $card.find( '.tavox-promo-event-guests' ).val();
			var image = $card.find( '.tavox-promo-image' ).val();
			var imageFocusX = $card.find( '.tavox-promo-image-focus-x' ).val();
			var imageFocusY = $card.find( '.tavox-promo-image-focus-y' ).val();
			var startsAt = $card.find( '.tavox-promo-starts-at' ).val();
			var endsAt = $card.find( '.tavox-promo-ends-at' ).val();
			var hasEditorialContent = [ title, badge, copy, eventMeta, eventGuests, image, startsAt, endsAt ]
				.some( function ( value ) {
					return !! String( value || '' ).trim();
				} );

			if ( ! productId && ! ( promoStyle === 'event' && hasEditorialContent ) ) {
				return;
			}

			list.push( {
				product_id: productId,
				enabled: $card.find( '.tavox-promo-enabled' ).is( ':checked' ),
				order: index + 1,
				promo_style: promoStyle,
				brand_scope: brandScope,
				badge: badge,
				title: title,
				copy: copy,
				event_meta: eventMeta,
				event_guests: eventGuests,
				show_in_search: $card.find( '.tavox-promo-show-in-search' ).is( ':checked' ),
				image: image,
				image_focus_x: clampFocusValue( imageFocusX ),
				image_focus_y: clampFocusValue( imageFocusY ),
				starts_at: startsAt,
				ends_at: endsAt,
			} );
		} );

		return list;
	}

	function findInvalidPromotionCard( $list ) {
		var invalid = null;

		$list.children( '.tavox-promo-card' ).each( function () {
			var $card = $( this );
			var productId = parseInt( $card.find( '.tavox-promo-product' ).val(), 10 );
			var product = getProductById( productId );
			var enabled = $card.find( '.tavox-promo-enabled' ).is( ':checked' );

			$card.removeClass( 'tavox-promo-card--invalid' );

			if ( enabled && product && product.manages_stock && ! product.promotion_available && ! invalid ) {
				invalid = $card;
				$card.addClass( 'tavox-promo-card--invalid' );
			}
		} );

		return invalid;
	}

	$( document ).ready( function () {
		var $list = $( '#tavox-promo-list' );
		var promos = Array.isArray( tavoxPromotions.promotions ) ? tavoxPromotions.promotions : [];

		if ( promos.length ) {
			promos.forEach( function ( item, index ) {
				$list.append( buildCard( item, index ) );
			} );
		} else {
			$list.append( buildCard( {}, 0 ) );
		}

		refreshOrderLabels( $list );

		$list.sortable( {
			handle: '.tavox-handle',
			axis: 'y',
			update: function () {
				refreshOrderLabels( $list );
			},
		} );

		$( '#tavox-add-promo' ).on( 'click', function ( event ) {
			event.preventDefault();
			$list.append( buildCard( {}, $list.children( '.tavox-promo-card' ).length ) );
			refreshOrderLabels( $list );
		} );

		$list.on( 'click', '.tavox-promo-remove', function ( event ) {
			event.preventDefault();
			$( this ).closest( '.tavox-promo-card' ).remove();

			if ( ! $list.children( '.tavox-promo-card' ).length ) {
				$list.append( buildCard( {}, 0 ) );
			}

			refreshOrderLabels( $list );
		} );

		$list.on( 'click', '.tavox-promo-move-up', function ( event ) {
			event.preventDefault();
			var $card = $( this ).closest( '.tavox-promo-card' );
			moveCard( $card, 'up' );
			refreshOrderLabels( $list );
			$card.get( 0 ).scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
		} );

		$list.on( 'click', '.tavox-promo-move-down', function ( event ) {
			event.preventDefault();
			var $card = $( this ).closest( '.tavox-promo-card' );
			moveCard( $card, 'down' );
			refreshOrderLabels( $list );
			$card.get( 0 ).scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
		} );

		$list.on( 'change', '.tavox-promo-product', function () {
			var $card = $( this ).closest( '.tavox-promo-card' );
			updateStockValidationField( $card, $( this ).val() );
			updateBrandScopeField( $card, $( this ).val() );
			updatePromoSummary( $card );
			updateImagePreview( $card.find( '.tavox-promo-image-field' ) );
			$card.removeClass( 'tavox-promo-card--invalid' );
		} );

		$list.on( 'change', '.tavox-promo-enabled', function () {
			var $card = $( this ).closest( '.tavox-promo-card' );
			updatePromoSummary( $card );
			$card.removeClass( 'tavox-promo-card--invalid' );
		} );

		$list.on( 'change', '.tavox-promo-style', function () {
			var $card = $( this ).closest( '.tavox-promo-card' );
			updatePromoStyleState( $card, $( this ).val() );
			updatePromoSummary( $card );
		} );

		$list.on( 'input change', '.tavox-promo-title, .tavox-promo-badge, .tavox-promo-event-meta, .tavox-promo-event-guests, .tavox-promo-starts-at, .tavox-promo-ends-at, .tavox-promo-show-in-search, .tavox-promo-brand-scope', function () {
			updatePromoSummary( $( this ).closest( '.tavox-promo-card' ) );
		} );

		$list.on( 'click', '.tavox-promo-collapse', function ( event ) {
			event.preventDefault();
			var $card = $( this ).closest( '.tavox-promo-card' );
			updateCollapseState( $card, ! $card.hasClass( 'tavox-promo-card--collapsed' ) );
		} );

		$list.on( 'click', '.tavox-promo-image-select', function ( event ) {
			event.preventDefault();
			openImageMediaFrame( $( this ).closest( '.tavox-promo-image-field' ) );
		} );

		$list.on( 'click', '.tavox-promo-image-remove', function ( event ) {
			event.preventDefault();
			var $field = $( this ).closest( '.tavox-promo-image-field' );
			$field.find( '.tavox-promo-image' ).val( '' ).trigger( 'change' );
		} );

		$list.on( 'input change', '.tavox-promo-image, .tavox-promo-image-focus-x, .tavox-promo-image-focus-y', function () {
			var $field = $( this ).closest( '.tavox-promo-image-field' );
			updateImagePreview( $field );
		} );

		$list.on( 'pointerdown', '.tavox-promo-image-preview', function ( event ) {
			var $preview = $( this );
			var $field = $preview.closest( '.tavox-promo-image-field' );
			var effectiveImage = getEffectivePreviewImage( $field );

			if ( ! effectiveImage.url ) {
				return;
			}

			event.preventDefault();
			$preview.addClass( 'is-dragging' );

			function moveFocus( pageX, pageY ) {
				var rect = $preview.get( 0 ).getBoundingClientRect();
				var x = ( ( pageX - rect.left ) / rect.width ) * 100;
				var y = ( ( pageY - rect.top ) / rect.height ) * 100;
				updateImageFocus( $field, x, y );
			}

			moveFocus( event.clientX, event.clientY );

			function handleMove( moveEvent ) {
				moveFocus( moveEvent.clientX, moveEvent.clientY );
			}

			function handleUp() {
				$preview.removeClass( 'is-dragging' );
				$( window ).off( '.tavoxPromoImageDrag' );
			}

			$( window ).on( 'pointermove.tavoxPromoImageDrag', handleMove );
			$( window ).on( 'pointerup.tavoxPromoImageDrag pointercancel.tavoxPromoImageDrag', handleUp );
		} );

		$( '#tavox-save-promos' ).on( 'click', function ( event ) {
			event.preventDefault();
			var $invalidCard = findInvalidPromotionCard( $list );
			if ( $invalidCard ) {
				alert( tavoxPromotions.messages.invalidStock || tavoxPromotions.messages.saveError );
				$invalidCard.get( 0 ).scrollIntoView( { behavior: 'smooth', block: 'center' } );
				return;
			}
			var data = collectCards( $list );

			$.post(
				ajaxurl,
				{
					action: 'tavox_save_promotions',
					nonce: tavoxPromotions.nonce,
					data: JSON.stringify( data ),
				}
			).done( function ( response ) {
				if ( response && response.success ) {
					alert( response.data && response.data.message ? response.data.message : tavoxPromotions.messages.saveSuccess );
					return;
				}

				alert( response && response.data && response.data.message ? response.data.message : tavoxPromotions.messages.saveError );
			} ).fail( function () {
				alert( tavoxPromotions.messages.saveError );
			} );
		} );
	} );
}( jQuery ) );
