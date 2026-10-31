(function () {
  'use strict';

  function start() {
    var container = document.getElementById('zero_galaxy_attack');
    var data = document.getElementById('zero_galaxy_attack_data');
    if (!container || !data) {
      return;
    }
    var config;
    try {
      config = JSON.parse(data.textContent);
    } catch (error) {
      return;
    }

    var step = Number(config.step);
    var form = container.querySelector('form');
    var labels = config.labels;
    var inputs = form ? form.querySelectorAll('input[name^="ships["]') : [];
    var submits = form ? form.querySelectorAll('input[type="submit"],button[type="submit"]') : [];
    var originallyDisabled = [];
    for (var s = 0; s < submits.length; s++) {
      originallyDisabled.push(submits[s].disabled);
    }

    var banner = document.createElement('div');
    banner.className = 'zero-galaxy-attack-budget';
    banner.style.cssText = 'margin:0.8em 0;';
    var title = document.createElement('h3');
    title.appendChild(document.createTextNode(labels.attackTitle + ': '));
    var target = document.createElement('span');
    target.textContent = config.targetName || config.coords;
    if (typeof config.color === 'string' && /^#[0-9a-fA-F]{6}$/.test(config.color)) {
      target.style.color = config.color;
    }
    title.appendChild(target);
    if (config.targetName && config.coords) {
      title.appendChild(document.createTextNode(' ' + config.coords));
    }
    banner.appendChild(title);
    if (config.groupName) {
      var groupName = document.createElement('p');
      groupName.textContent = config.groupName;
      banner.appendChild(groupName);
    }
    (config.groups || []).forEach(function (group) {
      var invite = document.createElement('p');
      var join = document.createElement('a');
      join.href = group.url;
      join.textContent = labels.join + ': ' + group.name;
      invite.appendChild(join);
      banner.appendChild(invite);
    });
    var table = document.createElement('table');
    table.style.cssText = 'width:auto;min-width:22em;';
    banner.appendChild(table);
    var body = document.createElement('tbody');
    table.appendChild(body);
    function metric(label) {
      var row = body.insertRow();
      var heading = row.insertCell();
      heading.className = 'c_l';
      heading.textContent = label;
      var value = row.insertCell();
      value.className = 'c_r';
      value.style.cssText = 'white-space:nowrap;font-variant-numeric:tabular-nums;';
      return {row: row, heading: heading, value: value};
    }
    var limitMetric = metric(labels.limit);
    var selectedMetric = metric(labels.selected);
    var remainingMetric = metric(labels.remaining);
    var acsArrival = Number(config.groupArrival || 0);
    var acsArrivalMetric;
    var acsReturnMetric;
    if (config.groupId && isFinite(acsArrival) && acsArrival > 0) {
      acsArrivalMetric = metric(labels.acsArrival);
      acsReturnMetric = metric(labels.acsReturn);
      var waitHint = document.createElement('p');
      waitHint.textContent = labels.acsWait;
      banner.appendChild(waitHint);
    }
    var warning = document.createElement('div');
    warning.className = 'error';
    warning.style.marginTop = '0.35em';
    banner.appendChild(warning);
    container.insertBefore(banner, form || container.firstChild);

    function setField(name, value) {
      var field = form.elements.namedItem(name);
      if (!field || !field.tagName) {
        field = document.createElement('input');
        field.type = 'hidden';
        field.name = name;
        form.appendChild(field);
      }
      field.value = String(value);
    }
    if (form) {
      form.action = config.nextUrl;
      Object.keys(config.fields || {}).forEach(function (name) {
        setField(name, config.fields[name]);
      });
      var missionFields = form.querySelectorAll('[name="target_mission"]');
      if (!missionFields.length) {
        setField('target_mission', config.mission || 1);
      }
      for (var m = 0; m < missionFields.length; m++) {
        if (missionFields[m].type === 'radio') {
          missionFields[m].checked = missionFields[m].value === String(config.mission || 1);
        } else {
          missionFields[m].value = String(config.mission || 1);
        }
      }
      setField('fleet_group', config.groupId || 0);
      if (step === 1) {
        var coordinates = String(config.coords).match(/^\[0:(\d+):(\d+)\]$/);
        ['galaxy', 'system', 'planet'].forEach(function (name, index) {
          var field = form.elements.namedItem(name);
          if (field && field.tagName) {
            field.readOnly = true;
            if (coordinates) {
              field.value = index === 0 ? '0' : coordinates[index];
            }
          }
        });
        var planetType = form.querySelector('select[name="planet_type"]');
        if (planetType) {
          planetType.value = '1';
          planetType.disabled = true;
          var fixedType = document.createElement('input');
          fixedType.type = 'hidden';
          fixedType.name = 'planet_type';
          fixedType.value = '1';
          form.appendChild(fixedType);
        }
        // The native destination shortcuts must not retarget this rift wizard.
        var tableIndex = 0;
        for (var child = 0; child < form.children.length; child++) {
          if (form.children[child].tagName === 'TABLE' && ++tableIndex === 2) {
            form.children[child].style.display = 'none';
          }
        }
      }
    }

    var fixedDistance = Number(config.distance);
    if (isFinite(fixedDistance) && fixedDistance > 0) {
      window.distance = function () { return fixedDistance; };
    }

    function updateAcsTimes() {
      if (!acsArrivalMetric) { return; }
      function formatTime(seconds, local) {
        var date = new Date(seconds * 1000);
        try {
          return local ? date.toLocaleString() : date.toLocaleString(undefined, {timeZone: config.serverTimeZone});
        } catch (error) { return date.toLocaleString(); }
      }
      function setTime(id, seconds, local) {
        var field = document.getElementById(id);
        if (field) { field.textContent = formatTime(seconds, local); }
      }
      acsArrivalMetric.value.textContent = formatTime(acsArrival, false);
      setTime('time_dst', acsArrival, false);
      setTime('time_dst_local', acsArrival, true);
      var speedField = form && form.elements.namedItem('speed');
      var speed = speedField && speedField.tagName ? String(speedField.value) : '10';
      var naturalDuration = Number((config.travelDurations || {})[speed] || config.naturalDuration || 0);
      acsReturnMetric.row.style.display = step > 0 && naturalDuration > 0 ? '' : 'none';
      if (step > 0 && naturalDuration > 0) {
        var returnAt = acsArrival + naturalDuration;
        acsReturnMetric.value.textContent = formatTime(returnAt, false);
        setTime('time_src', returnAt, false);
        setTime('time_src_local', returnAt, true);
      }
    }
    var nativeShortInfo = window.shortInfo;
    if (acsArrivalMetric && typeof nativeShortInfo === 'function') {
      window.shortInfo = function () {
        var result = nativeShortInfo.apply(this, arguments);
        updateAcsTimes();
        return result;
      };
    }

    var zero;
    var limit;
    var costScale;
    var decimalPlaces;
    try {
      if (typeof BigInt !== 'function' || !/^\d+$/.test(config.limit)
        || !/^10*$/.test(String(config.costScale || '1'))) {
        throw new Error('Invalid fleet cost data');
      }
      zero = BigInt('0');
      limit = BigInt(config.limit);
      costScale = BigInt(config.costScale || '1');
      decimalPlaces = String(config.costScale || '1').length - 1;
    } catch (error) {
      warning.textContent = labels.missing;
      for (var disabled = 0; disabled < submits.length; disabled++) {
        submits[disabled].disabled = true;
      }
      if (form) {
        form.addEventListener('submit', function (event) { event.preventDefault(); }, true);
      }
      return;
    }

    function integer(value) {
      var text = String(value).trim();
      return /^\d+$/.test(text) ? BigInt(text) : null;
    }
    function formatPoints(value) {
      var text = value.toString();
      while (text.length <= decimalPlaces) {
        text = '0' + text;
      }
      var whole = decimalPlaces ? text.slice(0, -decimalPlaces) : text;
      var fraction = decimalPlaces ? text.slice(-decimalPlaces).replace(/0+$/, '') : '';
      return whole.replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0') + (fraction ? '.' + fraction : '') + ' ' + labels.points;
    }
    limitMetric.value.textContent = formatPoints(limit);
    var showSelection = step === 0 || !!config.fixedFleet;
    selectedMetric.row.style.display = remainingMetric.row.style.display = showSelection ? '' : 'none';

    function update() {
      updateAcsTimes();
      var selected = zero;
      var quantity = zero;
      var invalid = false;
      function addShip(id, amount, existing) {
        var count = integer(amount === '' ? '0' : amount);
        var price = integer((config.unitCosts || {})[id]);
        if (count === null || (count > zero && price === null)) {
          invalid = true;
          return;
        }
        if (!existing) { quantity += count; }
        if (count > zero) {
          selected += count * price;
        }
      }
      if (step === 0) {
        for (var i = 0; i < inputs.length; i++) {
          var match = inputs[i].name.match(/^ships\[(\d+)\]$/);
          if (match) {
            addShip(match[1], inputs[i].value);
          }
        }
      } else {
        Object.keys(config.fixedFleet || {}).forEach(function (id) { addShip(id, config.fixedFleet[id]); });
      }
      Object.keys(config.existingFleet || {}).forEach(function (id) { addShip(id, config.existingFleet[id], true); });
      // Match the server: retain fractional unit costs, then floor the total once.
      selected = selected / costScale * costScale;
      var over = selected > limit;
      selectedMetric.value.textContent = formatPoints(selected);
      remainingMetric.heading.textContent = over ? labels.over : labels.remaining;
      remainingMetric.value.textContent = formatPoints(over ? selected - limit : limit - selected);
      remainingMetric.value.className = over || invalid ? 'c_r error' : 'c_r';
      warning.textContent = invalid ? labels.missing : '';
      var blocked = showSelection && (over || invalid || quantity === zero);
      for (var button = 0; button < submits.length; button++) {
        submits[button].disabled = originallyDisabled[button] || blocked;
      }
      return blocked;
    }
    var scheduled = false;
    function scheduleUpdate() {
      if (!scheduled) {
        scheduled = true;
        window.setTimeout(function () { scheduled = false; update(); }, 0);
      }
    }
    if (form) {
      form.addEventListener('input', scheduleUpdate);
      form.addEventListener('change', scheduleUpdate);
      form.addEventListener('keyup', scheduleUpdate);
      form.addEventListener('click', scheduleUpdate);
      form.addEventListener('submit', function (event) {
        setField('fleet_group', config.groupId || 0);
        if (update()) {
          event.preventDefault();
          event.stopImmediatePropagation();
        }
      }, true);
    }
    var nativeStats = window.fl_calc_stats;
    if (step === 0 && typeof nativeStats === 'function') {
      window.fl_calc_stats = function () {
        var result = nativeStats.apply(this, arguments);
        if (!window.fleet_global_update) {
          update();
        }
        return result;
      };
      if (window.jQuery) {
        window.jQuery(container).find('[id^="ships"][id$="slide"]').on('slide.zeroGalaxyAttack slidechange.zeroGalaxyAttack', scheduleUpdate);
      }
      if (document.getElementById('int_fleet_capacity')) {
        window.fl_calc_stats();
      }
    } else if (step === 1 && typeof window.shortInfo === 'function') {
      window.shortInfo();
    }
    update();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
}());
