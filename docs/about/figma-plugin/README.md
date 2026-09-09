# TE-KG Architecture Builder

This local Figma development plugin creates the detailed TE-KG data
architecture as editable Figma layers. It does not use the network, an LLM, or
the Figma MCP service.

## Install

1. Open the target design file in the Figma desktop app.
2. Open the Figma menu and select `Plugins > Development > Import plugin from
   manifest...`.
3. Select this directory's `manifest.json`.

## Run

1. Open `Plugins > Development > TE-KG Architecture Builder`.
2. The plugin creates or rebuilds the page named
   `TE-KG Detailed Architecture`.
3. The existing `TE-KG Overview` page is not modified.

Running the plugin again intentionally rebuilds only the detailed architecture
page. Do not rerun it after making manual adjustments unless you want to return
to the generated baseline.

## Local verification

Run `node --check code.js` and `node verify.cjs` from this directory. The mock
verification checks that the complete page hierarchy can be generated without
network or Figma MCP access.

## Visual assets

- The DeepSeek-V3 mark uses the DeepSeek path distributed by Simple Icons.
- The remaining scientific and interface symbols are lightweight editable
  vectors drawn for this diagram in a Lucide-compatible outline style.
- The plugin embeds every vector locally and declares no network access.
