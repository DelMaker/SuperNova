'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const template = fs.readFileSync(path.join(__dirname, '../design/templates/OpenGame/zero_galaxy_flying_fleets.tpl.html'), 'utf8');
const script = template.match(/<script[^>]*>([\s\S]*?)<\/script>/)[1].replace('{ZERO_ACS_BLOCKED_JSON}', '[11,12]');

// Each native order cell: a recall button, then <br> and the group button for attacks and ACS.
const cells = [11, 12, 13].map(id => {
  const cell = {children: []};
  const remove = node => { cell.children.splice(cell.children.indexOf(node), 1); };
  const lineBreak = {tagName: 'BR', parentNode: {removeChild: remove}};
  const group = {tagName: 'INPUT', parentNode: {removeChild: remove}, previousElementSibling: lineBreak,
    getAttribute: name => name === 'onclick' ? `sn_redirect('fleet.php?fleet_page=4&fleetid=${id}');` : null};
  cell.children.push({tagName: 'INPUT'}, lineBreak, group);
  return cell;
});
const document = {
  querySelectorAll(selector) {
    assert.equal(selector, 'input[onclick*="fleet_page=4&fleetid="]');
    return cells.map(cell => cell.children[2]).filter(Boolean);
  },
};
vm.runInContext(script, vm.createContext({document}), {filename: 'zero_galaxy_flying_fleets.tpl.html'});

assert.equal(cells[0].children.length, 1, 'A zero-galaxy attack loses its group button and the line break before it');
assert.equal(cells[1].children.length, 1, 'A zero-galaxy ACS flight loses its group button');
assert.equal(cells[2].children.length, 3, 'Ordinary galaxies keep the native group controls');
assert.equal(cells[0].children[0].tagName, 'INPUT', 'The recall button stays');
console.log('Flying fleets: zero-galaxy group buttons hidden, ordinary and recall controls kept; no browser.');
