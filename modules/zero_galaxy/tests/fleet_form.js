'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../design/js/zero_galaxy_attack.js'), 'utf8');
const template = fs.readFileSync(path.join(__dirname, '../design/templates/OpenGame/zero_galaxy_attack.tpl.html'), 'utf8');

function fixture(options = {}) {
  const config = Object.assign({
    step: 0, distance: 20000, mission: 1,
    fields: {source_id: 11, token_at: 1700000000, token: 'signed'},
    target: {system: 18, position: 6, name: '<Большой> разлом', color: '#7b2cbf', limit: '100'},
    unitCosts: {206: '20'}, costScale: '1', fixedFleet: {206: '3'},
    labels: {points: 'очк.', selected: 'Стоимость', remaining: 'Осталось', over: 'Превышение', missing: 'Нет данных'},
  }, options);
  const nodes = {};
  for (const match of template.matchAll(/id="([^"]+)"/g)) {
    nodes[match[1]] = {hidden: true, style: {}, textContent: '', className: ''};
  }
  const fields = Object.fromEntries(Object.entries({
    galaxy: '0', system: '18', planet: '6', planet_type: '1',
    target_mission: String(config.mission), speed: '10', source_id: 'old',
  }).map(([name, value]) => [name, {name, value, readOnly: false, disabled: false}]));
  const ships = [{name: 'ships[206]', value: '3'}];
  const button = {disabled: !!options.disabled};
  const radios = config.step === 2 ? [1, 2, 6, 8].map(value => ({
    name: 'target_mission', value: String(value), checked: value === config.mission,
  })) : [];
  const handlers = {};
  const timers = [];
  let appended = 0;
  const form = {
    elements: {namedItem(name) {
      return name === 'target_mission' && radios.length
        ? {value: (radios.find(radio => radio.checked) || {}).value || ''} : fields[name];
    }},
    appendChild(element) { fields[element.name] = element; appended++; },
    querySelector: () => radios.find(radio => radio.checked) || null,
    querySelectorAll: selector => selector.includes('ships[') ? ships : [button],
    addEventListener(event, handler) { (handlers[event] ||= []).push(handler); },
  };
  const container = nodes.zero_galaxy_attack;
  container.querySelector = () => form;
  nodes.zero_galaxy_attack_data.textContent = JSON.stringify(config);
  const document = {
    readyState: 'complete', getElementById: id => nodes[id] || null,
    createElement: () => ({}),
  };
  const context = vm.createContext({document, console, BigInt: options.noBigInt ? undefined : BigInt,
    setTimeout(callback) { timers.push(callback); },
    distance: () => 987, shortInfo: () => 'native shortInfo',
    fl_calc_stats: () => 'native stats', fleet_global_update: false,
  });
  context.window = context;
  vm.runInContext(source, context, {filename: 'zero_galaxy_attack.js'});
  function fire(event) {
    const result = {prevented: false, stopped: false,
      preventDefault() { this.prevented = true; }, stopImmediatePropagation() { this.stopped = true; }};
    for (const handler of handlers[event] || []) { handler(result); }
    while (timers.length) { timers.shift()(); }
    return result;
  }
  function mission(id) {
    for (const radio of radios) { radio.checked = Number(radio.value) === id; }
    fire('change');
  }
  return {config, context, form, fields, ships, button, nodes, fire, mission, appended: () => appended};
}

const selection = fixture();
assert.equal(selection.nodes.zero_galaxy_attack_budget.hidden, false);
assert.equal(selection.nodes.zero_galaxy_attack_selected.textContent, '60 очк.');
assert.equal(selection.nodes.zero_galaxy_attack_target.textContent, '<Большой> разлом [0:18:6]');
assert.equal(selection.appended(), 2, 'Existing native fields are updated rather than duplicated');
assert.equal(selection.fields.token.value, 'signed');
selection.ships[0].value = '6';
selection.context.fl_calc_stats();
assert.equal(selection.button.disabled, true, 'Native bulk-selection hooks update the live cap');
assert.equal(selection.fire('submit').prevented, true);
selection.ships[0].value = '5';
selection.fire('input');
assert.equal(selection.button.disabled, false);
assert.equal(selection.fire('submit').prevented, false);

const large = fixture({target: {system: 18, position: 6, name: 'Великий', limit: '9007199254740992'}, unitCosts: {206: '1'}});
large.ships[0].value = '9007199254740993';
large.fire('input');
assert.equal(large.nodes.zero_galaxy_attack_remaining.textContent, '1 очк.', 'Costs above Number precision retain all digits');
assert.equal(large.button.disabled, true);
const fractional = fixture({costScale: '1000000', unitCosts: {206: '600000'},
  target: {system: 18, position: 6, name: 'Малый', limit: '1000000'}});
assert.equal(fractional.nodes.zero_galaxy_attack_selected.textContent, '1 очк.', 'Round the combined cost once like the server');
assert.equal(fractional.button.disabled, false);
fractional.ships[0].value = '4'; fractional.fire('change');
assert.equal(fractional.button.disabled, true);

const coordinates = fixture({step: 1, fixedFleet: {206: '6'}});
assert.equal(coordinates.context.distance(), 20000);
assert.equal(coordinates.button.disabled, true);
coordinates.fields.target_mission.value = '0';
coordinates.context.shortInfo();
assert.equal(coordinates.nodes.zero_galaxy_attack_budget.hidden, false, 'Native mission placeholder retains the requested attack cap');
for (const [name, value] of [['galaxy', '1'], ['galaxy', ''], ['system', '19'], ['planet', '7'], ['planet_type', '3']]) {
  const previous = coordinates.fields[name].value;
  coordinates.fields[name].value = value;
  coordinates.context.shortInfo();
  assert.equal(coordinates.nodes.zero_galaxy_attack_budget.hidden, true, 'Retargeting ' + name + ' hides the stale cap');
  assert.equal(coordinates.button.disabled, false);
  if (name === 'galaxy' && value === '1') { assert.equal(coordinates.context.distance(), 987); }
  coordinates.fields[name].value = previous;
  coordinates.context.shortInfo();
  assert.equal(coordinates.nodes.zero_galaxy_attack_budget.hidden, false);
}

const confirmation = fixture({step: 2, fixedFleet: {206: '6'}});
assert.equal(confirmation.button.disabled, true);
for (const mission of [2, 6, 8]) {
  confirmation.mission(mission);
  assert.equal(confirmation.nodes.zero_galaxy_attack_budget.hidden, true, 'Only a solo attack has an attack budget');
  assert.equal(confirmation.button.disabled, false);
  assert.equal(confirmation.fire('submit').prevented, false);
}
confirmation.mission(1);
assert.equal(confirmation.nodes.zero_galaxy_attack_budget.hidden, false);
assert.equal(confirmation.button.disabled, true);
const ordinary = fixture({step: 2, target: null, noBigInt: true});
assert.equal(ordinary.nodes.zero_galaxy_attack_budget.hidden, true);
assert.equal(ordinary.button.disabled, false);
const unavailable = fixture({step: 2, noBigInt: true});
assert.equal(unavailable.button.disabled, true);
unavailable.mission(6);
assert.equal(unavailable.button.disabled, false, 'An unavailable attack calculator must not block other missions');
const nativeBlocked = fixture({step: 2, disabled: true});
nativeBlocked.mission(6);
assert.equal(nativeBlocked.button.disabled, true, 'Keep the native disabled state');

console.log('Fleet form: native routing, solo attack caps, editable targets, mission switches and exact costs passed; no browser.');
