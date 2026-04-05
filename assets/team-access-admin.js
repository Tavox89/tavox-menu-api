(function ($) {
  'use strict';

  var config = window.tavoxTeamAccess || {};
  var scopeChoices = Array.isArray(config.scopeChoices) ? config.scopeChoices : [];
  var messages = config.messages || {};
  var nonce = String(config.nonce || '').trim();

  function getMessage(key, fallback) {
    return messages && messages[key] ? messages[key] : fallback;
  }

  function normalizeScopes(scopes) {
    var values = Array.isArray(scopes) ? scopes : [];
    var map = {};

    values.forEach(function (scope) {
      var value = String(scope || '').trim();
      if (value) {
        map[value] = value;
      }
    });

    return Object.keys(map);
  }

  function normalizeUser(rawUser) {
    var user = rawUser && typeof rawUser === 'object' ? rawUser : {};
    return {
      id: parseInt(user.id, 10) || 0,
      display_name: String(user.display_name || '').trim() || String(user.login || '').trim() || 'Usuario sin nombre',
      login: String(user.login || '').trim(),
      email: String(user.email || '').trim(),
      enabled: Boolean(user.enabled),
      pin: String(user.pin || '').trim(),
      scopes: normalizeScopes(user.scopes),
      implicit_full_access: Boolean(user.implicit_full_access),
    };
  }

  function renderEmptyState() {
    var $tbody = $('#tavox-team-access-table');
    if ($tbody.children('tr[data-user-id]').length > 0) {
      $tbody.find('tr.tavox-team-empty-state').remove();
      return;
    }

    $tbody.html(
      $('<tr />')
        .addClass('tavox-team-empty-state')
        .append(
          $('<td />', {
            colspan: 5,
            text: getMessage('emptyState', 'Todavia no hay accesos del equipo configurados.'),
          }).css({ color: '#646970', fontStyle: 'italic' })
        )
    );
  }

  function buildScopeGrid(user) {
    var $grid = $('<div />').css({
      display: 'grid',
      gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))',
      gap: '6px 10px',
    });

    scopeChoices.forEach(function (choice) {
      var scopeId = String(choice.id || '').trim();
      if (!scopeId) {
        return;
      }

      var label = String(choice.label || scopeId).trim();
      var path = String(choice.path || '').trim();
      var $checkbox = $('<input />', {
        type: 'checkbox',
        class: 'tavox-team-scope',
        value: scopeId,
      }).prop('checked', user.scopes.indexOf(scopeId) >= 0);

      var $label = $('<label />').css({
        display: 'flex',
        flexDirection: 'column',
        gap: '4px',
        padding: '8px 10px',
        border: '1px solid #dcdcde',
        borderRadius: '8px',
        background: '#fff',
      });

      $label.append(
        $('<span />').css({ display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 500 }).append($checkbox).append($('<span />').text(label))
      );

      if (path) {
        $label.append(
          $('<span />').addClass('description').css({ marginLeft: '24px' }).text(path)
        );
      }

      $grid.append($label);
    });

    return $grid;
  }

  function buildUserRow(rawUser) {
    var user = normalizeUser(rawUser);
    var $tr = $('<tr />').attr('data-user-id', user.id);
    var details = [];

    if (user.login) {
      details.push(user.login);
    }

    if (user.email) {
      details.push(user.email);
    }

    $tr.append(
      $('<td />')
        .append($('<strong />').text(user.display_name))
        .append(
          $('<p />')
            .addClass('description')
            .css({ margin: '6px 0 0' })
            .text(details.join(' · '))
        )
        .append(
          user.implicit_full_access
            ? $('<p />')
                .addClass('description')
                .css({ margin: '6px 0 0', color: '#8a2424' })
                .text('Este usuario ya tiene acceso operativo por su rol. Aqui puedes dejarle un PIN y pantallas explicitas si quieres separarlo del flujo general.')
            : ''
        )
    );

    $tr.append(
      $('<td />').append(
        $('<input />', {
          type: 'text',
          class: 'regular-text tavox-team-pin',
          value: user.pin,
          inputmode: 'numeric',
          placeholder: 'Ej: 2580',
        }).css({ width: '100%' })
      )
    );

    $tr.append($('<td />').append(buildScopeGrid(user)));

    $tr.append(
      $('<td />')
        .css({ textAlign: 'center' })
        .append(
          $('<input />', {
            type: 'checkbox',
            class: 'tavox-team-enabled',
          }).prop('checked', user.enabled)
        )
    );

    $tr.append(
      $('<td />').append(
        $('<button />', {
          type: 'button',
          class: 'button-link-delete tavox-team-remove',
          text: getMessage('removeAction', 'Quitar'),
        })
      )
    );

    return $tr;
  }

  function highlightRow($row) {
    if (!$row || !$row.length) {
      return;
    }

    $row.css('background', '#fff8d6');
    window.setTimeout(function () {
      $row.css('background', '');
    }, 1600);
  }

  function addUserRow(rawUser) {
    var user = normalizeUser(rawUser);
    if (!user.id) {
      return;
    }

    var $tbody = $('#tavox-team-access-table');
    var $existing = $tbody.find('tr[data-user-id="' + user.id + '"]');

    if ($existing.length) {
      highlightRow($existing);
      var $pinInput = $existing.find('.tavox-team-pin');
      if ($pinInput.length) {
        $pinInput.trigger('focus');
      }
      return;
    }

    $tbody.find('tr.tavox-team-empty-state').remove();
    $tbody.append(buildUserRow(user));
    renderEmptyState();
  }

  function renderSearchResults(items) {
    var $results = $('#tavox-team-user-search-results');
    $results.empty();

    if (!Array.isArray(items) || items.length < 1) {
      $results.append(
        $('<div />')
          .addClass('notice notice-info inline')
          .css({ margin: 0 })
          .append($('<p />').text(getMessage('searchNoResults', 'No encontramos usuarios con ese criterio.')))
      );
      return;
    }

    items.forEach(function (rawUser) {
      var user = normalizeUser(rawUser);
      if (!user.id) {
        return;
      }

      var details = [];
      if (user.login) {
        details.push(user.login);
      }
      if (user.email) {
        details.push(user.email);
      }

      var $row = $('<div />').css({
        display: 'flex',
        justifyContent: 'space-between',
        alignItems: 'center',
        gap: '12px',
        padding: '10px 12px',
        border: '1px solid #dcdcde',
        borderRadius: '8px',
        background: '#fff',
      });

      $row.append(
        $('<div />')
          .append($('<strong />').text(user.display_name))
          .append(
            $('<div />')
              .addClass('description')
              .css({ marginTop: '4px' })
              .text(details.join(' · '))
          )
      );

      $row.append(
        $('<button />', {
          type: 'button',
          class: 'button button-secondary tavox-team-add-user',
          text: getMessage('addAction', 'Agregar'),
        }).attr('data-user', JSON.stringify(user))
      );

      $results.append($row);
    });
  }

  function callAjax(action, extraData) {
    return $.post(
      ajaxurl,
      $.extend(
        {
          action: action,
          nonce: nonce,
        },
        extraData || {}
      )
    );
  }

  function loadExistingUsers() {
    callAjax('tavox_get_waiter_access_users')
      .done(function (response) {
        if (!response || !response.success) {
          window.alert(
            response && response.data && response.data.message
              ? response.data.message
              : getMessage('loadError', 'No se pudo cargar la configuracion del equipo.')
          );
          renderEmptyState();
          return;
        }

        (Array.isArray(response.data) ? response.data : []).forEach(addUserRow);
        renderEmptyState();
      })
      .fail(function () {
        window.alert(getMessage('loadError', 'No se pudo cargar la configuracion del equipo.'));
        renderEmptyState();
      });
  }

  function collectRows() {
    var rows = [];

    $('#tavox-team-access-table')
      .children('tr[data-user-id]')
      .each(function () {
        var $tr = $(this);
        var scopes = [];

        $tr.find('.tavox-team-scope:checked').each(function () {
          var value = String($(this).val() || '').trim();
          if (value) {
            scopes.push(value);
          }
        });

        rows.push({
          id: parseInt($tr.attr('data-user-id'), 10) || 0,
          enabled: $tr.find('.tavox-team-enabled').is(':checked'),
          pin: String($tr.find('.tavox-team-pin').val() || '').trim(),
          scopes: normalizeScopes(scopes),
        });
      });

    return rows;
  }

  function runSearch() {
    var term = String($('#tavox-team-user-search').val() || '').trim();

    if (term.length < 2) {
      renderSearchResults([]);
      $('#tavox-team-user-search-results')
        .empty()
        .append(
          $('<div />')
            .addClass('notice notice-info inline')
            .css({ margin: 0 })
            .append($('<p />').text(getMessage('searchEmpty', 'Escribe al menos 2 caracteres para buscar.')))
        );
      return;
    }

    callAjax('tavox_search_waiter_access_users', { term: term })
      .done(function (response) {
        if (!response || !response.success) {
          window.alert(
            response && response.data && response.data.message
              ? response.data.message
              : getMessage('loadError', 'No se pudo cargar la configuracion del equipo.')
          );
          return;
        }

        renderSearchResults(response.data);
      })
      .fail(function () {
        window.alert(getMessage('loadError', 'No se pudo cargar la configuracion del equipo.'));
      });
  }

  $(document).ready(function () {
    loadExistingUsers();

    $('#tavox-team-user-search-button').on('click', function (event) {
      event.preventDefault();
      runSearch();
    });

    $('#tavox-team-user-search').on('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        runSearch();
      }
    });

    $(document).on('click', '.tavox-team-add-user', function (event) {
      event.preventDefault();

      try {
        var user = JSON.parse(String($(this).attr('data-user') || '{}'));
        addUserRow(user);
      } catch (error) {
        window.console && window.console.error(error);
      }
    });

    $(document).on('click', '.tavox-team-remove', function (event) {
      event.preventDefault();
      $(this).closest('tr').remove();
      renderEmptyState();
    });

    $('#tavox-save-team-access').on('click', function (event) {
      event.preventDefault();

      var $button = $(this);
      var originalText = $button.text();
      var payload = collectRows();

      $button.prop('disabled', true).text('Guardando...');

      callAjax('tavox_save_waiter_access_users', {
        data: JSON.stringify(payload),
      })
        .done(function (response) {
          if (!response || !response.success) {
            window.alert(
              response && response.data && response.data.message
                ? response.data.message
                : getMessage('saveError', 'No se pudieron guardar los accesos del equipo.')
            );
            return;
          }

          $('#tavox-team-access-table').empty();
          (Array.isArray(response.data && response.data.items) ? response.data.items : []).forEach(addUserRow);
          renderEmptyState();
          window.alert(
            response && response.data && response.data.message
              ? response.data.message
              : getMessage('saveSuccess', 'Accesos del equipo actualizados.')
          );
        })
        .fail(function (xhr) {
          var response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
          window.alert(
            response && response.data && response.data.message
              ? response.data.message
              : getMessage('saveError', 'No se pudieron guardar los accesos del equipo.')
          );
        })
        .always(function () {
          $button.prop('disabled', false).text(originalText);
        });
    });
  });
})(jQuery);
