import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const script = await readFile(new URL('../script.js', import.meta.url), 'utf8');
const names = ['isFigureShopProduct', 'getProductSalePrice', 'parsePrice', 'getProductPriceValue', 'matchesPriceFilter'];
const functions = names.map(name => {
  const match = script.match(new RegExp(`function ${name}\\([^]*?\\n\\}`));
  assert.ok(match, `Missing production function: ${name}`);
  return match[0];
}).join('\n');
const { price, matches, sale } = vm.runInNewContext(`${functions}; ({price:getProductPriceValue,matches:matchesPriceFilter,sale:getProductSalePrice})`);
const ranges = ['under-500', '500-1500', 'over-1500'];
const check = (product, expected) => assert.deepEqual(ranges.map(range => matches(product, range)), expected);

for (const [amount, expected] of [[250, [true, false, false]], [1500, [false, true, false]], [1800, [false, false, true]]]) {
  const figure = { saleType: 'garden_figure', grossPrice: `${amount} zł`, outletPrice: '1 zł', catalogPrice: '9999 zł' };
  assert.equal(price(figure), amount, 'Conflicting outlet/catalogue prices must not replace grossPrice');
  assert.equal(price(figure), vm.runInNewContext(`${functions}; parsePrice(value)`, { value: sale(figure) }), 'Filter and card use the same sale price');
  check(figure, expected);
  assert.equal(matches(figure, 'all'), true);
}
assert.equal(price({ saleType: 'garden_figure', grossPrice: '', outletPrice: '100 zł', catalogPrice: '200 zł' }), null, 'No catalogue/outlet fallback for figures');
assert.equal(price({ saleType: 'garden_figure', grossPrice: '1 800,00 zł' }), 1800);

check({ outletPrice: '250 zł', catalogPrice: '1800 zł', grossPrice: '9999 zł' }, [true, false, false]);
check({ outletPrice: '1500 zł', catalogPrice: '2000 zł' }, [false, true, false]);
check({ outletPrice: '1800 zł', catalogPrice: '250 zł' }, [false, false, true]);
check({ outletPrice: '', catalogPrice: '750 zł' }, [false, true, false]);
check({ outletPrice: '500 zł' }, [true, true, false]); // Existing inclusive boundaries.
check({ outletPrice: '', catalogPrice: '' }, [true, true, true]); // Existing missing-price behaviour.
assert.equal(price({ outletPrice: '0 zł', catalogPrice: '750 zł' }), 750);
const example = { saleType: 'garden_figure', grossPrice: '1500 zł', outletPrice: '', catalogPrice: '' };
check(example, [false, true, false]);
console.log('PASS: figure grossPrice, 250/1500/1800 boundaries, conflicting prices, unchanged ordinary products');
