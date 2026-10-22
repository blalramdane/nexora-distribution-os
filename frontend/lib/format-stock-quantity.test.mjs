import test from 'node:test';
import assert from 'node:assert/strict';
import { formatStockQuantity } from './format-stock-quantity.js';

test('formats carton and remainder quantities', () => {
  assert.equal(formatStockQuantity(107, 50), '2 كرتونة و 7 قطعة');
  assert.equal(formatStockQuantity(100, 50), '2 كرتونة');
  assert.equal(formatStockQuantity(7, 50), '7 قطعة');
  assert.equal(formatStockQuantity(0, 50), '0 قطعة');
});

test('rejects invalid quantities and carton sizes', () => {
  for (const quantity of [-1, 1.5, NaN, Infinity]) {
    assert.throws(() => formatStockQuantity(quantity, 50), /Quantity must be a non-negative integer/);
  }
  for (const size of [0, -1, 1.5, NaN, Infinity]) {
    assert.throws(() => formatStockQuantity(100, size), /Carton size must be a positive integer/);
  }
});
