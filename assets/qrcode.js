/*
 * Minimal QR code generator for SwiftPanel (byte mode, error correction M, versions 1-10 = up to 213 bytes).
 * No network, no dependencies. Usage: SwiftQR.svg("otpauth://...", 4) -> SVG markup (dark modules on a white card).
 * Based on the public QR Code specification (ISO/IEC 18004): Reed-Solomon over GF(256)/0x11D, 8 mask patterns.
 */
(function (global) {
  "use strict";
  var ECC_PER_BLOCK = [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];   // level M, index = version
  var NUM_BLOCKS    = [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];
  var MAX_VER = 10;

  function rawModules(v) {
    var r = (16 * v + 128) * v + 64;
    if (v >= 2) { var n = Math.floor(v / 7) + 2; r -= (25 * n - 10) * n - 55; if (v >= 7) r -= 36; }
    return r;
  }
  function dataCodewords(v) { return Math.floor(rawModules(v) / 8) - ECC_PER_BLOCK[v] * NUM_BLOCKS[v]; }

  function utf8(str) {
    var out = [], s = unescape(encodeURIComponent(str));
    for (var i = 0; i < s.length; i++) out.push(s.charCodeAt(i));
    return out;
  }
  function gfMul(x, y) {
    var z = 0;
    for (var i = 7; i >= 0; i--) { z = (z << 1) ^ ((z >>> 7) * 0x11D); z ^= ((y >>> i) & 1) * x; }
    return z;
  }
  function rsDivisor(deg) {
    var r = [], i, j, root = 1;
    for (i = 0; i < deg; i++) r.push(0);
    r[deg - 1] = 1;
    for (i = 0; i < deg; i++) {
      for (j = 0; j < deg; j++) { r[j] = gfMul(r[j], root); if (j + 1 < deg) r[j] ^= r[j + 1]; }
      root = gfMul(root, 2);
    }
    return r;
  }
  function rsRemainder(data, div) {
    var res = div.map(function () { return 0; });
    data.forEach(function (b) {
      var f = b ^ res.shift();
      res.push(0);
      div.forEach(function (c, i) { res[i] ^= gfMul(c, f); });
    });
    return res;
  }
  function bit(x, i) { return ((x >>> i) & 1) !== 0; }

  function encode(text) {
    var bytes = utf8(text), ver, i;
    for (ver = 1; ver <= MAX_VER; ver++) {
      if (4 + (ver < 10 ? 8 : 16) + 8 * bytes.length <= dataCodewords(ver) * 8) break;
    }
    if (ver > MAX_VER) throw new Error("Text too long for the QR code");
    var bits = [];
    function put(val, len) { for (var k = len - 1; k >= 0; k--) bits.push((val >>> k) & 1); }
    put(4, 4); put(bytes.length, ver < 10 ? 8 : 16);
    bytes.forEach(function (b) { put(b, 8); });
    var cap = dataCodewords(ver) * 8;
    put(0, Math.min(4, cap - bits.length));
    while (bits.length % 8) bits.push(0);
    for (var pad = 0xEC; bits.length < cap; pad ^= 0xEC ^ 0x11) put(pad, 8);
    var data = [];
    for (i = 0; i < bits.length; i += 8) {
      var v = 0; for (var k = 0; k < 8; k++) v = (v << 1) | bits[i + k];
      data.push(v);
    }
    // split into blocks, add Reed-Solomon codewords, interleave
    var nb = NUM_BLOCKS[ver], eccLen = ECC_PER_BLOCK[ver], raw = Math.floor(rawModules(ver) / 8);
    var shortBlocks = nb - raw % nb, shortLen = Math.floor(raw / nb), blocks = [], div = rsDivisor(eccLen), pos = 0;
    for (i = 0; i < nb; i++) {
      var dat = data.slice(pos, pos + shortLen - eccLen + (i < shortBlocks ? 0 : 1));
      pos += dat.length;
      var ecc = rsRemainder(dat, div);
      if (i < shortBlocks) dat.push(0);
      blocks.push(dat.concat(ecc));
    }
    var all = [];
    for (i = 0; i < blocks[0].length; i++) {
      blocks.forEach(function (b, j) { if (i !== shortLen - eccLen || j >= shortBlocks) all.push(b[i]); });
    }
    return { ver: ver, codewords: all };
  }

  function build(ver, codewords, mask, size, fixedOnly) {
    var m = [], f = [], x, y, i, j;
    for (y = 0; y < size; y++) { m.push(new Array(size).fill(false)); f.push(new Array(size).fill(false)); }
    function setF(x, y, dark) { m[y][x] = dark; f[y][x] = true; }
    for (i = 0; i < size; i++) { setF(6, i, i % 2 === 0); setF(i, 6, i % 2 === 0); }
    function finder(cx, cy) {
      for (var dy = -4; dy <= 4; dy++) for (var dx = -4; dx <= 4; dx++) {
        var d = Math.max(Math.abs(dx), Math.abs(dy)), xx = cx + dx, yy = cy + dy;
        if (xx >= 0 && xx < size && yy >= 0 && yy < size) setF(xx, yy, d !== 2 && d !== 4);
      }
    }
    finder(3, 3); finder(size - 4, 3); finder(3, size - 4);
    var pos = [];
    if (ver > 1) {
      var n = Math.floor(ver / 7) + 2, step = Math.ceil((ver * 4 + 4) / (n * 2 - 2)) * 2;
      pos = [6];
      for (var p = size - 7; pos.length < n; p -= step) pos.splice(1, 0, p);
      for (i = 0; i < n; i++) for (j = 0; j < n; j++) {
        if ((i === 0 && j === 0) || (i === 0 && j === n - 1) || (i === n - 1 && j === 0)) continue;
        for (var dy = -2; dy <= 2; dy++) for (var dx = -2; dx <= 2; dx++) setF(pos[i] + dx, pos[j] + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
      }
    }
    // format bits (level M = 0b00)
    var fdata = (0 << 3) | mask, rem = fdata;
    for (i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
    var fb = ((fdata << 10) | rem) ^ 0x5412;
    for (i = 0; i <= 5; i++) setF(8, i, bit(fb, i));
    setF(8, 7, bit(fb, 6)); setF(8, 8, bit(fb, 7)); setF(7, 8, bit(fb, 8));
    for (i = 9; i < 15; i++) setF(14 - i, 8, bit(fb, i));
    for (i = 0; i < 8; i++) setF(size - 1 - i, 8, bit(fb, i));
    for (i = 8; i < 15; i++) setF(8, size - 15 + i, bit(fb, i));
    setF(8, size - 8, true);
    if (ver >= 7) {
      var r = ver;
      for (i = 0; i < 12; i++) r = (r << 1) ^ ((r >>> 11) * 0x1F25);
      var vb = (ver << 12) | r;
      for (i = 0; i < 18; i++) { var a = size - 11 + i % 3, b = Math.floor(i / 3); setF(a, b, bit(vb, i)); setF(b, a, bit(vb, i)); }
    }
    // data placement (zig-zag)
    i = 0;
    for (var right = size - 1; right >= 1; right -= 2) {
      if (right === 6) right = 5;
      for (var vert = 0; vert < size; vert++) for (j = 0; j < 2; j++) {
        x = right - j; y = ((right + 1) & 2) === 0 ? size - 1 - vert : vert;
        if (!f[y][x] && i < codewords.length * 8) { m[y][x] = bit(codewords[i >>> 3], 7 - (i & 7)); i++; }
      }
    }
    // mask
    for (y = 0; y < size; y++) for (x = 0; x < size; x++) {
      var inv;
      switch (mask) {
        case 0: inv = (x + y) % 2 === 0; break;
        case 1: inv = y % 2 === 0; break;
        case 2: inv = x % 3 === 0; break;
        case 3: inv = (x + y) % 3 === 0; break;
        case 4: inv = (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0; break;
        case 5: inv = x * y % 2 + x * y % 3 === 0; break;
        case 6: inv = (x * y % 2 + x * y % 3) % 2 === 0; break;
        default: inv = ((x + y) % 2 + x * y % 3) % 2 === 0;
      }
      if (!f[y][x] && inv) m[y][x] = !m[y][x];
    }
    return m;
  }

  function penalty(m) {   // runs of 5+, 2x2 blocks, dark/light balance: enough to pick a clean mask
    var n = m.length, p = 0, x, y, dark = 0, run, prev, line;
    for (y = 0; y < n; y++) {
      for (var pass = 0; pass < 2; pass++) {
        run = 0; prev = null;
        for (x = 0; x < n; x++) {
          var c = pass ? m[x][y] : m[y][x];
          if (c === prev) { run++; if (run === 5) p += 3; else if (run > 5) p++; } else { prev = c; run = 1; }
        }
      }
      for (x = 0; x < n; x++) {
        if (m[y][x]) dark++;
        if (x + 1 < n && y + 1 < n && m[y][x] === m[y][x + 1] && m[y][x] === m[y + 1][x] && m[y][x] === m[y + 1][x + 1]) p += 3;
      }
    }
    p += Math.floor(Math.abs(dark * 20 - n * n * 10) / (n * n)) * 10;
    return p;
  }

  function matrix(text) {
    var e = encode(text), size = e.ver * 4 + 17, best = null, bp = Infinity;
    for (var mask = 0; mask < 8; mask++) {
      var m = build(e.ver, e.codewords, mask, size), pe = penalty(m);
      if (pe < bp) { bp = pe; best = m; }
    }
    return best;
  }

  function svg(text, scale) {
    var m = matrix(text), n = m.length, q = 4, s = scale || 4, d = "", x, y;
    for (y = 0; y < n; y++) for (x = 0; x < n; x++) if (m[y][x]) d += "M" + (x + q) + "," + (y + q) + "h1v1h-1z";
    var tot = n + q * 2;
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + tot + " " + tot + '" width="' + tot * s + '" height="' + tot * s +
      '" shape-rendering="crispEdges" role="img" aria-label="QR code"><rect width="' + tot + '" height="' + tot + '" fill="#fff"/><path d="' + d + '" fill="#000"/></svg>';
  }

  global.SwiftQR = { svg: svg, matrix: matrix };
  if (typeof module !== "undefined" && module.exports) module.exports = global.SwiftQR;
})(typeof window !== "undefined" ? window : globalThis);
