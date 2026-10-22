import csv, hashlib, json, re, secrets, sqlite3, time, unicodedata, uuid
from collections import defaultdict
from datetime import datetime
from pathlib import Path

root = Path(__file__).resolve().parents[1]
db = root / 'backend/database/nexora-local-preview.sqlite'
rows = json.loads((root / 'tools/bodaa_import_staging.json').read_text(encoding='utf-8'))

tag = 'BODAA-20261022'
tat = chr(0x0640)
carton = ''.join(map(chr, [0x0643, 0x631, 0x62A, 0x648, 0x646, 0x629]))

def norm(s):
    s = unicodedata.normalize('NFKC', s).replace(tat, ' ')
    s = ''.join(c for c in s if unicodedata.category(c) not in ('Mn', 'Cf'))
    for cp in (0x0623, 0x0625, 0x0622): s = s.replace(chr(cp), chr(0x0627))
    return re.sub(r'\s+', ' ', s).strip().casefold()

def ulid():
    alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'
    n = (int(time.time() * 1000) << 80) | secrets.randbits(80)
    out = []
    for _ in range(26): out.append(alphabet[n & 31]); n >>= 5
    return ''.join(reversed(out))

for r in rows:
    r['unit_key'] = 'carton' if norm(r['unit']) == norm(carton) else 'piece'
    r['pn'], r['sn'] = norm(r['product']), norm(r['supplier'])
if len(rows) != 312 or round(sum(float(r['qty']) for r in rows), 3) != 4526:
    raise RuntimeError('Source validation failed; no database changes made.')

con = sqlite3.connect(db, timeout=30)
con.row_factory = sqlite3.Row
con.execute('PRAGMA foreign_keys=ON')
if con.execute('PRAGMA integrity_check').fetchone()[0] != 'ok': raise RuntimeError('Database integrity check failed')
org = con.execute('SELECT id FROM organizations ORDER BY created_at LIMIT 1').fetchone()
loc = con.execute("SELECT id,organization_id,name FROM locations WHERE id=? AND type='warehouse' AND status='active'", ('01M5H0M78PFKEADXJ7CTEE8139',)).fetchone()
if not org or not loc or loc['organization_id'] != org['id']: raise RuntimeError('Sohaag warehouse/org check failed')
if con.execute("SELECT COUNT(*) FROM stock_movements WHERE source_document_type='stock_import' AND reference LIKE ?", (tag+'-%',)).fetchone()[0]: raise RuntimeError('Batch already exists; duplicate import blocked')
print('PRECHECK_OK', 'source_rows=',len(rows),'signed_total=',sum(float(r['qty']) for r in rows),'location=',loc['name'],'db=',db)
print('CURRENT', {t:con.execute(f'SELECT COUNT(*) FROM {t}').fetchone()[0] for t in ['customers','products','suppliers','stock_movements','stock_balances']})

units = {r['code']: r['id'] for r in con.execute('SELECT code,id FROM units')}
groups, supplier_names, units_by_name = defaultdict(list), {}, defaultdict(set)
for r in rows:
    r['product'] = re.sub(r'\s+', ' ', r['product'].replace(tat, ' ')).strip(' -')
    r['supplier'] = re.sub(r'\s+', ' ', r['supplier'].replace(tat, ' ')).strip(' -')
    r['pn'], r['sn'] = norm(r['product']), norm(r['supplier'])
    groups[(r['pn'], r['unit_key'])].append(r)
    supplier_names.setdefault(r['sn'], r['supplier'])
    units_by_name[r['pn']].add(r['unit_key'])

existing_products = {}
for p in con.execute('SELECT id,name_ar,base_unit_id,sku FROM products WHERE organization_id=?', (org['id'],)):
    existing_products[(norm(p['name_ar']), p['base_unit_id'])] = dict(p)
existing_suppliers = {}
for s in con.execute('SELECT id,name,normalized_name,code FROM suppliers WHERE organization_id=?', (org['id'],)):
    existing_suppliers[norm(s['name'])] = dict(s)
    existing_suppliers.setdefault(norm(s['normalized_name']), dict(s))

supplier_ids, product_ids = {}, {}
batch_id = ulid()
now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
created_suppliers = created_products = created_links = movements = layers = 0
negative = [r for r in rows if float(r['qty']) < 0]

con.execute('BEGIN IMMEDIATE')
try:
    for sn, name in sorted(supplier_names.items()):
        found = existing_suppliers.get(sn)
        if found:
            supplier_ids[sn] = found['id']
            continue
        code = 'SUP-BODAA-' + hashlib.sha256(sn.encode('utf-8')).hexdigest()[:8].upper()
        while con.execute('SELECT 1 FROM suppliers WHERE organization_id=? AND code=?', (org['id'], code)).fetchone():
            code = 'SUP-BODAA-' + secrets.token_hex(5).upper()
        sid = ulid()
        con.execute('INSERT INTO suppliers (id,organization_id,code,name,normalized_name,credit_terms_days,active,created_at,updated_at) VALUES (?,?,?,?,?,0,1,?,?)', (sid, org['id'], code, name, sn, now, now))
        supplier_ids[sn] = sid
        created_suppliers += 1

    for key, group in sorted(groups.items(), key=lambda kv: min(r['row'] for r in kv[1])):
        pn, unit_key = key
        unit_id = units[unit_key]
        found = existing_products.get((pn, unit_id))
        if found:
            product_ids[key] = found['id']
            continue
        first = min(group, key=lambda r: r['row'])
        name = first['product']
        if len(units_by_name[pn]) > 1:
            name += ' (' + first['unit'] + ')'
        sku = f"BODAA-ROW-{first['row']:03d}-{'C' if unit_key == 'carton' else 'P'}"
        pid = ulid()
        source_rows = ','.join(str(r['row']) for r in group)
        source_units = ', '.join(sorted({r['unit'] for r in group}))
        description = f"Imported from inventory PDF; source rows: {source_rows}; source unit: {source_units}; batch {tag}; source contains no prices/costs."
        con.execute('INSERT INTO products (id,organization_id,category_id,base_unit_id,sku,name_ar,name_en,description,brand,default_cost,default_piece_price,tax_code,active,created_at,updated_at,color) VALUES (?,?,NULL,?,?,?,NULL,?,NULL,0,0,NULL,1,?,?,NULL)', (pid, org['id'], unit_id, sku, name, description, now, now))
        product_ids[key] = pid
        created_products += 1

    linked = set()
    for r in rows:
        key = (r['pn'], r['unit_key'])
        pair = (supplier_ids[r['sn']], product_ids[key])
        if pair in linked:
            continue
        linked.add(pair)
        if con.execute('SELECT 1 FROM supplier_products WHERE organization_id=? AND supplier_id=? AND product_id=?', (org['id'], pair[0], pair[1])).fetchone():
            continue
        con.execute("INSERT INTO supplier_products (id,organization_id,supplier_id,product_id,supplier_sku,supplier_name,preferred_packaging_id,last_cost,last_purchase_at,active,created_at,updated_at,settlement_method,settlement_unit_price) VALUES (?,?,?,?,NULL,?,NULL,NULL,NULL,1,?,?,'purchase_debt',NULL)", (ulid(), org['id'], pair[0], pair[1], r['product'], now, now))
        created_links += 1

    totals = defaultdict(float)
    for r in rows:
        if float(r['qty']) > 0:
            totals[(r['pn'], r['unit_key'])] += float(r['qty'])
    for key, quantity in totals.items():
        con.execute("INSERT INTO stock_source_layers (id,organization_id,product_id,location_id,source_purchase_invoice_id,source_purchase_invoice_item_id,parent_layer_id,origin_type,origin_document_type,origin_document_id,quantity_received_base,quantity_remaining_base,unit_cost_base,created_at,updated_at) VALUES (?,?,?,?,NULL,NULL,NULL,'stock_adjustment_in','stock_import',?,?,?,0,?,?)", (ulid(), org['id'], product_ids[key], loc['id'], batch_id, quantity, quantity, now, now))
        layers += 1

    for r in rows:
        quantity = float(r['qty'])
        if quantity <= 0:
            continue
        pid = product_ids[(r['pn'], r['unit_key'])]
        reference = f"{tag}-ROW-{r['row']:03d} | PDF row {r['row']} | {r['supplier']}"
        con.execute("INSERT INTO stock_movements (id,organization_id,transaction_uuid,product_id,location_id,movement_type,quantity_base,unit_cost,source_document_type,source_document_id,occurred_at,posted_at,created_by,device_id,trip_id,reference,created_at,updated_at) VALUES (?,?,?,?,?,'stock_adjustment',?,0,'stock_import',?,?,?,NULL,NULL,NULL,?,?,?)", (ulid(), org['id'], str(uuid.uuid4()), pid, loc['id'], quantity, batch_id, now, now, reference, now, now))
        con.execute('INSERT INTO stock_balances (id,organization_id,product_id,location_id,quantity_base,reserved_quantity_base,updated_at,average_cost) VALUES (?,?,?,?,?,0,?,0) ON CONFLICT(organization_id,product_id,location_id) DO UPDATE SET quantity_base=stock_balances.quantity_base+excluded.quantity_base,updated_at=excluded.updated_at', (ulid(), org['id'], pid, loc['id'], quantity, now))
        movements += 1
    con.commit()
except Exception:
    con.rollback()
    con.close()
    raise

report = root / 'tools/bodaa_import_report.csv'
exceptions = root / 'tools/bodaa_negative_quantity_review.csv'
with report.open('w', newline='', encoding='utf-8-sig') as stream:
    writer = csv.DictWriter(stream, fieldnames=['row','qty','unit','product','supplier','status','warehouse','batch'])
    writer.writeheader()
    for r in rows:
        writer.writerow({'row':r['row'],'qty':r['qty'],'unit':r['unit'],'product':r['product'],'supplier':r['supplier'],'status':'REVIEW' if r['qty'] < 0 else 'IMPORTED','warehouse':loc['name'],'batch':tag})
with exceptions.open('w', newline='', encoding='utf-8-sig') as stream:
    writer = csv.DictWriter(stream, fieldnames=['row','qty','unit','product','supplier','reason'])
    writer.writeheader()
    for r in negative:
        writer.writerow({'row':r['row'],'qty':r['qty'],'unit':r['unit'],'product':r['product'],'supplier':r['supplier'],'reason':'Source quantity is below zero; left for reconciliation.'})

integrity = con.execute('PRAGMA integrity_check').fetchone()[0]
counts = {t:con.execute(f'SELECT COUNT(*) FROM {t}').fetchone()[0] for t in ['customers','products','suppliers','stock_movements','stock_balances','stock_source_layers']}
posted = con.execute("SELECT COALESCE(SUM(quantity_base),0) FROM stock_movements WHERE source_document_type='stock_import' AND reference LIKE ?", (tag+'-%',)).fetchone()[0]
con.close()
print(json.dumps({'status':'COMPLETED' if integrity == 'ok' else 'INTEGRITY_CHECK_FAILED','database':str(db),'warehouse':loc['name'],'batch_id':batch_id,'source_rows':len(rows),'source_signed_total':sum(float(r['qty']) for r in rows),'positive_rows_posted':movements,'positive_quantity_sum_mixed_units':posted,'negative_rows_not_posted':len(negative),'negative_quantity_sum':sum(float(r['qty']) for r in negative),'new_suppliers':created_suppliers,'new_products':created_products,'new_supplier_product_links':created_links,'source_layers_created':layers,'integrity_check':integrity,'counts_after':counts,'report':str(report),'exceptions':str(exceptions)},ensure_ascii=False,indent=2))
