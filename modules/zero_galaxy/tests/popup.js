'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../../..');
const nativeSource = fs.readFileSync(path.join(root, 'js/sn_universe.js'), 'utf8');
const moduleSource = fs.readFileSync(path.join(__dirname, '../design/js/zero_galaxy.js'), 'utf8');
const universe = fs.readFileSync(path.join(root, 'design/templates/OpenGame/universe.tpl.html'), 'utf8');
const planetTemplate = universe.match(/<span id="planet_template">([\s\S]*?)<\/span>/)[1]
  .replace(/\{galaxy\}/g, '0').replace(/\{system\}/g, '18');
const debrisTemplate = universe.match(/<span id="debris_template">([\s\S]*?)<\/span>/)[1]
  .replace(/\{galaxy\}/g, '0').replace(/\{system\}/g, '18');
const escapeText = text => String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const decodeText = text => text.replace(/&nbsp;/g, '\u00a0').replace(/&copy;/g, '©')
  .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');

class Element {
  constructor() { this.style = {}; }
  appendChild() {}
  setAttribute() {}
}
class PopupContent extends Element {
  set innerHTML(html) { this.html = html; }
  get innerHTML() { return this.html; }
  querySelector(selector) {
    const content = this;
    if (selector === 'table.planet_template th') {
      return {
        get textContent() { return decodeText(content.html.match(/<th\b[^>]*>([\s\S]*?)<\/th>/)[1]); },
        set textContent(text) {
          content.html = content.html.replace(/(<th\b[^>]*>)[\s\S]*?(<\/th>)/,
            (match, open, close) => open + escapeText(text) + close);
        },
      };
    }
    if (selector === 'table.planet_template') {
      return {insertRow() { return {insertCell() { return new Element(); }}; }};
    }
    return null;
  }
}

function fixture(name, nativeFailure = false, options = {}) {
  const container = {querySelector: () => null, querySelectorAll: () => []};
  const phase = options.phase || 'active';
  const available = phase === 'active' || phase === 'unstable';
  const data = {textContent: JSON.stringify({
    now: 1700000000, refreshSeconds: 30,
    rifts: [{position: 6, id: 543, phase, name, canSpy: available,
      canRecycle: available && options.canRecycle !== false,
      category: {name: 'Custom', color: '#7B2CBF'}, fleetLimit: '1000'}],
    labels: {time: {locale: 'ru'}},
  })};
  const events = {};
  const document = {
    readyState: 'complete',
    getElementById: id => ({zero_galaxy_native: container, zero_galaxy_data: data})[id],
    createElement: tag => tag === 'div' ? new PopupContent() : new Element(),
    createTextNode: text => ({textContent: text}),
    addEventListener(name, callback) { events[name] = callback; },
  };
  const jQuery = selector => ({html: () => selector === '#debris_template' ? debrisTemplate : planetTemplate, on() { return this; }});
  const nativeDoit = () => 'native mission';
  const context = vm.createContext({document, jQuery, $: jQuery, Intl,
    performance: {now: () => 0}, setInterval() {},
    location: {reload() { throw new Error('Unexpected reload'); }},
    uni_galaxy: 0, uni_system: 18, PLANET_RECYCLERS: 5,
    uni_row: {6: {owner: 99, planet_name: name, planet_image: 'normaltempplanet01',
      debris: available ? '100000' : '0', debris_metal: '60000', debris_crystal: '40000',
      debris_reserved: '20000', debris_reserved_percent: '20', debris_will_gather: '80000',
      debris_will_gather_percent: '80', debris_gather_total: '100000', debris_gather_total_percent: '100',
      planet_diameter: 1000, planet_fleet_id: 0, planet_destroyed: 0}},
    user_id: 1, uni_phalanx: false, uni_death_stars: 0, language: {sys_planet_short: 'П', sys_planet: 'Планета'},
    fleet_table_make: () => '',
    sn_format_number: number => String(number),
    doit: nativeDoit,
  });
  context.window = context;
  vm.runInContext(nativeSource, context, {filename: 'js/sn_universe.js'});
  const native = context.sn_universe_show_planet;
  context.sn_universe_show_planet = function (that) {
    if (nativeFailure) { throw new Error('Native fixture failure'); }
    return native.call(this, that);
  };
  vm.runInContext(moduleSource, context, {filename: 'zero_galaxy.js'});
  const target = {parents: () => ({attr: () => '6'}), attr: () => '1'};
  return {context, nativeDoit, show: () => context.sn_universe_show_planet(target),
    showDebris: () => context.sn_universe_show_debris(target), events, document};
}

const names = ['Cash $& разлом', "Cash $` $' $$ разлом",
  'Example [PLANET_IMAGE] [FLEET_TABLE] разлом', 'Literal &copy; &amp; разлом', 'Имя "Альфа" и \'Бета\' разлом'];
for (const name of names) {
  const {context, show} = fixture(name);
  const popup = show();
  const title = popup.html.match(/<th\b[^>]*>([\s\S]*?)<\/th>/)[1];
  assert.equal(decodeText(title), '[0:18:6]\u00a0П ' + name);
  assert.equal(context.uni_row[6].planet_name, name, 'Popup generation must restore the shared native row');
}
const failing = fixture(names[0], true);
assert.throws(failing.show, /Native fixture failure/);
assert.equal(failing.context.uni_row[6].planet_name, names[0], 'Restore shared data even when native generation throws');

const debris = fixture(names[0]);
const popup = debris.showDebris();
assert.ok(popup.html.includes('60000') && popup.html.includes('40000'), 'Native debris popup must show both actual resources');
assert.ok(popup.html.includes('20000') && popup.html.includes('80000'), 'Native debris popup must keep incoming and source capacity figures');
assert.ok(popup.html.includes('button_pseudo ownharvest') && !popup.html.includes('HIDE_RECYCLER_LINK'), 'Native quick recycle control must remain');
assert.equal(debris.context.doit, debris.nativeDoit,
  'Quick missions keep the native doit(); the server handles galaxy 0 on flotenajax.php');

for (const phase of ['energy', 'closed']) {
  const inactive = fixture(names[0], false, {phase});
  assert.equal(inactive.showDebris(), undefined, 'Unavailable phase must have no debris popup');
}

function clickControl(fixture, popupClass, controlClass) {
  const popup = {nodeType: 1, parentNode: fixture.document, hasAttribute: () => false,
    classList: {contains: name => name === popupClass}};
  const control = {nodeType: 1, parentNode: popup, hasAttribute: () => false,
    classList: {contains: name => name === controlClass}};
  let blocked = false;
  fixture.events.click({target: control, preventDefault() { blocked = true; }, stopPropagation() {}});
  return blocked;
}
assert.equal(clickControl(debris, 'debris_template', 'ownharvest'), false, 'Native quick collection must pass the module click guard');
assert.equal(clickControl(debris, 'planet_template', 'ownharvest'), true, 'Collection outside the debris popup remains forbidden');
assert.equal(clickControl(debris, 'debris_template', 'ownmissile'), true, 'Re-enabling collection must not enable forbidden controls');
console.log('Popup: literal names, native debris popup, native quick missions and phase guards passed; no browser.');
