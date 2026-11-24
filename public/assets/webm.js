// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// A WebM file (Matroska's subset for the web) of encoded video and audio: a video of a session's recording, made in the browser.
'use strict';

const enc = new TextEncoder();

/** An EBML variable-size integer of n bytes (1-8): the length of an element's data. */
function vsize(v, n = 0) {
  if (!n) for (n = 1; n < 8 && v >= 2 ** (7 * n) - 1; n++);
  const b = new Uint8Array(n);
  for (let i = n - 1, x = v; i >= 0; i--, x = Math.floor(x / 256)) b[i] = x % 256;
  b[0] |= 0x80 >> (n - 1);
  return b;
}

/** An unsigned integer in the fewest bytes (or n bytes). */
function uint(v, n = 0) {
  if (!n) for (n = 1; n < 8 && v >= 2 ** (8 * n); n++);
  const b = new Uint8Array(n);
  for (let i = n - 1, x = v; i >= 0; i--, x = Math.floor(x / 256)) b[i] = x % 256;
  return b;
}

const idBytes = (id) => uint(id);
const join = (parts) => {
  const out = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
  let o = 0;
  for (const p of parts) { out.set(p, o); o += p.length; }
  return out;
};

/** An element: its id, the length of its data, its data (bytes, or elements joined). */
export function el(id, data) {
  const d = Array.isArray(data) ? join(data) : data;
  return join([idBytes(id), vsize(d.length), d]);
}
const elUint = (id, v, n) => el(id, uint(v, n));
const elStr = (id, s) => el(id, enc.encode(s));
const elFloat = (id, v) => { const b = new Uint8Array(8); new DataView(b.buffer).setFloat64(0, v); return el(id, b); };

const ID = {
  EBML: 0x1A45DFA3, EBMLVersion: 0x4286, EBMLReadVersion: 0x42F7, EBMLMaxIDLength: 0x42F2, EBMLMaxSizeLength: 0x42F3, DocType: 0x4282,
  DocTypeVersion: 0x4287, DocTypeReadVersion: 0x4285, Segment: 0x18538067, SeekHead: 0x114D9B74, Seek: 0x4DBB, SeekID: 0x53AB,
  SeekPosition: 0x53AC, Info: 0x1549A966, TimecodeScale: 0x2AD7B1, Duration: 0x4489, MuxingApp: 0x4D80, WritingApp: 0x5741,
  Tracks: 0x1654AE6B, TrackEntry: 0xAE, TrackNumber: 0xD7, TrackUID: 0x73C5, TrackType: 0x83, FlagLacing: 0x9C, CodecID: 0x86,
  CodecPrivate: 0x63A2, CodecDelay: 0x56AA, SeekPreRoll: 0x56BB, Video: 0xE0, PixelWidth: 0xB0, PixelHeight: 0xBA, Audio: 0xE1,
  SamplingFrequency: 0xB5, Channels: 0x9F, Cluster: 0x1F43B675, Timecode: 0xE7, SimpleBlock: 0xA3, Cues: 0x1C53BB6B,
  CuePoint: 0xBB, CueTime: 0xB3, CueTrackPositions: 0xB7, CueTrack: 0xF7, CueClusterPosition: 0xF1,
};

/** An EBML element's id (its marker bits kept) and the length of its data (-1: unknown) at o, with the header's length; null if cut. */
function header(b, o) {
  if (o >= b.length) return null;
  let n = 1;
  while (n <= 4 && !(b[o] & (0x100 >> n))) n++;
  if (n > 4 || o + n > b.length) return n > 4 ? { bad: true } : null;
  let id = 0;
  for (let i = 0; i < n; i++) id = id * 256 + b[o + i];
  const s = o + n;
  if (s >= b.length) return null;
  let m = 1;
  while (m <= 8 && !(b[s] & (0x100 >> m))) m++;
  if (m > 8) return { bad: true };
  if (s + m > b.length) return null;
  let size = b[s] & ((0x100 >> m) - 1), ones = size === (0x100 >> m) - 1;
  for (let i = 1; i < m; i++) { size = size * 256 + b[s + i]; ones = ones && b[s + i] === 0xFF; }
  return { id, size: ones ? -1 : size, len: n + m };
}
const readUint = (b) => b.reduce((v, x) => v * 256 + x, 0);
// Timecode, SimpleBlock, BlockGroup, Position, PrevSize, SilentTracks, Void, CRC-32
const CLUSTER_CHILD = new Set([0xE7, 0xA3, 0xA0, 0xA7, 0xAB, 0x5854, 0xEC, 0xBF]);
const ELEMENT_MAX = 64 << 20;                            // an element read whole (a block, the tracks...): one larger is not of a video

/**
 * A WebM made by MediaRecorder (a recording made in the browser), given in pieces as it is read, made into one a player
 * can seek in: the segment and its clusters with their lengths (MediaRecorder leaves them unknown), the info with its
 * duration, its tracks as they were, the clusters with their blocks as they were, and the cues of the clusters that hold a
 * key frame of the video. A file cut short ends at its last whole element.
 */
export class WebmRemux {
  constructor() {
    this.buf = new Uint8Array(0);
    this.state = 'top';                                  // top, segment (its children), cluster (a cluster's)
    this.ebml = null;                                    // the EBML header, as it was
    this.scale = 1000000;                                // TimecodeScale (ns a tick)
    this.tracks = null;                                  // the Tracks element, as it was
    this.videoTrack = 1;
    this.clusters = [];                                  // Blobs of the clusters made
    this.offset = 0;                                     // their bytes
    this.cues = [];                                      // [time, offset of its cluster]
    this.open = null;                                    // the cluster being read: {time, parts, end (bytes left, -1: unknown), key}
    this.end = 0;                                        // the latest time of a block, in ticks
    this.bad = false;
    this.parts = [];                                     // what came and is not read yet, kept apart until it is needed
    this.have = 0;
    this.need = 1;                                       // bytes it takes to read on (an element whole, or its header)
  }

  push(bytes) {
    if (this.bad || !bytes.length) return;
    this.parts.push(bytes.slice());
    this.have += bytes.length;
    if (this.have < this.need) return;                   // (waiting for an element whole: not joined again for each piece)
    const b = new Uint8Array(this.have);
    let o = 0;
    for (const p of this.parts) { b.set(p, o); o += p.length; }
    o = 0;
    for (;;) {
      const used = this.#step(b, o);
      if (used <= 0) break;
      o += used;
    }
    this.parts = o < b.length ? [b.slice(o)] : [];
    this.have = b.length - o;
  }

  /** One element (or its header, for the segment and clusters) taken from b at o: the bytes used, 0 when more are needed. */
  #step(b, o) {
    const e = header(b, o);
    this.need = b.length - o + 1;                        // (a header cut short: any more bytes)
    if (!e) return 0;
    if (e.bad || e.size > ELEMENT_MAX && !(e.id === 0x18538067 || e.id === 0x1F43B675)) { this.bad = true; return 0; }   // (garbage: no more)
    if (this.state === 'cluster' && (!CLUSTER_CHILD.has(e.id) || this.open.end === 0)) this.#close();   // (an unknown length ends at the next non-child)
    if (this.state === 'top') {
      if (e.id === 0x18538067) { this.state = 'segment'; return e.len; }   // the segment: its children follow
      if (e.size < 0) { this.bad = true; return 0; }
      if (b.length - o < e.len + e.size) { this.need = e.len + e.size; return 0; }
      if (e.id === 0x1A45DFA3) this.ebml = b.slice(o, o + e.len + e.size);
      return e.len + e.size;
    }
    if (this.state === 'segment' && e.id === 0x1F43B675) {   // a cluster: its children follow
      this.state = 'cluster';
      this.open = { time: 0, parts: [], end: e.size, key: null };
      return e.len;
    }
    if (e.size < 0) { this.bad = true; return 0; }
    if (b.length - o < e.len + e.size) { this.need = e.len + e.size; return 0; }
    const whole = b.subarray(o, o + e.len + e.size), data = b.subarray(o + e.len, o + e.len + e.size);
    if (this.state === 'cluster') {
      this.open.parts.push(whole.slice());
      if (this.open.end > 0) this.open.end -= whole.length;
      if (e.id === 0xE7) this.open.time = readUint(data);
      else if (e.id === 0xA3 && data.length >= 4) this.#block(data, true);
      else if (e.id === 0xA0) {                          // a BlockGroup: its Block, a key frame when no ReferenceBlock is there
        let ref = false, blk = null;
        for (let q = 0; q < data.length;) {
          const c = header(data, q);
          if (!c || c.bad || c.size < 0 || q + c.len + c.size > data.length) break;
          if (c.id === 0xA1) blk = data.subarray(q + c.len, q + c.len + c.size);
          if (c.id === 0xFB) ref = true;
          q += c.len + c.size;
        }
        if (blk && blk.length >= 4) this.#block(blk, false, !ref);
      }
      if (this.open.end === 0) this.#close();
      return whole.length;
    }
    if (e.id === 0x1549A966) {                           // Info: its TimecodeScale
      for (let q = 0; q < data.length;) {
        const c = header(data, q);
        if (!c || c.bad || c.size < 0 || q + c.len + c.size > data.length) break;
        if (c.id === 0x2AD7B1) this.scale = readUint(data.subarray(q + c.len, q + c.len + c.size)) || 1000000;
        q += c.len + c.size;
      }
    } else if (e.id === 0x1654AE6B) {                    // Tracks: as they are; the video's number
      this.tracks = whole.slice();
      for (let q = 0; q < data.length;) {
        const t = header(data, q);
        if (!t || t.bad || t.size < 0 || q + t.len + t.size > data.length) break;
        let num = 0, type = 0;
        for (let r = q + t.len; r < q + t.len + t.size;) {
          const c = header(data, r);
          if (!c || c.bad || c.size < 0 || r + c.len + c.size > q + t.len + t.size) break;
          if (c.id === 0xD7) num = readUint(data.subarray(r + c.len, r + c.len + c.size));
          if (c.id === 0x83) type = readUint(data.subarray(r + c.len, r + c.len + c.size));
          r += c.len + c.size;
        }
        if (type === 1 && num) this.videoTrack = num;
        q += t.len + t.size;
      }
    }
    return whole.length;                                 // (SeekHead, Void, Cues, Tags...: left out, made again or not needed)
  }

  /** A block's time (and, of the video, whether it is a key frame: where a player can start). */
  #block(d, simple, key = false) {
    let n = 1;                                           // the track number: a variable-size integer
    while (n <= 8 && !(d[0] & (0x100 >> n))) n++;
    if (n > 8 || d.length < n + 3) return;
    let track = d[0] & ((0x100 >> n) - 1);
    for (let i = 1; i < n; i++) track = track * 256 + d[i];
    const time = this.open.time + new DataView(d.buffer, d.byteOffset + n, 2).getInt16(0);
    this.end = Math.max(this.end, time);
    if (track === this.videoTrack && (simple ? d[n + 2] & 0x80 : key) && this.open.key === null) this.open.key = time;
  }

  #close() {
    if (!this.open) return;
    const c = el(ID.Cluster, this.open.parts);
    if (this.open.key !== null) this.cues.push([this.open.key, this.offset]);
    this.clusters.push(new Blob([c]));
    this.offset += c.length;
    this.open = null;
    this.state = 'segment';
  }

  /** The file (the pieces given), or the bytes as they came when they were not a WebM this can read. */
  finish() {
    if (this.state === 'cluster') this.#close();
    if (!this.ebml || !this.tracks) return null;
    const frame = Math.round(1e9 / 15 / this.scale);     // (the last picture shows for a frame)
    const info = el(ID.Info, [elUint(ID.TimecodeScale, this.scale), elFloat(ID.Duration, this.end + frame),
      elStr(ID.MuxingApp, 'MuDeskClient'), elStr(ID.WritingApp, 'MuDeskClient')]);
    const seekLen = el(ID.SeekHead, [0, 1, 2].map(() => el(ID.Seek, [el(ID.SeekID, uint(ID.Info)), elUint(ID.SeekPosition, 0, 8)]))).length;
    const clustersAt = seekLen + info.length + this.tracks.length;
    const cuesAt = clustersAt + this.offset;
    const seek = el(ID.SeekHead, [[ID.Info, seekLen], [ID.Tracks, seekLen + info.length], [ID.Cues, cuesAt]]
      .map(([id, at]) => el(ID.Seek, [el(ID.SeekID, uint(id)), elUint(ID.SeekPosition, at, 8)])));
    const cues = el(ID.Cues, (this.cues.length ? this.cues : [[0, 0]]).map(([t, at]) => el(ID.CuePoint, [elUint(ID.CueTime, t),
      el(ID.CueTrackPositions, [elUint(ID.CueTrack, this.videoTrack), elUint(ID.CueClusterPosition, clustersAt + at, 8)])])));
    const segLen = seek.length + info.length + this.tracks.length + this.offset + cues.length;
    return new Blob([this.ebml, join([idBytes(ID.Segment), vsize(segLen, 8)]), seek, info, this.tracks, ...this.clusters, cues], { type: 'video/webm' });
  }
}

/** OpusHead (RFC 7845 5.1): what a Matroska Opus track carries as its codec data. */
export function opusHead(channels, preSkip, rate = 48000) {
  const b = new Uint8Array(19);
  b.set(enc.encode('OpusHead'));
  const dv = new DataView(b.buffer);
  b[8] = 1;
  b[9] = channels;
  dv.setUint16(10, preSkip, true);
  dv.setUint32(12, rate, true);
  return b;
}

/**
 * The WebM of a video track (VP9 or VP8) and, if any, an audio one (Opus at 48 kHz). Blocks are given in time order for each
 * track (milliseconds); they are put together in clusters in time order, a new cluster at each key frame of the video (where
 * a player can start: listed in the cues), or after 30 s. The clusters go into blobs as they close (a long recording is not
 * kept in memory twice), and the file is put together at the end, when its length and duration are known.
 */
export class WebmWriter {
  /**
   * @param {{codec: 'V_VP9'|'V_VP8', width: number, height: number}} video
   * @param {{channels: number, head: Uint8Array|null, preSkip: number}|null} audio its OpusHead (the encoder's, set when known) or made from the rest
   */
  constructor(video, audio = null) {
    this.video = video;
    this.audio = audio;
    this.pending = { 1: [], 2: [] };                     // blocks waiting for the other track to catch up: {ms, data, key}
    this.last = { 1: -1, 2: -1 };                        // the last time given on each track
    this.clusters = [];                                  // Blobs of the closed clusters
    this.offset = 0;                                     // bytes of clusters before the open one
    this.cues = [];                                      // [ms, offset of its cluster]
    this.open = null;                                    // {ms, parts: Uint8Array[], size}
    this.end = 0;                                        // the latest time of a block
  }

  /** A video frame encoded (VP9/VP8): its time in milliseconds, whether it is a key frame. */
  addVideo(ms, data, key) { this.#add(1, ms, data, key); }

  /** An audio packet encoded (Opus): its time in milliseconds. */
  addAudio(ms, data) { this.#add(2, ms, data, true); }

  #add(track, ms, data, key) {
    ms = Math.max(Math.round(ms), this.last[track]);
    this.last[track] = ms;
    this.pending[track].push({ track, ms, data: data instanceof Uint8Array ? data : new Uint8Array(data), key });
    this.#drain(false);
  }

  /** The blocks of both tracks up to where both have arrived, in time order (all of them at the end). */
  #drain(all) {
    const v = this.pending[1], a = this.pending[2];
    for (;;) {
      let next = null;
      if (v.length && a.length) next = v[0].ms <= a[0].ms ? v : a;
      else if (all || !this.audio) next = v.length ? v : a.length ? a : null;
      if (!next) return;
      this.#block(next.shift());
    }
  }

  #block(b) {
    const video = b.track === 1;
    if (!this.open || (video && b.key && b.ms > this.open.ms) || b.ms - this.open.ms > 30000) {
      this.#close();
      this.open = { ms: b.ms, parts: [elUint(ID.Timecode, b.ms)], size: 0 };
      this.open.size = this.open.parts[0].length;
      if (video && b.key) this.cues.push([b.ms, this.offset]);
    }
    const head = new Uint8Array(4);
    head[0] = 0x80 | b.track;                            // the track number as a 1-byte variable-size integer
    new DataView(head.buffer).setInt16(1, b.ms - this.open.ms);
    head[3] = b.key ? 0x80 : 0;
    const blk = el(ID.SimpleBlock, [head, b.data]);
    this.open.parts.push(blk);
    this.open.size += blk.length;
    this.end = Math.max(this.end, b.ms);
  }

  #close() {
    if (!this.open) return;
    const c = el(ID.Cluster, this.open.parts);
    this.clusters.push(new Blob([c]));
    this.offset += c.length;
    this.open = null;
  }

  /** The file: its duration (milliseconds) at least $duration. */
  finish(duration = 0) {
    this.#drain(true);
    this.#close();
    const ebml = el(ID.EBML, [elUint(ID.EBMLVersion, 1), elUint(ID.EBMLReadVersion, 1), elUint(ID.EBMLMaxIDLength, 4),
      elUint(ID.EBMLMaxSizeLength, 8), elStr(ID.DocType, 'webm'), elUint(ID.DocTypeVersion, 4), elUint(ID.DocTypeReadVersion, 2)]);
    const info = el(ID.Info, [elUint(ID.TimecodeScale, 1000000), elFloat(ID.Duration, Math.max(duration, this.end + 1)),
      elStr(ID.MuxingApp, 'MuDeskClient'), elStr(ID.WritingApp, 'MuDeskClient')]);
    const tracks = [el(ID.TrackEntry, [elUint(ID.TrackNumber, 1), elUint(ID.TrackUID, 1), elUint(ID.TrackType, 1), elUint(ID.FlagLacing, 0),
      elStr(ID.CodecID, this.video.codec), el(ID.Video, [elUint(ID.PixelWidth, this.video.width), elUint(ID.PixelHeight, this.video.height)])])];
    if (this.audio && this.last[2] >= 0) {
      tracks.push(el(ID.TrackEntry, [elUint(ID.TrackNumber, 2), elUint(ID.TrackUID, 2), elUint(ID.TrackType, 2), elUint(ID.FlagLacing, 0),
        elStr(ID.CodecID, 'A_OPUS'), el(ID.CodecPrivate, this.audio.head || opusHead(this.audio.channels, this.audio.preSkip)),
        elUint(ID.CodecDelay, Math.round(this.audio.preSkip / 48000 * 1e9)), elUint(ID.SeekPreRoll, 80000000),
        el(ID.Audio, [elFloat(ID.SamplingFrequency, 48000), elUint(ID.Channels, this.audio.channels)])]));
    }
    const tracksEl = el(ID.Tracks, tracks);
    // the seek head first: its positions in 8 bytes, so its own length does not depend on them
    const seekLen = el(ID.SeekHead, [0, 1, 2].map(() => el(ID.Seek, [el(ID.SeekID, uint(ID.Info)), elUint(ID.SeekPosition, 0, 8)]))).length;
    const clustersAt = seekLen + info.length + tracksEl.length;
    const cuesAt = clustersAt + this.offset;
    const seek = el(ID.SeekHead, [[ID.Info, seekLen], [ID.Tracks, seekLen + info.length], [ID.Cues, cuesAt]]
      .map(([id, at]) => el(ID.Seek, [el(ID.SeekID, uint(id)), elUint(ID.SeekPosition, at, 8)])));
    const cues = el(ID.Cues, (this.cues.length ? this.cues : [[0, 0]]).map(([ms, at]) => el(ID.CuePoint, [elUint(ID.CueTime, ms),
      el(ID.CueTrackPositions, [elUint(ID.CueTrack, 1), elUint(ID.CueClusterPosition, clustersAt + at, 8)])])));
    const segLen = seek.length + info.length + tracksEl.length + this.offset + cues.length;
    return new Blob([ebml, join([idBytes(ID.Segment), vsize(segLen, 8)]), seek, info, tracksEl, ...this.clusters, cues], { type: 'video/webm' });
  }
}
