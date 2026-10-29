'use strict';

// Exercise the actual native popup builder and module adapter without a browser.
// The small DOM boundary below implements only the text/HTML operations under test.
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

function fixture(name, nativeFailure = false) {
  const container = {querySelector: () => null, querySelectorAll: () => []};
  const data = {textContent: JSON.stringify({
    now: 1700000000, refreshSeconds: 30,
    rifts: [{position: 6, id: 543, phase: 'active', name, canSpy: true,
      category: {name: 'Custom', color: '#7B2CBF'}, fleetLimit: '1000'}],
    labels: {time: {locale: 'ru'}},
  })};
  const document = {
    readyState: 'complete',
    getElementById: id => ({zero_galaxy_native: container, zero_galaxy_data: data})[id],
    createElement: tag => tag === 'div' ? new PopupContent() : new Element(),
    createTextNode: text => ({textContent: text}),
    addEventListener() {},
  };
  const jQuery = () => ({html: () => planetTemplate, on() { return this; }});
  const context = vm.createContext({document, jQuery, $: jQuery, Intl,
    performance: {now: () => 0}, setInterval() {},
    location: {reload() { throw new Error('Unexpected reload'); }},
    uni_row: {6: {owner: 99, planet_name: name, planet_image: 'normaltempplanet01',
      planet_diameter: 1000, planet_fleet_id: 0, planet_destroyed: 0}},
    user_id: 1, uni_phalanx: false, uni_death_stars: 0, language: {sys_planet_short: 'П', sys_planet: 'Планета'},
    fleet_table_make: () => '',
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
  return {context, show: () => context.sn_universe_show_planet(target)};
}

const names = ['Крошечный разлом', 'Cash $& разлом', "Cash $` $' $$ разлом",
  'Example [PLANET_IMAGE] [FLEET_TABLE] разлом', 'Literal &copy; &amp; разлом', 'Имя "Альфа" и \'Бета\' разлом'];
for (const name of names) {
  const {context, show} = fixture(name);
  const popup = show();
  const title = popup.html.match(/<th\b[^>]*>([\s\S]*?)<\/th>/)[1];
  assert.equal(decodeText(title), '[0:18:6]\u00a0П ' + name);
  assert.ok(popup.html.includes('class="ownespionage button_pseudo"'), 'Native spy control must remain');
  assert.equal(context.uni_row[6].planet_name, name, 'Popup generation must restore the shared native row');
}
const failing = fixture(names[1], true);
assert.throws(failing.show, /Native fixture failure/);
assert.equal(failing.context.uni_row[6].planet_name, names[1], 'Restore shared data even when native generation throws');
console.log('Popup: 6 literal-name fixtures and failure restoration passed; real native function/template, no browser.');
