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

    // Keep native reconnaissance and debris collection controls.
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

    // Dark category colors need a light outline on the dark universe background.
    function isDark(color) {
      var luminance = [0.2126, 0.7152, 0.0722].reduce(function (sum, weight, index) {
        var channel = parseInt(color.substr(1 + index * 2, 2), 16) / 255;
        return sum + weight * (channel <= 0.03928 ? channel / 12.92 : Math.pow((channel + 0.055) / 1.055, 2.4));
      }, 0);
      return luminance < 0.15;
    }

    function profileDetails(rift) {
      var details = document.createElement('div');
      details.className = 'zero-galaxy-profile';
      var categoryText = document.createElement('span');
      categoryText.className = 'zero-galaxy-category';
      var indicator = document.createElement('span');
      indicator.className = 'zero-galaxy-category-color';
      indicator.style.backgroundColor = rift.category.color;
      indicator.setAttribute('aria-hidden', 'true');
      categoryText.appendChild(indicator);
      categoryText.appendChild(document.createTextNode(config.labels.category + ': ' + rift.category.name));
      details.appendChild(categoryText);

      var limit = document.createElement('span');
      limit.className = 'zero-galaxy-fleet-limit';
      var formattedLimit = rift.fleetLimit.replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0');
      limit.textContent = config.labels.fleetLimit + ': ' + formattedLimit + ' ' + config.labels.points;
      details.appendChild(limit);
      return details;
    }

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
        phaseImage.setAttribute('src', rift.imageUrl);
        phaseImage.setAttribute('alt', rift.name || rift.phaseLabel);
      }
      if (!rift.canSpy) {
        removeAll('[onclick*="doit"]', row);
        removeAll('.uni_object_type', row);
      }
      var name = row.querySelector('.g_galaxy_row');
      if (name) {
        var nameText = name.querySelector('span[planet_type="1"]');
        if (rift.category && nameText) {
          nameText.style.setProperty('color', rift.category.color, 'important');
          if (isDark(rift.category.color)) {
            nameText.style.textShadow = '0 0 2px rgba(255,255,255,0.85)';
          }
        }
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
        // Native replacements interpret dollar sequences and later template tokens.
        // Generate the shell with an empty name, then insert the literal title as text.
        var nativeRow = window.uni_row && window.uni_row[position];
        var nativeName = nativeRow && nativeRow.planet_name;
        var popup;
        try {
          if (nativeRow) {
            nativeRow.planet_name = '';
          }
          popup = nativePlanetPopup.call(this, that);
        } finally {
          if (nativeRow) {
            nativeRow.planet_name = nativeName;
          }
        }
        if (popup && popup.html) {
          var content = document.createElement('div');
          content.innerHTML = popup.html;
          var heading = content.querySelector('table.planet_template th');
          if (heading) {
            heading.textContent = heading.textContent.trim() + ' ' + (rift.name || nativeName || '');
          }
          var image = content.querySelector('.uni_popup_planet img');
          if (image && rift.imageUrl) {
            image.setAttribute('src', rift.imageUrl);
            image.setAttribute('alt', rift.name || rift.phaseLabel);
          }
          var popupTable = content.querySelector('table.planet_template');
          if (rift.category && popupTable) {
            var profileCell = popupTable.insertRow(1).insertCell(0);
            profileCell.colSpan = 2;
            profileCell.appendChild(profileDetails(rift));
          }
          var actions = rift.canAttack && content.querySelector('td[planet_planet][planet_type="1"]');
          if (actions) {
            var attack = document.createElement('a');
            attack.className = 'zero-galaxy-attack button_pseudo';
            attack.href = rift.attackUrl;
            attack.textContent = config.labels.attack;
            actions.insertBefore(attack, actions.firstChild);
          }
          popup.html = content.innerHTML;
        }
        return popup;
      };
    }

    // A native popup is moved outside our wrapper. Block its forbidden controls too.
    document.addEventListener('click', function (event) {
      var node = event.target;
      var hasControl = false;
      var hasHarvest = false;
      var debrisPopup = false;
      var belongsHere = false;
      while (node && node !== document) {
        if (node.nodeType === 1) {
          hasControl = hasControl || node.hasAttribute('go')
            || node.classList.contains('ownmissile');
          hasHarvest = hasHarvest || node.classList.contains('ownharvest');
          debrisPopup = debrisPopup || node.classList.contains('debris_template');
          belongsHere = belongsHere || node === container || node.classList.contains('planet_template')
            || node.classList.contains('user_template') || node.classList.contains('ally_template')
            || node.classList.contains('debris_template');
        }
        node = node.parentNode;
      }
      if (belongsHere && (hasControl || (hasHarvest && !debrisPopup))) {
        event.preventDefault();
        event.stopPropagation();
      }
    }, true);

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
      if (!reloading && currentTime >= reloadAt && !document.hidden && !editing) {
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
