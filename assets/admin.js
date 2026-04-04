( function ( $ ) {
    'use strict';

    /**
     * Construye una fila de la tabla con la información de la categoría.
     *
     * @param {Object} cat La categoría de WooCommerce.
     * @param {Object} cfg Configuración guardada para esta categoría.
     * @returns {jQuery} La fila como elemento jQuery.
     */
    function buildRow( cat, cfg ) {
        var id      = cat.id;
        var enabled = cfg && cfg.enabled ? true : false;
        var aliases = cfg && Array.isArray( cfg.aliases ) ? cfg.aliases.join( ', ' ) : '';
        var menuScope = cfg && cfg.menu_scope ? cfg.menu_scope : 'zona_b';
        var serviceStation = cfg && cfg.service_station ? cfg.service_station : 'auto';
        var row     = $( '<tr />' ).attr( 'data-id', id );
        row.append( $( '<td />' ).addClass( 'tavox-handle' ).css( { cursor: 'move', width: '40px', textAlign: 'center' } ).text( '\u22EE\u22EE' ) );
        row.append( $( '<td />' ).text( cat.name ) );
        row.append(
            $( '<td />' ).append(
                $( '<input />', {
                    type: 'text',
                    class: 'regular-text tavox-aliases',
                    value: aliases,
                    placeholder: tavoxMenu.labels && tavoxMenu.labels.aliasesPlaceholder ? tavoxMenu.labels.aliasesPlaceholder : 'Ej: asado, parrilla',
                } )
            ).append(
                $( '<p />' ).addClass( 'description' ).text(
                    tavoxMenu.labels && tavoxMenu.labels.aliasesHelp ? tavoxMenu.labels.aliasesHelp : 'Separadas por coma.'
                )
            )
        );
        row.append(
            $( '<td />' ).append(
                $( '<select />', { class: 'tavox-menu-scope' } )
                    .append( $( '<option />', { value: 'zona_b', text: tavoxMenu.labels && tavoxMenu.labels.scopeZonaB ? tavoxMenu.labels.scopeZonaB : 'Zona B' } ) )
                    .append( $( '<option />', { value: 'isola', text: tavoxMenu.labels && tavoxMenu.labels.scopeIsola ? tavoxMenu.labels.scopeIsola : 'ISOLA' } ) )
                    .append( $( '<option />', { value: 'common', text: tavoxMenu.labels && tavoxMenu.labels.scopeCommon ? tavoxMenu.labels.scopeCommon : 'Común' } ) )
                    .val( menuScope )
            ).append(
                $( '<p />' ).addClass( 'description' ).text(
                    tavoxMenu.labels && tavoxMenu.labels.scopeHelp ? tavoxMenu.labels.scopeHelp : 'Define el área visual de la categoría.'
                )
            )
        );
        row.append(
            $( '<td />' ).append(
                $( '<select />', { class: 'tavox-service-station' } )
                    .append( $( '<option />', { value: 'auto', text: tavoxMenu.labels && tavoxMenu.labels.stationAuto ? tavoxMenu.labels.stationAuto : 'Automático' } ) )
                    .append( $( '<option />', { value: 'kitchen', text: tavoxMenu.labels && tavoxMenu.labels.stationKitchen ? tavoxMenu.labels.stationKitchen : 'Cocina' } ) )
                    .append( $( '<option />', { value: 'horno', text: tavoxMenu.labels && tavoxMenu.labels.stationHorno ? tavoxMenu.labels.stationHorno : 'Horno' } ) )
                    .append( $( '<option />', { value: 'bar', text: tavoxMenu.labels && tavoxMenu.labels.stationBar ? tavoxMenu.labels.stationBar : 'Barra' } ) )
                    .val( serviceStation )
            ).append(
                $( '<p />' ).addClass( 'description' ).text(
                    tavoxMenu.labels && tavoxMenu.labels.stationHelp ? tavoxMenu.labels.stationHelp : 'Indica dónde se prepara esta categoría.'
                )
            )
        );
        var checkbox = $( '<input />', { type: 'checkbox', class: 'tavox-enabled' } ).prop( 'checked', enabled );
        row.append( $( '<td />' ).css( 'text-align', 'center' ).append( checkbox ) );
        return row;
    }

    /**
     * Devuelve un mapa id → config para facilitar la búsqueda.
     *
     * @param {Array} list Lista de configuraciones.
     * @returns {Object}
     */
    function configMap( list ) {
        var map = {};
        if ( Array.isArray( list ) ) {
            list.forEach( function ( item ) {
                map[ item.id ] = item;
            } );
        }
        return map;
    }

    $( document ).ready( function () {
        var cfg    = configMap( tavoxMenu.config || [] );
        var $table = $( '#tavox-cat-table' );

        // Cargar categorías mediante AJAX para evitar problemas de autenticación con la API de Woo.
        $.post(
          ajaxurl,
          {
            action: 'tavox_get_all_categories',
          },
          function ( resp ) {
            if ( ! resp || ! resp.success ) {
              console.error( resp && resp.data && resp.data.message ? resp.data.message : 'Error al obtener categorías' );
              return;
            }
            var cats = resp.data;
            // Ordenar por posición guardada; las no configuradas al final.
            cats.sort( function ( a, b ) {
              var ca = cfg[ a.id ] ? cfg[ a.id ].order : 9999;
              var cb = cfg[ b.id ] ? cfg[ b.id ].order : 9999;
              return ca - cb;
            } );
            cats.forEach( function ( cat ) {
              var row = buildRow( cat, cfg[ cat.id ] );
              $table.append( row );
            } );
            $table.sortable( {
              handle: '.tavox-handle',
              axis: 'y',
            } );
          }
        );

        // Manejo del botón de guardar.
        $( '#tavox-save-cats' ).on( 'click', function ( e ) {
            e.preventDefault();
            var list = [];
            $table.children( 'tr' ).each( function ( index ) {
                var $tr     = $( this );
                var id      = parseInt( $tr.data( 'id' ), 10 );
                var enabled = $tr.find( '.tavox-enabled' ).is( ':checked' );
                var aliases = $tr.find( '.tavox-aliases' ).val();
                var menuScope = $tr.find( '.tavox-menu-scope' ).val() || 'zona_b';
                var serviceStation = $tr.find( '.tavox-service-station' ).val() || 'auto';
                if ( id ) {
                    list.push( { id: id, enabled: enabled, order: index + 1, aliases: aliases, menu_scope: menuScope, service_station: serviceStation } );
                }
            } );
            $.post( ajaxurl, {
                action: 'tavox_save_cats',
                nonce: tavoxMenu.nonce,
                data: JSON.stringify( list )
            } ).done( function ( resp ) {
                if ( resp && resp.success ) {
                    alert( resp.data.message || ( tavoxMenu.messages && tavoxMenu.messages.saveSuccess ) || 'Categorías guardadas.' );
                } else {
                    alert( resp.data && resp.data.message ? resp.data.message : ( tavoxMenu.messages && tavoxMenu.messages.saveError ) || 'Error al guardar.' );
                }
            } ).fail( function () {
                alert( ( tavoxMenu.messages && tavoxMenu.messages.saveError ) || 'Error al guardar.' );
            } );
        } );
    } );
}( jQuery ));
