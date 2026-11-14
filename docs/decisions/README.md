# Decision records

Each record captures one architecture decision: its context, the decision, its consequences, and the alternatives rejected. Records are numbered and never renumbered. A reversed decision gets a new record that supersedes the old one.

| Record | Decision | Status |
|---|---|---|
| [0001](0001-read-only-observe-mode-first.md) | Ship read-only collection first, in phases that each release | Accepted |
| [0002](0002-alert-through-cacti-data-sources.md) | Alert through polled Cacti data sources, not a plugin alert engine | Accepted |
| [0003](0003-identify-gpus-by-uuid.md) | Identify GPUs by UUID, not index or PCI bus ID | Accepted for NVIDIA, AMD pending #19 |
| [0004](0004-gpus-are-data-query-indexes.md) | Represent each GPU as an index of a data query on the node's device | Accepted |
| [0005](0005-use-cacti-remote-collectors.md) | Reach remote GPU nodes through Cacti remote data collectors, not a custom agent | Accepted |
| [0006](0006-php-82-floor-with-80-parse-guard.md) | Require PHP 8.2, keep entry points parseable on PHP 8.0 | Accepted |
| [0007](0007-run-vendor-tools-with-fixed-argument-lists.md) | Run vendor tools with fixed argument lists, no shell, and no elevated privilege | Accepted |

Open decisions are tracked as issues labelled `type: decision`. The collection path (#7) is the largest one and is not decided.
