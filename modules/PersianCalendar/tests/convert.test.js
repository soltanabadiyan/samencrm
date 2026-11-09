// node tests/convert.test.js  – checks Jalali conversion against known dates and a full round trip
const PC = require('../layouts/v7/modules/PersianCalendar/resources/PersianCalendar.js');
const assert = require('assert');
const known = [ // gregorian -> jalali (official Iranian calendar)
  [[2026,11,10],[1405,8,19]], [[2026,3,21],[1405,1,1]], [[2025,3,21],[1404,1,1]], [[2024,3,20],[1403,1,1]],
  [[2025,3,20],[1403,12,30]], [[2021,3,21],[1400,1,1]], [[1979,2,11],[1357,11,22]], [[2000,1,1],[1378,10,11]],
  [[2023,3,21],[1402,1,1]], [[2022,8,23],[1401,6,1]], [[1990,9,23],[1369,7,1]],
];
for (const [g,j] of known) {
  const r = PC.toJalali(...g); assert.deepStrictEqual([r.jy,r.jm,r.jd], j, `g2j ${g}`);
  const b = PC.toGregorian(...j); assert.deepStrictEqual([b.gy,b.gm,b.gd], g, `j2g ${j}`);
}
// round trip every day 1950..2060
let d = new Date(Date.UTC(1950,0,1)), n = 0;
while (d.getUTCFullYear() < 2060) {
  const g = [d.getUTCFullYear(), d.getUTCMonth()+1, d.getUTCDate()], j = PC.toJalali(...g), b = PC.toGregorian(j.jy,j.jm,j.jd);
  assert.deepStrictEqual([b.gy,b.gm,b.gd], g); assert(j.jd <= PC.monthLength(j.jy,j.jm)); n++;
  d = new Date(d.getTime()+86400000);
}
assert.strictEqual(PC.isLeap(1403), true); assert.strictEqual(PC.isLeap(1404), false);
// display rewriting
PC._setupFormat('yyyy-mm-dd');
assert.strictEqual(PC._convertText('Due 2026-11-10 09:00'), 'Due 1405/08/19 09:00');
assert.strictEqual(PC._convertText('id 12026-11-10x'), null);
assert.strictEqual(PC._convertText('+989120261110'), null);
assert.strictEqual(PC._convertText('Study start date: 1400/08/01'), null);
PC._setupFormat('dd-mm-yyyy');
assert.strictEqual(PC._convertText('10-11-2026'), '1405/08/19');
assert.deepStrictEqual(PC.parseJ('۱۴۰۵/۰۸/۱۹'), {jy:1405,jm:8,jd:19}); assert.strictEqual(PC.parseJ('1405/12/30'), null);
console.log('OK', n, 'days round-tripped');
