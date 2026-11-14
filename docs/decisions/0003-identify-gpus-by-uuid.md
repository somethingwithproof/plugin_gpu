# 0003. Identify GPUs by UUID, not index or PCI bus ID

Status: Accepted for NVIDIA (2026-11-14); AMD identity is open in #19

## Context

Cacti keeps a data source's history by its data query index. If the index changes meaning, one GPU's history attaches to another GPU. nvidia-smi documents the properties it can report: `uuid` as "the globally unique immutable alphanumeric identifier of the GPU", `serial` as the number printed on the board, `pci.bus_id` as `domain:bus:device.function`, and `index` as a zero-based index that "can change at each boot" (help text as captured in a pynvml snapshot from 2021, to be reconfirmed in #14 and #19).

## Decision

- The identity key of an NVIDIA GPU is its UUID. It is the data query index.
- Index, PCI bus ID, name, driver, and node are attributes that can change without creating a new GPU.
- A new UUID at a known node, index, and bus ID creates a new GPU record flagged as a possible replacement. It never inherits history.
- Serial number is not used as a key and is not stored unless research shows a need, because it is a physical identifier that some sites treat as sensitive.

## Consequences

- A reboot that reorders indexes changes graph titles, not history.
- Replacing a card starts a new history on purpose. The old history stays on the absent GPU.
- A site that cannot read the UUID cannot be monitored; the collector reports an error instead of falling back to index.

## Alternatives rejected

- **Index as key.** Changes at boot.
- **PCI bus ID as key.** Stable for a slot, but a replaced card in the same slot would inherit the old card's history, and passthrough or re-enumeration can change it.
- **Serial number.** Not available on every product.
