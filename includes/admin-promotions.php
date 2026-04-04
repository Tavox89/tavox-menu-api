<?php

defined( 'ABSPATH' ) || exit;

/**
 * Renderiza la página de promociones.
 */
function tavox_menu_api_render_promotions_page(): void {
	$promotions = array_map(
		static function ( array $promotion ): array {
			$promotion['starts_at'] = tavox_menu_api_prepare_datetime_local_input( (string) ( $promotion['starts_at'] ?? '' ) );
			$promotion['ends_at']   = tavox_menu_api_prepare_datetime_local_input( (string) ( $promotion['ends_at'] ?? '' ) );
			$promotion['show_in_search'] = array_key_exists( 'show_in_search', $promotion ) ? ! empty( $promotion['show_in_search'] ) : true;
			$promotion['promo_style'] = ! empty( $promotion['promo_style'] ) ? sanitize_key( (string) $promotion['promo_style'] ) : 'default';
			$promotion['brand_scope'] = tavox_menu_api_sanitize_menu_scope( (string) ( $promotion['brand_scope'] ?? 'zona_b' ) );
			$promotion['event_meta'] = sanitize_text_field( (string) ( $promotion['event_meta'] ?? '' ) );
			$promotion['event_guests'] = sanitize_text_field( (string) ( $promotion['event_guests'] ?? '' ) );
			$promotion['image_focus_x'] = tavox_menu_api_normalize_focus_value( $promotion['image_focus_x'] ?? 50 );
			$promotion['image_focus_y'] = tavox_menu_api_normalize_focus_value( $promotion['image_focus_y'] ?? 50 );

			return $promotion;
		},
		tavox_menu_api_get_promotions_config()
	);

	wp_enqueue_script( 'jquery-ui-sortable' );
	wp_enqueue_media();
	wp_enqueue_style(
		'tavox-menu-promotions-admin',
		TAVOX_MENU_API_URL . 'assets/promotions-admin.css',
		[],
		filemtime( TAVOX_MENU_API_PATH . 'assets/promotions-admin.css' )
	);
	wp_register_script(
		'tavox-menu-promotions-admin',
		TAVOX_MENU_API_URL . 'assets/promotions-admin.js',
		[ 'jquery', 'jquery-ui-sortable' ],
		filemtime( TAVOX_MENU_API_PATH . 'assets/promotions-admin.js' ),
		true
	);
	wp_localize_script(
		'tavox-menu-promotions-admin',
		'tavoxPromotions',
		[
			'nonce'      => wp_create_nonce( 'tavox_save_promotions' ),
			'promotions' => $promotions,
			'products'   => tavox_menu_api_get_admin_product_choices(),
			'messages'   => [
				'saveSuccess' => __( 'Promociones actualizadas.', 'tavox-menu-api' ),
				'saveError'   => __( 'No se pudieron guardar las promociones.', 'tavox-menu-api' ),
				'emptyProduct'=> __( 'Selecciona un producto.', 'tavox-menu-api' ),
				'invalidStock'=> __( 'No puedes activar una promoción con un producto sin disponibilidad cuando maneja stock.', 'tavox-menu-api' ),
			],
			'defaults'   => [
				'copy'        => __( 'Una recomendación de la casa, pensada para abrir el pedido con buen gusto.', 'tavox-menu-api' ),
				'eventMeta'   => __( 'Este sábado · 8:00 PM', 'tavox-menu-api' ),
				'eventGuests' => __( 'Música en vivo con invitados especiales', 'tavox-menu-api' ),
				'imageFocusX' => 50,
				'imageFocusY' => 50,
			],
			'labels'     => [
				'product'      => __( 'Producto', 'tavox-menu-api' ),
				'style'        => __( 'Estilo', 'tavox-menu-api' ),
				'styleDefault' => __( 'Promo regular', 'tavox-menu-api' ),
				'styleEvent'   => __( 'Evento', 'tavox-menu-api' ),
				'badge'        => __( 'Badge', 'tavox-menu-api' ),
				'title'        => __( 'Título', 'tavox-menu-api' ),
				'brandScope'   => __( 'Dónde se verá', 'tavox-menu-api' ),
				'brandScopeHelp' => __( 'Si la promo usa producto, esta zona se toma del producto. Si es editorial o evento sin producto, elige aquí si pertenece a Zona B, ISOLA o Común.', 'tavox-menu-api' ),
				'brandZonaB'   => __( 'Zona B', 'tavox-menu-api' ),
				'brandIsola'   => __( 'ISOLA', 'tavox-menu-api' ),
				'brandCommon'  => __( 'Común', 'tavox-menu-api' ),
				'brandAuto'    => __( 'Se hereda del producto', 'tavox-menu-api' ),
				'copy'         => __( 'Frase promocional', 'tavox-menu-api' ),
				'eventMeta'    => __( 'Agenda del evento', 'tavox-menu-api' ),
				'eventGuests'  => __( 'Gancho / invitados', 'tavox-menu-api' ),
				'image'        => __( 'Imagen override', 'tavox-menu-api' ),
				'startsAt'     => __( 'Inicio', 'tavox-menu-api' ),
				'endsAt'       => __( 'Fin', 'tavox-menu-api' ),
				'active'       => __( 'Activa', 'tavox-menu-api' ),
				'showInSearch' => __( 'Mostrar también en búsqueda', 'tavox-menu-api' ),
				'remove'       => __( 'Eliminar', 'tavox-menu-api' ),
				'add'          => __( 'Agregar promoción', 'tavox-menu-api' ),
				'placeholder'  => __( 'Selecciona un producto', 'tavox-menu-api' ),
				'copyHelp'     => __( 'Si queda vacía, el frontend usa la frase genérica.', 'tavox-menu-api' ),
				'styleHelp'    => __( 'Usa "Evento" para promos editoriales como sábado, música en vivo, DJs o invitados.', 'tavox-menu-api' ),
				'eventMetaHelp'=> __( 'Ejemplo: Este sábado · 8:00 PM.', 'tavox-menu-api' ),
				'eventGuestsHelp' => __( 'Ejemplo: Música en vivo con invitados especiales.', 'tavox-menu-api' ),
				'imageHelp'    => __( 'Elige una imagen de la biblioteca, súbela aquí o pega una URL externa si la necesitas.', 'tavox-menu-api' ),
				'imageSelect'  => __( 'Elegir o subir imagen', 'tavox-menu-api' ),
				'imageReplace' => __( 'Cambiar imagen', 'tavox-menu-api' ),
				'imageRemove'  => __( 'Quitar imagen', 'tavox-menu-api' ),
				'imagePreview' => __( 'Vista previa de la imagen promocional', 'tavox-menu-api' ),
				'imagePreviewProduct' => __( 'Vista previa usando la imagen del producto.', 'tavox-menu-api' ),
				'imagePreviewOverride' => __( 'Vista previa usando la imagen override.', 'tavox-menu-api' ),
				'imagePreviewEmpty' => __( 'Selecciona un producto con imagen o sube una imagen para ver el encuadre.', 'tavox-menu-api' ),
				'imageFocus'   => __( 'Encuadre visible', 'tavox-menu-api' ),
				'imageFocusX'  => __( 'Mover horizontal', 'tavox-menu-api' ),
				'imageFocusY'  => __( 'Mover vertical', 'tavox-menu-api' ),
				'imageFocusHelp' => __( 'Arrastra la vista previa o usa los controles para decidir qué parte de la imagen se verá.', 'tavox-menu-api' ),
				'searchHelp'   => __( 'Si está activa, la promoción puede aparecer como primer resultado cuando la búsqueda haga match.', 'tavox-menu-api' ),
				'stockHelp'    => __( 'Si el producto maneja stock, debe tener disponibilidad para poder activar la promoción.', 'tavox-menu-api' ),
				'stockOk'      => __( 'Disponible para promocionar.', 'tavox-menu-api' ),
				'stockInvalid' => __( 'Este producto maneja stock y no tiene disponibilidad. No podrás guardar la promoción activa.', 'tavox-menu-api' ),
				'moveUp'       => __( 'Subir', 'tavox-menu-api' ),
				'moveDown'     => __( 'Bajar', 'tavox-menu-api' ),
				'collapse'     => __( 'Plegar', 'tavox-menu-api' ),
				'expand'       => __( 'Desplegar', 'tavox-menu-api' ),
				'priority'     => __( 'Orden', 'tavox-menu-api' ),
				'priorityHelp' => __( 'La tarjeta 1 sale primero. Puedes arrastrar o usar subir y bajar.', 'tavox-menu-api' ),
				'summarySearchOn' => __( 'Sale en búsqueda', 'tavox-menu-api' ),
				'summarySearchOff' => __( 'Sólo en promociones', 'tavox-menu-api' ),
				'summaryActive' => __( 'Activa', 'tavox-menu-api' ),
				'summaryPaused' => __( 'Pausada', 'tavox-menu-api' ),
				'summaryDates'  => __( 'Vigencia', 'tavox-menu-api' ),
				'summaryNoProduct' => __( 'Promoción sin producto', 'tavox-menu-api' ),
				'summaryProduct' => __( 'Producto', 'tavox-menu-api' ),
				'summaryNoDates' => __( 'Sin fechas definidas', 'tavox-menu-api' ),
				'saveHint'     => __( 'Arrastra las tarjetas para reordenar la secuencia promocional.', 'tavox-menu-api' ),
				'sequenceHint' => __( 'Cada tarjeta representa una promoción en el rail principal. Puedes activar varias y cambiar su orden sin romper el frontend.', 'tavox-menu-api' ),
				'crudHint'     => __( 'El CRUD está pensado para edición rápida: selecciona producto, define si es promo regular o evento, ajusta textos, agenda, búsqueda y guarda.', 'tavox-menu-api' ),
			],
		]
	);
	wp_enqueue_script( 'tavox-menu-promotions-admin' );

	echo '<div class="wrap tavox-promotions-wrap">';
	echo '<h1>' . esc_html__( 'Promociones del menú', 'tavox-menu-api' ) . '</h1>';
	echo '<div class="tavox-promotions-shell">';
	echo '<section class="tavox-promotions-hero">';
	echo '<h2>' . esc_html__( 'Organiza las promociones del menú', 'tavox-menu-api' ) . '</h2>';
	echo '<p>' . esc_html__( 'Aquí eliges qué promociones se muestran, en qué orden aparecen y si también pueden salir en los resultados de búsqueda.', 'tavox-menu-api' ) . '</p>';
	echo '<div class="tavox-promotions-hint">' . esc_html__( 'Puedes preparar promociones normales o de evento, cambiar imagen y textos, definir fechas y mover cada tarjeta para decidir cuál sale primero.', 'tavox-menu-api' ) . '</div>';
	echo '</section>';
	echo '<section id="tavox-promo-list" class="tavox-promo-list"></section>';
	echo '<section class="tavox-promotions-actions">';
	echo '<div class="tavox-promotions-actions-copy">' . esc_html__( 'Agrega, ordena y guarda las promociones del menú cuando termines.', 'tavox-menu-api' ) . '</div>';
	echo '<div style="display:flex; gap:8px; flex-wrap:wrap;">';
	echo '<button id="tavox-add-promo" class="button">' . esc_html__( 'Agregar promoción', 'tavox-menu-api' ) . '</button>';
	echo '<button id="tavox-save-promos" class="button button-primary">' . esc_html__( 'Guardar promociones', 'tavox-menu-api' ) . '</button>';
	echo '</div>';
	echo '</section>';
	echo '</div>';
	echo '</div>';
}

/**
 * Guarda la configuración de promociones.
 */
function tavox_menu_api_save_promotions(): void {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'tavox_save_promotions' ) ) {
		wp_send_json_error( [ 'message' => __( 'Nonce inválido.', 'tavox-menu-api' ) ], 403 );
	}

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'tavox-menu-api' ) ], 403 );
	}

	$data = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
	$list = json_decode( $data, true );

	if ( ! is_array( $list ) ) {
		wp_send_json_error( [ 'message' => __( 'Datos inválidos.', 'tavox-menu-api' ) ], 400 );
	}

	$sanitized = [];
	$order     = 1;

	foreach ( $list as $item ) {
		$promo_style = in_array( sanitize_key( (string) ( $item['promo_style'] ?? 'default' ) ), [ 'default', 'event' ], true ) ? sanitize_key( (string) ( $item['promo_style'] ?? 'default' ) ) : 'default';
		$product_id = absint( $item['product_id'] ?? 0 );
		$brand_scope = tavox_menu_api_sanitize_menu_scope( (string) ( $item['brand_scope'] ?? 'zona_b' ) );
		$title      = sanitize_text_field( (string) ( $item['title'] ?? '' ) );
		$badge      = sanitize_text_field( (string) ( $item['badge'] ?? '' ) );
		$copy       = sanitize_textarea_field( (string) ( $item['copy'] ?? '' ) );
		$event_meta = sanitize_text_field( (string) ( $item['event_meta'] ?? '' ) );
		$event_guests = sanitize_text_field( (string) ( $item['event_guests'] ?? '' ) );
		$image      = esc_url_raw( (string) ( $item['image'] ?? '' ) );
		$image_focus_x = tavox_menu_api_normalize_focus_value( $item['image_focus_x'] ?? 50 );
		$image_focus_y = tavox_menu_api_normalize_focus_value( $item['image_focus_y'] ?? 50 );
		$starts_at  = sanitize_text_field( (string) ( $item['starts_at'] ?? '' ) );
		$ends_at    = sanitize_text_field( (string) ( $item['ends_at'] ?? '' ) );
		$has_editorial_content = '' !== trim( implode( '', [ $title, $badge, $copy, $event_meta, $event_guests, $image, $starts_at, $ends_at ] ) );

		if ( $product_id <= 0 && ! ( 'event' === $promo_style && $has_editorial_content ) ) {
			continue;
		}

		$product = $product_id > 0 ? wc_get_product( $product_id ) : null;
		if ( $product_id > 0 && ! $product instanceof WC_Product ) {
			continue;
		}

		if ( $product instanceof WC_Product && ! empty( $item['enabled'] ) && ! tavox_menu_api_is_product_available_for_promotion( $product ) ) {
			wp_send_json_error(
				[
					/* translators: %s product name */
					'message' => sprintf(
						__( 'La promoción no se puede activar porque "%s" maneja stock y no tiene disponibilidad.', 'tavox-menu-api' ),
						html_entity_decode( $product->get_name() )
					),
				],
				400
			);
		}

		$sanitized[] = [
			'product_id' => $product_id,
			'enabled'    => ! empty( $item['enabled'] ),
			'show_in_search' => array_key_exists( 'show_in_search', $item ) ? ! empty( $item['show_in_search'] ) : true,
			'order'      => $order,
			'promo_style'=> $promo_style,
			'brand_scope'=> $brand_scope,
			'badge'      => $badge,
			'title'      => $title,
			'copy'       => $copy,
			'event_meta' => $event_meta,
			'event_guests' => $event_guests,
			'image'      => $image,
			'image_focus_x' => $image_focus_x,
			'image_focus_y' => $image_focus_y,
			'starts_at'  => $starts_at,
			'ends_at'    => $ends_at,
		];
		$order++;
	}

	update_option( 'tavox_menu_promotions', $sanitized, false );
	tavox_menu_api_bump_cache_version();

	wp_send_json_success( [ 'message' => __( 'Promociones actualizadas.', 'tavox-menu-api' ) ] );
}
add_action( 'wp_ajax_tavox_save_promotions', 'tavox_menu_api_save_promotions' );
