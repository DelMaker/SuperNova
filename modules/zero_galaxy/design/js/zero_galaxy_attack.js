(function () {
  'use strict';

  function start() {
    var container = document.getElementById('zero_galaxy_attack');
    var data = document.getElementById('zero_galaxy_attack_data');
    if (!container || !data) { return; }
    var config;
    try { config = JSON.parse(data.textContent); } catch (error) { return; }
    var form = container.querySelector('form');
    if (!form) { return; }
    var step = Number(config.step);
    var target = config.target;
    var labels = config.labels || {};
    var budget = document.getElementById('zero_galaxy_attack_budget');
    var inputs = form.querySelectorAll('input[name^="ships["]');
    var submits = Array.prototype.slice.call(form.querySelectorAll('input[type="submit"],button[type="submit"]'));
    var originallyDisabled = submits.map(function (button) { return button.disabled; });

    function field(name, fallback) {
      var element = form.elements.namedItem(name);
      return element ? element.value : fallback;
    }
    Object.keys(config.fields || {}).forEach(function (name) {
      var element = form.elements.namedItem(name);
      if (!element) {
        element = document.createElement('input');
        element.type = 'hidden'; element.name = name;
        form.appendChild(element);
      }
      element.value = config.fields[name];
    });
    var nativeDistance = window.distance;
    if (typeof nativeDistance === 'function') {
      window.distance = function () {
        return String(field('galaxy', '')).trim() !== '' && Number(field('galaxy')) === 0
          ? Number(config.distance) : nativeDistance.apply(this, arguments);
      };
    }

    function matches(name, expected) {
      var value = String(field(name, '')).trim();
      return /^\d+$/.test(value) && Number(value) === Number(expected);
    }
    function applies() {
      var checked = form.querySelector('[name="target_mission"]:checked');
      var mission = Number(checked ? checked.value : field('target_mission', config.mission));
      if (step !== 2 && !mission) { mission = Number(config.mission); }
      return target && mission === 1
        && matches('galaxy', 0) && matches('system', target.system) && matches('planet', target.position)
        && matches('planet_type', 1);
    }
    function text(id, value) {
      var element = document.getElementById(id);
      if (element) { element.textContent = value; }
    }
    function integer(value) {
      var digits = String(value).trim();
      if (!/^\d+$/.test(digits)) { throw new Error('Invalid fleet cost data'); }
      return BigInt(digits);
    }
    function points(value) {
      var decimals = String(config.costScale || '1').length - 1;
      var digits = value.toString();
      while (digits.length <= decimals) { digits = '0' + digits; }
      var whole = decimals ? digits.slice(0, -decimals) : digits;
      var fraction = decimals ? digits.slice(-decimals).replace(/0+$/, '') : '';
      return whole.replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0') + (fraction ? '.' + fraction : '') + ' ' + labels.points;
    }
    function update() {
      var active = !!applies();
      budget.hidden = !active;
      var blocked = false;
      if (active) {
        var name = document.getElementById('zero_galaxy_attack_target');
        name.textContent = target.name + ' [0:' + target.system + ':' + target.position + ']';
        if (/^#[0-9a-fA-F]{6}$/.test(target.color)) { name.style.color = target.color; }
        try {
          if (!/^10*$/.test(String(config.costScale || '1'))) { throw new Error('Invalid fleet cost scale'); }
          var scale = integer(config.costScale || '1');
          var limit = integer(target.limit);
          var selected = integer(0);
          var quantity = integer(0);
          function add(id, amount) {
            var count = integer(amount === '' ? '0' : amount);
            quantity += count;
            if (count > 0) { selected += count * integer((config.unitCosts || {})[id]); }
          }
          if (step === 0) {
            Array.prototype.forEach.call(inputs, function (input) {
              var match = input.name.match(/^ships\[(\d+)\]$/);
              if (match) { add(match[1], input.value); }
            });
          } else {
            Object.keys(config.fixedFleet || {}).forEach(function (id) { add(id, config.fixedFleet[id]); });
          }
          selected = selected / scale * scale;
          var over = selected > limit;
          text('zero_galaxy_attack_limit', points(limit));
          text('zero_galaxy_attack_selected_label', labels.selected);
          text('zero_galaxy_attack_selected', points(selected));
          text('zero_galaxy_attack_remaining_label', over ? labels.over : labels.remaining);
          text('zero_galaxy_attack_remaining', points(over ? selected - limit : limit - selected));
          document.getElementById('zero_galaxy_attack_remaining').className = over ? 'c_r error' : 'c_r';
          text('zero_galaxy_attack_warning', '');
          blocked = over || quantity === integer(0);
        } catch (error) {
          text('zero_galaxy_attack_warning', labels.missing);
          blocked = true;
        }
      }
      submits.forEach(function (button, index) { button.disabled = originallyDisabled[index] || blocked; });
      return blocked;
    }
    var scheduled = false;
    function schedule() {
      if (!scheduled) {
        scheduled = true;
        window.setTimeout(function () { scheduled = false; update(); }, 0);
      }
    }
    ['input', 'change', 'click'].forEach(function (event) { form.addEventListener(event, schedule); });
    form.addEventListener('submit', function (event) {
      if (update()) { event.preventDefault(); event.stopImmediatePropagation(); }
    }, true);
    ['shortInfo', 'fl_calc_stats'].forEach(function (name) {
      var native = window[name];
      if (typeof native !== 'function') { return; }
      window[name] = function () {
        var result = native.apply(this, arguments);
        if (!window.fleet_global_update) { update(); }
        return result;
      };
    });
    if (window.jQuery && step === 0) {
      window.jQuery(container).find('[id^="ships"][id$="slide"]').on('slide.zeroGalaxyAttack slidechange.zeroGalaxyAttack', schedule);
    }
    if (step === 1 && typeof window.shortInfo === 'function') { window.shortInfo(); }
    else { update(); }
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); }
  else { start(); }
}());
