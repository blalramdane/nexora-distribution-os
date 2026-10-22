// One-off, token-protected database ops bridge for the Blitz deployment.
// It only runs the statements/backup we ask for, then the app is deleted after
// the migration window. No secrets are stored in this file; the token and DB
// URL come from environment variables injected by blitz.cloud.
import http from "node:http";
import mysql from "mysql2/promise";

const cfg = (() => {
  const url = new URL(process.env.DATABASE_URL);
  return {
    host: url.hostname,
    port: Number(url.port) || 3306,
    user: url.username,
    password: decodeURIComponent(url.password || ""),
    database: url.pathname.replace(/^\//, ""),
    connectTimeout: 30000,
    charset: "utf8mb4",
  };
})();
const TOKEN = process.env.OPS_PROXY_TOKEN || "";
if (!TOKEN) {
  console.error("OPS_PROXY_TOKEN missing; refusing to start.");
  process.exit(1);
}

function esc(v) {
  if (v === null || v === undefined) return "NULL";
  if (typeof v === "number" || typeof v === "bigint") return String(v);
  if (Buffer.isBuffer(v)) return "'" + v.toString("binary").replace(/\\/g, "\\\\").replace(/'/g, "''") + "'";
  if (typeof v === "boolean") return v ? "1" : "0";
  if (v instanceof Date) return "'" + v.toISOString().slice(0, 19).replace("T", " ") + "'";
  if (typeof v === "object") return "'binary-object' /* skipped */";
  const s = String(v);
  return "'" + s.replace(/\\/g, "\\\\").replace(/'/g, "''").replace(/\n/g, "\\n").replace(/\r/g, "\\r").replace(/\0/g, "\\0") + "'";
}

function authed(req) {
  const h = req.headers.authorization || "";
  return h === `Bearer ${TOKEN}`;
}

function send(res, status, body, headers = {}) {
  const data = typeof body === "string" || Buffer.isBuffer(body) ? body : JSON.stringify(body);
  res.writeHead(status, { "content-type": "text/plain; charset=utf-8", ...headers });
  res.end(data);
}

async function withConn(fn) {
  const conn = await mysql.createConnection({ ...cfg, multipleStatements: true, dateStrings: true });
  try { return await fn(conn); } finally { await conn.end(); }
}

const server = http.createServer(async (req, res) => {
  try {
    if (!authed(req)) return send(res, 401, "unauthorized\n");
    const url = new URL(req.url, "http://x");

    if (req.method === "GET" && url.pathname === "/healthz") {
      const v = await withConn(async (c) => (await c.query("SELECT VERSION() AS v, DATABASE() AS d"))[0][0]);
      return send(res, 200, `ok server=${v.v} db=${v.d}\n`);
    }

    if (req.method === "GET" && url.pathname === "/tables") {
      const rows = await withConn(async (c) => {
        const [tables] = await c.query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        const out = [];
        for (const r of tables) {
          const t = Object.values(r)[0];
          const [cnt] = await c.query(`SELECT COUNT(*) AS n FROM \`${t}\``);
          const [crt] = await c.query(`SHOW CREATE TABLE \`${t}\``);
          out.push({ table: t, rows: cnt[0].n, create: crt[0]["Create Table"] });
        }
        return out;
      });
      return send(res, 200, JSON.stringify(rows, null, 1), { "content-type": "application/json; charset=utf-8" });
    }

    if (req.method === "GET" && url.pathname === "/backup") {
      const dump = await withConn(async (c) => {
        const [tables] = await c.query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        let out = `-- Restorable dump of ${cfg.database} at ${new Date().toISOString()}\n` +
          "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
        let totalRows = 0;
        for (const r of tables) {
          const t = Object.values(r)[0];
          const [crt] = await c.query(`SHOW CREATE TABLE \`${t}\``);
          out += `DROP TABLE IF EXISTS \`${t}\`;\n${crt[0]["Create Table"]};\n`;
          const [cols] = await c.query(`SHOW COLUMNS FROM \`${t}\``);
          const names = cols.map((x) => "`" + x.Field + "`").join(", ");
          const [rows] = await c.query(`SELECT * FROM \`${t}\``);
          for (const row of rows) {
            totalRows += 1;
            const vals = cols.map((x) => esc(row[x.Field])).join(", ");
            out += `INSERT INTO \`${t}\` (${names}) VALUES (${vals});\n`;
          }
          out += "\n";
        }
        out += "SET FOREIGN_KEY_CHECKS=1;\n";
        out = out.replace(/^-- Restorable dump/, `-- rows billed via stream: ${totalRows}`)
        return out;
      });
      return send(res, 200, dump, { "content-type": "text/plain; charset=utf-8",
        "content-disposition": `attachment; filename="prod-backup-${cfg.database}.sql"` });
    }

    if (req.method === "POST" && url.pathname === "/exec") {
      let body = "";
      for await (const chunk of req) body += chunk;
      let payload;
      try { payload = JSON.parse(body); } catch { return send(res, 400, "bad json\n"); }
      const sql = String(payload.sql || "");
      if (!sql.trim()) return send(res, 400, "empty sql\n");
      const result = await withConn(async (c) => {
        const [rs] = await c.query(sql);
        const norm = Array.isArray(rs) ? rs : [rs];
        return norm.filter(Boolean).map((r) => ({
          affectedRows: r.affectedRows ?? null,
          insertId: r.insertId ?? null,
          rows: Array.isArray(r) ? r.length : 0,
          header: r.info ?? null,
        }));
      });
      return send(res, 200, JSON.stringify(result, null, 1), { "content-type": "application/json; charset=utf-8" });
    }

    return send(res, 404, "not found\n");
  } catch (err) {
    console.error("ops error:", err && err.message);
    return send(res, 500, String(err && err.message || err) + "\n");
  }
});

server.listen(8000, "0.0.0.0", () => console.log("ops-proxy listening on 8000"));
