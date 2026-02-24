---
title: "VST3 VBAN Transmitter for Low-Latency Guitar Streaming to Discord"
description: ""
author: "Gyni"
date: 2026-02-24T20:43:41.893Z
comments: true
draft: false
---

If you've ever tried to share live guitar over Discord while keeping your ASIO latency tight, you know the pain. VoiceMeeter is the go to for routing audio between apps on Windows, but the moment you involve it in your DAW's signal chain, you're forced into compromises — higher buffer sizes, driver headaches, or janky workarounds that defeat the purpose of having a good audio interface in the first place.

I got tired of it, so I wrote a VST3 plugin that solves the problem with one simple trick: **VBAN, the VB-Audio Network protocol**.

## The Problem

Here's the setup I wanted:

- Guitar → Audio interface → DAW (amp sims, effects, the works) → monitors/headphones
- Simultaneously: that same processed guitar audio → Discord, so my friends can hear me play

Sounds simple. It is not.

### The VoiceMeeter-Inside-the-DAW Approach

The "standard" solution is to route your DAW's output through VoiceMeeter's virtual ASIO driver. This means VoiceMeeter sits between your DAW and your audio interface. The problem? VoiceMeeter's ASIO driver doesn't play nicely at ultra-low buffer sizes. You end up at **256 samples** or higher just to avoid crackling and dropouts. At 48kHz, that's over 5ms of latency *per buffer* — and it compounds through the chain. For live guitar monitoring, it feels sluggish. You notice it. It's awful.

You also lose direct ASIO access to your interface. Your carefully chosen Focusrite/M-Audio/whatever is now filtered through VoiceMeeter's virtual driver, and you're at the mercy of its internal engine for stability.

### What I Actually Wanted

- **My DAW talks directly to my audio interface via ASIO** — no middleman, no virtual driver, no compromises. Buffer size: **32 samples**. That's sub-millisecond. I can feel every pick attack in real time.
- **Discord gets the audio too** — without touching my DAW's driver config at all.

## The Solution: VBAN Over Localhost

VBAN is a protocol created by VB-Audio (the same people behind VoiceMeeter). It streams audio over UDP, typically across a network between machines, but it works perfectly fine over **localhost** too.

The idea is dead simple:

1. A VST3 plugin inside my DAW captures the audio from whatever track it's on
2. It packs the samples into VBAN UDP packets
3. It sends them to `127.0.0.1:6980`
4. VoiceMeeter receives the VBAN stream natively (it has built-in VBAN support)
5. VoiceMeeter routes it to a virtual output that Discord sees as a microphone

**The DAW never knows VoiceMeeter exists.** It's still talking pure ASIO to my audio interface. The VBAN stream is a fire-and-forget side channel. It adds essentially zero overhead to the audio thread.

## Building the Plugin

I built this with the [JUCE framework](https://github.com/juce-framework/JUCE), which is the industry standard for audio plugin development. The entire plugin is a single C++ file - no GUI, no editor window, just pure signal processing and networking... And a config file for the IP/port/stream name.

### The Architecture

The plugin has two threads:

1. **The audio thread** (called by the DAW via `processBlock`) — this is real-time, lock-free, and cannot block. It writes interleaved samples into a lock-free FIFO ring buffer.
2. **A network thread** — this reads from the FIFO and sends VBAN packets over UDP.

This separation is critical. You **never** do network I/O on the audio thread. A single blocked `sendto()` call can cause a BSOD. The FIFO decouples them cleanly.

### The VBAN Packet Format

VBAN is refreshingly simple. Each packet is a 28-byte header followed by raw PCM sample data:

```cpp
#pragma pack(push, 1)
struct VBANHeader
{
    char vban[4];          // "VBAN"
    uint8_t format_SR;     // Sample rate index + protocol
    uint8_t format_nbs;    // Number of samples per frame - 1
    uint8_t format_nbc;    // Number of channels - 1
    uint8_t format_bit;    // Bit format (PCM 16-bit, 24-bit, float, etc.)
    char streamname[16];   // Stream name (null-terminated)
    uint32_t nuFrame;      // Frame counter
};
#pragma pack(pop)
```

The audio data immediately follows the header. In my case, interleaved 16-bit signed integers. The network thread packs 256 samples at a time into each packet.

### The Lock-Free FIFO

JUCE provides `AbstractFifo`, which is a single-producer, single-consumer lock-free FIFO. It gives you index ranges to write into and read from, and you manage the underlying storage yourself. Perfect for real-time audio.

The audio thread writes into the FIFO:

```cpp
fifo.prepareToWrite(numSamples, start1, size1, start2, size2);
// Write interleaved samples into audioBuffer at the given ranges
fifo.finishedWrite(size1 + size2);
```

The network thread reads from it:

```cpp
fifo.prepareToRead(samplesPerPacket, start1, size1, start2, size2);
// Convert float → int16, build VBAN header, send UDP packet
fifo.finishedRead(samplesRead);
```

No mutexes, no locks, no priority inversion. The audio thread is never blocked.

### Configuration

Rather than building a whole GUI, the plugin reads a JSON config file from `%APPDATA%\VBANTX\config.json`:

```json
{
  "ip": "127.0.0.1",
  "port": 6980,
  "streamName": "AudioStream"
}
```

If the file doesn't exist on first launch, the plugin creates it with sensible defaults. Edit the file, reload the plugin, done. For a utility plugin that you set up once and forget about, a config file is simpler and faster than a GUI.

### The Build

The entire build system is CMake. JUCE is included as a Git submodule. Three commands and you have a VST3:

```bash
git clone --recursive https://github.com/inGyni/vban-tx.git
cd vban-tx
cmake -S . -B build -G "Visual Studio 17 2022" -A x64
cmake --build build --config Release
```

Out comes `VBAN TX.vst3`. Drop it in `C:\Program Files\Common Files\VST3\` and your DAW picks it up.

## The Full Signal Chain

Here's what my actual setup looks like:

```
→ DAW (M-Track Solo ASIO driver, buffer size 32 samples)
  → Audio From Ext. In 2 (guitar input)
  → Helix Native (amp sim)
  → VBAN TX plugin on the track → UDP to 127.0.0.1:6980 VoiceMeeter VBAN Receiver
  → Audio To Ext. Out 1/2 (direct ASIO to interface for monitoring, no VoiceMeeter in this path)
→ VoiceMeeter VBAN Receiver (listening on 127.0.0.1:6980)
  → Routes to B1/B2 → Discord Listens on VoiceMeeter 
```

### Setting Up VoiceMeeter's VBAN Receiver

1. Open VoiceMeeter → **VBAN**
2. Under **Incoming Streams**, enable one slot
3. Set the stream name to match your config (e.g., `AudioStream`)
4. Set the IP to `127.0.0.1` and the port to `6980`
5. Set the output destination to whatever you want (e.g., #2)
6. Route that output to B1/B2, which is what Discord will pick up as a microphone

Then just select VoiceMeeter Output B1/B2 as your mic in Discord. Done.

## The Result

| | Before (VoiceMeeter ASIO in DAW) | After (VBAN TX Plugin) |
|---|---|---|
| **DAW buffer size** | 256 samples | **32 samples** |
| **Round-trip latency** | ~15.4ms | **~6.06ms** |
| **DAW driver** | VoiceMeeter Virtual ASIO | Native ASIO (direct to interface) |
| **Stability** | Occasional crackles | Rock solid |
| **Setup complexity** | Moderate (virtual routing) | Drop plugin on track, done |

The guitar feels like it's plugged straight into an amp. No perceptible delay. My Discord friends hear the same processed tone, and I didn't have to sacrifice a single sample of latency to make it happen.

## Why UDP and Why It's Fine

"But UDP packets can be dropped!" — yes, and it doesn't matter. This is real-time audio streaming over localhost. Packets essentially never get dropped on the loopback adapter. And even if one did, you'd lose ~5ms of audio. In a Discord voice chat. Nobody would notice.

UDP is the right choice here because:
- **No handshake overhead** — just fire packets
- **No retransmission** — a retransmitted audio packet arriving late is worse than a dropped one
- **No head-of-line blocking** — each packet is independent
- **VoiceMeeter expects VBAN over UDP** — it's the protocol spec

## Closing Thoughts

This whole plugin is ~250 lines of C++. No GUI. No complex DSP. Just a FIFO, a UDP socket, and a 28-byte header. And it completely solved a problem that had been bugging me for months.

The key insight here is that **the monitoring path and the sharing path don't have to be the same**. Your ears need as low latency as it can get. Discord doesn't. VBAN over localhost bridges that gap without either side compromising.

If you play guitar (or any live instrument) through a DAW and want to share it on Discord/Zoom/OBS without tanking your latency, [grab the prebuilt binary plugin from my GitHub repo](https://github.com/inGyni/vban-tx/releases) and give it a shot. The setup takes about two minutes.

---

*Built with [JUCE](https://juce.com/) and the VBAN protocol by [VB-Audio](https://vb-audio.com/Voicemeeter/vban.htm).*
