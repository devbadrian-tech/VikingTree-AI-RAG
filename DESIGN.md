---
name: "Arbor"
description: "A calm operational knowledge workspace with visible hierarchy and exact source content."
colors:
  accent: "#23664b"
  accent-soft: "#e9f3ec"
  accent-hover: "#194f39"
  accent-disabled: "#567b65"
  canvas: "#f5f6f2"
  panel: "#fff"
  ink: "#24342e"
  muted: "#627169"
  line: "#dce2db"
  canvas-tree: "#fafbf8"
  control-hover: "#f2f5f0"
  focus: "#75a68b"
  field-focus: "#98b7a3"
  danger: "#a33935"
  danger-soft: "#fff8f6"
  danger-line: "#e6bcb6"
  node-line: "#d4ded1"
  node-hover-line: "#95b09b"
  node-symbol: "#f1f5ed"
  node-symbol-ink: "#567f5c"
  visited: "#edf4ed"
  visited-line: "#a6c5a6"
  current: "#fff6e5"
  current-line: "#b68533"
typography:
  headline:
    fontFamily: "Inter, -apple-system, BlinkMacSystemFont, \"Segoe UI\", sans-serif"
    fontSize: "29px"
    fontWeight: 600
    lineHeight: 1.3
    letterSpacing: "-1px"
  inspector-title:
    fontFamily: "Inter, -apple-system, BlinkMacSystemFont, \"Segoe UI\", sans-serif"
    fontSize: "20px"
    fontWeight: 600
    lineHeight: 1.35
    letterSpacing: "-0.5px"
  panel-title:
    fontFamily: "Inter, -apple-system, BlinkMacSystemFont, \"Segoe UI\", sans-serif"
    fontSize: "14px"
    fontWeight: 600
    lineHeight: 1.55
    letterSpacing: "-0.15px"
  node-title:
    fontFamily: "Inter, -apple-system, BlinkMacSystemFont, \"Segoe UI\", sans-serif"
    fontSize: "14px"
    fontWeight: 550
    lineHeight: 1.35
  body:
    fontFamily: "Inter, -apple-system, BlinkMacSystemFont, \"Segoe UI\", sans-serif"
    fontSize: "13px"
    fontWeight: 400
    lineHeight: 1.55
  source:
    fontFamily: "Inter, -apple-system, BlinkMacSystemFont, \"Segoe UI\", sans-serif"
    fontSize: "12px"
    fontWeight: 400
    lineHeight: 1.85
  label:
    fontFamily: "Inter, -apple-system, BlinkMacSystemFont, \"Segoe UI\", sans-serif"
    fontSize: "12px"
    fontWeight: 600
    lineHeight: 1.55
  button:
    fontFamily: "Inter, -apple-system, BlinkMacSystemFont, \"Segoe UI\", sans-serif"
    fontSize: "12px"
    fontWeight: 550
    lineHeight: 1.55
  small:
    fontFamily: "Inter, -apple-system, BlinkMacSystemFont, \"Segoe UI\", sans-serif"
    fontSize: "11px"
    fontWeight: 400
    lineHeight: 1.55
  code:
    fontFamily: "ui-monospace, SFMono-Regular, Consolas, monospace"
    fontSize: "11px"
  path:
    fontFamily: "ui-monospace, SFMono-Regular, Consolas, monospace"
    fontSize: "10px"
    lineHeight: 1.7
rounded:
  chip: "5px"
  compact: "6px"
  control: "7px"
  notice: "8px"
  node: "9px"
  panel: "12px"
  dialog: "14px"
spacing:
  "4": "4px"
  "6": "6px"
  "8": "8px"
  "10": "10px"
  "12": "12px"
  "16": "16px"
  "18": "18px"
  "20": "20px"
  "24": "24px"
  "28": "28px"
  "32": "32px"
components:
  button-primary:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.panel}"
    typography: "{typography.button}"
    rounded: "{rounded.control}"
    padding: "8px 13px"
  button-primary-hover:
    backgroundColor: "{colors.accent-hover}"
  button-primary-disabled:
    backgroundColor: "{colors.accent-disabled}"
  button-neutral:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.ink}"
    typography: "{typography.button}"
    rounded: "{rounded.control}"
    padding: "8px 13px"
  button-neutral-hover:
    backgroundColor: "{colors.control-hover}"
  button-danger:
    backgroundColor: "{colors.danger-soft}"
    textColor: "{colors.danger}"
    typography: "{typography.button}"
    rounded: "{rounded.control}"
    padding: "8px 13px"
  button-text:
    textColor: "{colors.accent}"
    padding: "4px 0"
  button-icon:
    textColor: "{colors.muted}"
    rounded: "{rounded.compact}"
    height: "30px"
    width: "30px"
  input:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.ink}"
    rounded: "{rounded.control}"
    padding: "9px 11px"
  pill:
    backgroundColor: "{colors.accent-soft}"
    textColor: "{colors.accent}"
    rounded: "{rounded.chip}"
    padding: "3px 7px"
  pill-subtle:
    backgroundColor: "{colors.canvas}"
    textColor: "{colors.muted}"
    rounded: "{rounded.chip}"
    padding: "3px 7px"
  panel:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.ink}"
    rounded: "{rounded.panel}"
  graph-node:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.ink}"
    rounded: "{rounded.node}"
    padding: "10px 12px"
    width: "224px"
    height: "66px"
  graph-node-visited:
    backgroundColor: "{colors.visited}"
  graph-node-current:
    backgroundColor: "{colors.current}"
---

# Design System: Arbor

## Overview

**Creative North Star: "Visible hierarchy, exact sources"**

Arbor is a calm operational workspace. Warm off-white surroundings, white working panels, restrained sage states, and fine rules keep the tree and source material in the foreground. The visual thesis is visible hierarchy with exact source instructions: interface chrome helps people locate and understand information without rewriting its meaning.

The interface is compact enough for repeated use, with a clear page heading and a more open source-reading area. The tree is the primary visual artifact. The workspace and Traversal Lab share the same material and controls while keeping editing and request inspection distinct. This record is extracted from the implemented PHP views and vanilla JavaScript interface; it does not claim a formal design certificate or a rendered accessibility audit.

**Key Characteristics:**

- Quiet, light surfaces with sage used for actions and selection.
- Visible hierarchy through panels, thin rules, and tree connections.
- Readable source content beside compact operational controls.
- Explicit labels for versions, origins, references, and request state.
- Small state transitions, with reduced motion support.

## Colors

The palette combines warm paper neutrals with a restrained sage accent. Frontmatter contains the normative values; the names below describe their roles.

### Primary

- **Operational Sage** (`accent`): primary actions, active navigation, text actions, collection symbols, and selected node borders.
- **Sage Wash** (`accent-soft`): selected outline rows, active response tabs, request-history selection, and compact status labels.
- **Deep Sage** (`accent-hover`) and **Muted Sage** (`accent-disabled`): primary-button interaction states.

### Neutral

- **Warm Paper** (`canvas`): the application background and subdued labels.
- **White Working Surface** (`panel`): controls, source panels, command panels, and response panels.
- **Forest Ink** (`ink`): readable content, headings, and primary labels.
- **Quiet Ink** (`muted`): context, provenance, paths, and supporting labels.
- **Fine Rule** (`line`): panel divisions and neutral control borders.
- **Tree Paper** (`canvas-tree`): the graph field, with a sparse dotted grid; it remains visibly distinct from the source panel.
- **Control Wash** (`control-hover`): neutral-button hover feedback.

Functional colors are state roles, not additional brand accents. `danger` and its pale surface/border support destructive actions; `visited` and `current` distinguish traversal history from its active position. The node border and symbol colors support the graph's quieter default state. `focus` and `field-focus` are the shipped keyboard/field focus treatments.

**The State Has Meaning Rule.** Use sage for primary actions and selection, pale green for visited nodes, and pale amber for the current traversal position. Pair state color with text, a legend, or a visible control state.

## Typography

The UI uses the system-oriented sans stack in the frontmatter. Inter is the first preference, but the application does not load a custom font; the operating system fallback must remain usable. The technical stack uses native monospace families. There is no separate display face.

- **Headline:** page purpose; the desktop headline reduces to (25px) at the narrow breakpoint.
- **Inspector title:** selected source title, kept prominent above its path and actions.
- **Panel title:** compact working-region labels. Settings introductions use a local (18px) title treatment.
- **Node title:** a compact two-line graph label, clipped after two lines; the inspector retains the complete title.
- **Body:** general interface text and page introductions.
- **Source:** readable content with a more open line height than the interface. Source paragraphs preserve whitespace; API read results limit the source block to (800px).
- **Label / Button:** strong but restrained control hierarchy. Labels stay in sentence case.
- **Small / Code / Path:** subordinate context and technical data. Existing micro-labels range from (9px) to (11px); do not extend their use to primary reading or actions.

**The Source Stays Legible Rule.** Use proportional type for source reading and editing. Reserve monospace for paths, JSON, API names, and technical identifiers.

## Layout

The shell has a horizontal navigation bar (72px desktop height), a page-heading row, and bounded working regions. The main container has a (2000px) maximum width with desktop padding of (28px 28px 16px). The spacing steps in frontmatter are observed repeated values, not a claim of a mathematically uniform scale.

The desktop workspace is a single connected enclosure: an outline (220px), a flexible graph (minimum 280px), and a source inspector (350px). A shared version/action bar caps it. The working region uses `calc(100dvh - 260px)` with a (530px) minimum height. Keep the graph and selected source available together; avoid inserting decorative content ahead of them.

The Traversal Lab places a command/history panel (330px) beside the graph and response regions. The right column uses `minmax(290px, 1fr)` above `minmax(240px, .8fr)`, separated by (18px). Its desktop height is `calc(100dvh - 218px)` with a (650px) minimum. History and response bodies scroll within their regions. Keep the active request visible when the history changes.

Observed responsive behavior:

- At and above (1700px), the workspace outline and inspector expand to (250px) and (390px); the graph minimum becomes (400px).
- At and below (1200px), the workspace columns become (195px / minimum 240px / 300px), main padding narrows, the lab command panel becomes (300px), and secondary chrome is reduced.
- At and below (960px), the workspace inspector moves beneath the outline and graph; the upper row is (450px). Settings become a single column. The lab retains two columns with a (280px) command panel and taller stacked right-hand regions.
- At and below (680px), navigation wraps beneath the brand, the workspace stacks outline / graph / inspector, and the lab stacks command / graph / response. The outline is capped at (260px), the workspace graph is (350px), and request history is capped at (150px). Horizontal graph scrolling remains available.
- Independently of viewport breakpoints, a graph container narrower than (650px) initially focuses a nearby subtree when no explicit scope is set. “Show whole tree” and “Focus” controls remain explicit choices.

## Elevation & Depth

The interface relies on white surfaces, fine borders, and a lightly tinted graph field. Working panels have no shadow. Tree nodes receive a modest resting shadow and stronger hover/selection feedback; dialogs and notifications float above the page. Exact shadow, backdrop, and motion values live in the sidecar because the frontmatter schema does not support them.

**The Quiet Surface Rule.** Keep working panels flat. Use the existing modest lift on tree nodes and stronger shadows only for transient overlays.

Buttons and graph-node state transitions last (150ms). Progress width changes take (300ms). Notifications enter over (200ms) with an ease-out fade and a small upward movement. Reduced-motion preference removes animation and transitions; do not add movement to source-reading or tree traversal merely for decoration.

## Shapes

The corner hierarchy is deliberate: compact labels and icon/row controls are tighter than fields and buttons; graph nodes are softer; panel groups and dialogs are broader. Use the role-based corner tokens from frontmatter. The implemented control corner is (7px), graph-node corner is (9px), panel corner is (12px), and dialog corner is (14px).

Most boundaries use a single thin stroke. Tree connections are smooth horizontal curves with (1.5px) strokes, increasing to (2px) when visited. Graph nodes have a fixed (224px × 66px) footprint, with a (30px) symbol tile. Circular marks are reserved for small state dots and the existing empty-state icon treatment.

Inline SVG icons use a (24-unit) viewBox, (1.7px) stroke, rounded caps and joins, and no fill. Standard control icons render at (17px); outline icons use (14px). Use the existing icon vocabulary when extending the application.

## Components

### Buttons

Compact, legible actions with a clear primary emphasis. Full buttons use the frontmatter padding and control corner, a (37px) minimum height, a thin border, and an (8px) icon gap. Primary buttons use sage and white; neutral buttons use white and ink; destructive confirmations use the pale danger surface. Text actions use sage, a smaller (11px) label, and underline on hover. Icon buttons are (30px) squares with an explicit accessible label.

Keep the shipped hover states, visible focus outline, and disabled treatment. Disabled buttons have reduced opacity and a wait cursor; this records current behavior and is not a general prescription for all unavailable controls. Busy actions update their labels, such as “Sending…” or “Testing…”.

### Inputs / Fields

Quiet white fields with thin neutral borders, the control corner, and frontmatter padding. Use visible labels; placeholders only supplement them. Focus uses a distinct field outline (2px, offset 1px). The shared keyboard outline is (3px, offset 3px), although field-specific focus styles take precedence on inputs, selects, and textareas.

Source editing uses proportional text. JSON arguments and import text use monospace. Errors appear as explicit text in an error surface or notification; do not imply that field-level invalid or disabled styling has been implemented beyond the observed native behavior.

### Navigation

Persistent horizontal navigation contains Workspace, Traversal Lab, and API & models. The active destination uses sage text and a short bottom rule (3px), with `aria-current`. Default links use muted ink and brighten on hover. At narrow widths the complete navigation wraps below the brand. Selected response tabs use a sage wash, sage type, and `aria-selected`.

### Chips

Small, gently squared labels communicate live API, node type, optional configuration, and other short states. The sage version uses a pale accent wash and fine green border; the subdued version uses paper, muted ink, and the standard rule. These labels are informational; do not make them look like interactive filters unless an action is actually implemented.

### Panels / Containers

White, flat working containers with thin borders and the panel corner. Panel-heading rows are usually (56px) high. Padding follows function: graph headers, command interiors, source reading, and response text use different established insets. Preserve the connected workspace enclosure rather than turning each of its regions into detached cards. Dialogs use the larger corner, a dimmed and blurred backdrop, and separated heading/body/footer areas.

### Knowledge tree

The outline and graph select the same node. Outline rows use a pale sage selection, indentation, and a fine vertical hierarchy rule. Labels truncate in the narrow outline; the selected source title remains available in the inspector. Graph nodes show an icon, a two-line title, kind, and an optional child count. Selection uses a sage border and ring. Visited nodes have a pale green surface; the current traversal position has a pale amber surface and ring.

Graph dragging changes arrangement only where enabled. Outline dragging changes the parent after validation. Keep these effects distinct in labels and feedback. The graph offers explicit fit, zoom, whole-tree, and focus controls, and retains scrolling when it exceeds the viewing area.

### Source inspector

The selected title, path, and actions lead. The abstract is a tinted inset with an origin label; source content is the main reading surface. Explicit references, child menus, and preserved-source provenance are separate sections with fine dividers. Draft editing retains visible saved/unsaved/saving state. Published versions present a read-only state. Do not blend inferred summaries with the exact source text.

### Command history and response

Requests are numbered in a compact ledger, with tool name, target context, and duration or error. Active history uses the same sage wash as other selection. The selected row is kept in view without moving the page. Readable, JSON, and Trace views share one response region. Traversal colors reflect recorded events for the selected response/version; replay is labeled as recorded activity. Empty and error states explain the next available action without inventing success.

## Do's and Don'ts

### Do:

- **Do** keep the page purpose, selected version, and current working state visible.
- **Do** preserve the distinction between abstracts, original content, preserved sources, and explicit references.
- **Do** reuse the actual control, node, and panel corner tokens instead of applying one radius everywhere.
- **Do** keep the selected request visible in history and align its response with the traversal state.
- **Do** retain visible keyboard focus, accessible control labels, native dialog behavior, and reduced motion support.
- **Do** stack working regions at narrow widths and allow the graph itself to scroll.

### Don't:

- **Don't** add marketing heroes, decorative cards, or fabricated metrics to this operational workspace.
- **Don't** make source instructions look like automatically executed workflow decisions.
- **Don't** present lexical search, sample content, or fixtures as model output.
- **Don't** use graph repositioning to imply a hierarchy change; outline movement and graph layout have distinct effects.
- **Don't** use color alone to distinguish source provenance or request outcomes.
- **Don't** infer new accent families or replace the quiet panel system with gradients, heavy shadows, or ornamental surfaces.
