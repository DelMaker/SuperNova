(function () {
  'use strict';

  function start() {
    var container = document.getElementById('zero_galaxy_native');
    var data = document.getElementById('zero_galaxy_data');
    if (!container || !data) {
      return;
    }
    var config;
    try {
      config = JSON.parse(data.textContent);
    } catch (error) {
      return;
    }

    var rifts = {};
    var countdowns = [];
    var reloading = false;
    var sending = false;
    var clock = window.performance && typeof window.performance.now === 'function'
      ? function () { return window.performance.now(); }
      : function () { return Date.now(); };
    var startedAt = clock();
    var serverNow = Number(config.now);
    var refreshSeconds = Number(config.refreshSeconds);
    if (!isFinite(serverNow) || serverNow <= 0) {
      serverNow = Date.now() / 1000;
    }
    if (!isFinite(refreshSeconds) || refreshSeconds <= 0) {
      refreshSeconds = 30;
    }
    var reloadAt = serverNow + Math.max(2, refreshSeconds);

    function removeAll(selector, root) {
      var nodes = (root || container).querySelectorAll(selector);
      for (var i = 0; i < nodes.length; i++) {
        nodes[i].parentNode.removeChild(nodes[i]);
      }
    }

    // Keep the native popup layout and spy control, removing other mission controls.
    var planetTemplate = container.querySelector('#planet_template');
    if (planetTemplate) {
      removeAll('[go], .ownmissile, .ownharvest', planetTemplate);
    }
    removeAll('[go="galaxy"][mode="name"], [href*="mode=name"]');
    var expedition = container.querySelector('.ownexpedition');
    if (expedition) {
      var expeditionRow = expedition;
      while (expeditionRow && expeditionRow.tagName !== 'TR') {
        expeditionRow = expeditionRow.parentNode;
      }
      if (expeditionRow) {
        expeditionRow.parentNode.removeChild(expeditionRow);
      }
    }
    var profileControls = container.querySelectorAll('.uni_show_user, .uni_show_ally');
    for (var i = 0; i < profileControls.length; i++) {
      profileControls[i].classList.remove('uni_show_user', 'uni_show_ally', 'button_pseudo');
    }
    removeAll('#user_template [go], #ally_template [go], #ally_template a');

    for (var r = 0; r < config.rifts.length; r++) {
      var rift = config.rifts[r];
      rifts[rift.position] = rift;
      var row = container.querySelector('.uni_planet_row[planet_pos="' + rift.position + '"]');
      if (!row) {
        continue;
      }
      row.setAttribute('data-rift-id', rift.id);
      row.setAttribute('data-phase', rift.phase);
      row.classList.add('zero-galaxy-phase-' + rift.phase);
      var phaseObject = row.querySelector('.uni_show_planet[planet_type="1"]');
      var phaseImage = phaseObject && phaseObject.querySelector('img');
      if (phaseImage && rift.imageUrl) {
        // Also override dimming from an older compiled module template.
        phaseObject.style.opacity = '1';
        phaseImage.setAttribute('src', rift.imageUrl);
        phaseImage.setAttribute('alt', rift.phaseLabel);
      }
      if (!rift.canSpy) {
        removeAll('[onclick*="doit"]', row);
        removeAll('.uni_object_type', row);
      }
      var name = row.querySelector('.g_galaxy_row');
      if (name) {
        var details = document.createElement('div');
        details.className = 'zero-galaxy-next';
        details.appendChild(document.createTextNode(rift.nextLabel + ': '));
        var countdown = document.createElement('span');
        countdown.className = 'zero-galaxy-countdown';
        countdown.setAttribute('data-next-at', rift.nextAt);
        details.appendChild(countdown);
        name.appendChild(details);
        countdowns.push({element: countdown, nextAt: Number(rift.nextAt)});
      }
      if (Number(rift.nextAt) > 0) {
        reloadAt = Math.min(reloadAt, Math.max(serverNow + 2, Number(rift.nextAt)));
      }
    }

    var nativePlanetPopup = window.sn_universe_show_planet;
    if (typeof nativePlanetPopup === 'function') {
      window.sn_universe_show_planet = function (that) {
        var position = Number(that.parents('.uni_planet_row').attr('planet_pos'));
        var rift = rifts[position];
        if (!rift || !rift.canSpy) {
          return null;
        }
        var popup = nativePlanetPopup.call(this, that);
        if (popup && popup.html && rift.imageUrl) {
          var content = document.createElement('div');
          content.innerHTML = popup.html;
          var image = content.querySelector('.uni_popup_planet img');
          if (image) {
            image.setAttribute('src', rift.imageUrl);
            image.setAttribute('alt', rift.phaseLabel);
            popup.html = content.innerHTML;
          }
        }
        return popup;
      };
    }

    // A native popup is moved outside our wrapper. Block its forbidden controls too.
    document.addEventListener('click', function (event) {
      var node = event.target;
      var hasControl = false;
      var belongsHere = false;
      while (node && node !== document) {
        if (node.nodeType === 1) {
          hasControl = hasControl || node.hasAttribute('go')
            || node.classList.contains('ownmissile') || node.classList.contains('ownharvest');
          belongsHere = belongsHere || node === container || node.classList.contains('planet_template')
            || node.classList.contains('user_template') || node.classList.contains('ally_template')
            || node.classList.contains('debris_template');
        }
        node = node.parentNode;
      }
      if (belongsHere && hasControl) {
        event.preventDefault();
        event.stopPropagation();
      }
    }, true);

    function notify(message, failed) {
      if (typeof window.addToTable === 'function') {
        window.addToTable(message, failed ? 1 : 0);
      }
      var source = container.querySelector('#message_template');
      if (source && typeof window.popup_show === 'function') {
        var text = document.createElement('span');
        text.textContent = message;
        window.popup_show(source.innerHTML.replace(/\[MESSAGE\]/g, function () { return text.innerHTML; })
          .replace(/\[CLASS\]/g, failed ? 'error_bg' : 'ok_bg'));
      }
    }

    var nativeDoit = window.doit;
    window.doit = function (missionId, position, planetType, shipcount) {
      if (Number(window.uni_galaxy) !== 0) {
        return typeof nativeDoit === 'function' ? nativeDoit.apply(this, arguments) : false;
      }
      var rift = rifts[Number(position)];
      if (Number(missionId) !== Number(config.spyMission) || Number(planetType) !== Number(config.planetType)) {
        notify(config.labels.badRequest, true);
        return false;
      }
      if (!rift || !rift.canSpy) {
        notify(config.labels.missing, true);
        return false;
      }
      if (sending) {
        return false;
      }
      sending = true;
      window.jQuery.post(config.spyUrl, {
        galaxy: 0, system: Number(window.uni_system), planet: Number(position),
        planet_type: Number(config.planetType), mission: Number(config.spyMission),
        shipcount: Math.max(1, Math.floor(Number(shipcount) || Number(config.spyCount))), rift_id: rift.id
      }, function (response) {
        var parts = String(response).split('|');
        var success = parts.length > 1;
        if (success) {
          var spies = parts[1].match(/^flt_spies=(\d+)$/);
          var spiesElement = document.getElementById('flt_spies');
          if (spies && spiesElement) {
            spiesElement.textContent = spies[1];
          }
        }
        notify(parts[0], !success);
      }, 'text').fail(function () {
        notify(config.labels.failed, true);
      }).always(function () {
        sending = false;
      });
      return false;
    };

    function now() {
      return serverNow + Math.max(0, clock() - startedAt) / 1000;
    }
    var timeLabels = config.labels.time;
    var plurals = new Intl.PluralRules(timeLabels.locale);
    function timeEstimate(remaining) {
      if (remaining < 3600) {
        return timeLabels.lessHour;
      }
      var amount = Math.ceil(remaining / 3600);
      var unit = 'hours';
      if (amount >= 24) {
        amount = Math.ceil(remaining / 86400);
        unit = 'days';
        if (amount >= 7) {
          amount = Math.ceil(remaining / 604800);
          unit = 'weeks';
        }
      }
      var forms = timeLabels[unit];
      return amount + ' ' + (forms[plurals.select(amount)] || forms.other);
    }
    function tick() {
      var currentTime = now();
      for (var i = 0; i < countdowns.length; i++) {
        var remaining = Math.max(0, Math.ceil(countdowns[i].nextAt - currentTime));
        countdowns[i].element.textContent = timeEstimate(remaining);
      }
      var focused = document.activeElement;
      var editing = focused && (focused.id === 'galaxy' || focused.id === 'system');
      if (!reloading && !sending && currentTime >= reloadAt && !document.hidden && !editing) {
        reloading = true;
        window.location.reload();
      }
    }
    var inputs = container.querySelectorAll('#galaxy, #system');
    for (var input = 0; input < inputs.length; input++) {
      inputs[input].addEventListener('blur', function () { window.setTimeout(tick, 0); });
    }
    var form = container.querySelector('#galaxy_form');
    if (form) {
      form.addEventListener('submit', function () { reloading = true; });
    }
    document.addEventListener('visibilitychange', tick);
    window.setInterval(tick, 1000);
    tick();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
}());
