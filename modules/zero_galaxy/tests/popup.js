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
    spyUrl: '/zero-spy', spyMission: 6, planetType: 1, spyCount: 2,
    recycleUrl: '/zero-recycle', recycleMission: 8, debrisType: 2,
    rifts: [{position: 6, id: 543, phase, name, canSpy: available,
      canRecycle: available && options.canRecycle !== false,
      category: {name: 'Custom', color: '#7B2CBF'}, fleetLimit: '1000'}],
    labels: {time: {locale: 'ru'}, badRequest: 'bad', missing: 'missing', failed: 'failed',
      recycleBadRequest: 'bad recycle', recycleMissing: 'missing debris', recycleFailed: 'recycle failed'},
  })};
  const events = {};
  const counters = {flt_recyclers: {textContent: '5'}, flt_spies: {textContent: '7'}};
  const requests = [];
  const messages = [];
  const document = {
    readyState: 'complete',
    getElementById: id => ({zero_galaxy_native: container, zero_galaxy_data: data, ...counters})[id],
    createElement: tag => tag === 'div' ? new PopupContent() : new Element(),
    createTextNode: text => ({textContent: text}),
    addEventListener(name, callback) { events[name] = callback; },
  };
  const jQuery = selector => ({html: () => selector === '#debris_template' ? debrisTemplate : planetTemplate, on() { return this; }});
  jQuery.post = (url, request, callback) => {
    requests.push({url, request: JSON.parse(JSON.stringify(request))});
    if (!options.postFailure) { callback(options.response || (url === '/zero-recycle' ? 'sent|flt_recyclers=9007199254740993' : 'sent|flt_spies=5')); }
    return {
      fail(callback) { if (options.postFailure) { callback(); } return this; },
      always(callback) { callback(); return this; },
    };
  };
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
    addToTable: (message, failed) => messages.push({message, failed}),
    doit: () => 'native mission',
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
  return {context, show: () => context.sn_universe_show_planet(target),
    showDebris: () => context.sn_universe_show_debris(target), requests, messages, events, counters, document};
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

const debris = fixture(names[0]);
const popup = debris.showDebris();
assert.ok(popup.html.includes('60000') && popup.html.includes('40000'), 'Native debris popup must show both actual resources');
assert.ok(popup.html.includes('20000') && popup.html.includes('80000'), 'Native debris popup must keep incoming and source capacity figures');
assert.ok(popup.html.includes('button_pseudo ownharvest') && !popup.html.includes('HIDE_RECYCLER_LINK'), 'Native quick recycle control must remain');
debris.context.doit(8, 6, 2);
assert.deepEqual(debris.requests, [{url: '/zero-recycle', request: {galaxy: 0, system: 18, planet: 6, planet_type: 2, mission: 8, rift_id: 543}}]);
assert.equal(debris.counters.flt_recyclers.textContent, '9007199254740993', 'Response recycler count must retain all digits without Number conversion');
assert.equal(debris.counters.flt_spies.textContent, '7', 'Collection must not change the probe counter');
debris.context.doit(6, 6, 1, 3);
assert.deepEqual(debris.requests[1], {url: '/zero-spy', request: {galaxy: 0, system: 18, planet: 6, planet_type: 1, mission: 6, rift_id: 543, shipcount: 3}});
assert.equal(debris.counters.flt_spies.textContent, '5', 'Existing reconnaissance must retain its endpoint and counter');
debris.context.doit(8, 6, 1);
assert.equal(debris.requests.length, 2, 'Collection must reject a planet target instead of debris');
debris.context.doit(1, 6, 1);
assert.equal(debris.requests.length, 2, 'Forbidden missions must never reach a native launch endpoint');
debris.context.uni_galaxy = 1;
assert.equal(debris.context.doit(8, 6, 2), 'native mission', 'Ordinary universe missions retain native dispatch');

for (const phase of ['energy', 'closed']) {
  const inactive = fixture(names[0], false, {phase});
  assert.equal(inactive.showDebris(), undefined, 'Unavailable phase must have no debris popup');
  inactive.context.doit(8, 6, 2);
  assert.equal(inactive.requests.length, 0, 'Unavailable phase must never dispatch recyclers');
}
const noRecyclers = fixture(names[0], false, {canRecycle: false});
noRecyclers.context.doit(8, 6, 2);
assert.equal(noRecyclers.requests.length, 0, 'Unavailable collection must remain blocked');
const networkFailure = fixture(names[0], false, {postFailure: true});
networkFailure.context.doit(8, 6, 2);
assert.deepEqual(networkFailure.messages, [{message: 'recycle failed', failed: 1}], 'Collection request failures must use the collection message');
const wrongCounter = fixture(names[0], false, {response: 'oops|flt_spies=1'});
wrongCounter.context.doit(8, 6, 2);
assert.equal(wrongCounter.counters.flt_recyclers.textContent, '5', 'A wrong mission response must not update recycler inventory');
assert.equal(wrongCounter.messages[0].failed, 1, 'A wrong mission response must not be presented as a successful launch');

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
console.log('Popup: literal names, native debris popup, quick recycle routing/counters, phase guards and failures passed; no browser.');
